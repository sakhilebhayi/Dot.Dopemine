<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * One line of the Dot Points ledger. Append-only: an entry is never edited or removed, a mistake is
 * corrected by a new entry (see App\Services\Points\PointsLedger).
 */
class LedgerEntry extends Model
{
    public const AWARD = 'award';

    public const REFUND = 'refund';

    public const REDEEM = 'redeem';

    public const EXPIRE = 'expire';

    public const REVERSE = 'reverse';

    /** Entry types that start a lot of spendable points. */
    public const LOT_TYPES = [self::AWARD, self::REFUND];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'created_at' => 'datetime', 'meta' => 'array', 'points' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->uuid ??= (string) Str::uuid();
            $entry->created_at ??= now();
        });

        static::updating(fn () => throw new LogicException('The points ledger is append-only: entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('The points ledger is append-only: entries cannot be deleted.'));
    }

    public function isLot(): bool
    {
        return in_array($this->type, self::LOT_TYPES, true);
    }
}
