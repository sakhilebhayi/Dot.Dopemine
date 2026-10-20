<?php

namespace Tests\Feature\Points;

use App\Models\Contribution;
use App\Models\ExpiryWarning;
use App\Models\LedgerEntry;
use App\Models\RewardRule;
use App\Services\Notify\NotifyClient;
use App\Services\Points\ContributionTenantRecipients;
use App\Services\Points\ExpiryWarningRecipients;
use App\Services\Points\PointsLedger;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpiryWarningDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://notify.example.test/api/ingest/events';

    private const SECRET = 'test-secret-not-real';

    private const COMPANY = '11111111-2222-4333-8444-555555555555';

    private PointsLedger $ledger;

    private AddressBook $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(PointsLedger::class);
        $this->book = new AddressBook;

        config(['services.notify.url' => self::URL, 'services.notify.secret' => self::SECRET, 'services.notify.platform' => 'dopemine']);

        $this->app->instance(ExpiryWarningRecipients::class, $this->book);
    }

    /** A person with an award that lapses in $days days (the award is made far enough back for that). */
    private function personWithLotLapsingIn(int $days, string $label = 'a'): array
    {
        $user = (string) Str::uuid();
        $this->book->addresses[$user] = "person-{$label}@example.test";

        $entry = $this->ledger->award($user, RewardRule::current('infodot.answer.published'), "infodot:answer:{$label}", "evt-{$label}-".Str::random(5))->entry;
        DB::table('ledger_entries')->where('id', $entry->id)->update(['expires_at' => now()->addDays($days)]); // test setup only: the model refuses edits

        return [$user, $entry->fresh()];
    }

    public function test_it_sends_one_signed_notification_per_warning_with_only_the_necessary_data(): void
    {
        Http::fake(['*' => Http::response(['id' => 7, 'status' => 'routed'], 202)]);
        $this->travelTo(now()->startOfSecond());
        [$user, $lot] = $this->personWithLotLapsingIn(10);

        $this->artisan('points:warn-expiring')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($user, $lot) {
            $body = $request->body();
            $payload = json_decode($body, true);
            $timestamp = $request->header('X-InfoDot-Timestamp')[0];
            $signature = $request->header('X-InfoDot-Signature')[0];

            $this->assertSame(self::URL, $request->url());
            $this->assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET), $signature, 'HMAC-SHA256 over "{timestamp}.{raw body}" with the platform secret');
            $this->assertSame((string) now()->timestamp, $timestamp);
            $this->assertEqualsCanonicalizing(['platform', 'company_uuid', 'event', 'recipient', 'data', 'idempotency_key'], array_keys($payload));
            $this->assertSame('dopemine', $payload['platform']);
            $this->assertSame(self::COMPANY, $payload['company_uuid']);
            $this->assertSame('points.expiring', $payload['event']);
            $this->assertSame(['email' => 'person-a@example.test'], $payload['recipient']);
            $this->assertSame(['user_uuid' => $user, 'points' => 5, 'expires_on' => $lot->expires_at->toDateString()], $payload['data'], 'user uuid, points about to expire, expiry date and nothing else');
            $this->assertSame('points.expiring:warning-'.ExpiryWarning::sole()->id, $payload['idempotency_key']);
            $this->assertStringNotContainsString('infodot:answer', $body, 'no contribution reference leaves Dopemine');
            $this->assertStringNotContainsString(self::SECRET, $body);

            return true;
        });

        $warning = ExpiryWarning::sole();
        $this->assertSame(['delivered', 1, 'routed'], [$warning->status, $warning->attempts, $warning->notify_status]);
        $this->assertNotNull($warning->delivered_at);
    }

    public function test_the_signature_timestamp_is_taken_at_send_time_so_a_retry_is_never_stale(): void
    {
        Http::fakeSequence()->push([], 503)->push(['status' => 'routed'], 202);
        $this->travelTo(now()->startOfSecond());
        $this->personWithLotLapsingIn(10);

        $this->artisan('points:warn-expiring');
        $this->travel(2)->hours();
        $this->artisan('points:warn-expiring');

        $stamps = [];
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use (&$stamps) {
            $stamps[] = (int) $request->header('X-InfoDot-Timestamp')[0];

            return true;
        });
        $this->assertGreaterThanOrEqual(7200, $stamps[1] - $stamps[0]);
    }

    public function test_a_warning_is_marked_delivered_only_after_a_2xx(): void
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 503)]);
        $this->personWithLotLapsingIn(10);

        $this->artisan('points:warn-expiring')->assertSuccessful();

        $warning = ExpiryWarning::sole();
        $this->assertSame(['pending', 1, 'http_503'], [$warning->status, $warning->attempts, $warning->last_error]);
        $this->assertNull($warning->delivered_at);
        $this->assertNotNull($warning->next_attempt_at);
    }

    public function test_a_second_run_does_not_send_anything_twice(): void
    {
        Http::fake(['*' => Http::response(['status' => 'routed'], 202)]);
        $this->personWithLotLapsingIn(10);

        $this->artisan('points:warn-expiring');
        $this->artisan('points:warn-expiring');
        $this->artisan('points:warn-expiring');

        Http::assertSentCount(1);
        $this->assertSame(1, ExpiryWarning::count());
    }

    public function test_a_lot_outside_the_window_is_not_warned_about_yet(): void
    {
        Http::fake();
        $this->personWithLotLapsingIn(90);

        $this->artisan('points:warn-expiring')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, ExpiryWarning::count());
    }

    public function test_partial_failure_delivers_the_rest_and_retries_only_the_failed_one(): void
    {
        $this->personWithLotLapsingIn(10, 'a');
        $this->personWithLotLapsingIn(11, 'b');
        $this->personWithLotLapsingIn(12, 'c');

        $bIsDown = true;
        Http::fake(function (Request $request) use (&$bIsDown) {
            return $bIsDown && str_contains($request->body(), 'person-b@') ? Http::response([], 503) : Http::response(['status' => 'routed'], 202);
        });

        $this->artisan('points:warn-expiring')->assertSuccessful();

        $this->assertSame(['delivered', 'pending', 'delivered'], ExpiryWarning::orderBy('id')->pluck('status')->all());
        Http::assertSentCount(3);

        // Not due again straight away ...
        $this->artisan('points:warn-expiring');
        Http::assertSentCount(3);

        // ... but after the wait only the failed one goes out, and this time it works.
        $bIsDown = false;
        $this->travel(61)->minutes();
        $this->artisan('points:warn-expiring')->assertSuccessful();

        Http::assertSentCount(4);
        $this->assertSame(['delivered', 'delivered', 'delivered'], ExpiryWarning::orderBy('id')->pluck('status')->all());
        $this->assertSame([1, 2, 1], ExpiryWarning::orderBy('id')->pluck('attempts')->all());
    }

    public function test_retries_back_off_and_are_given_up_after_the_last_attempt(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        $this->travelTo(now()->startOfSecond());
        $this->personWithLotLapsingIn(29);

        $waits = [];
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->artisan('points:warn-expiring');
            $warning = ExpiryWarning::sole();
            $this->assertSame($attempt, $warning->attempts);

            if ($warning->status === 'pending') {
                $waits[] = (int) now()->diffInMinutes($warning->next_attempt_at, true);
                $this->travelTo($warning->next_attempt_at);
            }
        }

        $this->assertSame([60, 120, 240, 480, 960], $waits, 'each wait is longer than the last');
        $warning = ExpiryWarning::sole();
        $this->assertSame(['failed', 'http_500'], [$warning->status, $warning->last_error]);
        $this->assertNull($warning->next_attempt_at);

        $this->travel(3)->days();
        $this->artisan('points:warn-expiring');
        Http::assertSentCount(6);
    }

    public function test_the_notify_event_id_is_the_same_on_every_retry(): void
    {
        Http::fakeSequence()->push([], 503)->push([], 503)->push(['status' => 'routed'], 202);
        $this->personWithLotLapsingIn(10);

        $this->artisan('points:warn-expiring');
        $this->travel(2)->hours();
        $this->artisan('points:warn-expiring');
        $this->travel(3)->hours();
        $this->artisan('points:warn-expiring');

        $keys = [];
        Http::assertSent(function (Request $request) use (&$keys) {
            $keys[] = json_decode($request->body(), true)['idempotency_key'];

            return true;
        });
        $this->assertCount(3, $keys);
        $this->assertCount(1, array_unique($keys), 'Notify collapses the retries into one message');
        $this->assertSame('points.expiring:warning-'.ExpiryWarning::sole()->id, $keys[0]);
    }

    public function test_a_rejected_payload_or_unknown_company_is_not_retried(): void
    {
        $this->personWithLotLapsingIn(10, 'a');
        $this->personWithLotLapsingIn(11, 'b');
        Http::fake(function (Request $request) {
            return str_contains($request->body(), 'person-a@') ? Http::response(['error' => 'not_found'], 404) : Http::response(['error' => 'invalid_request'], 422);
        });

        $this->artisan('points:warn-expiring')->assertSuccessful();
        $this->travel(2)->days();
        $this->artisan('points:warn-expiring');

        Http::assertSentCount(2);
        $this->assertSame(['failed', 'failed'], ExpiryWarning::orderBy('id')->pluck('status')->all());
        $this->assertSame(['http_404', 'http_422'], ExpiryWarning::orderBy('id')->pluck('last_error')->all());
    }

    public function test_a_refused_signature_stops_the_run_without_spending_attempts_or_giving_up(): void
    {
        $this->personWithLotLapsingIn(10, 'a');
        $this->personWithLotLapsingIn(11, 'b');
        $secretWrong = true;
        Http::fake(function () use (&$secretWrong) {
            return $secretWrong ? Http::response(['error' => 'invalid_request'], 401) : Http::response(['status' => 'routed'], 202);
        });

        $this->artisan('points:warn-expiring')->assertFailed();

        Http::assertSentCount(1);
        $this->assertSame(['pending', 'pending'], ExpiryWarning::orderBy('id')->pluck('status')->all());
        $this->assertSame([0, 0], ExpiryWarning::orderBy('id')->pluck('attempts')->all());

        // Once the secret is fixed, the very next run delivers both.
        $secretWrong = false;
        $this->artisan('points:warn-expiring')->assertSuccessful();
        $this->assertSame(['delivered', 'delivered'], ExpiryWarning::orderBy('id')->pluck('status')->all());
    }

    public function test_a_run_pauses_when_notify_keeps_failing_instead_of_hammering_it(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->personWithLotLapsingIn(10 + $i, (string) $i);
        }
        Http::fake(['*' => Http::response([], 503)]);

        $this->artisan('points:warn-expiring')->assertFailed();

        Http::assertSentCount(3);
        $this->assertSame(3, ExpiryWarning::where('attempts', 1)->count());
        $this->assertSame(3, ExpiryWarning::where('attempts', 0)->count(), 'the rest simply wait for a later run');
    }

    public function test_an_unreachable_notify_is_retried_not_lost(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->personWithLotLapsingIn(10);

        $this->artisan('points:warn-expiring')->assertSuccessful();

        $warning = ExpiryWarning::sole();
        $this->assertSame(['pending', 'unreachable', 1], [$warning->status, $warning->last_error, $warning->attempts]);
    }

    public function test_when_not_configured_it_does_nothing_and_says_so_once(): void
    {
        Http::fake();
        Log::spy();
        $this->personWithLotLapsingIn(10);

        foreach ([['url' => null, 'secret' => self::SECRET], ['url' => self::URL, 'secret' => ''], ['url' => '', 'secret' => null]] as $config) {
            config(['services.notify.url' => $config['url'], 'services.notify.secret' => $config['secret']]);

            $this->artisan('points:warn-expiring')->expectsOutputToContain('not configured')->assertSuccessful();
        }

        Http::assertNothingSent();
        $this->assertSame(0, ExpiryWarning::count(), 'not even a queue row: the lot is warned about later, once Notify is set up');
        Log::shouldHaveReceived('info')->withArgs(fn ($message) => str_contains($message, 'not configured'))->times(3); // once per run
    }

    public function test_a_lot_spent_or_lapsed_before_delivery_is_closed_without_sending(): void
    {
        [$user, $lot] = $this->personWithLotLapsingIn(10);
        Http::fake(['*' => Http::response([], 503)]);
        $this->artisan('points:warn-expiring');
        $this->assertSame('pending', ExpiryWarning::sole()->status);

        // The person spends everything before the retry.
        LedgerEntry::create(['user_uuid' => $user, 'type' => 'redeem', 'points' => -5, 'lot_id' => $lot->id, 'idempotency_key' => 'spend-1']);
        Http::fake(['*' => Http::response(['status' => 'routed'], 202)]);
        $this->travel(2)->hours();
        $this->artisan('points:warn-expiring');

        Http::assertNothingSent();
        $this->assertSame('moot', ExpiryWarning::sole()->status);
    }

    public function test_the_points_sent_are_what_is_left_at_send_time(): void
    {
        [$user, $lot] = $this->personWithLotLapsingIn(10);
        LedgerEntry::create(['user_uuid' => $user, 'type' => 'redeem', 'points' => -2, 'lot_id' => $lot->id, 'idempotency_key' => 'spend-1']);
        Http::fake(['*' => Http::response(['status' => 'routed'], 202)]);

        $this->artisan('points:warn-expiring');

        Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['data']['points'] === 3);
    }

    public function test_a_person_who_cannot_be_addressed_is_skipped_and_picked_up_once_they_can(): void
    {
        Http::fake(['*' => Http::response(['status' => 'routed'], 202)]);
        [$user] = $this->personWithLotLapsingIn(10);
        unset($this->book->addresses[$user]);

        $this->artisan('points:warn-expiring')->expectsOutputToContain('1 without an address')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame(0, ExpiryWarning::count());

        $this->book->addresses[$user] = 'now-known@example.test';
        $this->artisan('points:warn-expiring')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame('delivered', ExpiryWarning::sole()->status);
    }

    public function test_warnings_never_touch_the_ledger(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->personWithLotLapsingIn(10);
        $before = LedgerEntry::count();

        $this->artisan('points:warn-expiring');

        $this->assertSame($before, LedgerEntry::count());
        $this->assertSame(5, $this->ledger->balance(LedgerEntry::first()->user_uuid));
    }

    public function test_the_client_is_enabled_only_with_both_a_url_and_a_secret(): void
    {
        $client = app(NotifyClient::class);
        $this->assertTrue($client->enabled());

        config(['services.notify.secret' => '']);
        $this->assertFalse($client->enabled());

        config(['services.notify.secret' => self::SECRET, 'services.notify.url' => '']);
        $this->assertFalse($client->enabled());
    }

    public function test_the_default_recipient_lookup_needs_a_company_and_an_address_it_already_holds(): void
    {
        $this->app->bind(ExpiryWarningRecipients::class, ContributionTenantRecipients::class);
        $user = (string) Str::uuid();
        $lookup = fn () => (new ContributionTenantRecipients)->for($user);

        $this->assertNull($lookup(), 'no contribution, no company');

        Contribution::create(['platform' => 'infodot', 'ref' => 'infodot:answer:1', 'user_uuid' => $user, 'kind' => 'answer', 'tenant_uuid' => self::COMPANY]);
        $this->assertNull($lookup(), 'a company but no address Dopemine holds: nothing is guessed');

        Schema::table('users', fn ($table) => $table->uuid('uuid')->nullable());
        DB::table('users')->insert(['name' => 'P', 'email' => 'p@example.test', 'password' => 'x', 'uuid' => $user]);

        $this->assertSame(['company_uuid' => self::COMPANY, 'email' => 'p@example.test'], (new ContributionTenantRecipients)->for($user));
    }

    public function test_the_hourly_schedule_is_registered_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'points:warn-expiring'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
    }
}

/** Stand-in for the recipient lookup: only people put in `addresses` can be addressed. */
class AddressBook implements ExpiryWarningRecipients
{
    /** @var array<string, string> user uuid => email */
    public array $addresses = [];

    public function for(string $userUuid): ?array
    {
        return isset($this->addresses[$userUuid]) ? ['company_uuid' => '11111111-2222-4333-8444-555555555555', 'email' => $this->addresses[$userUuid]] : null;
    }
}
