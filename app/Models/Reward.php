<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of the price list. Only things that can be redeemed are listed: recognition (badges) is never for sale
 * with points, so it is not a kind here.
 */
class Reward extends Model
{
    /** Stored value => what the back-office calls it. */
    public const KIND_LABELS = [
        'billing_credit' => 'Billing credit',
        'capacity' => 'Premium capacity',
        'marketplace' => 'Marketplace item',
    ];

    public const KINDS = ['billing_credit', 'capacity', 'marketplace'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'params' => 'array', 'stock' => 'integer'];
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }
}
