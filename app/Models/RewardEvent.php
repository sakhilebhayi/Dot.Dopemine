<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An event received from a platform: the audit trail and the replay guard (event_id is unique). */
class RewardEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $e) => $e->received_at ??= now());
    }
}
