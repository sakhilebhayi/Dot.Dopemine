<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RewardRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'requires_approval' => 'boolean',
            'effective_from' => 'datetime',
        ];
    }

    /** The version of a rule in force right now, or null when it is switched off or unknown. */
    public static function current(string $key): ?self
    {
        $rule = static::where('key', $key)->where('effective_from', '<=', now())->orderByDesc('version')->first();

        return $rule && $rule->active ? $rule : null;
    }

    /**
     * Change a rule: a rule row is immutable, so this writes the next version. Entries already in the ledger keep
     * pointing at the version that produced them.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function revise(string $key, array $changes, ?string $changedBy = null, ?string $note = null): self
    {
        $latest = static::where('key', $key)->orderByDesc('version')->firstOrFail();

        return static::create(array_merge(
            $latest->only(['key', 'platform', 'event_type', 'points', 'min_points', 'max_points', 'requires_approval', 'daily_cap_points', 'max_awards_per_contribution', 'active']),
            $changes,
            ['version' => $latest->version + 1, 'effective_from' => now(), 'changed_by' => $changedBy, 'note' => $note],
        ));
    }
}
