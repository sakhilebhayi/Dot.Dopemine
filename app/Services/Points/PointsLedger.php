<?php

namespace App\Services\Points;

use App\Exceptions\Points\InsufficientPoints;
use App\Models\LedgerEntry;
use App\Models\RewardRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the Dot Points ledger.
 *
 * Rules it keeps, each covered by a test:
 *  - append-only: nothing is edited or deleted, mistakes are corrected with new entries;
 *  - idempotent: the same idempotency key, or the same contribution already rewarded under the same rule,
 *    never pays twice;
 *  - every award records the exact rule version and expires 12 months after it is granted;
 *  - spending takes the oldest-expiring points first and never more than the unexpired balance;
 *  - a reversal takes back only what is still unspent, so a balance can never go below zero;
 *  - the balance is always derived from the entries, nothing is stored that could drift.
 */
class PointsLedger
{
    public const LIFETIME_MONTHS = 12;

    /**
     * @param  int|null  $points  for a rule with a range (min_points..max_points): how many, clamped into the range
     * @param  array<string, mixed>  $meta
     */
    public function award(string $userUuid, RewardRule $rule, string $contributionRef, string $idempotencyKey, ?int $points = null, array $meta = [], bool $approved = false): AwardOutcome
    {
        if (($existing = LedgerEntry::where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return new AwardOutcome(AwardOutcome::DUPLICATE, $existing);
        }

        if (! $rule->active) {
            return new AwardOutcome(AwardOutcome::RULE_OFF);
        }

        if ($rule->requires_approval && ! $approved) {
            return new AwardOutcome(AwardOutcome::NEEDS_APPROVAL);
        }

        $amount = $this->resolvePoints($rule, $points);

        return DB::transaction(function () use ($userUuid, $rule, $contributionRef, $idempotencyKey, $amount, $meta) {
            $already = LedgerEntry::where('type', LedgerEntry::AWARD)
                ->where('rule_key', $rule->key)
                ->where('contribution_ref', $contributionRef)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('ledger_entries as r')->whereColumn('r.lot_id', 'ledger_entries.id')->where('r.type', LedgerEntry::REVERSE))
                ->count();

            if ($already >= $rule->max_awards_per_contribution) {
                return new AwardOutcome(AwardOutcome::ALREADY_REWARDED);
            }

            $decay = $this->pairDecay($userUuid, $meta);
            if ($decay !== null) {
                $meta['pair_decay'] = ['count' => $decay['count'], 'multiplier' => $decay['multiplier'], 'full_points' => $amount];
                $amount = (int) floor($amount * $decay['multiplier']);

                if ($amount <= 0) {
                    return new AwardOutcome(AwardOutcome::PAIR_LIMITED);
                }
            }

            if ($rule->daily_cap_points !== null && $this->awardedToday($userUuid, $rule->key) + $amount > $rule->daily_cap_points) {
                return new AwardOutcome(AwardOutcome::DAILY_CAP);
            }

            $entry = LedgerEntry::create([
                'user_uuid' => $userUuid,
                'type' => LedgerEntry::AWARD,
                'points' => $amount,
                'rule_key' => $rule->key,
                'rule_version' => $rule->version,
                'contribution_ref' => $contributionRef,
                'idempotency_key' => $idempotencyKey,
                'expires_at' => now()->addMonths(self::LIFETIME_MONTHS),
                'meta' => $meta ?: null,
            ]);

            return new AwardOutcome(AwardOutcome::AWARDED, $entry);
        });
    }

    /** Points the person can spend right now: what is left in lots that have not expired. */
    public function balance(string $userUuid): int
    {
        return (int) $this->openLots($userUuid)->sum('remaining');
    }

    /**
     * Lots with points left, oldest expiry first. Each item is the lot entry with a `remaining` attribute.
     *
     * @return Collection<int, LedgerEntry>
     */
    public function openLots(string $userUuid): Collection
    {
        return $this->lotsQuery($userUuid)
            ->where('expires_at', '>', now())
            ->get()
            ->each(fn (LedgerEntry $lot) => $lot->setAttribute('remaining', (int) $lot->remaining))
            ->filter(fn (LedgerEntry $lot) => $lot->remaining > 0)
            ->sortBy([['expires_at', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Every lot a person ever had, spent or lapsed or not, each with a `remaining` attribute. Newest first. For
     * read-only views (the back-office ledger explorer); spending only ever uses openLots().
     *
     * @return Collection<int, LedgerEntry>
     */
    public function lots(string $userUuid): Collection
    {
        return $this->lotsQuery($userUuid)->orderByDesc('ledger_entries.id')->get()
            ->each(fn (LedgerEntry $lot) => $lot->setAttribute('remaining', (int) $lot->remaining));
    }

    /**
     * Spend points, oldest-expiring lots first. All or nothing: never more than the balance.
     *
     * @return list<LedgerEntry> one entry per lot drawn on
     *
     * @throws InsufficientPoints
     */
    public function redeem(string $userUuid, int $points, string $idempotencyKey, ?string $reason = null, array $meta = []): array
    {
        abort_if($points <= 0, 422, 'Redeem a positive number of points.');

        $done = LedgerEntry::where('user_uuid', $userUuid)->where('idempotency_key', 'like', $idempotencyKey.':%')->get();

        if ($done->isNotEmpty()) {
            return $done->all();
        }

        return DB::transaction(function () use ($userUuid, $points, $idempotencyKey, $reason, $meta) {
            $lots = $this->openLots($userUuid);
            $available = (int) $lots->sum('remaining');

            if ($available < $points) {
                throw new InsufficientPoints($points, $available);
            }

            $need = $points;
            $entries = [];

            foreach ($lots as $lot) {
                if ($need === 0) {
                    break;
                }

                $take = min($need, $lot->remaining);
                $entries[] = LedgerEntry::create([
                    'user_uuid' => $userUuid,
                    'type' => LedgerEntry::REDEEM,
                    'points' => -$take,
                    'lot_id' => $lot->id,
                    'idempotency_key' => $idempotencyKey.':'.$lot->id,
                    'reason' => $reason,
                    'meta' => $meta ?: null,
                ]);
                $need -= $take;
            }

            return $entries;
        });
    }

    /**
     * Give back what a failed or cancelled redemption took: a NEW lot (the original may have expired meanwhile),
     * which keeps the original expiry when that is still ahead and otherwise lasts 30 days.
     *
     * @param  list<LedgerEntry>  $redeemEntries
     */
    public function refund(array $redeemEntries, string $idempotencyKey, string $reason): ?LedgerEntry
    {
        if (($existing = LedgerEntry::where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $existing;
        }

        $points = -array_sum(array_map(fn (LedgerEntry $e) => $e->points, $redeemEntries));

        if ($points <= 0) {
            return null;
        }

        $first = $redeemEntries[0];
        $original = LedgerEntry::find($first->lot_id);
        $expires = $original && $original->expires_at->isFuture() ? $original->expires_at : now()->addDays(30);

        return LedgerEntry::create([
            'user_uuid' => $first->user_uuid,
            'type' => LedgerEntry::REFUND,
            'points' => $points,
            'idempotency_key' => $idempotencyKey,
            'expires_at' => $expires,
            'reason' => $reason,
        ]);
    }

    /**
     * Take back an award that was fraudulent, duplicated or invalidated. Only the part still unspent can be taken
     * back; the rest is reported as unrecovered for a moderator and the balance can never go below zero.
     *
     * @return array{reversed: int, unrecovered: int, entry: ?LedgerEntry}
     */
    public function reverse(LedgerEntry $award, string $reason, string $idempotencyKey): array
    {
        abort_unless($award->isLot(), 422, 'Only an award can be reversed.');

        if (($existing = LedgerEntry::where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return ['reversed' => -$existing->points, 'unrecovered' => (int) ($existing->meta['unrecovered'] ?? 0), 'entry' => $existing];
        }

        return DB::transaction(function () use ($award, $reason, $idempotencyKey) {
            if (LedgerEntry::where('lot_id', $award->id)->where('type', LedgerEntry::REVERSE)->exists()) {
                return ['reversed' => 0, 'unrecovered' => 0, 'entry' => null];
            }

            $remaining = $this->remaining($award);
            $unrecovered = $award->points - $remaining;
            $entry = null;

            if ($remaining > 0) {
                $entry = LedgerEntry::create([
                    'user_uuid' => $award->user_uuid,
                    'type' => LedgerEntry::REVERSE,
                    'points' => -$remaining,
                    'rule_key' => $award->rule_key,
                    'rule_version' => $award->rule_version,
                    'contribution_ref' => $award->contribution_ref,
                    'lot_id' => $award->id,
                    'idempotency_key' => $idempotencyKey,
                    'reason' => $reason,
                    'meta' => ['unrecovered' => $unrecovered],
                ]);
            } else {
                // Nothing left to take back; still record the decision and the amount already spent or expired.
                $entry = LedgerEntry::create([
                    'user_uuid' => $award->user_uuid,
                    'type' => LedgerEntry::REVERSE,
                    'points' => 0,
                    'rule_key' => $award->rule_key,
                    'rule_version' => $award->rule_version,
                    'contribution_ref' => $award->contribution_ref,
                    'lot_id' => $award->id,
                    'idempotency_key' => $idempotencyKey,
                    'reason' => $reason,
                    'meta' => ['unrecovered' => $unrecovered],
                ]);
            }

            return ['reversed' => $remaining, 'unrecovered' => $unrecovered, 'entry' => $entry];
        });
    }

    /** Write `expire` entries for lots past their expiry that still hold points. Safe to run any number of times. */
    public function expireDue(): int
    {
        $count = 0;

        $this->lotsQuery(null)->where('expires_at', '<=', now())->get()
            ->each(fn (LedgerEntry $lot) => $lot->setAttribute('remaining', (int) $lot->remaining))
            ->filter(fn (LedgerEntry $lot) => $lot->remaining > 0)
            ->each(function (LedgerEntry $lot) use (&$count): void {
                $created = LedgerEntry::firstOrCreate(
                    ['idempotency_key' => 'expire:'.$lot->id],
                    ['user_uuid' => $lot->user_uuid, 'type' => LedgerEntry::EXPIRE, 'points' => -$lot->remaining, 'lot_id' => $lot->id, 'reason' => 'Expired 12 months after it was granted.'],
                );
                $count += $created->wasRecentlyCreated ? 1 : 0;
            });

        return $count;
    }

    /** Points left in a lot (it plus everything that drew on it). */
    public function remaining(LedgerEntry $lot): int
    {
        return $lot->points + (int) LedgerEntry::where('lot_id', $lot->id)->sum('points');
    }

    /**
     * The reduction for a pair of accounts that reward each other, or null when none applies (switched off, no actor, a
     * back-office actor, or one-way help only). `count` is the awards between the two inside the window, both ways.
     *
     * @param  array<string, mixed>  $meta
     * @return array{count: int, multiplier: float}|null
     */
    private function pairDecay(string $userUuid, array $meta): ?array
    {
        $cfg = config('points.pair_decay');
        $actor = $meta['accepted_by'] ?? $meta['verified_by'] ?? $meta['approved_by'] ?? null;

        if (! ($cfg['enabled'] ?? false) || ! is_string($actor) || $actor === '' || $actor === $userUuid || str_starts_with($actor, 'admin:')) {
            return null;
        }

        $since = now()->subDays(max(1, (int) $cfg['window_days']));
        $forward = $this->awardsByActor($userUuid, $actor, $since);
        $backward = $this->awardsByActor($actor, $userUuid, $since);

        if ($backward === 0) {
            return null;
        }

        $count = $forward + $backward;
        $free = max(0, (int) $cfg['free_awards']);

        if ($count < $free) {
            return null;
        }

        $steps = array_values($cfg['steps'] ?: [0.0]);
        $multiplier = (float) $steps[min($count - $free, count($steps) - 1)];

        return $multiplier >= 1.0 ? null : ['count' => $count, 'multiplier' => $multiplier];
    }

    /** Live awards (not reversed) to `$author` since `$since` that `$actor` accepted, verified or approved. */
    private function awardsByActor(string $author, string $actor, Carbon $since): int
    {
        return LedgerEntry::where('user_uuid', $author)->where('type', LedgerEntry::AWARD)->where('created_at', '>=', $since)
            ->where(fn ($q) => $q->where('meta->accepted_by', $actor)->orWhere('meta->verified_by', $actor)->orWhere('meta->approved_by', $actor))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('ledger_entries as r')->whereColumn('r.lot_id', 'ledger_entries.id')->where('r.type', LedgerEntry::REVERSE))
            ->count();
    }

    /** Points awarded under a rule today (UTC), reversals not counted back. */
    public function awardedToday(string $userUuid, string $ruleKey): int
    {
        return (int) LedgerEntry::where('user_uuid', $userUuid)->where('type', LedgerEntry::AWARD)->where('rule_key', $ruleKey)
            ->where('created_at', '>=', now()->startOfDay())->sum('points');
    }

    /** Every entry of a person, newest first: the history the profile shows. */
    public function history(string $userUuid, int $limit = 100): Collection
    {
        return LedgerEntry::where('user_uuid', $userUuid)->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Lots expiring within `$days` that the person has not yet been warned about (one heads-up per lot).
     *
     * @return Collection<int, LedgerEntry>
     */
    public function dueForExpiryWarning(int $days = 30): Collection
    {
        return $this->lotsQuery(null)
            ->where('expires_at', '>', now())->where('expires_at', '<=', now()->addDays($days))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('expiry_warnings')->whereColumn('expiry_warnings.lot_id', 'ledger_entries.id'))
            ->get()->each(fn (LedgerEntry $lot) => $lot->setAttribute('remaining', (int) $lot->remaining))
            ->filter(fn (LedgerEntry $lot) => $lot->remaining > 0)->values();
    }

    public function markWarned(LedgerEntry $lot): void
    {
        DB::table('expiry_warnings')->insertOrIgnore(['lot_id' => $lot->id, 'user_uuid' => $lot->user_uuid, 'points' => $lot->remaining, 'warned_at' => now()]);
    }

    /** What an award under this rule is worth for a requested amount: the base value, or the request clamped into the rule's range. */
    public function amountFor(RewardRule $rule, ?int $points = null): int
    {
        return $this->resolvePoints($rule, $points);
    }

    private function resolvePoints(RewardRule $rule, ?int $points): int
    {
        if ($rule->min_points !== null && $rule->max_points !== null && $points !== null) {
            return max($rule->min_points, min($rule->max_points, $points));
        }

        return $rule->points;
    }

    /** Lots (awards and refunds) with their remaining points computed in the same query. */
    private function lotsQuery(?string $userUuid)
    {
        return LedgerEntry::query()
            ->whereIn('type', LedgerEntry::LOT_TYPES)
            ->when($userUuid !== null, fn ($q) => $q->where('user_uuid', $userUuid))
            ->select('ledger_entries.*')
            ->selectRaw('ledger_entries.points + COALESCE((select sum(d.points) from ledger_entries d where d.lot_id = ledger_entries.id), 0) as remaining');
    }
}
