<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One version of a reward rule. A version is immutable: the only change a stored row ever accepts is the one-time
 * `effective_to` stamp it gets when its successor takes over, and a row can never be deleted. Everything else is a
 * new version (see revise()), so the ledger entry that points at "rule X, version N" can always be explained.
 */
class RewardRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'requires_approval' => 'boolean',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $rule): void {
            $changed = array_keys($rule->getDirty());
            $onlyClosing = array_diff($changed, ['effective_to', 'updated_at']) === []
                && in_array('effective_to', $changed, true)
                && $rule->getOriginal('effective_to') === null;

            if (! $onlyClosing) {
                throw new LogicException('A reward rule version is immutable: publish a new version instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('A reward rule version cannot be deleted.'));
    }

    /** The version of a rule in force right now, or null when it is switched off or unknown. */
    public static function current(string $key): ?self
    {
        $rule = static::where('key', $key)->where('effective_from', '<=', now())->orderByDesc('version')->first();

        return $rule && $rule->active ? $rule : null;
    }

    /** The newest version of a rule, switched on or off. */
    public static function latestVersion(string $key): ?self
    {
        return static::where('key', $key)->orderByDesc('version')->first();
    }

    /**
     * Change a rule: a rule row is immutable, so this writes the next version and stamps the one it replaces with
     * `effective_to`. Entries already in the ledger keep pointing at the version that produced them.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function revise(string $key, array $changes, ?string $changedBy = null, ?string $note = null): self
    {
        return DB::transaction(function () use ($key, $changes, $changedBy, $note): self {
            $latest = static::where('key', $key)->orderByDesc('version')->firstOrFail();
            $now = now();

            $next = static::create(array_merge(
                $latest->only(['key', 'platform', 'event_type', 'points', 'min_points', 'max_points', 'requires_approval', 'daily_cap_points', 'max_awards_per_contribution', 'active']),
                $changes,
                ['version' => $latest->version + 1, 'effective_from' => $now, 'changed_by' => $changedBy, 'note' => $note],
            ));

            if ($latest->effective_to === null) {
                $latest->forceFill(['effective_to' => $now])->save();
            }

            return $next;
        });
    }
}
