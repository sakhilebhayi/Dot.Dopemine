<?php

namespace App\Services\Points;

use App\Models\ExpiryWarning;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;

/**
 * The pull side of the expiry heads-up (POINTS_EXPIRY_DELIVERY=infodot): InfoDot asks which warnings are due, emails
 * the people itself and reports the result per warning. Dopemine holds and receives no address, name or message
 * text; it only ever hands out `{warning_id, user_uuid, points, expires_on}`.
 *
 * Like the Notify path it sits beside the ledger: it reads lots and writes only to `expiry_warnings`, never to
 * `ledger_entries`.
 */
class ExpiryWarningPull
{
    public function __construct(private readonly PointsLedger $ledger) {}

    public static function enabled(): bool
    {
        return config('points.expiry_delivery', 'infodot') === 'infodot';
    }

    /**
     * Warnings that are due to be sent, oldest first. A warning whose lot was spent or lapsed meanwhile is closed as
     * `moot` and left out. `points` is what is left in the lot right now.
     *
     * @return list<array{warning_id: int, user_uuid: string, points: int, expires_on: string}>
     */
    public function due(int $limit): array
    {
        if (! self::enabled()) {
            return [];
        }

        $this->queueLotsInWindow();

        $out = [];
        $query = ExpiryWarning::where('status', ExpiryWarning::PENDING)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id');

        // Moot rows are closed as we go, so keep reading past them until the page is full or the rows run out.
        $query->chunkById(max($limit, 50), function ($warnings) use (&$out, $limit): bool {
            foreach ($warnings as $warning) {
                $lot = LedgerEntry::find($warning->lot_id);
                $remaining = $lot ? $this->ledger->remaining($lot) : 0;

                if ($lot === null || $lot->expires_at === null || $lot->expires_at->isPast() || $remaining <= 0) {
                    ExpiryWarning::where('id', $warning->id)->where('status', ExpiryWarning::PENDING)
                        ->update(['status' => ExpiryWarning::MOOT, 'next_attempt_at' => null]);

                    continue;
                }

                $out[] = [
                    'warning_id' => (int) $warning->id,
                    'user_uuid' => (string) $warning->user_uuid,
                    'points' => $remaining,
                    'expires_on' => $lot->expires_at->toDateString(),
                ];

                if (count($out) >= $limit) {
                    return false;
                }
            }

            return true;
        });

        return $out;
    }

    /**
     * Apply InfoDot's report on one warning and return its resulting state, or null if there is no such warning.
     * Idempotent and monotonic: a delivered, failed or moot row never changes again, and a `retry` only counts while
     * the warning is actually due (a replayed `retry` inside the backoff wait is a no-op).
     */
    public function record(int $warningId, string $outcome): ?string
    {
        return DB::transaction(function () use ($warningId, $outcome): ?string {
            $warning = ExpiryWarning::lockForUpdate()->find($warningId);

            if ($warning === null) {
                return null;
            }

            if ($warning->status !== ExpiryWarning::PENDING) {
                return $warning->status;
            }

            match ($outcome) {
                'delivered' => $warning->forceFill([
                    'status' => ExpiryWarning::DELIVERED, 'attempts' => $warning->attempts + 1, 'delivered_at' => now(),
                    'next_attempt_at' => null, 'notify_status' => null, 'last_error' => null,
                ])->save(),
                'permanent_failure' => $warning->forceFill([
                    'status' => ExpiryWarning::FAILED, 'attempts' => $warning->attempts + 1, 'next_attempt_at' => null, 'last_error' => 'permanent_failure',
                ])->save(),
                'retry' => $this->retry($warning),
            };

            return $warning->status;
        });
    }

    private function retry(ExpiryWarning $warning): void
    {
        // Not due: a retry for this attempt was already recorded (or the row is waiting out its backoff). No-op.
        if ($warning->next_attempt_at !== null && $warning->next_attempt_at->isFuture()) {
            return;
        }

        $attempts = $warning->attempts + 1;
        $minutes = ExpiryWarning::backoffMinutesAfter($attempts);

        if ($minutes === null) {
            $warning->forceFill(['status' => ExpiryWarning::FAILED, 'attempts' => $attempts, 'next_attempt_at' => null, 'last_error' => 'retries_exhausted'])->save();

            return;
        }

        $warning->forceFill(['attempts' => $attempts, 'next_attempt_at' => now()->addMinutes($minutes), 'last_error' => 'retry'])->save();
    }

    /** One warning per lot that lapses inside the warning window; the unique lot id makes this idempotent. */
    private function queueLotsInWindow(): void
    {
        foreach ($this->ledger->dueForExpiryWarning((int) config('points.expiry_warning.days_before', 30)) as $lot) {
            $this->ledger->markWarned($lot);
        }
    }
}
