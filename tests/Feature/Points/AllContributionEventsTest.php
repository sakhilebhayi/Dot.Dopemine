<?php

namespace Tests\Feature\Points;

use App\Models\Contribution;
use App\Models\LedgerEntry;
use App\Models\RewardEvent;
use App\Models\RewardRule;
use App\Services\Points\ContributionEventProcessor;
use App\Services\Points\PointsLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every InfoDot event type the rules cover, and the approval flow (R-9): a moderator's approval is the approval.
 */
class AllContributionEventsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'infodot-ingest-secret-for-tests';

    private string $author;

    private string $moderator;

    protected function setUp(): void
    {
        parent::setUp();
        config(['points.ingest.secrets.infodot' => self::SECRET]);
        $this->author = (string) Str::uuid();
        $this->moderator = (string) Str::uuid();
    }

    /** @param array<string, mixed> $overrides */
    private function event(string $type, string $ref, array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'type' => $type,
            'occurred_at' => now()->toIso8601String(),
            'user_uuid' => $this->author,
            'contribution_ref' => $ref,
            'visibility' => 'public',
            'facts' => [],
        ], $overrides);
    }

    private function send(array $event)
    {
        $body = json_encode($event);
        $timestamp = now()->timestamp;

        return $this->call('POST', '/api/ingest/contributions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET),
        ], $body);
    }

    private function balance(?string $user = null): int
    {
        return app(PointsLedger::class)->balance($user ?? $this->author);
    }

    private function awards(?string $ref = null): int
    {
        return LedgerEntry::where('type', LedgerEntry::AWARD)->when($ref, fn ($q) => $q->where('contribution_ref', $ref))->count();
    }

    private function publishQuestion(string $ref = 'infodot:question:1', array $overrides = []): void
    {
        $this->send($this->event('infodot.question.published', $ref, array_merge(['facts' => ['approved' => false]], $overrides)))
            ->assertStatus(202)->assertJson(['status' => 'needs_approval']);
    }

    private function publishAnswer(string $ref = 'infodot:answer:1'): void
    {
        $this->send($this->event('infodot.answer.published', $ref, ['facts' => ['question_ref' => 'infodot:question:1', 'self_answer' => false]]))->assertStatus(202);
    }

    private function approve(string $ref, ?string $actor = null, array $overrides = [])
    {
        return $this->send($this->event('infodot.contribution.approved', $ref, array_merge(['actor_uuid' => $actor ?? $this->moderator], $overrides)));
    }

    // ----- the question reward and the approved event ---------------------------------------------------------

    public function test_a_question_published_unapproved_is_pending_and_pays_nothing_until_a_moderator_approves_it(): void
    {
        $this->publishQuestion();

        $this->assertSame(0, $this->balance());
        $this->assertSame('pending_approval', Contribution::first()->status);

        $approval = $this->event('infodot.contribution.approved', 'infodot:question:1', ['actor_uuid' => $this->moderator]);
        $this->send($approval)->assertStatus(202)->assertJson(['status' => 'awarded']);

        $this->assertSame(2, $this->balance());
        $this->assertSame('rewarded', Contribution::first()->status);
        $entry = LedgerEntry::where('type', LedgerEntry::AWARD)->firstOrFail();
        $this->assertSame('infodot.question.published', $entry->rule_key);
        $this->assertSame(1, $entry->rule_version);
        $this->assertSame($this->moderator, $entry->meta['approved_by']);
    }

    public function test_replaying_an_approval_or_approving_again_with_a_new_event_pays_once(): void
    {
        $this->publishQuestion();
        $approval = $this->event('infodot.contribution.approved', 'infodot:question:1', ['actor_uuid' => $this->moderator]);

        $this->send($approval)->assertJson(['status' => 'awarded']);
        $this->send($approval)->assertOk()->assertJson(['status' => 'duplicate']);
        $this->approve('infodot:question:1', (string) Str::uuid())->assertStatus(202)->assertJson(['status' => 'already_approved']);

        $this->assertSame(2, $this->balance());
        $this->assertSame(1, $this->awards());
    }

    public function test_an_edit_of_an_approved_question_changes_nothing(): void
    {
        $this->publishQuestion();
        $this->approve('infodot:question:1')->assertJson(['status' => 'awarded']);

        $this->send($this->event('infodot.question.published', 'infodot:question:1', ['facts' => ['approved' => false]]))
            ->assertStatus(202)->assertJson(['status' => 'already_rewarded']);

        $this->assertSame(2, $this->balance());
        $this->assertSame('rewarded', Contribution::first()->status);
    }

    public function test_an_approval_before_the_publication_is_retryable_and_succeeds_when_resent_afterwards(): void
    {
        $approval = $this->event('infodot.contribution.approved', 'infodot:question:1', ['actor_uuid' => $this->moderator, 'facts' => ['kind' => 'question']]);

        $this->send($approval)->assertStatus(409)->assertHeader('Retry-After')->assertJson(['code' => 'contribution_not_ready', 'status' => 'not_ready'])->assertJsonStructure(['message']);
        $this->assertSame(0, RewardEvent::count(), 'a retryable answer must not be remembered, or the retry would be treated as a replay');
        $this->assertSame(0, Contribution::count());
        $this->assertSame(0, LedgerEntry::count());

        $this->publishQuestion();
        $this->send($approval)->assertStatus(202)->assertJson(['status' => 'awarded']);

        $this->assertSame(2, $this->balance());
        $this->send($approval)->assertOk()->assertJson(['status' => 'duplicate']);
    }

    public function test_an_approval_for_a_contribution_that_is_not_yet_published_is_retryable(): void
    {
        Contribution::create(['platform' => 'infodot', 'ref' => 'infodot:question:1', 'user_uuid' => $this->author, 'kind' => 'question', 'status' => 'validated']);

        $this->approve('infodot:question:1')->assertStatus(409)->assertJson(['code' => 'contribution_not_ready']);

        $this->assertSame(0, $this->awards());
        $this->assertSame(0, RewardEvent::count());
    }

    public function test_an_author_cannot_approve_their_own_contribution_and_nothing_is_written(): void
    {
        $this->publishQuestion();
        $ledgerBefore = LedgerEntry::count();

        $this->approve('infodot:question:1', $this->author)->assertStatus(202)->assertJson(['status' => 'rejected']);
        $this->send($this->event('infodot.contribution.approved', 'infodot:question:1'))->assertStatus(202)->assertJson(['status' => 'rejected']);

        $this->assertSame($ledgerBefore, LedgerEntry::count());
        $this->assertSame(0, $this->balance());
        $this->assertSame('pending_approval', Contribution::first()->status);

        $this->approve('infodot:question:1')->assertJson(['status' => 'awarded']);
        $this->assertSame(2, $this->balance());
    }

    public function test_an_approval_naming_the_wrong_author_or_a_contribution_nobody_waits_on_pays_nobody(): void
    {
        $this->publishQuestion();
        $thief = (string) Str::uuid();

        $this->approve('infodot:question:1', null, ['user_uuid' => $thief])->assertJson(['status' => 'rejected']);
        $this->assertSame(0, $this->balance($thief));

        $this->publishAnswer();
        $this->approve('infodot:answer:1')->assertJson(['status' => 'ignored']);
        $this->assertSame(5, $this->balance(), 'only the answer reward, nothing for the pointless approval');
    }

    public function test_a_question_published_the_old_way_as_approved_still_pays_but_never_when_the_author_is_the_approver(): void
    {
        $this->send($this->event('infodot.question.published', 'infodot:question:9', ['actor_uuid' => $this->author, 'facts' => ['approved' => true]]))
            ->assertStatus(202)->assertJson(['status' => 'rejected']);
        $this->assertSame(0, $this->balance());

        $this->send($this->event('infodot.question.published', 'infodot:question:9', ['actor_uuid' => $this->moderator, 'facts' => ['approved' => true]]))
            ->assertStatus(202)->assertJson(['status' => 'awarded']);
        $this->assertSame(2, $this->balance());
    }

    public function test_a_question_recorded_before_approvals_were_tracked_can_still_be_approved(): void
    {
        Contribution::create(['platform' => 'infodot', 'ref' => 'infodot:question:7', 'user_uuid' => $this->author, 'kind' => 'question', 'status' => 'published']);

        $this->approve('infodot:question:7')->assertStatus(202)->assertJson(['status' => 'awarded']);

        $this->assertSame(2, $this->balance());
    }

    public function test_a_flagged_contribution_cannot_be_approved_into_a_payment(): void
    {
        $this->publishQuestion();
        $this->send($this->event('infodot.contribution.flagged', 'infodot:question:1'))->assertJson(['status' => 'flagged']);

        $this->approve('infodot:question:1')->assertStatus(202)->assertJson(['status' => 'no_points']);

        $this->assertSame(0, $this->balance());
    }

    public function test_company_only_knowledge_is_not_paid_on_approval_unless_the_policy_allows_it(): void
    {
        $this->send($this->event('infodot.question.published', 'infodot:question:1', ['visibility' => 'company', 'facts' => ['approved' => false]]))->assertJson(['status' => 'no_points']);
        $this->approve('infodot:question:1')->assertStatus(202)->assertJson(['status' => 'no_points']);
        $this->assertSame(0, $this->balance());
        $this->assertSame('approved', Contribution::first()->status, 'it leaves the waiting list: nothing will ever be paid for it');

        config(['points.reward_company_visibility' => true]);
        $this->send($this->event('infodot.question.published', 'infodot:question:2', ['visibility' => 'company', 'facts' => ['approved' => false]]))->assertJson(['status' => 'needs_approval']);
        $this->approve('infodot:question:2')->assertJson(['status' => 'awarded']);
        $this->assertSame(2, $this->balance());
    }

    public function test_the_daily_cap_holds_back_an_approval_which_then_stays_waiting(): void
    {
        foreach ([1, 2, 3, 4] as $n) {
            $this->publishQuestion("infodot:question:{$n}");
        }

        foreach ([1, 2, 3] as $n) {
            $this->approve("infodot:question:{$n}")->assertJson(['status' => 'awarded']);
        }
        $this->approve('infodot:question:4')->assertStatus(202)->assertJson(['status' => 'daily_cap']);

        $this->assertSame(6, $this->balance());
        $this->assertSame('pending_approval', Contribution::where('ref', 'infodot:question:4')->first()->status);
    }

    public function test_a_switched_off_rule_pays_nothing_and_keeps_the_contribution_waiting(): void
    {
        $this->publishQuestion();
        RewardRule::revise('infodot.question.published', ['active' => false], 'test', 'off');

        $this->approve('infodot:question:1')->assertJson(['status' => 'no_points']);

        $this->assertSame(0, $this->balance());
        $this->assertSame('pending_approval', Contribution::first()->status);
    }

    public function test_reversing_an_approved_award_takes_it_back_and_a_later_approval_does_not_pay_it_again(): void
    {
        $this->publishQuestion();
        $this->approve('infodot:question:1')->assertJson(['status' => 'awarded']);

        $this->send($this->event('infodot.contribution.reward_reversed', 'infodot:question:1', ['facts' => ['rule_key' => 'infodot.question.published', 'reason' => 'Spam']]))
            ->assertStatus(202)->assertJson(['status' => 'reversed']);
        $this->assertSame(0, $this->balance());

        $this->approve('infodot:question:1', (string) Str::uuid())->assertJson(['status' => 'already_approved']);

        $this->assertSame(0, $this->balance());
        $this->assertSame(1, $this->awards());
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntry::REVERSE)->count());
    }

    // ----- verified solutions ---------------------------------------------------------------------------------

    public function test_a_verified_solution_pays_the_author_once_and_a_replay_is_a_no_op(): void
    {
        $this->publishAnswer();
        $verified = $this->event('infodot.solution.verified', 'infodot:answer:1', ['actor_uuid' => $this->moderator]);

        $this->send($verified)->assertStatus(202)->assertJson(['status' => 'awarded']);
        $this->send($verified)->assertOk()->assertJson(['status' => 'duplicate']);
        $this->send($this->event('infodot.solution.verified', 'infodot:answer:1', ['actor_uuid' => (string) Str::uuid()]))->assertJson(['status' => 'already_rewarded']);

        $this->assertSame(15, $this->balance());
        $this->assertSame('verified', Contribution::first()->status);
        $this->assertSame($this->moderator, LedgerEntry::where('rule_key', 'infodot.solution.verified')->first()->meta['verified_by']);
    }

    public function test_a_solution_cannot_be_verified_by_its_author_or_without_a_verifier(): void
    {
        $this->publishAnswer();

        $this->send($this->event('infodot.solution.verified', 'infodot:answer:1', ['actor_uuid' => $this->author]))->assertStatus(202)->assertJson(['status' => 'rejected']);
        $this->send($this->event('infodot.solution.verified', 'infodot:answer:1'))->assertStatus(202)->assertJson(['status' => 'rejected']);

        $this->assertSame(5, $this->balance());
        $this->assertSame(1, $this->awards());
    }

    public function test_a_solution_that_was_never_announced_is_recorded_and_paid_by_its_verification(): void
    {
        $verified = $this->event('infodot.solution.verified', 'infodot:solution:5', ['actor_uuid' => $this->moderator, 'facts' => ['kind' => 'solution']]);

        $this->send($verified)->assertStatus(202)->assertJson(['status' => 'awarded']);
        $this->send($verified)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(10, $this->balance());
        $contribution = Contribution::where('ref', 'infodot:solution:5')->sole();
        $this->assertSame('solution', $contribution->kind);
        $this->assertSame('verified', $contribution->status);
    }

    public function test_unverifying_takes_back_only_the_verification_award(): void
    {
        $this->publishAnswer();
        $this->send($this->event('infodot.solution.verified', 'infodot:answer:1', ['actor_uuid' => $this->moderator]))->assertJson(['status' => 'awarded']);
        $this->assertSame(15, $this->balance());

        $this->send($this->event('infodot.contribution.reward_reversed', 'infodot:answer:1', ['facts' => ['rule_key' => 'infodot.solution.verified', 'reason' => 'Verification withdrawn']]))
            ->assertStatus(202)->assertJson(['status' => 'reversed']);

        $this->assertSame(5, $this->balance());
    }

    public function test_a_flag_without_an_actor_is_accepted(): void
    {
        $this->publishQuestion();

        $this->send($this->event('infodot.contribution.flagged', 'infodot:question:1', ['facts' => ['reason' => 'spam']]))->assertStatus(202)->assertJson(['status' => 'flagged']);
    }

    public function test_a_question_is_not_a_solution(): void
    {
        $this->publishQuestion('infodot:question:5');

        $this->send($this->event('infodot.solution.verified', 'infodot:question:5', ['actor_uuid' => $this->moderator]))->assertJson(['status' => 'ignored']);
    }

    public function test_a_self_answer_earns_nothing_when_verified_and_the_wrong_author_is_refused(): void
    {
        $this->send($this->event('infodot.answer.published', 'infodot:answer:2', ['facts' => ['self_answer' => true]]));
        $this->send($this->event('infodot.solution.verified', 'infodot:answer:2', ['actor_uuid' => $this->moderator]))->assertJson(['status' => 'no_points']);

        $this->publishAnswer();
        $thief = (string) Str::uuid();
        $this->send($this->event('infodot.solution.verified', 'infodot:answer:1', ['user_uuid' => $thief, 'actor_uuid' => $this->moderator]))->assertJson(['status' => 'rejected']);

        $this->assertSame(0, $this->balance($thief));
        $this->assertSame(0, $this->balance() - 5);
    }

    // ----- approval-gated typed events ------------------------------------------------------------------------

    /** @return array<string, array{string, string, int, int, int}> type, ref, default, min, max */
    public static function gatedTypes(): array
    {
        return [
            'tutorial' => ['infodot.tutorial.approved', 'infodot:tutorial:1', 10, 10, 20],
            'troubleshooting' => ['infodot.troubleshooting.approved', 'infodot:troubleshooting:1', 10, 10, 25],
            'correction' => ['infodot.knowledge.corrected', 'infodot:correction:1', 3, 3, 10],
            'adoption' => ['infodot.knowledge.adopted', 'infodot:adoption:1', 10, 10, 25],
        ];
    }

    #[DataProvider('gatedTypes')]
    public function test_an_approved_typed_event_pays_the_rule_default_once_and_records_the_contribution(string $type, string $ref, int $default, int $min, int $max): void
    {
        $event = $this->event($type, $ref, ['actor_uuid' => $this->moderator]);

        $this->send($event)->assertStatus(202)->assertJson(['status' => 'awarded']);
        $this->send($event)->assertOk()->assertJson(['status' => 'duplicate']);
        $this->send($this->event($type, $ref, ['actor_uuid' => $this->moderator]))->assertJson(['status' => 'already_rewarded']);

        $this->assertSame($default, $this->balance());
        $this->assertSame(1, $this->awards($ref));
        $contribution = Contribution::where('ref', $ref)->firstOrFail();
        $this->assertSame('rewarded', $contribution->status);
        $this->assertSame($this->author, $contribution->user_uuid);
    }

    #[DataProvider('gatedTypes')]
    public function test_the_requested_points_are_clamped_into_the_rule_range(string $type, string $ref, int $default, int $min, int $max): void
    {
        $this->send($this->event($type, $ref.'-high', ['actor_uuid' => $this->moderator, 'facts' => ['points' => 1000]]))->assertJson(['status' => 'awarded']);
        $this->assertSame($max, $this->balance());

        $this->send($this->event($type, $ref.'-low', ['actor_uuid' => $this->moderator, 'facts' => ['points' => 0]]))->assertJson(['status' => 'awarded']);
        $this->assertSame($max + $min, $this->balance());

        $mid = intdiv($min + $max, 2);
        $this->send($this->event($type, $ref.'-mid', ['actor_uuid' => $this->moderator, 'facts' => ['points' => $mid]]))->assertJson(['status' => 'awarded']);
        $this->assertSame($max + $min + $mid, $this->balance());
    }

    #[DataProvider('gatedTypes')]
    public function test_self_approval_and_a_missing_approver_are_refused_with_no_ledger_write(string $type, string $ref, int $default, int $min, int $max): void
    {
        $this->send($this->event($type, $ref, ['actor_uuid' => $this->author]))->assertStatus(202)->assertJson(['status' => 'rejected']);
        $this->send($this->event($type, $ref))->assertStatus(202)->assertJson(['status' => 'rejected']);
        $this->send($this->event($type, $ref, ['actor_uuid' => $this->author, 'facts' => ['approved' => true]]))->assertJson(['status' => 'rejected']);

        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame(0, $this->balance());
    }

    #[DataProvider('gatedTypes')]
    public function test_registered_unapproved_then_approved_by_a_moderator_pays_the_remembered_amount(string $type, string $ref, int $default, int $min, int $max): void
    {
        $this->send($this->event($type, $ref, ['facts' => ['approved' => false, 'points' => $max]]))->assertStatus(202)->assertJson(['status' => 'needs_approval']);

        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame('pending_approval', Contribution::where('ref', $ref)->first()->status);

        $this->approve($ref)->assertStatus(202)->assertJson(['status' => 'awarded']);
        $this->approve($ref)->assertJson(['status' => 'already_approved']);

        $this->assertSame($max, $this->balance());
    }

    public function test_the_typed_events_respect_the_company_visibility_policy(): void
    {
        $this->send($this->event('infodot.tutorial.approved', 'infodot:tutorial:1', ['visibility' => 'company', 'actor_uuid' => $this->moderator]))
            ->assertStatus(202)->assertJson(['status' => 'no_points']);
        $this->assertSame(0, LedgerEntry::count());

        config(['points.reward_company_visibility' => true]);
        $this->send($this->event('infodot.tutorial.approved', 'infodot:tutorial:2', ['visibility' => 'company', 'actor_uuid' => $this->moderator]))->assertJson(['status' => 'awarded']);
        $this->assertSame(10, $this->balance());
    }

    public function test_an_approved_typed_award_can_be_reversed_and_the_balance_never_goes_negative(): void
    {
        $this->send($this->event('infodot.tutorial.approved', 'infodot:tutorial:1', ['actor_uuid' => $this->moderator]))->assertJson(['status' => 'awarded']);
        app(PointsLedger::class)->redeem($this->author, 4, 'spend');

        $this->send($this->event('infodot.contribution.reward_reversed', 'infodot:tutorial:1', ['facts' => ['rule_key' => 'infodot.tutorial.approved']]))->assertJson(['status' => 'reversed']);

        $this->assertSame(0, $this->balance());
        $this->assertStringContainsString('already spent', (string) RewardEvent::latest('id')->first()->detail);
    }

    public function test_an_event_naming_a_rule_that_belongs_to_another_event_type_is_refused(): void
    {
        $this->send($this->event('infodot.tutorial.approved', 'infodot:tutorial:1', ['actor_uuid' => $this->moderator, 'facts' => ['rule_key' => 'infodot.answer.accepted']]))
            ->assertStatus(202)->assertJson(['status' => 'rejected']);
        $this->send($this->event('infodot.tutorial.approved', 'infodot:tutorial:1', ['actor_uuid' => $this->moderator, 'facts' => ['rule_key' => ['x']]]))
            ->assertStatus(202)->assertJson(['status' => 'rejected']);

        $this->assertSame(0, LedgerEntry::count());
    }

    // ----- helpful contributions ------------------------------------------------------------------------------

    public function test_a_helpful_threshold_pays_the_author_once_stores_only_the_count_and_clamps_the_points(): void
    {
        $this->publishAnswer();
        $helpful = $this->event('infodot.contribution.helpful', 'infodot:answer:1', ['facts' => ['helpful_count' => 5, 'points' => 9, 'voter_uuids' => [(string) Str::uuid()]]]);

        $this->send($helpful)->assertStatus(202)->assertJson(['status' => 'awarded']);
        $this->send($helpful)->assertOk()->assertJson(['status' => 'duplicate']);
        $this->send($this->event('infodot.contribution.helpful', 'infodot:answer:1', ['facts' => ['helpful_count' => 10]]))->assertJson(['status' => 'already_rewarded']);

        $this->assertSame(5 + 3, $this->balance());
        $entry = LedgerEntry::where('rule_key', 'infodot.contribution.helpful')->firstOrFail();
        $this->assertSame(['event' => 'infodot.contribution.helpful', 'helpful_count' => 5], $entry->meta);
    }

    public function test_helpful_for_a_contribution_not_known_yet_is_retryable(): void
    {
        $helpful = $this->event('infodot.contribution.helpful', 'infodot:answer:1', ['facts' => ['helpful_count' => 3]]);

        $this->send($helpful)->assertStatus(409)->assertJson(['code' => 'contribution_not_ready']);
        $this->assertSame(0, RewardEvent::count());

        $this->publishAnswer();
        $this->send($helpful)->assertStatus(202)->assertJson(['status' => 'awarded']);
    }

    public function test_the_helpful_daily_cap_applies(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->publishAnswer("infodot:answer:{$n}");
        }

        $this->send($this->event('infodot.contribution.helpful', 'infodot:answer:1', ['facts' => ['points' => 3]]))->assertJson(['status' => 'awarded']);
        $this->send($this->event('infodot.contribution.helpful', 'infodot:answer:2', ['facts' => ['points' => 3]]))->assertJson(['status' => 'awarded']);
        $this->send($this->event('infodot.contribution.helpful', 'infodot:answer:3', ['facts' => ['points' => 1]]))->assertJson(['status' => 'daily_cap']);

        $this->assertSame(15 + 6, $this->balance());
    }

    public function test_the_multi_user_bonus_is_a_helpful_event_naming_its_rule_and_needs_a_moderator(): void
    {
        $this->publishAnswer();
        $bonus = fn (array $overrides) => $this->event('infodot.contribution.helpful', 'infodot:answer:1', array_merge(['facts' => ['rule_key' => 'infodot.solution.multi_user_bonus', 'points' => 50]], $overrides));

        $this->send($bonus([]))->assertJson(['status' => 'rejected']);
        $this->send($bonus(['actor_uuid' => $this->author]))->assertJson(['status' => 'rejected']);
        $this->assertSame(5, $this->balance());

        $this->send($bonus(['actor_uuid' => $this->moderator]))->assertJson(['status' => 'awarded']);
        $this->send($bonus(['actor_uuid' => $this->moderator]))->assertJson(['status' => 'already_rewarded']);

        $this->assertSame(5 + 20, $this->balance());
    }

    public function test_helpful_is_not_paid_on_a_flagged_contribution(): void
    {
        $this->publishAnswer();
        $this->send($this->event('infodot.contribution.flagged', 'infodot:answer:1'));

        $this->send($this->event('infodot.contribution.helpful', 'infodot:answer:1', ['facts' => ['helpful_count' => 3]]))->assertJson(['status' => 'no_points']);

        $this->assertSame(5, $this->balance());
    }

    public function test_every_type_the_rules_cover_is_handled(): void
    {
        $this->assertSame([], array_values(array_diff(
            ['infodot.question.published', 'infodot.answer.published', 'infodot.answer.accepted', 'infodot.solution.verified', 'infodot.tutorial.approved', 'infodot.troubleshooting.approved', 'infodot.contribution.helpful', 'infodot.knowledge.corrected', 'infodot.knowledge.adopted', 'infodot.contribution.flagged', 'infodot.contribution.reward_reversed', 'infodot.contribution.approved'],
            ContributionEventProcessor::SUPPORTED,
        )));
    }
}
