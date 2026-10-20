<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Who changed what in the Dot Points back-office, when, and why. Append-only, like the ledger. */
class AdminAudit extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $audit) => $audit->created_at ??= now());
        static::updating(fn () => throw new LogicException('The admin audit trail is append-only: entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('The admin audit trail is append-only: entries cannot be deleted.'));
    }

    /** @param  array<string, mixed>  $details */
    public static function record(User $actor, string $action, string $subjectType, string $subjectKey, string $reason, array $details = []): self
    {
        return static::create([
            'actor_id' => $actor->id,
            'actor_label' => $actor->name.' <'.$actor->email.'>',
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_key' => $subjectKey,
            'reason' => $reason,
            'details' => $details ?: null,
        ]);
    }
}
