<?php

namespace App\Services\Points;

use App\Models\Contribution;
use App\Models\LedgerEntry;
use App\Models\RewardRule;
use Illuminate\Support\Facades\DB;

/**
 * Turns a validated platform event into contribution state and, where the rules say so, points.
 *
 * Nothing here trusts the sender beyond what the signed envelope proves: awards are decided by the rule engine
 * (caps, approval, one per contribution), the author of a contribution is whoever the FIRST event for it named
 * (a later event cannot reassign it), and the person who accepts, verifies or approves must not be its author.
 *
 * Approval (owner decision): a moderator's approval IS the approval. A rule that `requires_approval` pays only when
 * somebody other than the author approved it, either through the typed event itself (a tutorial, troubleshooting
 * answer, correction or adoption arrives already approved, naming the approver in `actor_uuid`) or, for a
 * contribution registered earlier with `facts.approved = false`, through `infodot.contribution.approved`. A
 * Dopemine administrator approves through the same code path (approvePending). Dot.Brain takes no part.
 *
 * `not_ready` is the one outcome that records nothing: an event that refers to a contribution this service has not
 * been told about yet. The intake answers it with a retryable HTTP status and does not remember the event id, so
 * the sender's next attempt is processed afresh.
 */
class ContributionEventProcessor
{
    public const NOT_READY = 'not_ready';

    public const SUPPORTED = [
        'infodot.question.published',
        'infodot.answer.published',
        'infodot.answer.accepted',
        'infodot.contribution.reward_reversed',
        'infodot.contribution.flagged',
        'infodot.contribution.approved',
        'infodot.solution.verified',
        'infodot.tutorial.approved',
        'infodot.troubleshooting.approved',
        'infodot.knowledge.corrected',
        'infodot.knowledge.adopted',
        'infodot.contribution.helpful',
    ];

    /**
     * Events that pay under a rule named after (or, for helpful, by) their type.
     *  - creates:  the event may be the first time the contribution is heard of (it then records it as `kind`);
     *              otherwise the contribution must already be known, or the event is `not_ready`.
     *  - actor:    `actor_uuid` (the verifier or approver) is always required and must differ from the author.
     *              It is also required whenever the rule requires approval, whatever this says.
     *  - status:   the lifecycle status a successful award moves the contribution to (null: leave it alone).
     */
    private const TYPED = [
        'infodot.solution.verified' => ['kind' => 'solution', 'creates' => true, 'actor' => true, 'status' => 'verified'],
        'infodot.contribution.helpful' => ['kind' => 'helpful', 'creates' => false, 'actor' => false, 'status' => null],
        'infodot.tutorial.approved' => ['kind' => 'tutorial', 'creates' => true, 'actor' => true, 'status' => 'rewarded'],
        'infodot.troubleshooting.approved' => ['kind' => 'troubleshooting', 'creates' => true, 'actor' => true, 'status' => 'rewarded'],
        'infodot.knowledge.corrected' => ['kind' => 'correction', 'creates' => true, 'actor' => true, 'status' => 'rewarded'],
        'infodot.knowledge.adopted' => ['kind' => 'adoption', 'creates' => true, 'actor' => true, 'status' => 'rewarded'],
    ];

    public function __construct(private readonly PointsLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $event  the validated envelope
     * @return array{outcome: string, detail: ?string}
     */
    public function handle(string $platform, array $event): array
    {
        return DB::transaction(function () use ($platform, $event): array {
            $type = $event['type'];

            return match ($type) {
                'infodot.question.published' => $this->published($platform, $event, 'question', 'infodot.question.published'),
                'infodot.answer.published' => $this->published($platform, $event, 'answer', 'infodot.answer.published'),
                'infodot.answer.accepted' => $this->accepted($platform, $event),
                'infodot.contribution.reward_reversed' => $this->reversed($platform, $event),
                'infodot.contribution.flagged' => $this->flagged($platform, $event),
                'infodot.contribution.approved' => $this->approved($platform, $event),
                default => isset(self::TYPED[$type])
                    ? $this->typed($platform, $event, self::TYPED[$type])
                    : ['outcome' => 'unsupported', 'detail' => "No handler for {$type} yet."],
            };
        });
    }

    /**
     * Approve a contribution that is waiting for it and pay the approval-gated rule exactly once. This is the single
     * code path for a moderator's `infodot.contribution.approved` event and for an administrator in the back-office.
     *
     * @param  string  $actor  who approved: an InfoDot user uuid, or `admin:<id>` for a back-office administrator
     * @param  string  $idempotencyKey  the ledger key: the event id, or a stable key per contribution for the back-office
     * @param  string  $via  `event` or `admin`, kept on the contribution
     * @return array{outcome: string, detail: ?string, points?: int}
     */
    public function approvePending(Contribution $contribution, ?string $actor, string $idempotencyKey, string $via = 'event'): array
    {
        return DB::transaction(function () use ($contribution, $actor, $idempotencyKey, $via): array {
            // Re-read under a lock so two approvals of the same contribution (two moderators, a moderator and an
            // administrator) are serialised and the second sees the first.
            $contribution = Contribution::whereKey($contribution->getKey())->lockForUpdate()->firstOrFail();

            $ruleKey = $contribution->pendingRuleKey();

            if ($ruleKey === null) {
                return ['outcome' => 'ignored', 'detail' => 'Nothing is waiting for approval on this contribution.'];
            }

            if ($this->alreadyApproved($contribution, $ruleKey)) {
                if ($contribution->status !== 'rewarded') {
                    $contribution->transitionTo('rewarded');
                }

                return ['outcome' => 'already_approved', 'detail' => null];
            }

            if (($blocked = $this->blockedState($contribution)) !== null) {
                return $blocked;
            }

            if ($actor === null || $actor === '' || $actor === $contribution->user_uuid) {
                return ['outcome' => 'rejected', 'detail' => 'A contribution cannot be approved by its own author.'];
            }

            if ($contribution->visibility !== 'public' && ! config('points.reward_company_visibility')) {
                // Nothing will ever be paid for it, so it leaves the waiting list rather than sit there for ever.
                $this->recordApproval($contribution, $actor, $via, 'approved');

                return ['outcome' => 'no_points', 'detail' => 'Company-only knowledge is not rewarded.'];
            }

            $rule = RewardRule::current($ruleKey);
            if ($rule === null) {
                return ['outcome' => 'no_points', 'detail' => 'The rule is switched off. The contribution stays waiting for approval.'];
            }

            $requested = isset($contribution->meta['pending_points']) ? (int) $contribution->meta['pending_points'] : null;
            $result = $this->ledger->award($contribution->user_uuid, $rule, $contribution->ref, $idempotencyKey, $requested, ['approved_by' => $actor, 'via' => $via], approved: true);

            if ($result->awarded()) {
                $this->recordApproval($contribution, $actor, $via, 'rewarded');

                return ['outcome' => $result->status, 'detail' => null, 'points' => $result->entry->points];
            }

            if ($result->status === AwardOutcome::ALREADY_REWARDED) {
                $this->recordApproval($contribution, $actor, $via, 'rewarded');

                return ['outcome' => 'already_approved', 'detail' => null];
            }

            // duplicate (same key seen), daily_cap, rule_off: nothing was paid and the contribution stays waiting.
            return ['outcome' => $result->status, 'detail' => match ($result->status) {
                AwardOutcome::DAILY_CAP => 'The daily cap for this rule is reached. It stays waiting for approval.',
                AwardOutcome::PAIR_LIMITED => 'These two accounts have rewarded each other repeatedly in the last 30 days; this award would pay nothing. It stays waiting for approval.',
                default => null,
            }];
        });
    }

    /** `infodot.contribution.approved`: a moderator (not the author) approves a contribution that is waiting. */
    private function approved(string $platform, array $event): array
    {
        $contribution = Contribution::where('platform', $platform)->where('ref', $event['contribution_ref'])->first();

        if ($contribution === null || $this->notYetPublished($contribution)) {
            return $this->notReady();
        }

        if ($contribution->user_uuid !== $event['user_uuid']) {
            return ['outcome' => 'rejected', 'detail' => 'The author named does not match the contribution.'];
        }

        return $this->approvePending($contribution, $event['actor_uuid'] ?? null, $event['event_id'], 'event');
    }

    /** Events that pay under a rule of the same name (see TYPED). */
    private function typed(string $platform, array $event, array $spec): array
    {
        $type = $event['type'];
        $ref = $event['contribution_ref'];
        $facts = $event['facts'] ?? [];
        $actor = $event['actor_uuid'] ?? null;

        $ruleKey = $this->ruleKeyFor($type, $facts);
        if ($ruleKey === null) {
            return ['outcome' => 'rejected', 'detail' => 'The rule named is not one that this event type can pay.'];
        }

        $contribution = Contribution::where('platform', $platform)->where('ref', $ref)->lockForUpdate()->first();

        if ($contribution === null) {
            if (! $spec['creates']) {
                return $this->notReady();
            }

            $contribution = Contribution::create([
                'platform' => $platform,
                'ref' => $ref,
                'user_uuid' => $event['user_uuid'],
                'kind' => $spec['kind'],
                'status' => 'published',
                'tenant_uuid' => $event['tenant_uuid'] ?? null,
                'visibility' => $event['visibility'] ?? 'public',
                'meta' => ['self_answer' => false],
                'status_changed_at' => now(),
            ]);
        } elseif ($this->notYetPublished($contribution)) {
            return $this->notReady();
        }

        if ($contribution->user_uuid !== $event['user_uuid']) {
            return ['outcome' => 'rejected', 'detail' => 'The author named does not match the contribution.'];
        }

        if (($blocked = $this->blockedState($contribution)) !== null) {
            return $blocked;
        }

        if ($type === 'infodot.solution.verified' && $contribution->kind === 'question') {
            return ['outcome' => 'ignored', 'detail' => 'A question is not a solution.'];
        }

        if ($contribution->visibility !== 'public' && ! config('points.reward_company_visibility')) {
            return ['outcome' => 'no_points', 'detail' => 'Company-only knowledge is not rewarded.'];
        }

        if ($contribution->meta['self_answer'] ?? false) {
            return ['outcome' => 'no_points', 'detail' => 'Self-answer.'];
        }

        $rule = RewardRule::current($ruleKey);
        if ($rule === null) {
            return ['outcome' => 'no_points', 'detail' => 'The rule is switched off.'];
        }

        $requested = is_numeric($facts['points'] ?? null) ? (int) $facts['points'] : null;

        // Registered but not approved yet: remember it, pay nothing, wait for `infodot.contribution.approved`.
        if ($rule->requires_approval && ($facts['approved'] ?? null) === false) {
            $this->markPending($contribution, $ruleKey, $requested);

            return ['outcome' => AwardOutcome::NEEDS_APPROVAL, 'detail' => null];
        }

        // The typed event is the approval, so someone other than the author must have given it.
        if (($spec['actor'] || $rule->requires_approval) && ($actor === null || $actor === $contribution->user_uuid)) {
            return ['outcome' => 'rejected', 'detail' => 'It must be verified or approved by someone other than its author.'];
        }

        $meta = array_filter([
            'event' => $type,
            'approved_by' => $rule->requires_approval ? $actor : null,
            'verified_by' => $type === 'infodot.solution.verified' ? $actor : null,
            // Only the count: who voted never reaches this service.
            'helpful_count' => is_numeric($facts['helpful_count'] ?? null) ? (int) $facts['helpful_count'] : null,
        ], fn ($value) => $value !== null);

        $result = $this->ledger->award($contribution->user_uuid, $rule, $ref, $event['event_id'], $requested, $meta, approved: true);

        if ($result->awarded()) {
            if ($rule->requires_approval) {
                $this->recordApproval($contribution, (string) $actor, 'event', $spec['status'] ?? 'rewarded');
            } elseif ($spec['status'] !== null) {
                $contribution->transitionTo($spec['status']);
            }
        }

        return ['outcome' => $result->status, 'detail' => null];
    }

    /** The rule an event pays: `facts.rule_key` when it names a rule of this event type (helpful: the multi-user bonus), else the type. */
    private function ruleKeyFor(string $type, array $facts): ?string
    {
        $key = $facts['rule_key'] ?? $type;

        if (! is_string($key)) {
            return null;
        }

        return RewardRule::latestVersion($key)?->event_type === $type ? $key : null;
    }

    private function notReady(): array
    {
        return ['outcome' => self::NOT_READY, 'detail' => 'The contribution is not known here yet. Send this event again later.'];
    }

    /** Known but not yet published: an approval or verification now would come before the thing it judges. */
    private function notYetPublished(Contribution $contribution): bool
    {
        return in_array($contribution->status, ['submitted', 'validated'], true);
    }

    /** A flagged contribution is held for a moderator and a reversed one was invalidated: neither earns anything new. */
    private function blockedState(Contribution $contribution): ?array
    {
        return match ($contribution->status) {
            'flagged' => ['outcome' => 'no_points', 'detail' => 'The contribution is flagged and held for review.'],
            'reversed' => ['outcome' => 'no_points', 'detail' => 'The contribution was reversed.'],
            default => null,
        };
    }

    private function alreadyApproved(Contribution $contribution, string $ruleKey): bool
    {
        if (isset($contribution->meta['approved_at'])) {
            return true;
        }

        return LedgerEntry::where('type', LedgerEntry::AWARD)->where('rule_key', $ruleKey)->where('contribution_ref', $contribution->ref)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('ledger_entries as r')->whereColumn('r.lot_id', 'ledger_entries.id')->where('r.type', LedgerEntry::REVERSE))
            ->exists();
    }

    private function markPending(Contribution $contribution, string $ruleKey, ?int $requested): void
    {
        if (! in_array($contribution->status, ['published', 'pending_approval'], true) || isset($contribution->meta['approved_at'])) {
            return;
        }

        $meta = $contribution->meta ?? [];
        $meta['pending_rule_key'] ??= $ruleKey;
        if ($requested !== null) {
            $meta['pending_points'] ??= $requested;
        }

        $contribution->forceFill(['meta' => $meta])->save();

        if ($contribution->status !== 'pending_approval') {
            $contribution->transitionTo('pending_approval');
        }
    }

    private function recordApproval(Contribution $contribution, string $actor, string $via, string $status): void
    {
        $meta = $contribution->meta ?? [];
        $meta['approved_by'] = $actor;
        $meta['approved_at'] = now()->toIso8601String();
        $meta['approved_via'] = $via;

        $contribution->forceFill(['meta' => $meta])->save();
        $contribution->transitionTo($status);
    }

    private function published(string $platform, array $event, string $kind, string $ruleKey): array
    {
        $ref = $event['contribution_ref'];
        $facts = $event['facts'] ?? [];

        $contribution = Contribution::firstOrCreate(['platform' => $platform, 'ref' => $ref], [
            'user_uuid' => $event['user_uuid'],
            'kind' => $kind,
            'status' => 'published',
            'tenant_uuid' => $event['tenant_uuid'] ?? null,
            'visibility' => $event['visibility'] ?? 'public',
            'parent_ref' => $facts['question_ref'] ?? null,
            'meta' => ['self_answer' => (bool) ($facts['self_answer'] ?? false)],
            'status_changed_at' => now(),
        ]);

        if ($contribution->user_uuid !== $event['user_uuid']) {
            return ['outcome' => 'rejected', 'detail' => 'The contribution already belongs to someone else.'];
        }

        if ($contribution->visibility !== 'public' && ! config('points.reward_company_visibility')) {
            return ['outcome' => 'no_points', 'detail' => 'Company-only knowledge is not rewarded.'];
        }

        // Answering your own question earns nothing: it is a note to self, not help given.
        if ($kind === 'answer' && ($contribution->meta['self_answer'] ?? false)) {
            return ['outcome' => 'no_points', 'detail' => 'Self-answer.'];
        }

        $rule = RewardRule::current($ruleKey);
        if ($rule === null) {
            return ['outcome' => 'no_points', 'detail' => 'The rule is switched off.'];
        }

        $approved = (bool) ($facts['approved'] ?? false);
        $actor = $event['actor_uuid'] ?? null;

        if ($rule->requires_approval) {
            // An edit of something a moderator already approved changes nothing.
            if ($this->alreadyApproved($contribution, $ruleKey)) {
                return ['outcome' => AwardOutcome::ALREADY_REWARDED, 'detail' => null];
            }

            // `approved = true` with an approver named: the approver must not be the author.
            if ($approved && $actor !== null && $actor === $contribution->user_uuid) {
                return ['outcome' => 'rejected', 'detail' => 'A contribution cannot be approved by its own author.'];
            }
        }

        $result = $this->ledger->award($contribution->user_uuid, $rule, $ref, $event['event_id'], null, ['event' => $event['type']], approved: $approved);

        if ($result->awarded()) {
            $contribution->transitionTo('rewarded');
        } elseif ($result->status === AwardOutcome::NEEDS_APPROVAL) {
            // Published, but a moderator has not approved it: wait for `infodot.contribution.approved`.
            $this->markPending($contribution, $ruleKey, null);
        }

        return ['outcome' => $result->status, 'detail' => null];
    }

    private function accepted(string $platform, array $event): array
    {
        $contribution = Contribution::where('platform', $platform)->where('ref', $event['contribution_ref'])->first();

        if ($contribution === null || $contribution->kind !== 'answer') {
            return ['outcome' => 'ignored', 'detail' => 'The answer is not known here.'];
        }

        // `user_uuid` is the answer's author; `actor_uuid` is who accepted it. They must be different people.
        if ($contribution->user_uuid !== $event['user_uuid']) {
            return ['outcome' => 'rejected', 'detail' => 'The author named does not match the answer.'];
        }

        $actor = $event['actor_uuid'] ?? null;
        if ($actor === null || $actor === $contribution->user_uuid) {
            return ['outcome' => 'rejected', 'detail' => 'An answer cannot be accepted by its own author.'];
        }

        if ($contribution->meta['self_answer'] ?? false) {
            return ['outcome' => 'no_points', 'detail' => 'Self-answer.'];
        }

        if ($contribution->visibility !== 'public' && ! config('points.reward_company_visibility')) {
            return ['outcome' => 'no_points', 'detail' => 'Company-only knowledge is not rewarded.'];
        }

        $contribution->transitionTo('accepted');

        $rule = RewardRule::current('infodot.answer.accepted');
        if ($rule === null) {
            return ['outcome' => 'no_points', 'detail' => 'The rule is switched off.'];
        }

        $result = $this->ledger->award($contribution->user_uuid, $rule, $contribution->ref, $event['event_id'], null, ['accepted_by' => $actor], approved: true);

        return ['outcome' => $result->status, 'detail' => $result->status === AwardOutcome::PAIR_LIMITED ? 'These two accounts have rewarded each other repeatedly in the last 30 days; this acceptance pays nothing.' : null];
    }

    /** Takes back the unspent part of the points a contribution earned under one rule (or all rules). */
    private function reversed(string $platform, array $event): array
    {
        $ref = $event['contribution_ref'];
        $ruleKey = $event['facts']['rule_key'] ?? null;

        $awards = LedgerEntry::where('type', LedgerEntry::AWARD)->where('contribution_ref', $ref)
            ->when($ruleKey, fn ($q) => $q->where('rule_key', $ruleKey))->get();

        if ($awards->isEmpty()) {
            return ['outcome' => 'ignored', 'detail' => 'Nothing was awarded for it.'];
        }

        $unrecovered = 0;
        foreach ($awards as $award) {
            $unrecovered += $this->ledger->reverse($award, (string) ($event['facts']['reason'] ?? 'Reversed by the platform'), 'reverse:'.$event['event_id'].':'.$award->id)['unrecovered'];
        }

        if (! $ruleKey) {
            Contribution::where('platform', $platform)->where('ref', $ref)->first()?->transitionTo('reversed');
        }

        return ['outcome' => 'reversed', 'detail' => $unrecovered > 0 ? "{$unrecovered} points were already spent and could not be taken back." : null];
    }

    private function flagged(string $platform, array $event): array
    {
        $contribution = Contribution::where('platform', $platform)->where('ref', $event['contribution_ref'])->first();

        if ($contribution === null) {
            return ['outcome' => 'ignored', 'detail' => 'Not known here.'];
        }

        // Flagging holds the contribution for review; it does not take points back. A moderator decides.
        $contribution->transitionTo('flagged');

        return ['outcome' => 'flagged', 'detail' => null];
    }
}
