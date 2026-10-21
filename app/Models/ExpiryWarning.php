<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The one 30-day heads-up about a lot, and whether it reached Dot.Notify. Holds no address and no message text. */
class ExpiryWarning extends Model
{
    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    /** The points were spent or lapsed before the warning could go out, so there was nothing left to warn about. */
    public const MOOT = 'moot';

    /** Dot.Notify refused it in a way retrying cannot fix, or every attempt was used. */
    public const FAILED = 'failed';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['warned_at' => 'datetime', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime', 'attempts' => 'integer'];
    }

    /**
     * The wait before the next attempt once `$attempts` attempts have failed, or null when the attempts are used up
     * and the warning is to be given up on. One schedule for every delivery path (Notify push and InfoDot pull).
     */
    public static function backoffMinutesAfter(int $attempts): ?int
    {
        if ($attempts >= (int) config('points.expiry_warning.max_attempts', 6)) {
            return null;
        }

        $steps = array_values((array) config('points.expiry_warning.backoff_minutes', [60]));

        return (int) ($steps[min(max($attempts, 1), count($steps)) - 1] ?? end($steps));
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'lot_id');
    }
}
