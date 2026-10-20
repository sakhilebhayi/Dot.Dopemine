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
 * (a later event cannot reassign it), and the person who accepts or verifies must not be its author.
 */
class ContributionEventProcessor
{
    public const SUPPORTED = [
        'infodot.question.published',
        'infodot.answer.published',
        'infodot.answer.accepted',
        'infodot.contribution.reward_reversed',
        'infodot.contribution.flagged',
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
            $ref = $event['contribution_ref'];

            return match ($type) {
                'infodot.question.published' => $this->published($platform, $event, 'question', 'infodot.question.published'),
                'infodot.answer.published' => $this->published($platform, $event, 'answer', 'infodot.answer.published'),
                'infodot.answer.accepted' => $this->accepted($platform, $event),
                'infodot.contribution.reward_reversed' => $this->reversed($platform, $event),
                'infodot.contribution.flagged' => $this->flagged($platform, $event),
                default => ['outcome' => 'unsupported', 'detail' => "No handler for {$type} yet."],
            };
        });
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

        $result = $this->ledger->award($contribution->user_uuid, $rule, $ref, $event['event_id'], null, ['event' => $event['type']], approved: (bool) ($facts['approved'] ?? false));

        if ($result->awarded()) {
            $contribution->transitionTo('rewarded');
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

        return ['outcome' => $result->status, 'detail' => null];
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
