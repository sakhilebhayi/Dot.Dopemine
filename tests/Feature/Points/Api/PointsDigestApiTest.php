<?php

namespace Tests\Feature\Points\Api;

use App\Models\LedgerEntry;
use App\Models\PointsDigest;
use App\Models\RewardRule;
use App\Services\Points\PointsLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PointsDigestApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'infodot-ingest-secret-for-tests';

    private PointsLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        config(['points.ingest.secrets.infodot' => self::SECRET, 'points.digests.enabled' => true]);
        $this->ledger = app(PointsLedger::class);
        DB::table('reward_rules')->update(['effective_from' => '2020-01-01 00:00:00']); // the seeded rules predate the clock the tests travel to
        // Wednesday 21 Oct 2026, noon UTC: the closed week is Mon 12 - Sun 18 Oct, the closed month is September.
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(12, 0));
    }

    /** @param array<string, mixed>|null $json */
    private function signed(string $method, string $uri, ?array $json = null, ?string $secret = self::SECRET, ?int $at = null)
    {
        $timestamp = $at ?? now()->timestamp;
        $body = $json === null ? '' : json_encode($json);

        return $this->call($method, $uri, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? 'x'),
        ], $body);
    }

    /** An award for $user made at $when (local time of the app timezone), through the ledger. */
    private function awardAt(string $user, string $when, string $rule = 'infodot.answer.published', ?int $points = null): LedgerEntry
    {
        $entry = $this->ledger->award($user, RewardRule::current($rule), 'ref-'.Str::random(8), 'evt-'.Str::random(12), $points)->entry;
        DB::table('ledger_entries')->where('id', $entry->id)->update(['created_at' => $when]); // test setup only: the model refuses edits

        return $entry->fresh();
    }

    private function reverseAt(LedgerEntry $award, string $when): void
    {
        $reversal = $this->ledger->reverse($award, 'duplicate', 'rev-'.Str::random(8))['entry'];
        DB::table('ledger_entries')->where('id', $reversal->id)->update(['created_at' => $when]);
    }

    // ---- signature ------------------------------------------------------------------------------------------

    public function test_both_endpoints_require_a_valid_fresh_signature(): void
    {
        $user = (string) Str::uuid();
        $this->awardAt($user, '2026-10-14 09:00:00');
        $id = $this->signed('GET', '/api/points/digests?period=week')->assertOk()->json('0.digest_id');

        foreach ([['GET', '/api/points/digests?period=week', null], ['POST', "/api/points/digests/{$id}/result", ['outcome' => 'delivered']]] as [$method, $uri, $json]) {
            $this->signed($method, $uri, $json, 'wrong-secret')->assertStatus(401)->assertJson(['code' => 'invalid_signature']);
            $this->signed($method, $uri, $json, self::SECRET, now()->timestamp - 301)->assertStatus(401)->assertJson(['code' => 'invalid_signature']);
            $this->signed($method, $uri, $json, self::SECRET, now()->timestamp + 301)->assertStatus(401)->assertJson(['code' => 'invalid_signature']);
            $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json'], $json === null ? '' : json_encode($json))
                ->assertStatus(401)->assertJson(['code' => 'invalid_signature']);
        }

        $this->assertSame(PointsDigest::PENDING, PointsDigest::where('uuid', $id)->value('state'), 'unsigned calls changed nothing');
    }

    public function test_an_unconfigured_platform_secret_is_refused(): void
    {
        config(['points.ingest.secrets.infodot' => null]);

        $this->signed('GET', '/api/points/digests?period=week', null, 'anything')->assertStatus(401)->assertJson(['code' => 'invalid_signature']);
    }

    // ---- switch ---------------------------------------------------------------------------------------------

    public function test_it_is_off_by_default_and_then_nothing_is_listed_or_created(): void
    {
        $this->assertFalse((require config_path('points.php'))['digests']['enabled'], 'the shipped default is off');
        $this->assertStringContainsString('POINTS_DIGESTS_ENABLED=false', (string) file_get_contents(base_path('.env.example')));

        config(['points.ingest.secrets.infodot' => self::SECRET, 'points.digests.enabled' => false]);
        $this->awardAt((string) Str::uuid(), '2026-10-14 09:00:00');

        $this->signed('GET', '/api/points/digests?period=week')->assertOk()->assertExactJson([]);
        $this->signed('GET', '/api/points/digests?period=month')->assertOk()->assertExactJson([]);
        $this->assertSame(0, PointsDigest::count());
    }

    public function test_period_and_limit_are_validated(): void
    {
        $this->signed('GET', '/api/points/digests')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->signed('GET', '/api/points/digests?period=year')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->signed('GET', '/api/points/digests?period=week&limit=0')->assertStatus(422);
        $this->signed('GET', '/api/points/digests?period=week&limit=201')->assertStatus(422);
    }

    // ---- who gets one ---------------------------------------------------------------------------------------

    public function test_only_people_who_earned_in_the_closed_period_get_a_digest(): void
    {
        [$earner, $before, $during, $after, $spender] = array_map(fn () => (string) Str::uuid(), range(1, 5));

        $this->awardAt($earner, '2026-10-14 09:00:00');
        $this->awardAt($before, '2026-10-11 23:59:59');   // Sunday of the week before
        $this->awardAt($after, '2026-10-19 00:00:00');    // this (open) week
        $this->awardAt($during, '2026-10-05 10:00:00');   // earned in an older week
        $this->awardAt($spender, '2026-10-05 10:00:00');
        $this->ledger->redeem($spender, 3, 'spend-1'); // spending in the period is not earning

        $list = $this->signed('GET', '/api/points/digests?period=week')->assertOk()->json();

        $this->assertSame([$earner], array_column($list, 'user_uuid'));
        $this->assertSame(1, PointsDigest::count(), 'no row is made for anyone who earned nothing in the period');
    }

    public function test_a_refund_is_not_earning(): void
    {
        $user = (string) Str::uuid();
        $this->awardAt($user, '2026-10-05 10:00:00');
        $spent = $this->ledger->redeem($user, 3, 'spend-2');
        $refund = $this->ledger->refund($spent, 'refund-2', 'failed redemption');
        DB::table('ledger_entries')->where('id', $refund->id)->update(['created_at' => '2026-10-14 09:00:00']);

        $this->signed('GET', '/api/points/digests?period=week')->assertOk()->assertExactJson([]);
    }

    // ---- payload --------------------------------------------------------------------------------------------

    public function test_the_digest_has_exactly_the_contract_fields_and_figures_from_the_ledger(): void
    {
        $user = (string) Str::uuid();
        $this->awardAt($user, '2026-10-13 09:00:00');                                         // answer.published: 5
        $this->awardAt($user, '2026-10-15 09:00:00');                                         // answer.published: 5
        $accepted = $this->awardAt($user, '2026-10-18 23:59:59', 'infodot.answer.accepted');  // 10
        $this->awardAt($user, '2026-10-05 09:00:00');                                         // older week: not in this digest, but in the balance

        $row = $this->signed('GET', '/api/points/digests?period=week')->assertOk()->json('0');

        $this->assertSame(
            ['digest_id', 'user_uuid', 'period', 'period_start', 'period_end', 'points_earned', 'awards', 'points_reversed', 'balance', 'expiring_next_30_days'],
            array_keys($row),
        );
        $this->assertTrue(Str::isUuid($row['digest_id']));
        $this->assertSame($user, $row['user_uuid']);
        $this->assertSame(['week', '2026-10-12', '2026-10-18'], [$row['period'], $row['period_start'], $row['period_end']]);
        $this->assertSame(20, $row['points_earned']);
        $this->assertSame(0, $row['points_reversed']);
        $this->assertSame(25, $row['balance']);
        $this->assertNull($row['expiring_next_30_days']);
        $this->assertSame(
            [
                ['rule_key' => 'infodot.answer.accepted', 'label' => 'Answer accepted as the solution by the asker or a moderator who is not the answerer.', 'count' => 1, 'points' => 10],
                ['rule_key' => 'infodot.answer.published', 'label' => 'Substantive, original answer.', 'count' => 2, 'points' => 10],
            ],
            $row['awards'],
        );
        $this->assertNotNull($accepted);
    }

    public function test_a_rule_without_a_description_gets_a_readable_label_not_an_internal_id(): void
    {
        $user = (string) Str::uuid();
        DB::table('reward_rules')->where('key', 'infodot.answer.published')->update(['note' => null]);
        $this->awardAt($user, '2026-10-14 09:00:00');

        $label = $this->signed('GET', '/api/points/digests?period=week')->json('0.awards.0.label');

        $this->assertSame('Answer published', $label);
    }

    public function test_the_payload_has_no_address_content_or_contribution_reference(): void
    {
        $user = (string) Str::uuid();
        $this->awardAt($user, '2026-10-14 09:00:00');

        $response = $this->signed('GET', '/api/points/digests?period=week')->assertOk();
        $json = $response->getContent();

        foreach (['email', 'name', 'title', 'body', 'content', 'contribution_ref', 'ref-', 'idempotency', 'reason'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($json), "payload leaks `{$forbidden}`");
        }

        $stored = json_encode(PointsDigest::firstOrFail()->getAttributes());
        $this->assertStringNotContainsString('@', $stored);
        $this->assertStringNotContainsString('ref-', $stored);
    }

    public function test_points_expiring_within_30_days_are_shown(): void
    {
        $user = (string) Str::uuid();
        $award = $this->awardAt($user, '2026-10-14 09:00:00');
        DB::table('ledger_entries')->where('id', $award->id)->update(['expires_at' => now()->addDays(10)]);

        $row = $this->signed('GET', '/api/points/digests?period=week')->json('0');

        $this->assertSame(5, $row['expiring_next_30_days']['points']);
        $this->assertSame(now()->addDays(10)->toIso8601String(), $row['expiring_next_30_days']['first_at']);
    }

    public function test_award_and_reversal_are_both_shown_honestly(): void
    {
        $user = (string) Str::uuid();
        $first = $this->awardAt($user, '2026-10-13 09:00:00', 'infodot.answer.accepted');   // +10
        $this->awardAt($user, '2026-10-14 09:00:00');                                         // +5
        $this->reverseAt($first, '2026-10-16 09:00:00');                                      // -10

        $row = $this->signed('GET', '/api/points/digests?period=week')->json('0');

        $this->assertSame(15, $row['points_earned'], 'what was awarded is not hidden by a later reversal');
        $this->assertSame(10, $row['points_reversed'], 'the reversal is reported as a plain positive amount');
        $this->assertSame(5, $row['balance']);
        $this->assertGreaterThanOrEqual(0, $row['points_earned']);
        $this->assertGreaterThanOrEqual(0, $row['points_reversed']);
    }

    public function test_a_person_with_only_a_reversal_in_the_period_gets_no_digest(): void
    {
        $user = (string) Str::uuid();
        $old = $this->awardAt($user, '2026-10-05 09:00:00');
        $this->reverseAt($old, '2026-10-15 09:00:00');

        $this->signed('GET', '/api/points/digests?period=week')->assertOk()->assertExactJson([]);
    }

    // ---- periods --------------------------------------------------------------------------------------------

    public function test_the_week_is_monday_to_sunday_and_the_month_is_the_calendar_month(): void
    {
        $user = (string) Str::uuid();
        $this->awardAt($user, '2026-09-01 00:00:00');   // first second of September
        $this->awardAt($user, '2026-09-30 23:59:59');   // last second of September
        $this->awardAt($user, '2026-10-01 00:00:00');   // October: not in September's digest
        $this->awardAt($user, '2026-08-31 23:59:59');   // August: not in it either

        $month = $this->signed('GET', '/api/points/digests?period=month')->assertOk()->json('0');

        $this->assertSame(['month', '2026-09-01', '2026-09-30', 10], [$month['period'], $month['period_start'], $month['period_end'], $month['points_earned']]);
    }

    public function test_the_week_across_a_year_boundary_and_the_december_month(): void
    {
        $this->travelTo(now()->setDate(2027, 1, 5)->setTime(8, 0)); // Tuesday; ISO week 53 of 2026 was 28 Dec - 3 Jan
        $user = (string) Str::uuid();
        $this->awardAt($user, '2026-12-28 00:00:00');
        $this->awardAt($user, '2027-01-03 23:59:59');
        $this->awardAt($user, '2027-01-04 00:00:00');   // the open week
        $this->awardAt($user, '2026-12-27 23:59:59');   // the week before

        $week = $this->signed('GET', '/api/points/digests?period=week')->assertOk()->json('0');
        $this->assertSame(['2026-12-28', '2027-01-03', 10], [$week['period_start'], $week['period_end'], $week['points_earned']]);

        $month = $this->signed('GET', '/api/points/digests?period=month')->assertOk()->json('0');
        $this->assertSame(['2026-12-01', '2026-12-31'], [$month['period_start'], $month['period_end']]);
        $this->assertSame(10, $month['points_earned'], '27 Dec and 28 Dec, nothing from January');
    }

    public function test_a_month_that_ends_on_a_short_day_is_closed_correctly(): void
    {
        $this->travelTo(now()->setDate(2027, 3, 1)->setTime(0, 5));
        $user = (string) Str::uuid();
        $this->awardAt($user, '2027-02-28 23:59:59');

        $month = $this->signed('GET', '/api/points/digests?period=month')->assertOk()->json('0');

        $this->assertSame(['2027-02-01', '2027-02-28'], [$month['period_start'], $month['period_end']]);
    }

    public function test_the_closed_period_follows_the_application_timezone(): void
    {
        config(['app.timezone' => 'Africa/Johannesburg']); // UTC+2, no DST

        // 22:30 UTC on Sunday 18 Oct is already 00:30 Monday 19 Oct in Johannesburg: the week of 12 Oct has just closed there.
        $this->travelTo(CarbonImmutable::parse('2026-10-18 22:30:00', 'UTC'));
        $user = (string) Str::uuid();
        $this->awardAt($user, '2026-10-18 23:30:00'); // local wall clock, Sunday evening

        $week = $this->signed('GET', '/api/points/digests?period=week')->assertOk()->json('0');

        $this->assertSame(['2026-10-12', '2026-10-18', 5], [$week['period_start'], $week['period_end'], $week['points_earned']]);

        // The same instant read in UTC (still Sunday): the closed week would be the one before.
        config(['app.timezone' => 'UTC']);
        DB::table('points_digests')->delete();
        $utc = $this->signed('GET', '/api/points/digests?period=week')->assertOk()->json();
        $this->assertSame([], $utc, 'nothing was earned in the week of 5 Oct');
    }

    // ---- one per period -------------------------------------------------------------------------------------

    public function test_re_pulling_returns_the_same_digest_and_never_duplicates(): void
    {
        $user = (string) Str::uuid();
        $this->awardAt($user, '2026-10-14 09:00:00');

        $first = $this->signed('GET', '/api/points/digests?period=week')->json();
        $this->awardAt($user, '2026-10-15 09:00:00'); // arrives after the digest was made: the numbers stay fixed
        $second = $this->signed('GET', '/api/points/digests?period=week')->json();

        $this->assertSame($first, $second);
        $this->assertSame(5, $second[0]['points_earned']);
        $this->assertSame(1, PointsDigest::count());
    }

    public function test_a_week_digest_and_a_month_digest_are_separate_and_one_row_per_period_is_enforced_by_the_database(): void
    {
        $user = (string) Str::uuid();
        $this->awardAt($user, '2026-09-10 09:00:00');
        $this->awardAt($user, '2026-10-14 09:00:00');

        $this->signed('GET', '/api/points/digests?period=week')->assertOk();
        $this->signed('GET', '/api/points/digests?period=month')->assertOk();
        $this->assertSame(2, PointsDigest::count());

        $this->expectException(UniqueConstraintViolationException::class);
        PointsDigest::create(['user_uuid' => $user, 'period' => 'week', 'period_start' => '2026-10-12', 'period_end' => '2026-10-18', 'payload' => []]);
    }

    public function test_the_limit_and_the_per_pull_cap_bound_a_pull_and_the_rest_follow(): void
    {
        config(['points.digests.max_per_pull' => 2]);
        foreach (range(1, 5) as $i) {
            $this->awardAt((string) Str::uuid(), '2026-10-14 09:00:00');
        }

        $seen = [];
        foreach (range(1, 3) as $pull) {
            $page = $this->signed('GET', '/api/points/digests?period=week&limit=100')->assertOk()->json();
            $this->assertLessThanOrEqual(2, count($page));
            foreach ($page as $row) {
                $seen[$row['digest_id']] = true;
                $this->signed('POST', "/api/points/digests/{$row['digest_id']}/result", ['outcome' => 'delivered'])->assertOk();
            }
        }

        $this->assertCount(5, $seen);
        $this->assertSame(5, PointsDigest::count());
        $this->signed('GET', '/api/points/digests?period=week')->assertExactJson([]);
    }

    // ---- results --------------------------------------------------------------------------------------------

    private function pendingDigest(): string
    {
        $this->awardAt((string) Str::uuid(), '2026-10-14 09:00:00');

        return $this->signed('GET', '/api/points/digests?period=week')->json('0.digest_id');
    }

    public function test_delivered_closes_the_digest_and_is_idempotent_and_monotonic(): void
    {
        $id = $this->pendingDigest();

        $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'delivered'])->assertOk()->assertExactJson(['digest_id' => $id, 'state' => 'delivered']);
        $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'delivered'])->assertOk()->assertJson(['state' => 'delivered']);
        $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'retry'])->assertOk()->assertJson(['state' => 'delivered']);
        $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'skipped'])->assertOk()->assertJson(['state' => 'delivered']);

        $this->assertSame(1, PointsDigest::where('uuid', $id)->value('attempts'));
        $this->signed('GET', '/api/points/digests?period=week')->assertExactJson([]);
    }

    public function test_skipped_permanently_closes_it_and_it_is_never_listed_or_retried(): void
    {
        $id = $this->pendingDigest();

        $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'skipped'])->assertOk()->assertExactJson(['digest_id' => $id, 'state' => 'skipped']);
        $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'skipped'])->assertOk()->assertJson(['state' => 'skipped']);
        $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'delivered'])->assertOk()->assertJson(['state' => 'skipped']);
        $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'retry'])->assertOk()->assertJson(['state' => 'skipped']);

        $this->travelTo(now()->addDays(2)->subDays(2)->addMinutes(5)); // even much later in the same period
        $this->signed('GET', '/api/points/digests?period=week')->assertExactJson([]);
        $this->assertSame(1, PointsDigest::count(), 'a skipped digest is not made again');
    }

    public function test_retry_backs_off_is_not_listed_while_waiting_and_a_replay_does_not_burn_an_attempt(): void
    {
        $id = $this->pendingDigest();

        $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'retry'])->assertOk()->assertJson(['state' => 'pending']);
        $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'retry'])->assertOk()->assertJson(['state' => 'pending']);

        $digest = PointsDigest::where('uuid', $id)->first();
        $this->assertSame(1, $digest->attempts, 'a duplicated retry inside the wait counts once');
        $this->assertEquals(now()->addMinutes(60), $digest->next_attempt_at);
        $this->signed('GET', '/api/points/digests?period=week')->assertExactJson([]);

        $this->travelTo(now()->addMinutes(61));
        $this->assertSame($id, $this->signed('GET', '/api/points/digests?period=week')->json('0.digest_id'), 'listed again once the wait is over, same id');
    }

    public function test_the_wait_grows_and_the_fifth_failed_attempt_gives_up(): void
    {
        $id = $this->pendingDigest();
        $waits = [];

        foreach (range(1, 4) as $attempt) {
            $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'retry'])->assertOk()->assertJson(['state' => 'pending']);
            $digest = PointsDigest::where('uuid', $id)->first();
            $waits[] = (int) now()->diffInMinutes($digest->next_attempt_at, true);
            $this->travelTo($digest->next_attempt_at->addMinute());
        }

        $this->assertSame([60, 120, 240, 480], array_map(fn ($m) => (int) round($m), $waits));

        $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'retry'])->assertOk()->assertExactJson(['digest_id' => $id, 'state' => 'failed']);

        $digest = PointsDigest::where('uuid', $id)->first();
        $this->assertSame(5, $digest->attempts);
        $this->assertNull($digest->next_attempt_at);
        $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'delivered'])->assertOk()->assertJson(['state' => 'failed']);
        $this->signed('GET', '/api/points/digests?period=week')->assertExactJson([]);
    }

    public function test_delivered_after_a_retry_still_delivers(): void
    {
        $id = $this->pendingDigest();

        $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'retry'])->assertOk();
        $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'delivered'])->assertOk()->assertJson(['state' => 'delivered']);
    }

    public function test_unknown_ids_are_404_and_bad_outcomes_are_422(): void
    {
        $id = $this->pendingDigest();

        $this->signed('POST', '/api/points/digests/'.Str::uuid().'/result', ['outcome' => 'delivered'])->assertStatus(404)->assertJson(['code' => 'digest_not_found']);
        $this->signed('POST', '/api/points/digests/12345/result', ['outcome' => 'delivered'])->assertStatus(404)->assertJson(['code' => 'digest_not_found']);

        foreach ([['outcome' => 'permanent_failure'], ['outcome' => 'sent'], [], ['outcome' => ['delivered']]] as $body) {
            $this->signed('POST', "/api/points/digests/{$id}/result", $body)->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        }

        $this->assertSame(PointsDigest::PENDING, PointsDigest::where('uuid', $id)->value('state'));
    }

    public function test_results_are_still_accepted_when_the_switch_is_turned_off_later(): void
    {
        $id = $this->pendingDigest();
        config(['points.digests.enabled' => false]);

        $this->signed('POST', "/api/points/digests/{$id}/result", ['outcome' => 'delivered'])->assertOk()->assertJson(['state' => 'delivered']);
        $this->signed('GET', '/api/points/digests?period=week')->assertExactJson([]);
    }

    public function test_the_digest_routes_do_not_swallow_or_get_swallowed_by_the_summary_route(): void
    {
        $this->signed('GET', '/api/points/digests?period=week')->assertOk()->assertExactJson([]);
        $this->signed('GET', '/api/points/'.Str::uuid())->assertOk()->assertJsonStructure(['balance', 'history']);
    }
}
