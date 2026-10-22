<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One weekly or monthly summary for one person and one closed period. Holds the figures only (no address, name or
 * contribution content). `payload` is fixed when the row is first produced, so every re-pull and every retry shows
 * the person the same numbers.
 */
class PointsDigest extends Model
{
    public const WEEK = 'week';

    public const MONTH = 'month';

    public const PERIODS = [self::WEEK, self::MONTH];

    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    /** InfoDot reported the person has not opted in. Permanently closed, never retried. */
    public const SKIPPED = 'skipped';

    /** Every attempt was used up. */
    public const FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $digest) => $digest->uuid ??= (string) Str::uuid());
    }

    /**
     * The wait before the next attempt once `$attempts` attempts have failed, or null when they are used up and the
     * digest is to be given up on. Same growing schedule as the expiry warnings, fewer attempts.
     */
    public static function backoffMinutesAfter(int $attempts): ?int
    {
        if ($attempts >= (int) config('points.digests.max_attempts', 5)) {
            return null;
        }

        $steps = array_values((array) config('points.digests.backoff_minutes', [60]));

        return (int) ($steps[min(max($attempts, 1), count($steps)) - 1] ?? end($steps));
    }
}
