<?php

namespace App\Services\Points;

use App\Models\ExpiryWarning;
use App\Models\LedgerEntry;
use App\Services\Notify\NotifyClient;
use App\Services\Notify\NotifyResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers the one 30-day heads-up before a lot of points lapses, through Dot.Notify.
 *
 * It sits beside the ledger, never inside it: it only reads lots, and writes only to `expiry_warnings`, so a slow
 * or broken Notify cannot slow, fail or alter a single ledger write.
 *
 *  - one notification per warning (per lot); the Notify idempotency key is derived from the warning id, so a retry
 *    or a double run collapses into one message;
 *  - a warning is marked delivered only after Notify answers 2xx; a transient failure is retried with a growing
 *    wait, and given up on after the configured number of attempts (the row stays, status `failed`, for a human);
 *  - the points and date sent are re-read at send time, and a lot that was spent or lapsed meanwhile is closed as
 *    `moot` instead of warning about points the person no longer has;
 *  - what is sent: the user's uuid, the points about to expire and the expiry date, plus the two things Notify's
 *    contract itself requires (the company uuid and the recipient address). Nothing about contributions.
 *  - not configured (no Notify URL or secret): it does nothing, and says so once per run.
 */
class ExpiryWarnings
{
    public const EVENT = 'points.expiring';

    public function __construct(
        private readonly PointsLedger $ledger,
        private readonly NotifyClient $notify,
        private readonly ExpiryWarningRecipients $recipients,
    ) {}

    /**
     * @return array{configured: bool, queued: int, delivered: int, retrying: int, failed: int, moot: int, unaddressable: int, stopped: ?string}
     */
    public function run(): array
    {
        $summary = ['configured' => true, 'queued' => 0, 'delivered' => 0, 'retrying' => 0, 'failed' => 0, 'moot' => 0, 'unaddressable' => 0, 'stopped' => null];

        if (! $this->notify->enabled()) {
            Log::info('Expiry warnings skipped: Dot.Notify is not configured (NOTIFY_INGEST_URL or NOTIFY_INGEST_SECRET is blank).');

            return ['configured' => false] + $summary;
        }

        $this->queueDueLots($summary);
        $this->deliverPending($summary);

        Log::info('Expiry warnings run finished.', $summary);

        return $summary;
    }

    /** @param  array<string, mixed>  $summary */
    private function queueDueLots(array &$summary): void
    {
        foreach ($this->ledger->dueForExpiryWarning((int) config('points.expiry_warning.days_before', 30)) as $lot) {
            // A lot nobody can be addressed for is not queued at all: it is looked at again on the next run, while
            // it is still inside the warning window.
            if ($this->recipients->for($lot->user_uuid) === null) {
                $summary['unaddressable']++;

                continue;
            }

            $this->ledger->markWarned($lot);
            $summary['queued']++;
        }
    }

    /** @param  array<string, mixed>  $summary */
    private function deliverPending(array &$summary): void
    {
        $stopAfter = (int) config('points.expiry_warning.stop_after_failures', 3);
        $failuresInARow = 0;

        $due = ExpiryWarning::where('status', ExpiryWarning::PENDING)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit((int) config('points.expiry_warning.batch', 200))->get();

        foreach ($due as $warning) {
            try {
                $outcome = $this->deliver($warning, $summary);
            } catch (Throwable $e) {
                // One bad row must not stop the others.
                Log::warning('An expiry warning could not be processed.', ['warning' => $warning->id, 'error' => class_basename($e)]);

                continue;
            }

            if ($outcome === NotifyResult::UNAUTHORISED) {
                $summary['stopped'] = 'Dot.Notify refused our signature: check NOTIFY_INGEST_SECRET against NOTIFY_INGEST_SECRET_'.strtoupper($this->notify->platform()).' on Notify.';
                Log::error('Expiry warnings stopped: '.$summary['stopped']);

                return;
            }

            $failuresInARow = $outcome === NotifyResult::RETRY ? $failuresInARow + 1 : 0;

            if ($stopAfter > 0 && $failuresInARow >= $stopAfter) {
                $summary['stopped'] = 'Dot.Notify did not answer successfully '.$failuresInARow.' times in a row; the rest wait for the next run.';
                Log::warning('Expiry warnings paused: '.$summary['stopped']);

                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return string|null a NotifyResult outcome, or null when nothing was sent
     */
    private function deliver(ExpiryWarning $warning, array &$summary): ?string
    {
        $lot = LedgerEntry::find($warning->lot_id);
        $remaining = $lot ? $this->ledger->remaining($lot) : 0;

        if ($lot === null || $lot->expires_at === null || $lot->expires_at->isPast() || $remaining <= 0) {
            $warning->forceFill(['status' => ExpiryWarning::MOOT, 'next_attempt_at' => null])->save();
            $summary['moot']++;

            return null;
        }

        $recipient = $this->recipients->for($warning->user_uuid);

        if ($recipient === null) {
            $summary['unaddressable']++;

            return null;
        }

        $result = $this->notify->send([
            'platform' => $this->notify->platform(),
            'company_uuid' => $recipient['company_uuid'],
            'event' => self::EVENT,
            'recipient' => ['email' => $recipient['email']],
            'data' => [
                'user_uuid' => $warning->user_uuid,
                'points' => $remaining,
                'expires_on' => $lot->expires_at->toDateString(),
            ],
            'idempotency_key' => self::EVENT.':warning-'.$warning->id,
        ]);

        $attempts = $warning->attempts + 1;

        match ($result->outcome) {
            NotifyResult::DONE => $this->markDelivered($warning, $attempts, $result, $summary),
            NotifyResult::RETRY => $this->scheduleRetry($warning, $attempts, $result, $summary),
            NotifyResult::UNAUTHORISED => null, // a configuration problem, not this warning's fault: no attempt is spent
            default => $this->giveUp($warning, $attempts, $result, $summary),
        };

        return $result->outcome;
    }

    /** @param  array<string, mixed>  $summary */
    private function markDelivered(ExpiryWarning $warning, int $attempts, NotifyResult $result, array &$summary): void
    {
        $warning->forceFill([
            'status' => ExpiryWarning::DELIVERED, 'attempts' => $attempts, 'delivered_at' => now(),
            'next_attempt_at' => null, 'notify_status' => $result->notifyStatus, 'last_error' => null,
        ])->save();
        $summary['delivered']++;
    }

    /** @param  array<string, mixed>  $summary */
    private function scheduleRetry(ExpiryWarning $warning, int $attempts, NotifyResult $result, array &$summary): void
    {
        $minutes = ExpiryWarning::backoffMinutesAfter($attempts);

        if ($minutes === null) {
            $this->giveUp($warning, $attempts, $result, $summary);

            return;
        }

        $warning->forceFill(['attempts' => $attempts, 'next_attempt_at' => now()->addMinutes($minutes), 'last_error' => $result->error])->save();
        $summary['retrying']++;
    }

    /** @param  array<string, mixed>  $summary */
    private function giveUp(ExpiryWarning $warning, int $attempts, NotifyResult $result, array &$summary): void
    {
        $warning->forceFill(['status' => ExpiryWarning::FAILED, 'attempts' => $attempts, 'next_attempt_at' => null, 'last_error' => $result->error])->save();
        $summary['failed']++;
    }
}
