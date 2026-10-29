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
 * `infodot.seminar.hosted` (R-13, owner decision 2026-10-29): the HOST earns once for a seminar that finished with at
 * least 3 distinct attendees and an average rating of 4.0 or more from at least 3 ratings. Attending earns nothing.
 * Dopemine enforces this itself: a seminar that does not qualify is a 422 that writes nothing.
 */
class SeminarHostedEventTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'infodot-ingest-secret-for-tests';

    private string $host;

    protected function setUp(): void
    {
        parent::setUp();
        config(['points.ingest.secrets.infodot' => self::SECRET]);
        $this->host = (string) Str::uuid();
    }

    /** @param array<string, mixed> $facts */
    private function seminar(string $ref = 'seminar:41', array $facts = [], array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'type' => 'infodot.seminar.hosted',
            'occurred_at' => now()->toIso8601String(),
            'user_uuid' => $this->host,
            'contribution_ref' => $ref,
            'visibility' => 'public',
            'facts' => array_merge(['attendee_count' => 5, 'rating_count' => 4, 'average_rating' => 4.5], $facts),
        ], $overrides);
    }

    private function send(array $event, ?string $secret = self::SECRET)
    {
        $body = json_encode($event);
        $timestamp = now()->timestamp;

        return $this->call('POST', '/api/ingest/contributions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? 'x'),
        ], $body);
    }

    private function balance(?string $user = null): int
    {
        return app(PointsLedger::class)->balance($user ?? $this->host);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, LedgerEntry::count(), 'a refused seminar must leave no ledger row');
        $this->assertSame(0, RewardEvent::count(), 'a refused seminar must not remember its event id');
        $this->assertSame(0, Contribution::count());
    }

    public function test_the_seminar_rule_is_seeded_as_version_one_with_the_proposed_numbers(): void
    {
        $rule = RewardRule::where('key', 'seminar_hosted')->where('version', 1)->sole();

        $this->assertSame('infodot', $rule->platform);
        $this->assertSame('infodot.seminar.hosted', $rule->event_type);
        $this->assertSame([10, 5, 15, 20, 1, false, true], [$rule->points, $rule->min_points, $rule->max_points, $rule->daily_cap_points, $rule->max_awards_per_contribution, $rule->requires_approval, $rule->active]);
    }

    public function test_a_qualifying_seminar_pays_the_host_once_and_records_only_counts(): void
    {
        $this->send($this->seminar())->assertStatus(202)->assertJson(['status' => 'awarded']);

        $this->assertSame(10, $this->balance());
        $entry = LedgerEntry::where('type', LedgerEntry::AWARD)->sole();
        $this->assertSame($this->host, $entry->user_uuid);
        $this->assertSame('seminar_hosted', $entry->rule_key);
        $this->assertSame(1, $entry->rule_version);
        $this->assertSame('seminar:41', $entry->contribution_ref);
        $this->assertSame(['event' => 'infodot.seminar.hosted', 'attendee_count' => 5, 'rating_count' => 4, 'average_rating' => 4.5], $entry->meta);

        $contribution = Contribution::sole();
        $this->assertSame('seminar', $contribution->kind);
        $this->assertSame('rewarded', $contribution->status);
        $this->assertSame($this->host, $contribution->user_uuid);
    }

    public function test_the_exact_thresholds_qualify(): void
    {
        $this->send($this->seminar('seminar:1', ['attendee_count' => 3, 'rating_count' => 3, 'average_rating' => 4.0]))
            ->assertStatus(202)->assertJson(['status' => 'awarded']);
        $this->send($this->seminar('seminar:2', ['attendee_count' => 3, 'rating_count' => 3, 'average_rating' => 4]))
            ->assertStatus(202)->assertJson(['status' => 'awarded']);
    }

    public function test_a_replay_is_a_duplicate_and_a_second_event_for_the_same_seminar_pays_nothing(): void
    {
        $event = $this->seminar();

        $this->send($event)->assertStatus(202)->assertJson(['status' => 'awarded']);
        $this->send($event)->assertOk()->assertJson(['status' => 'duplicate']);
        $this->send($this->seminar())->assertStatus(202)->assertJson(['status' => 'already_rewarded']);

        $this->assertSame(10, $this->balance());
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntry::AWARD)->count());
        $this->assertSame(1, RewardEvent::where('event_id', $event['event_id'])->count());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function belowThreshold(): array
    {
        return [
            'two attendees' => [['attendee_count' => 2], 'seminar_below_attendee_threshold'],
            'no attendees' => [['attendee_count' => 0], 'seminar_below_attendee_threshold'],
            'two ratings' => [['rating_count' => 2], 'seminar_below_rating_count_threshold'],
            'no ratings' => [['rating_count' => 0, 'average_rating' => 0], 'seminar_below_rating_count_threshold'],
            'average 3.9' => [['average_rating' => 3.9], 'seminar_below_rating_threshold'],
            'average 0' => [['average_rating' => 0], 'seminar_below_rating_threshold'],
        ];
    }

    #[DataProvider('belowThreshold')]
    public function test_a_seminar_below_any_threshold_is_refused_with_a_reason_and_writes_nothing(array $facts, string $code): void
    {
        $this->send($this->seminar('seminar:41', $facts))
            ->assertStatus(422)->assertJson(['code' => $code])->assertJsonStructure(['code', 'message', 'reasons']);

        $this->assertNothingWritten();
        $this->assertSame(0, $this->balance());
    }

    public function test_every_failing_threshold_is_reported_and_the_event_can_be_sent_again_when_it_qualifies(): void
    {
        $event = $this->seminar('seminar:41', ['attendee_count' => 1, 'rating_count' => 1, 'average_rating' => 2.0]);

        $this->send($event)->assertStatus(422)->assertJson(['code' => 'seminar_below_attendee_threshold'])->assertJsonCount(3, 'reasons');

        $event['facts'] = ['attendee_count' => 6, 'rating_count' => 5, 'average_rating' => 4.2];
        $this->send($event)->assertStatus(202)->assertJson(['status' => 'awarded']);
        $this->assertSame(10, $this->balance());
    }

    public function test_the_thresholds_come_from_configuration(): void
    {
        config(['points.seminar_hosted.min_attendees' => 5, 'points.seminar_hosted.min_ratings' => 4, 'points.seminar_hosted.min_average_rating' => 4.5]);

        $this->send($this->seminar('seminar:1', ['attendee_count' => 4]))->assertStatus(422)->assertJson(['code' => 'seminar_below_attendee_threshold']);
        $this->send($this->seminar('seminar:2', ['rating_count' => 3]))->assertStatus(422)->assertJson(['code' => 'seminar_below_rating_count_threshold']);
        $this->send($this->seminar('seminar:3', ['average_rating' => 4.4]))->assertStatus(422)->assertJson(['code' => 'seminar_below_rating_threshold']);
        $this->send($this->seminar('seminar:4', ['attendee_count' => 5, 'rating_count' => 4, 'average_rating' => 4.5]))->assertStatus(202)->assertJson(['status' => 'awarded']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> */
    public static function unknownFields(): array
    {
        return [
            'attendee identities in facts' => [['attendees' => ['a', 'b', 'c']], []],
            'free text in facts' => [['comment' => 'great talk'], []],
            'points in facts' => [['points' => 500], []],
            'approval flag in facts' => [['approved' => true], []],
            'rule key in facts' => [['rule_key' => 'infodot.solution.verified'], []],
            'tier data in facts' => [['tier' => 'expert'], []],
            'extra top-level field' => [[], ['attendees' => ['x']]],
            'extra top-level tier' => [[], ['host_tier' => 'expert']],
        ];
    }

    #[DataProvider('unknownFields')]
    public function test_a_field_outside_the_whitelist_is_refused_and_writes_nothing(array $extraFacts, array $extraTop): void
    {
        $event = $this->seminar('seminar:41', $extraFacts, $extraTop);

        $this->send($event)->assertStatus(422)->assertJson(['code' => 'seminar_unknown_field'])->assertJsonStructure(['fields']);

        $this->assertNothingWritten();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function malformedFigures(): array
    {
        return [
            'attendees as a string' => [['attendee_count' => '5']],
            'attendees fractional' => [['attendee_count' => 5.5]],
            'attendees negative' => [['attendee_count' => -1]],
            'attendees null' => [['attendee_count' => null]],
            'ratings negative' => [['rating_count' => -2]],
            'average as a string' => [['average_rating' => '4.5']],
            'average above five' => [['average_rating' => 5.5]],
            'average negative' => [['average_rating' => -1]],
            'average with two decimals' => [['average_rating' => 4.25]],
            'average null' => [['average_rating' => null]],
        ];
    }

    #[DataProvider('malformedFigures')]
    public function test_malformed_figures_are_refused_and_write_nothing(array $facts): void
    {
        $this->send($this->seminar('seminar:41', $facts))->assertStatus(422)->assertJson(['code' => 'seminar_facts_invalid']);

        $this->assertNothingWritten();
    }

    public function test_missing_figures_are_refused(): void
    {
        foreach (['attendee_count', 'rating_count', 'average_rating'] as $field) {
            $event = $this->seminar();
            unset($event['facts'][$field]);
            $this->send($event)->assertStatus(422)->assertJson(['code' => 'seminar_facts_invalid']);
        }

        $event = $this->seminar();
        unset($event['facts']);
        $this->send($event)->assertStatus(422)->assertJson(['code' => 'seminar_facts_invalid']);

        $this->assertNothingWritten();
    }

    public function test_the_reference_must_be_seminar_colon_id(): void
    {
        foreach (['infodot:seminar:41', 'seminar:', 'seminar:a b', 'seminar:41/../x'] as $ref) {
            $this->send($this->seminar($ref))->assertStatus(422)->assertJson(['code' => 'seminar_ref_invalid']);
        }

        $this->assertNothingWritten();
        $this->send($this->seminar('seminar:01HZX3-abc_9'))->assertStatus(202)->assertJson(['status' => 'awarded']);
    }

    public function test_an_unsigned_or_wrongly_signed_request_is_refused_before_anything_is_read(): void
    {
        $event = $this->seminar();

        $this->postJson('/api/ingest/contributions', $event)->assertUnauthorized();
        $this->send($event, 'wrong-secret')->assertUnauthorized();

        $this->assertNothingWritten();
    }

    public function test_a_malformed_envelope_is_still_a_422(): void
    {
        $event = $this->seminar();
        unset($event['user_uuid']);

        $this->send($event)->assertStatus(422);
        $this->assertNothingWritten();
    }

    public function test_the_daily_cap_stops_a_third_seminar_on_the_same_day(): void
    {
        $this->send($this->seminar('seminar:1'))->assertJson(['status' => 'awarded']);
        $this->send($this->seminar('seminar:2'))->assertJson(['status' => 'awarded']);
        $this->send($this->seminar('seminar:3'))->assertStatus(202)->assertJson(['status' => 'daily_cap']);

        $this->assertSame(20, $this->balance());
        $this->assertSame(2, LedgerEntry::where('type', LedgerEntry::AWARD)->count());
        $this->assertSame(0, LedgerEntry::where('contribution_ref', 'seminar:3')->count());
    }

    public function test_the_host_is_fixed_by_the_first_event_for_a_seminar(): void
    {
        $this->send($this->seminar())->assertJson(['status' => 'awarded']);
        $other = (string) Str::uuid();

        $this->send($this->seminar('seminar:41', [], ['user_uuid' => $other]))->assertStatus(202)->assertJson(['status' => 'rejected']);

        $this->assertSame(0, $this->balance($other));
        $this->assertSame(10, $this->balance());
    }

    public function test_nobody_but_the_host_earns_and_a_named_actor_is_ignored(): void
    {
        $attendee = (string) Str::uuid();

        $this->send($this->seminar('seminar:41', [], ['actor_uuid' => $attendee]))->assertStatus(202)->assertJson(['status' => 'awarded']);

        $this->assertSame(0, $this->balance($attendee));
        $this->assertSame(0, LedgerEntry::where('user_uuid', $attendee)->count());
        $this->assertSame([$this->host], LedgerEntry::pluck('user_uuid')->unique()->values()->all());
    }

    public function test_a_reference_that_already_belongs_to_another_kind_of_contribution_is_not_a_seminar(): void
    {
        Contribution::create(['platform' => 'infodot', 'ref' => 'seminar:41', 'user_uuid' => $this->host, 'kind' => 'answer', 'status' => 'published', 'visibility' => 'public', 'status_changed_at' => now()]);

        $this->send($this->seminar())->assertStatus(202)->assertJson(['status' => 'rejected']);

        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_company_only_seminars_and_flagged_seminars_earn_nothing(): void
    {
        $this->send($this->seminar('seminar:1', [], ['visibility' => 'company', 'tenant_uuid' => (string) Str::uuid()]))
            ->assertStatus(202)->assertJson(['status' => 'no_points']);

        $this->send($this->seminar('seminar:2'))->assertJson(['status' => 'awarded']);
        Contribution::where('ref', 'seminar:3')->delete();
        Contribution::create(['platform' => 'infodot', 'ref' => 'seminar:3', 'user_uuid' => $this->host, 'kind' => 'seminar', 'status' => 'published', 'visibility' => 'public', 'status_changed_at' => now()]);
        $this->send($this->event('infodot.contribution.flagged', 'seminar:3'))->assertJson(['status' => 'flagged']);
        $this->send($this->seminar('seminar:3'))->assertStatus(202)->assertJson(['status' => 'no_points']);

        $this->assertSame(10, $this->balance());
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntry::AWARD)->count());
    }

    public function test_a_switched_off_rule_pays_nothing(): void
    {
        RewardRule::revise('seminar_hosted', ['active' => false], 'test', 'off');

        $this->send($this->seminar())->assertStatus(202)->assertJson(['status' => 'no_points']);

        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_a_later_rule_version_is_the_one_recorded_on_the_award(): void
    {
        RewardRule::revise('seminar_hosted', ['points' => 12], 'test', 'owner tuned');

        $this->send($this->seminar())->assertJson(['status' => 'awarded']);

        $this->assertSame(12, $this->balance());
        $this->assertSame(2, LedgerEntry::where('type', LedgerEntry::AWARD)->sole()->rule_version);
    }

    public function test_a_cancelled_seminar_can_be_reversed_and_is_not_paid_again(): void
    {
        $this->send($this->seminar())->assertJson(['status' => 'awarded']);
        $this->assertSame(10, $this->balance());

        $this->send($this->event('infodot.contribution.reward_reversed', 'seminar:41', ['facts' => ['rule_key' => 'seminar_hosted', 'reason' => 'Seminar cancelled']]))
            ->assertStatus(202)->assertJson(['status' => 'reversed']);

        $this->assertSame(0, $this->balance());
        $this->assertSame('reversed', Contribution::sole()->status);
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntry::REVERSE)->count());

        $this->send($this->seminar())->assertStatus(202)->assertJson(['status' => 'no_points']);
        $this->assertSame(0, $this->balance());
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntry::AWARD)->count());
    }

    public function test_a_reversal_without_a_rule_key_also_takes_the_seminar_back(): void
    {
        $this->send($this->seminar())->assertJson(['status' => 'awarded']);

        $this->send($this->event('infodot.contribution.reward_reversed', 'seminar:41', ['facts' => ['reason' => 'Seminar cancelled']]))
            ->assertStatus(202)->assertJson(['status' => 'reversed']);

        $this->assertSame(0, $this->balance());
    }

    public function test_points_already_spent_are_not_clawed_back_below_zero(): void
    {
        $this->send($this->seminar())->assertJson(['status' => 'awarded']);
        app(PointsLedger::class)->redeem($this->host, 10, 'spend-1');

        $this->send($this->event('infodot.contribution.reward_reversed', 'seminar:41', ['facts' => ['rule_key' => 'seminar_hosted']]))
            ->assertStatus(202)->assertJson(['status' => 'reversed', 'detail' => '10 points were already spent and could not be taken back.']);

        $this->assertSame(0, $this->balance());
    }

    public function test_the_processor_checks_the_thresholds_itself(): void
    {
        $event = $this->seminar('seminar:41', ['attendee_count' => 1]);

        $result = app(ContributionEventProcessor::class)->handle('infodot', $event);

        $this->assertSame('rejected', $result['outcome']);
        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame(0, Contribution::count());
    }

    public function test_existing_event_types_are_unchanged(): void
    {
        $this->assertSame([], array_values(array_diff(
            ['infodot.question.published', 'infodot.answer.published', 'infodot.answer.accepted', 'infodot.solution.verified', 'infodot.tutorial.approved', 'infodot.troubleshooting.approved', 'infodot.contribution.helpful', 'infodot.knowledge.corrected', 'infodot.knowledge.adopted', 'infodot.contribution.flagged', 'infodot.contribution.reward_reversed', 'infodot.contribution.approved'],
            ContributionEventProcessor::SUPPORTED,
        )));
        $this->assertContains('infodot.seminar.hosted', ContributionEventProcessor::SUPPORTED);

        // Other types still ignore facts they do not read (the strict body is for the seminar type only).
        $this->send($this->event('infodot.answer.published', 'infodot:answer:1', ['facts' => ['question_ref' => 'infodot:question:1', 'self_answer' => false, 'anything_else' => 'ignored']]))
            ->assertStatus(202)->assertJson(['status' => 'awarded']);
        $this->send($this->event('infodot.something.not.built.yet', 'infodot:solution:1'))->assertStatus(202)->assertJson(['status' => 'unsupported']);
        $this->assertSame(5, $this->balance());
    }

    public function test_the_seminar_rule_cannot_be_paid_through_another_event_type(): void
    {
        $this->send($this->event('infodot.contribution.helpful', 'seminar:41', ['facts' => ['rule_key' => 'seminar_hosted']]))
            ->assertStatus(202)->assertJson(['status' => 'rejected']);

        $this->assertSame(0, LedgerEntry::count());
    }

    /** @param array<string, mixed> $overrides */
    private function event(string $type, string $ref, array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'type' => $type,
            'occurred_at' => now()->toIso8601String(),
            'user_uuid' => $this->host,
            'contribution_ref' => $ref,
            'visibility' => 'public',
            'facts' => [],
        ], $overrides);
    }
}
