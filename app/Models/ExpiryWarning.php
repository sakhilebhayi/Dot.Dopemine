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

    public function lot(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'lot_id');
    }
}
