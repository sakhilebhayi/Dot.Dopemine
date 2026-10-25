<?php

namespace Tests\Feature\Points\Api;

use App\Models\LedgerEntry;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\RewardRule;
use App\Services\Points\PointsLedger;
use App\Services\Points\RewardRedemptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PointsRedemptionApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'infodot-ingest-secret-for-tests';

    private string $alice;

    private string $bob;

    private PointsLedger $ledger;

    private Reward $credit;

    protected function setUp(): void
    {
        parent::setUp();
        config(['points.ingest.secrets.infodot' => self::SECRET]);
        $this->alice = (string) Str::uuid();
        $this->bob = (string) Str::uuid();
        $this->ledger = app(PointsLedger::class);
        $this->credit = Reward::create(['key' => 'billing.credit-r50', 'title' => 'R50 billing credit', 'description' => 'Off your next invoice.', 'kind' => 'billing_credit', 'price_points' => 20, 'active' => true]);
    }

    /** Give a person points as separate lots (so FIFO is observable): each accepted answer is its own lot of 10 points. */
    private function fund(string $uuid, int $awards): void
    {
        for ($i = 1; $i <= $awards; $i++) {
            $this->ledger->award($uuid, RewardRule::current('infodot.answer.accepted'), "infodot:answer:{$uuid}:{$i}", "evt-{$uuid}-{$i}");
        }
    }

    /** @param array<string, string> $headers */
    private function signed(string $method, string $uri, ?array $json = null, ?string $secret = self::SECRET, ?int $at = null, array $headers = [])
    {
        $timestamp = $at ?? now()->timestamp;
        $body = $json === null ? '' : json_encode($json);

        return $this->call($method, $uri, [], [], [], $headers + [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? 'x'),
        ], $body);
    }

    private function redeem(string $uuid, int $rewardId, string $key = 'request-0001', ?string $secret = self::SECRET)
    {
        return $this->signed('POST', '/api/points/redemptions', ['user_uuid' => $uuid, 'reward_id' => $rewardId, 'idempotency_key' => $key], $secret);
    }

    // ---- signature on every endpoint ------------------------------------------------------------------------

    public function test_every_endpoint_requires_a_valid_fresh_signature(): void
    {
        $body = ['user_uuid' => $this->alice, 'reward_id' => $this->credit->id, 'idempotency_key' => 'request-0001'];
        $calls = [
            ['GET', '/api/points/rewards', null],
            ['GET', '/api/points/redemptions?user_uuid='.$this->alice, null],
            ['POST', '/api/points/redemptions', $body],
        ];

        foreach ($calls as [$method, $uri, $json]) {
            // Missing.
            $this->json($method, $uri, $json ?? [])->assertUnauthorized()->assertJson(['code' => 'invalid_signature']);
            // Wrong secret.
            $this->signed($method, $uri, $json, 'not-the-secret')->assertUnauthorized();
            // Right secret, stale timestamp (and a future one).
            $this->signed($method, $uri, $json, self::SECRET, now()->timestamp - 301)->assertUnauthorized();
            $this->signed($method, $uri, $json, self::SECRET, now()->timestamp + 301)->assertUnauthorized();
        }

        $this->assertSame(0, Redemption::count());
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_a_body_changed_after_signing_is_refused(): void
    {
        $this->fund($this->alice, 4);
        $timestamp = now()->timestamp;
        $signedBody = json_encode(['user_uuid' => $this->bob, 'reward_id' => $this->credit->id, 'idempotency_key' => 'request-0001']);
        $sentBody = json_encode(['user_uuid' => $this->alice, 'reward_id' => $this->credit->id, 'idempotency_key' => 'request-0001']);

        $this->call('POST', '/api/points/redemptions', [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$signedBody, self::SECRET),
        ], $sentBody)->assertUnauthorized();

        $this->assertSame(0, Redemption::count());
    }

    public function test_the_summary_route_still_works_next_to_the_new_ones(): void
    {
        $this->signed('GET', '/api/points/'.$this->alice)->assertOk()->assertJson(['balance' => 0]);
    }

    // ---- catalogue ------------------------------------------------------------------------------------------

    public function test_the_catalogue_lists_active_redeemable_rewards_and_hides_internals(): void
    {
        Reward::create(['key' => 'cap.extra', 'title' => 'Extra capacity', 'kind' => 'capacity', 'price_points' => 5, 'active' => true, 'stock' => 7, 'monthly_cap' => 50, 'params' => ['secret' => 'x']]);
        Reward::create(['key' => 'old.thing', 'title' => 'Retired', 'kind' => 'marketplace', 'price_points' => 1, 'active' => false]);
        Reward::create(['key' => 'badge.gold', 'title' => 'Gold badge', 'kind' => 'recognition', 'price_points' => 1, 'active' => true]);

        $json = $this->signed('GET', '/api/points/rewards')->assertOk()->json();

        $this->assertSame(['Extra capacity', 'R50 billing credit'], array_column($json, 'name'));
        $this->assertSame(['id', 'name', 'kind', 'description', 'cost_points', 'available'], array_keys($json[1]));
        $this->assertSame(['id', 'name', 'kind', 'description', 'cost_points', 'available'], array_keys($json[0]));
        $this->assertSame(['id' => $this->credit->id, 'name' => 'R50 billing credit', 'kind' => 'billing_credit', 'description' => 'Off your next invoice.', 'cost_points' => 20, 'available' => true], $json[1]);
        $this->assertStringNotContainsString('stock', json_encode($json));
        $this->assertStringNotContainsString('secret', json_encode($json));
    }

    public function test_an_out_of_stock_reward_is_listed_as_unavailable(): void
    {
        Reward::create(['key' => 'gone', 'title' => 'Gone', 'kind' => 'marketplace', 'price_points' => 9, 'active' => true, 'stock' => 0]);
        Reward::create(['key' => 'last', 'title' => 'Last one', 'kind' => 'marketplace', 'price_points' => 10, 'active' => true, 'stock' => 1]);

        $by = collect($this->signed('GET', '/api/points/rewards')->assertOk()->json())->keyBy('name');

        $this->assertFalse($by['Gone']['available']);
        $this->assertTrue($by['Last one']['available']);
    }

    public function test_a_reward_at_its_monthly_limit_is_unavailable(): void
    {
        $capped = Reward::create(['key' => 'capped', 'title' => 'Capped', 'kind' => 'marketplace', 'price_points' => 2, 'active' => true, 'monthly_cap' => 1]);
        $this->fund($this->alice, 2);
        app(RewardRedemptions::class)->redeem($this->alice, $capped, 'someone-took-it');

        $by = collect($this->signed('GET', '/api/points/rewards')->json())->keyBy('name');
        $this->assertFalse($by['Capped']['available']);

        $this->fund($this->bob, 2);
        $this->redeem($this->bob, $capped->id)->assertStatus(409)->assertJson(['code' => 'monthly_limit_reached']);
    }

    // ---- redeeming ------------------------------------------------------------------------------------------

    public function test_redeeming_spends_the_oldest_lots_first_and_returns_the_new_balance(): void
    {
        $this->fund($this->alice, 5); // five lots of 10 = 50
        $lots = $this->ledger->openLots($this->alice);
        $this->assertCount(5, $lots);

        $response = $this->redeem($this->alice, $this->credit->id)->assertCreated();

        $json = $response->json();
        $this->assertSame('pending', $json['status']);
        $this->assertSame(['id' => $this->credit->id, 'name' => 'R50 billing credit', 'kind' => 'billing_credit'], $json['reward']);
        $this->assertSame(20, $json['points_spent']);
        $this->assertSame(30, $json['balance_after']);
        $this->assertSame(Redemption::first()->uuid, $json['redemption_id']);

        // The two oldest lots drained, the rest untouched: FIFO by expiry.
        $drawn = LedgerEntry::where('type', LedgerEntry::REDEEM)->pluck('lot_id')->all();
        $this->assertEqualsCanonicalizing($lots->take(2)->pluck('id')->all(), $drawn);
        $this->assertSame(-20, (int) LedgerEntry::where('type', LedgerEntry::REDEEM)->sum('points'));
    }

    public function test_insufficient_points_is_a_clean_conflict_with_nothing_written(): void
    {
        Reward::whereKey($this->credit->id)->update(['stock' => 3]);
        $this->fund($this->alice, 1); // 10 < 20

        $this->redeem($this->alice, $this->credit->id)->assertStatus(409)->assertJson(['code' => 'insufficient_points', 'points_needed' => 20, 'balance' => 10]);

        $this->assertSame(0, Redemption::count());
        $this->assertSame(0, LedgerEntry::where('type', LedgerEntry::REDEEM)->count());
        $this->assertSame(3, Reward::find($this->credit->id)->stock, 'the claimed unit went back with the rolled-back transaction');
        $this->assertSame(10, $this->ledger->balance($this->alice));
    }

    public function test_out_of_stock_is_a_conflict_and_takes_no_points(): void
    {
        Reward::whereKey($this->credit->id)->update(['stock' => 0]);
        $this->fund($this->alice, 3);

        $this->redeem($this->alice, $this->credit->id)->assertStatus(409)->assertJson(['code' => 'out_of_stock']);

        $this->assertSame(0, Redemption::count());
        $this->assertSame(30, $this->ledger->balance($this->alice));
    }

    public function test_the_last_unit_goes_to_one_person_only(): void
    {
        Reward::whereKey($this->credit->id)->update(['stock' => 1]);
        $this->fund($this->alice, 2);
        $this->fund($this->bob, 2);

        $this->redeem($this->alice, $this->credit->id)->assertCreated();
        $this->redeem($this->bob, $this->credit->id)->assertStatus(409)->assertJson(['code' => 'out_of_stock']);
        $this->assertSame(20, $this->ledger->balance($this->bob));
    }

    public function test_an_unknown_retired_or_recognition_reward_is_unavailable(): void
    {
        $retired = Reward::create(['key' => 'old', 'title' => 'Old', 'kind' => 'marketplace', 'price_points' => 1, 'active' => false]);
        $badge = Reward::create(['key' => 'badge', 'title' => 'Badge', 'kind' => 'recognition', 'price_points' => 1, 'active' => true]);
        $this->fund($this->alice, 1);

        foreach ([$retired->id, $badge->id, 99999] as $i => $id) {
            $this->redeem($this->alice, $id, 'request-000'.$i)->assertNotFound()->assertJson(['code' => 'reward_unavailable']);
        }

        $this->assertSame(0, Redemption::count());
        $this->assertSame(10, $this->ledger->balance($this->alice));
    }

    public function test_replaying_the_same_key_returns_the_original_without_a_second_spend(): void
    {
        $this->fund($this->alice, 3);

        $first = $this->redeem($this->alice, $this->credit->id, 'same-key-1')->assertCreated()->json();
        $again = $this->redeem($this->alice, $this->credit->id, 'same-key-1')->assertOk()->json();

        $this->assertSame($first['redemption_id'], $again['redemption_id']);
        $this->assertSame(20, $again['points_spent']);
        $this->assertSame(1, Redemption::count());
        $this->assertSame(10, $this->ledger->balance($this->alice));
        $this->assertSame(-20, (int) LedgerEntry::where('type', LedgerEntry::REDEEM)->sum('points'));
    }

    public function test_reusing_a_key_for_a_different_reward_is_refused(): void
    {
        $other = Reward::create(['key' => 'other', 'title' => 'Other', 'kind' => 'marketplace', 'price_points' => 1, 'active' => true]);
        $this->fund($this->alice, 5);

        $this->redeem($this->alice, $this->credit->id, 'same-key-1')->assertCreated();
        $this->redeem($this->alice, $other->id, 'same-key-1')->assertStatus(409)->assertJson(['code' => 'idempotency_key_reused']);

        $this->assertSame(1, Redemption::count());
    }

    public function test_one_persons_key_cannot_replay_or_collide_with_another_persons(): void
    {
        $this->fund($this->alice, 3);
        $this->fund($this->bob, 3);

        $a = $this->redeem($this->alice, $this->credit->id, 'shared-key-1')->assertCreated()->json();
        $b = $this->redeem($this->bob, $this->credit->id, 'shared-key-1')->assertCreated()->json();

        $this->assertNotSame($a['redemption_id'], $b['redemption_id']);
        $this->assertSame(10, $this->ledger->balance($this->alice));
        $this->assertSame(10, $this->ledger->balance($this->bob));
        $this->assertSame(2, Redemption::count());
    }

    public function test_validation_errors_are_422_with_a_stable_code(): void
    {
        $bad = [
            [],
            ['user_uuid' => 'nope', 'reward_id' => 1, 'idempotency_key' => 'request-0001'],
            ['user_uuid' => $this->alice, 'reward_id' => 'abc', 'idempotency_key' => 'request-0001'],
            ['user_uuid' => $this->alice, 'reward_id' => 1, 'idempotency_key' => 'short'],
            ['user_uuid' => $this->alice, 'reward_id' => 1, 'idempotency_key' => 'has spaces and !'],
            ['user_uuid' => strtoupper($this->alice), 'reward_id' => 1, 'idempotency_key' => 'request-0001'],
        ];

        foreach ($bad as $body) {
            $this->signed('POST', '/api/points/redemptions', $body === [] ? ['x' => 1] : $body)->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        }

        $this->signed('GET', '/api/points/redemptions')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->signed('GET', '/api/points/redemptions?user_uuid=nope')->assertStatus(422);
    }

    public function test_redemptions_are_rate_limited_per_person(): void
    {
        config(['points.redemptions.rate_limit' => 3]);
        $cheap = Reward::create(['key' => 'cheap', 'title' => 'Cheap', 'kind' => 'marketplace', 'price_points' => 1, 'active' => true]);
        $this->fund($this->alice, 1);
        $this->fund($this->bob, 1);

        for ($i = 1; $i <= 3; $i++) {
            $this->redeem($this->alice, $cheap->id, 'request-000'.$i)->assertCreated();
        }

        $limited = $this->redeem($this->alice, $cheap->id, 'request-0004')->assertStatus(429)->assertJson(['code' => 'rate_limited']);
        $this->assertNotNull($limited->headers->get('Retry-After'));
        $this->assertSame(3, Redemption::where('user_uuid', $this->alice)->count());

        // A replay is free, and another person is not affected.
        $this->redeem($this->alice, $cheap->id, 'request-0001')->assertOk();
        $this->redeem($this->bob, $cheap->id, 'request-0001')->assertCreated();
    }

    public function test_the_default_limit_is_ten_per_hour(): void
    {
        $this->assertSame(10, config('points.redemptions.rate_limit'));
    }

    // ---- listing --------------------------------------------------------------------------------------------

    public function test_the_list_is_the_persons_own_newest_first(): void
    {
        $cheap = Reward::create(['key' => 'cheap', 'title' => 'Cheap', 'kind' => 'marketplace', 'price_points' => 1, 'active' => true]);
        $this->fund($this->alice, 6);
        $this->fund($this->bob, 6);

        $this->travelTo(now()->subDay());
        $this->redeem($this->alice, $this->credit->id, 'request-0001')->assertCreated();
        $this->travelBack();
        $this->redeem($this->alice, $cheap->id, 'request-0002')->assertCreated();
        $this->redeem($this->bob, $cheap->id, 'request-0003')->assertCreated();

        $json = $this->signed('GET', '/api/points/redemptions?user_uuid='.$this->alice)->assertOk()->json();

        $this->assertCount(2, $json);
        $this->assertSame(['Cheap', 'R50 billing credit'], array_map(fn ($r) => $r['reward']['name'], $json));
        $this->assertSame(['redemption_id', 'reward', 'points_spent', 'status', 'requested_at', 'resolved_at', 'points_returned', 'message'], array_keys($json[0]));
        $this->assertSame(['name', 'kind'], array_keys($json[0]['reward']));
        $this->assertSame('pending', $json[0]['status']);
        $this->assertNull($json[0]['resolved_at']);
        $this->assertFalse($json[0]['points_returned']);
        $bobs = Redemption::where('user_uuid', $this->bob)->value('uuid');
        $this->assertNotContains($bobs, array_column($json, 'redemption_id'));

        $this->assertSame([], $this->signed('GET', '/api/points/redemptions?user_uuid='.(string) Str::uuid())->assertOk()->json());
    }

    public function test_a_fulfilled_redemption_shows_when_it_was_resolved(): void
    {
        $this->fund($this->alice, 5);
        $this->redeem($this->alice, $this->credit->id)->assertCreated();
        app(RewardRedemptions::class)->fulfil(Redemption::first(), 'INV-1', 'operator@example.test');

        $row = $this->signed('GET', '/api/points/redemptions?user_uuid='.$this->alice)->json()[0];

        $this->assertSame('fulfilled', $row['status']);
        $this->assertNotNull($row['resolved_at']);
        $this->assertFalse($row['points_returned']);
    }

    public function test_a_failed_or_cancelled_redemption_says_the_points_came_back(): void
    {
        $cheap = Reward::create(['key' => 'cheap', 'title' => 'Cheap', 'kind' => 'marketplace', 'price_points' => 10, 'active' => true]);
        $this->fund($this->alice, 3);
        $this->redeem($this->alice, $this->credit->id, 'request-0001')->assertCreated();
        $this->redeem($this->alice, $cheap->id, 'request-0002')->assertCreated();
        $this->assertSame(0, $this->ledger->balance($this->alice));

        $redemptions = app(RewardRedemptions::class);
        $redemptions->fail(Redemption::where('reward_id', $this->credit->id)->first(), 'Operator private note: card declined');
        $redemptions->fail(Redemption::where('reward_id', $cheap->id)->first(), 'Changed my mind', cancelled: true);

        $response = $this->signed('GET', '/api/points/redemptions?user_uuid='.$this->alice)->assertOk();
        $rows = collect($response->json())->keyBy('status');

        foreach (['failed', 'cancelled'] as $status) {
            $this->assertTrue($rows[$status]['points_returned']);
            $this->assertSame('Your points were returned.', $rows[$status]['message']);
            $this->assertNotNull($rows[$status]['resolved_at']);
        }
        $this->assertStringNotContainsString('private note', $response->getContent(), 'operator notes never leave');
        $this->assertSame(30, $this->ledger->balance($this->alice));
    }

    // ---- summary extension ----------------------------------------------------------------------------------

    public function test_the_summary_now_also_says_how_much_lapses_first(): void
    {
        $this->fund($this->alice, 2);

        $json = $this->signed('GET', '/api/points/'.$this->alice)->assertOk()->json();

        $this->assertSame(20, $json['balance']);
        $this->assertSame(10, $json['next_expiry_points']);
        $this->assertNotNull($json['next_expiry']);

        $empty = $this->signed('GET', '/api/points/'.$this->bob)->assertOk()->json();
        $this->assertNull($empty['next_expiry_points']);
    }
}
