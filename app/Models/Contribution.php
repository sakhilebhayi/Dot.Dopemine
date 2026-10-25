<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Contribution extends Model
{
    public const STATUSES = ['submitted', 'validated', 'published', 'pending_approval', 'approved', 'accepted', 'verified', 'rewarded', 'flagged', 'reversed'];

    /**
     * The approval-gated rule each kind of contribution waits on when nothing more specific was recorded. A question
     * published before approvals were tracked has no `pending_rule_key` and still resolves through this map.
     */
    public const APPROVAL_RULES = [
        'question' => 'infodot.question.published',
        'tutorial' => 'infodot.tutorial.approved',
        'troubleshooting' => 'infodot.troubleshooting.approved',
        'correction' => 'infodot.knowledge.corrected',
        'adoption' => 'infodot.knowledge.adopted',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['meta' => 'array', 'status_changed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $c) => $c->uuid ??= (string) Str::uuid());
    }

    /**
     * Contributions a moderator has not approved yet: those recorded as pending, plus questions published before
     * approvals were tracked that were never paid.
     */
    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->where('status', 'pending_approval')
                ->orWhere(fn (Builder $legacy) => $legacy->where('status', 'published')->where('kind', 'question')
                    ->whereNotExists(fn ($award) => $award->select(DB::raw(1))->from('ledger_entries')
                        ->whereColumn('ledger_entries.contribution_ref', 'contributions.ref')
                        ->where('ledger_entries.type', LedgerEntry::AWARD)
                        ->where('ledger_entries.rule_key', self::APPROVAL_RULES['question'])));
        });
    }

    /** The approval-gated rule this contribution is waiting on, or null when it is not an approval-gated kind. */
    public function pendingRuleKey(): ?string
    {
        return $this->meta['pending_rule_key'] ?? self::APPROVAL_RULES[$this->kind] ?? null;
    }

    public function transitionTo(string $status): void
    {
        abort_unless(in_array($status, self::STATUSES, true), 422, 'Unknown contribution status.');
        $this->forceFill(['status' => $status, 'status_changed_at' => now()])->save();
    }
}
