<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Redemption extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }
}
