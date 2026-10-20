<?php

namespace Tests\Feature\Points;

use App\Models\Contribution;
use App\Models\LedgerEntry;
use App\Models\RewardEvent;
use App\Services\Points\PointsLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContributionIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'infodot-ingest-secret-for-tests';

    private string $author;

    private string $asker;

    protected function setUp(): void
    {
        parent::setUp();
        config(['points.ingest.secrets.infodot' => self::SECRET]);
        $this->author = (string) Str::uuid();
        $this->asker = (string) Str::uuid();
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

    private function send(array $event, ?string $secret = self::SECRET, ?int $timestamp = null)
    {
        $body = json_encode($event);
        $timestamp ??= now()->timestamp;
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? 'x');

        return $this->call('POST', '/api/ingest/contributions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => $signature,
        ], $body);
    }

    private function balance(?string $user = null): int
    {
        return app(PointsLedger::class)->balance($user ?? $this->author);
    }

    private function publishAnswer(string $ref = 'infodot:answer:1', array $facts = []): void
    {
        $this->send($this->event('infodot.answer.published', $ref, ['facts' => array_merge(['question_ref' => 'infodot:question:1', 'self_answer' => false], $facts)]))->assertStatus(202);
    }

    public function test_an_unsigned_or_wrongly_signed_or_stale_request_is_refused_before_anything_is_read(): void
    {
        $event = $this->event('infodot.answer.published', 'infodot:answer:1');

        $this->postJson('/api/ingest/contributions', $event)->assertUnauthorized();
        $this->send($event, 'wrong-secret')->assertUnauthorized();
        $this->send($event, self::SECRET, now()->subMinutes(10)->timestamp)->assertUnauthorized();

        config(['points.ingest.secrets.infodot' => '']);
        $this->send($event)->assertUnauthorized();

        $this->assertSame(0, RewardEvent::count());
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_a_signed_answer_event_awards_the_author_once_and_a_replay_changes_nothing(): void
    {
        $event = $this->event('infodot.answer.published', 'infodot:answer:1', ['facts' => ['question_ref' => 'infodot:question:1', 'self_answer' => false]]);

        $this->send($event)->assertStatus(202)->assertJson(['status' => 'awarded']);
        $this->send($event)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(5, $this->balance());
        $this->assertSame(1, RewardEvent::where('event_id', $event['event_id'])->count());
        $this->assertSame('rewarded', Contribution::first()->status);
    }

    public function test_an_edit_event_for_the_same_answer_does_not_pay_again(): void
    {
        $this->publishAnswer();
        $this->publishAnswer();

        $this->assertSame(5, $this->balance());
    }

    public function test_a_question_pays_nothing_until_it_is_approved(): void
    {
        $this->send($this->event('infodot.question.published', 'infodot:question:1', ['facts' => ['approved' => false]]))
            ->assertStatus(202)->assertJson(['status' => 'needs_approval']);
        $this->assertSame(0, $this->balance());

        $this->send($this->event('infodot.question.published', 'infodot:question:1', ['facts' => ['approved' => true]]))
            ->assertStatus(202)->assertJson(['status' => 'awarded']);
        $this->assertSame(2, $this->balance());
    }

    public function test_answering_your_own_question_earns_nothing(): void
    {
        $this->publishAnswer('infodot:answer:1', ['self_answer' => true]);

        $this->assertSame(0, $this->balance());
        $this->assertSame('no_points', RewardEvent::first()->outcome);
    }

    public function test_company_only_knowledge_is_not_rewarded_by_default(): void
    {
        $this->send($this->event('infodot.answer.published', 'infodot:answer:1', ['visibility' => 'company', 'facts' => ['self_answer' => false]]))
            ->assertStatus(202)->assertJson(['status' => 'no_points']);

        $this->assertSame(0, $this->balance());
    }

    public function test_acceptance_pays_the_author_once_and_never_for_an_acceptance_by_the_author(): void
    {
        $this->publishAnswer();

        $selfAccept = $this->event('infodot.answer.accepted', 'infodot:answer:1', ['actor_uuid' => $this->author]);
        $this->send($selfAccept)->assertStatus(202)->assertJson(['status' => 'rejected']);
        $this->assertSame(5, $this->balance());

        $accept = $this->event('infodot.answer.accepted', 'infodot:answer:1', ['actor_uuid' => $this->asker]);
        $this->send($accept)->assertStatus(202)->assertJson(['status' => 'awarded']);
        $this->send($accept)->assertOk()->assertJson(['status' => 'duplicate']);
        $this->send($this->event('infodot.answer.accepted', 'infodot:answer:1', ['actor_uuid' => $this->asker]))->assertJson(['status' => 'already_rewarded']);

        $this->assertSame(15, $this->balance());
        $this->assertSame('accepted', Contribution::first()->status);
    }

    public function test_acceptance_of_an_unknown_answer_or_with_the_wrong_author_is_not_paid(): void
    {
        $this->send($this->event('infodot.answer.accepted', 'infodot:answer:404', ['actor_uuid' => $this->asker]))->assertJson(['status' => 'ignored']);

        $this->publishAnswer();
        $thief = (string) Str::uuid();
        $this->send($this->event('infodot.answer.accepted', 'infodot:answer:1', ['user_uuid' => $thief, 'actor_uuid' => $this->asker]))->assertJson(['status' => 'rejected']);

        $this->assertSame(0, $this->balance($thief));
        $this->assertSame(5, $this->balance());
    }

    public function test_the_first_event_fixes_who_wrote_a_contribution(): void
    {
        $this->publishAnswer();
        $thief = (string) Str::uuid();

        $this->send($this->event('infodot.answer.published', 'infodot:answer:1', ['user_uuid' => $thief, 'facts' => ['self_answer' => false]]))
            ->assertStatus(202)->assertJson(['status' => 'rejected']);

        $this->assertSame(0, $this->balance($thief));
        $this->assertSame($this->author, Contribution::first()->user_uuid);
    }

    public function test_a_revoked_acceptance_takes_back_only_that_award_and_reports_what_was_already_spent(): void
    {
        $this->publishAnswer();
        $this->send($this->event('infodot.answer.accepted', 'infodot:answer:1', ['actor_uuid' => $this->asker]));
        $this->assertSame(15, $this->balance());

        $this->send($this->event('infodot.contribution.reward_reversed', 'infodot:answer:1', ['facts' => ['rule_key' => 'infodot.answer.accepted', 'reason' => 'Acceptance removed']]))
            ->assertStatus(202)->assertJson(['status' => 'reversed']);

        $this->assertSame(5, $this->balance(), 'the answer reward stays, the acceptance bonus goes');
    }

    public function test_a_reversal_after_the_points_were_spent_never_makes_the_balance_negative(): void
    {
        $this->publishAnswer();
        $this->send($this->event('infodot.answer.accepted', 'infodot:answer:1', ['actor_uuid' => $this->asker]));
        app(PointsLedger::class)->redeem($this->author, 13, 'spend-most');

        $this->send($this->event('infodot.contribution.reward_reversed', 'infodot:answer:1', ['facts' => ['rule_key' => 'infodot.answer.accepted']]))->assertStatus(202);

        $this->assertGreaterThanOrEqual(0, $this->balance());
        $this->assertStringContainsString('already spent', (string) RewardEvent::latest('id')->first()->detail);
    }

    public function test_a_flag_holds_the_contribution_but_takes_no_points_back(): void
    {
        $this->publishAnswer();

        $this->send($this->event('infodot.contribution.flagged', 'infodot:answer:1'))->assertStatus(202)->assertJson(['status' => 'flagged']);

        $this->assertSame('flagged', Contribution::first()->status);
        $this->assertSame(5, $this->balance());
    }

    public function test_a_type_from_another_platform_and_malformed_events_are_refused(): void
    {
        $this->send($this->event('pulse.post.published', 'x:1'))->assertStatus(422);
        $this->send($this->event('infodot.answer.published', 'infodot:answer:1', ['user_uuid' => 'not-a-uuid']))->assertStatus(422);
        $bad = $this->event('infodot.answer.published', 'infodot:answer:1');
        unset($bad['event_id']);
        $this->send($bad)->assertStatus(422);

        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_an_event_type_not_handled_yet_is_recorded_and_pays_nothing(): void
    {
        $this->send($this->event('infodot.knowledge.adopted', 'infodot:solution:1'))->assertStatus(202)->assertJson(['status' => 'unsupported']);

        $this->assertSame(0, $this->balance());
        $this->assertSame('unsupported', RewardEvent::first()->outcome);
    }
}
