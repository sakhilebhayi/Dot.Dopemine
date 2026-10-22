<?php

namespace App\Services\Points;

use App\Models\LedgerEntry;
use App\Models\PointsDigest;
use App\Models\RewardRule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The pull side of the weekly / monthly summary (POINTS_DIGESTS_ENABLED). InfoDot asks which digests are due, sends
 * them under the person's own preferences and reports the result per digest. Dopemine holds and receives no address,
 * name, message text or contribution content: a digest is only figures read from the ledger.
 *
 * Rules it keeps:
 *  - only people who EARNED points in the closed period get one (nothing is produced for anyone else, so nobody is
 *    nagged);
 *  - at most one digest per person, period and period start (a unique key; creating is idempotent);
 *  - figures come from `ledger_entries` at the moment the row is first produced, never from a cache, and are then
 *    fixed, so a re-pull or a retry shows the same numbers;
 *  - it only reads the ledger; the only table it writes is `points_digests`.
 */
class DigestPull
{
    public function __construct(private readonly PointsLedger $ledger) {}

    public static function enabled(): bool
    {
        return (bool) config('points.digests.enabled', false);
    }

    /**
     * The most recent fully closed period of the given kind, in the application timezone: a week is an ISO week
     * (Monday to Sunday), a month is a calendar month. Returns the first and last day and the half-open window
     * [from, to) the ledger is read over. Calendar days are stepped by date, so a DST change cannot shift a boundary.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, from: CarbonImmutable, to: CarbonImmutable}
     */
    public function closedPeriod(string $period, ?CarbonInterface $now = null): array
    {
        $zone = (string) config('app.timezone');
        $today = ($now ? CarbonImmutable::instance($now) : CarbonImmutable::now())->setTimezone($zone)->startOfDay();

        if ($period === PointsDigest::WEEK) {
            $start = $today->startOfWeek(CarbonInterface::MONDAY)->subWeek();
            $end = $start->addDays(6);
        } else {
            $start = $today->startOfMonth()->subMonthNoOverflow();
            $end = $start->endOfMonth()->startOfDay();
        }

        return ['start' => $start, 'end' => $end, 'from' => $start->startOfDay(), 'to' => $end->addDay()->startOfDay()];
    }

    /**
     * Digests that are due to be sent for the latest closed period, oldest first: pending and not waiting out a retry
     * delay. People with nothing earned in that period have no digest and are never listed.
     *
     * @return list<array<string, mixed>>
     */
    public function due(string $period, int $limit): array
    {
        if (! self::enabled()) {
            return [];
        }

        $limit = max(1, min($limit, (int) config('points.digests.max_per_pull', 200)));
        $window = $this->closedPeriod($period);

        $this->produce($period, $window, $limit);

        return PointsDigest::where('period', $period)
            ->whereDate('period_start', $window['start']->toDateString())
            ->where('state', PointsDigest::PENDING)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (PointsDigest $d) => $this->present($d))
            ->all();
    }

    /**
     * Apply InfoDot's report on one digest and return its resulting state, or null if there is no such digest.
     * Idempotent and monotonic: a delivered, skipped or failed row never changes again, and a `retry` only counts
     * while the digest is actually due (a replayed `retry` inside the backoff wait is a no-op).
     */
    public function record(string $digestId, string $outcome): ?string
    {
        return DB::transaction(function () use ($digestId, $outcome): ?string {
            $digest = PointsDigest::where('uuid', $digestId)->lockForUpdate()->first();

            if ($digest === null) {
                return null;
            }

            if ($digest->state !== PointsDigest::PENDING) {
                return $digest->state;
            }

            match ($outcome) {
                'delivered' => $digest->forceFill([
                    'state' => PointsDigest::DELIVERED, 'attempts' => $digest->attempts + 1, 'delivered_at' => now(),
                    'next_attempt_at' => null, 'last_error' => null,
                ])->save(),
                'skipped' => $digest->forceFill(['state' => PointsDigest::SKIPPED, 'next_attempt_at' => null, 'last_error' => null])->save(),
                'retry' => $this->retry($digest),
            };

            return $digest->state;
        });
    }

    private function retry(PointsDigest $digest): void
    {
        // Not due: a retry for this attempt was already recorded (or the row is waiting out its backoff). No-op.
        if ($digest->next_attempt_at !== null && $digest->next_attempt_at->isFuture()) {
            return;
        }

        $attempts = $digest->attempts + 1;
        $minutes = PointsDigest::backoffMinutesAfter($attempts);

        if ($minutes === null) {
            $digest->forceFill(['state' => PointsDigest::FAILED, 'attempts' => $attempts, 'next_attempt_at' => null, 'last_error' => 'retries_exhausted'])->save();

            return;
        }

        $digest->forceFill(['attempts' => $attempts, 'next_attempt_at' => now()->addMinutes($minutes), 'last_error' => 'retry'])->save();
    }

    /**
     * Create the digest of every person who earned points in the window and has none yet, up to `$cap` per pull.
     * The unique (user, period, period_start) key makes a concurrent or repeated pull harmless.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, from: CarbonImmutable, to: CarbonImmutable}  $window
     */
    private function produce(string $period, array $window, int $cap): void
    {
        $people = LedgerEntry::query()
            ->where('type', LedgerEntry::AWARD)->where('points', '>', 0)
            ->where('created_at', '>=', $window['from'])->where('created_at', '<', $window['to'])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('points_digests')
                ->whereColumn('points_digests.user_uuid', 'ledger_entries.user_uuid')
                ->where('points_digests.period', $period)
                ->whereDate('points_digests.period_start', $window['start']->toDateString()))
            ->distinct()->orderBy('user_uuid')->limit($cap)->pluck('user_uuid');

        if ($people->isEmpty()) {
            return;
        }

        $labels = $this->labels();

        foreach ($people as $userUuid) {
            DB::table('points_digests')->insertOrIgnore([
                'uuid' => (string) Str::uuid(),
                'user_uuid' => $userUuid,
                'period' => $period,
                'period_start' => $window['start']->toDateString(),
                'period_end' => $window['end']->toDateString(),
                'payload' => json_encode($this->payload((string) $userUuid, $window, $labels)),
                'state' => PointsDigest::PENDING,
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Figures for one person, read from the ledger. `points_earned` is what was awarded in the period and
     * `points_reversed` what the ledger took back in it (a positive number, shown plainly beside it, never netted
     * away); neither is ever negative. Refunds of a failed redemption are the person's own points handed back, not
     * earnings, so they are not counted as earned.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, from: CarbonImmutable, to: CarbonImmutable}  $window
     * @param  array<string, string>  $labels
     * @return array{points_earned: int, awards: list<array{rule_key: string, label: string, count: int, points: int}>, points_reversed: int, balance: int, expiring_next_30_days: array{points: int, first_at: string}|null}
     */
    private function payload(string $userUuid, array $window, array $labels): array
    {
        $inWindow = fn () => LedgerEntry::where('user_uuid', $userUuid)->where('created_at', '>=', $window['from'])->where('created_at', '<', $window['to']);

        $awards = $inWindow()->where('type', LedgerEntry::AWARD)->where('points', '>', 0)
            ->selectRaw('rule_key, count(*) as awards, sum(points) as total')->groupBy('rule_key')->get()
            ->map(fn ($row) => [
                'rule_key' => (string) $row->rule_key,
                'label' => $labels[$row->rule_key] ?? $this->humanise((string) $row->rule_key),
                'count' => (int) $row->awards,
                'points' => (int) $row->total,
            ])
            ->sortBy([['points', 'desc'], ['rule_key', 'asc']])->values()->all();

        $reversed = -(int) $inWindow()->where('type', LedgerEntry::REVERSE)->sum('points');

        $lots = $this->ledger->openLots($userUuid);
        $soon = $lots->filter(fn (LedgerEntry $lot) => $lot->expires_at->lte(now()->addDays(30)));

        return [
            'points_earned' => (int) array_sum(array_column($awards, 'points')),
            'awards' => $awards,
            'points_reversed' => max(0, $reversed),
            'balance' => (int) $lots->sum('remaining'),
            'expiring_next_30_days' => $soon->isEmpty() ? null : ['points' => (int) $soon->sum('remaining'), 'first_at' => $soon->min('expires_at')->toIso8601String()],
        ];
    }

    /**
     * The wording each reward rule has in the back-office: the description it was first published with (what the
     * profile history already shows). A rule without one falls back to a readable form of its key.
     *
     * @return array<string, string>
     */
    private function labels(): array
    {
        return RewardRule::orderBy('version')->get(['key', 'note'])->unique('key')
            ->filter(fn ($rule) => filled($rule->note))->mapWithKeys(fn ($rule) => [$rule->key => (string) $rule->note])->all();
    }

    /** `infodot.answer.accepted` becomes "Answer accepted": no internal id leaves Dopemine. */
    private function humanise(string $ruleKey): string
    {
        $parts = explode('.', $ruleKey);
        $readable = count($parts) > 1 ? array_slice($parts, 1) : $parts;

        return ucfirst(str_replace('_', ' ', implode(' ', $readable))) ?: 'Contribution';
    }

    /** @return array<string, mixed> */
    private function present(PointsDigest $digest): array
    {
        $payload = (array) $digest->payload;

        return [
            'digest_id' => $digest->uuid,
            'user_uuid' => $digest->user_uuid,
            'period' => $digest->period,
            'period_start' => $digest->period_start->toDateString(),
            'period_end' => $digest->period_end->toDateString(),
            'points_earned' => $payload['points_earned'],
            'awards' => $payload['awards'],
            'points_reversed' => $payload['points_reversed'],
            'balance' => $payload['balance'],
            'expiring_next_30_days' => $payload['expiring_next_30_days'],
        ];
    }
}
