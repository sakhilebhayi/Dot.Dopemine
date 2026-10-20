<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Contribution extends Model
{
    public const STATUSES = ['submitted', 'validated', 'published', 'accepted', 'verified', 'rewarded', 'flagged', 'reversed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['meta' => 'array', 'status_changed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->uuid ??= (string) Str::uuid());
    }

    public function transitionTo(string $status): void
    {
        abort_unless(in_array($status, self::STATUSES, true), 422, 'Unknown contribution status.');
        $this->forceFill(['status' => $status, 'status_changed_at' => now()])->save();
    }
}
