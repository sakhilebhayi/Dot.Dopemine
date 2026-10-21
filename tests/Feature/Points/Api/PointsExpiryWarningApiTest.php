<?php

namespace Tests\Feature\Points\Api;

use App\Models\ExpiryWarning;
use App\Models\RewardRule;
use App\Services\Notify\NotifyClient;
use App\Services\Points\PointsLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class PointsExpiryWarningApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'infodot-ingest-secret-for-tests';

    private PointsLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        config(['points.ingest.secrets.infodot' => self::SECRET, 'points.expiry_delivery' => 'infodot']);
        $this->ledger = app(PointsLedger::class);
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

    /** A person whose award lapses in $days days. Returns [user_uuid, lot entry]. */
    private function lotLapsingIn(int $days, string $label = 'a'): array
    {
        $user = (string) Str::uuid();
        $entry = $this->ledger->award($user, RewardRule::current('infodot.answer.published'), "infodot:answer:{$label}", "evt-{$label}-".Str::random(5))->entry;
        DB::table('ledger_entries')->where('id', $entry->id)->update(['expires_at' => now()->addDays($days)]); // test setup only: the model refuses edits

        return [$user, $entry->fresh()];
    }

    private function warningFor(int $lotId): ExpiryWarning
    {
        return ExpiryWarning::where('lot_id', $lotId)->firstOrFail();
    }

    // ---- signature ------------------------------------------------------------------------------------------

    public function test_both_endpoints_require_a_valid_fresh_signature(): void
    {
        [, $lot] = $this->lotLapsingIn(10);
        $this->signed('GET', '/api/points/expiry-warnings')->assertOk();
        $id = $this->warningFor($lot->id)->id;

        foreach ([['GET', '/api/points/expiry-warnings', null], ['POST', "/api/points/expiry-warnings/{$id}/result", ['outcome' => 'delivered']]] as [$method, $uri, $json]) {
            $this->signed($method, $uri, $json, 'wrong-secret')->assertStatus(401)->assertJson(['code' => 'invalid_signature']);
            $this->signed($method, $uri, $json, self::SECRET, now()->timestamp - 301)->assertStatus(401)->assertJson(['code' => 'invalid_signature']);
            $this->signed($method, $uri, $json, self::SECRET, now()->timestamp + 301)->assertStatus(401)->assertJson(['code' => 'invalid_signature']);
            $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json'], $json === null ? '' : json_encode($json))
                ->assertStatus(401)->assertJson(['code' => 'invalid_signature']);
        }

        $this->assertSame(ExpiryWarning::PENDING, $this->warningFor($lot->id)->status, 'unsigned calls changed nothing');
    }

    public function test_an_unconfigured_platform_secret_is_refused(): void
    {
        config(['points.ingest.secrets.infodot' => null]);

        $this->signed('GET', '/api/points/expiry-warnings', null, 'anything')->assertStatus(401)->assertJson(['code' => 'invalid_signature']);
    }

    // ---- list -----------------------------------------------------------------------------------------------

    public function test_the_list_has_only_the_four_fields_for_lots_inside_the_window_oldest_first(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$uA, $lotA] = $this->lotLapsingIn(10, 'a');
        [$uB, $lotB] = $this->lotLapsingIn(20, 'b');
        $this->lotLapsingIn(90, 'far'); // outside the 30 day window: no warning yet

        $response = $this->signed('GET', '/api/points/expiry-warnings')->assertOk();
        $rows = $response->json();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['warning_id', 'user_uuid', 'points', 'expires_on'], array_keys($row));
        }
        $this->assertSame($uA, $rows[0]['user_uuid']);
        $this->assertSame($uB, $rows[1]['user_uuid']);
        $this->assertSame((int) $lotA->points, $rows[0]['points']);
        $this->assertSame(now()->addDays(10)->toDateString(), $rows[0]['expires_on']);
        $this->assertLessThan($rows[1]['warning_id'], $rows[0]['warning_id']);
        $this->assertSame(2, ExpiryWarning::count(), 'the out-of-window lot has no warning');
    }

    public function test_listing_twice_creates_no_duplicates_and_changes_no_state(): void
    {
        $this->lotLapsingIn(10);

        $first = $this->signed('GET', '/api/points/expiry-warnings')->json();
        $second = $this->signed('GET', '/api/points/expiry-warnings')->json();

        $this->assertSame($first, $second);
        $this->assertSame(1, ExpiryWarning::count());
    }

    public function test_points_are_what_is_left_in_the_lot_at_pull_time(): void
    {
        [$user, $lot] = $this->lotLapsingIn(10);
        $this->signed('GET', '/api/points/expiry-warnings')->assertOk();
        $this->ledger->redeem($user, 2, 'spend-1');

        $row = $this->signed('GET', '/api/points/expiry-warnings')->json()[0];

        $this->assertSame((int) $lot->points - 2, $row['points']);
    }

    public function test_a_lot_spent_or_lapsed_meanwhile_is_closed_as_moot_and_excluded(): void
    {
        [$spentUser, $spentLot] = $this->lotLapsingIn(10, 'spent');
        [, $lapsedLot] = $this->lotLapsingIn(10, 'lapsed');
        [, $liveLot] = $this->lotLapsingIn(10, 'live');
        $this->signed('GET', '/api/points/expiry-warnings')->assertOk();

        $this->ledger->redeem($spentUser, (int) $spentLot->points, 'spend-all');
        DB::table('ledger_entries')->where('id', $lapsedLot->id)->update(['expires_at' => now()->subMinute()]);

        $rows = $this->signed('GET', '/api/points/expiry-warnings')->json();

        $this->assertCount(1, $rows);
        $this->assertSame($this->warningFor($liveLot->id)->id, $rows[0]['warning_id']);
        $this->assertSame(ExpiryWarning::MOOT, $this->warningFor($spentLot->id)->status);
        $this->assertSame(ExpiryWarning::MOOT, $this->warningFor($lapsedLot->id)->status);
        $this->assertSame(ExpiryWarning::PENDING, $this->warningFor($liveLot->id)->status);
    }

    public function test_delivered_failed_moot_and_not_yet_due_warnings_are_not_listed(): void
    {
        $this->travelTo(now()->startOfSecond());
        $lots = [];
        foreach (['delivered', 'failed', 'moot', 'later', 'due'] as $label) {
            [, $lots[$label]] = $this->lotLapsingIn(10, $label);
        }
        $this->signed('GET', '/api/points/expiry-warnings')->assertOk();

        $this->warningFor($lots['delivered']->id)->forceFill(['status' => ExpiryWarning::DELIVERED])->save();
        $this->warningFor($lots['failed']->id)->forceFill(['status' => ExpiryWarning::FAILED])->save();
        $this->warningFor($lots['moot']->id)->forceFill(['status' => ExpiryWarning::MOOT])->save();
        $this->warningFor($lots['later']->id)->forceFill(['next_attempt_at' => now()->addHour()])->save();
        $this->warningFor($lots['due']->id)->forceFill(['next_attempt_at' => now()->subMinute()])->save();

        $ids = array_column($this->signed('GET', '/api/points/expiry-warnings')->json(), 'warning_id');

        $this->assertSame([$this->warningFor($lots['due']->id)->id], $ids);

        $this->travel(61)->minutes();
        $ids = array_column($this->signed('GET', '/api/points/expiry-warnings')->json(), 'warning_id');
        $this->assertContains($this->warningFor($lots['later']->id)->id, $ids, 'a retry comes back once its wait is over');
    }

    public function test_limit_is_validated_and_applied(): void
    {
        foreach (range(1, 3) as $i) {
            $this->lotLapsingIn(10, "n{$i}");
        }

        $this->assertCount(3, $this->signed('GET', '/api/points/expiry-warnings')->json());
        $this->assertCount(2, $this->signed('GET', '/api/points/expiry-warnings?limit=2')->json());
        $this->assertCount(1, $this->signed('GET', '/api/points/expiry-warnings?limit=1')->json());

        foreach (['0', '201', 'abc', '-1'] as $bad) {
            $this->signed('GET', "/api/points/expiry-warnings?limit={$bad}")->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        }
        $this->signed('GET', '/api/points/expiry-warnings?limit=200')->assertOk();
    }

    public function test_the_limit_counts_live_warnings_not_moot_ones_skipped_over(): void
    {
        [$user, $spent] = $this->lotLapsingIn(10, 'spent');
        $this->lotLapsingIn(10, 'x');
        $this->lotLapsingIn(10, 'y');
        $this->signed('GET', '/api/points/expiry-warnings')->assertOk();
        $this->ledger->redeem($user, (int) $spent->points, 'spend-all');

        $this->assertCount(2, $this->signed('GET', '/api/points/expiry-warnings?limit=2')->json());
    }

    public function test_other_modes_return_an_empty_list_and_write_nothing(): void
    {
        $this->lotLapsingIn(10);

        foreach (['notify', 'none'] as $mode) {
            config(['points.expiry_delivery' => $mode]);

            $this->signed('GET', '/api/points/expiry-warnings')->assertOk()->assertExactJson([]);
        }

        $this->assertSame(0, ExpiryWarning::count());
    }

    public function test_the_list_route_is_not_swallowed_by_the_summary_route(): void
    {
        $this->signed('GET', '/api/points/expiry-warnings')->assertOk()->assertExactJson([]);
    }

    public function test_listing_never_writes_to_the_ledger(): void
    {
        $this->lotLapsingIn(10);
        $before = DB::table('ledger_entries')->count();

        $this->signed('GET', '/api/points/expiry-warnings');

        $this->assertSame($before, DB::table('ledger_entries')->count());
    }

    // ---- result ---------------------------------------------------------------------------------------------

    private function pendingWarning(): ExpiryWarning
    {
        [, $lot] = $this->lotLapsingIn(10, Str::random(6));
        $this->signed('GET', '/api/points/expiry-warnings')->assertOk();

        return $this->warningFor($lot->id);
    }

    private function report(ExpiryWarning|int $warning, string $outcome)
    {
        $id = $warning instanceof ExpiryWarning ? $warning->id : $warning;

        return $this->signed('POST', "/api/points/expiry-warnings/{$id}/result", ['outcome' => $outcome]);
    }

    public function test_delivered_marks_delivered_and_is_idempotent(): void
    {
        $w = $this->pendingWarning();

        $this->report($w, 'delivered')->assertOk()->assertExactJson(['warning_id' => $w->id, 'state' => 'delivered']);
        $first = $w->fresh();
        $this->assertNotNull($first->delivered_at);
        $this->assertNull($first->next_attempt_at);

        $this->travel(5)->minutes();
        $this->report($w, 'delivered')->assertOk()->assertExactJson(['warning_id' => $w->id, 'state' => 'delivered']);
        $this->assertEquals($first->delivered_at, $w->fresh()->delivered_at);
        $this->assertSame($first->attempts, $w->fresh()->attempts);
        $this->assertEmpty($this->signed('GET', '/api/points/expiry-warnings')->json());
    }

    public function test_a_delivered_or_failed_row_never_goes_back(): void
    {
        $delivered = $this->pendingWarning();
        $this->report($delivered, 'delivered')->assertOk();
        foreach (['retry', 'permanent_failure', 'delivered'] as $outcome) {
            $this->report($delivered, $outcome)->assertOk()->assertExactJson(['warning_id' => $delivered->id, 'state' => 'delivered']);
        }

        $failed = $this->pendingWarning();
        $this->report($failed, 'permanent_failure')->assertOk()->assertExactJson(['warning_id' => $failed->id, 'state' => 'failed']);
        foreach (['retry', 'delivered', 'permanent_failure'] as $outcome) {
            $this->report($failed, $outcome)->assertOk()->assertExactJson(['warning_id' => $failed->id, 'state' => 'failed']);
        }
        $this->assertNull($failed->fresh()->delivered_at);
        $this->assertSame(ExpiryWarning::DELIVERED, $delivered->fresh()->status);
    }

    public function test_a_moot_row_stays_moot(): void
    {
        $w = $this->pendingWarning();
        $w->forceFill(['status' => ExpiryWarning::MOOT])->save();

        foreach (['delivered', 'retry', 'permanent_failure'] as $outcome) {
            $this->report($w, $outcome)->assertOk()->assertExactJson(['warning_id' => $w->id, 'state' => 'moot']);
        }
    }

    public function test_retry_follows_the_notify_backoff_schedule_and_fails_after_the_last(): void
    {
        $this->travelTo(now()->startOfSecond());
        $w = $this->pendingWarning();

        foreach ([60, 120, 240, 480, 960] as $i => $minutes) {
            $this->report($w, 'retry')->assertOk()->assertExactJson(['warning_id' => $w->id, 'state' => 'pending']);
            $fresh = $w->fresh();
            $this->assertSame($i + 1, $fresh->attempts);
            $this->assertEquals(now()->addMinutes($minutes), $fresh->next_attempt_at, 'attempt '.($i + 1)." waits {$minutes} minutes");

            $this->assertEmpty($this->signed('GET', '/api/points/expiry-warnings')->json(), 'not listed during the wait');
            $this->travel($minutes)->minutes();
            $this->assertSame([$w->id], array_column($this->signed('GET', '/api/points/expiry-warnings')->json(), 'warning_id'), 'listed again when due');
        }

        $this->report($w, 'retry')->assertOk()->assertExactJson(['warning_id' => $w->id, 'state' => 'failed']);
        $this->assertSame(6, $w->fresh()->attempts);
        $this->assertNull($w->fresh()->next_attempt_at);
        $this->assertEmpty($this->signed('GET', '/api/points/expiry-warnings')->json());
    }

    public function test_the_backoff_is_the_same_schedule_the_notify_path_uses(): void
    {
        $this->assertSame([60, 120, 240, 480, 960, null], array_map(fn (int $n) => ExpiryWarning::backoffMinutesAfter($n), [1, 2, 3, 4, 5, 6]));
    }

    public function test_repeating_a_retry_inside_its_wait_is_a_no_op(): void
    {
        $w = $this->pendingWarning();

        $this->report($w, 'retry')->assertOk();
        $after = $w->fresh();
        $this->report($w, 'retry')->assertOk()->assertExactJson(['warning_id' => $w->id, 'state' => 'pending']);

        $this->assertSame(1, $w->fresh()->attempts);
        $this->assertEquals($after->next_attempt_at, $w->fresh()->next_attempt_at);
    }

    public function test_a_delivered_report_after_a_retry_still_delivers(): void
    {
        $w = $this->pendingWarning();
        $this->report($w, 'retry')->assertOk();

        $this->report($w, 'delivered')->assertOk()->assertExactJson(['warning_id' => $w->id, 'state' => 'delivered']);
        $this->assertNull($w->fresh()->next_attempt_at);
    }

    public function test_unknown_or_malformed_ids_are_404_and_bad_outcomes_422(): void
    {
        $w = $this->pendingWarning();

        $this->report(999999, 'delivered')->assertStatus(404)->assertJson(['code' => 'warning_not_found']);
        $this->signed('POST', '/api/points/expiry-warnings/abc/result', ['outcome' => 'delivered'])->assertStatus(404)->assertJson(['code' => 'warning_not_found']);

        foreach ([['outcome' => 'sent'], ['outcome' => ''], ['outcome' => 5], [], ['other' => 'delivered']] as $body) {
            $this->signed('POST', "/api/points/expiry-warnings/{$w->id}/result", $body)->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        }
        $this->assertSame(ExpiryWarning::PENDING, $w->fresh()->status);
    }

    public function test_results_never_touch_the_ledger(): void
    {
        $w = $this->pendingWarning();
        $before = DB::table('ledger_entries')->count();

        $this->report($w, 'retry');
        $this->report($w, 'delivered');

        $this->assertSame($before, DB::table('ledger_entries')->count());
    }

    // ---- the Notify push command ----------------------------------------------------------------------------

    public function test_the_notify_command_does_nothing_unless_the_mode_is_notify(): void
    {
        Http::fake();
        config(['services.notify.url' => 'https://notify.example.test/api/ingest/events', 'services.notify.secret' => 'test-secret-not-real']);
        Log::spy();
        $this->lotLapsingIn(10);

        foreach (['infodot', 'none'] as $mode) {
            config(['points.expiry_delivery' => $mode]);
            $this->artisan('points:warn-expiring')->assertSuccessful();
        }

        Http::assertNothingSent();
        $this->assertSame(0, ExpiryWarning::count(), 'the command does not even queue warnings outside notify mode');
        Log::shouldHaveReceived('info')->twice();
    }

    public function test_the_default_mode_is_infodot(): void
    {
        $this->assertSame('infodot', (require base_path('config/points.php'))['expiry_delivery']);
        $this->assertFalse(app(NotifyClient::class)->enabled() && false);
    }
}
