<?php

namespace Tests\Feature\Points;

use App\Models\RewardRule;
use App\Services\Points\PointsLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PointsSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'infodot-ingest-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();
        config(['points.ingest.secrets.infodot' => self::SECRET]);
    }

    private function signedGet(string $uuid, ?string $secret = self::SECRET)
    {
        $timestamp = now()->timestamp;

        return $this->call('GET', '/api/points/'.$uuid, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INFODOT_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INFODOT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.', $secret ?? 'x'),
        ]);
    }

    public function test_an_unsigned_or_wrongly_signed_read_is_refused(): void
    {
        $uuid = (string) Str::uuid();

        $this->getJson('/api/points/'.$uuid)->assertUnauthorized();
        $this->signedGet($uuid, 'wrong')->assertUnauthorized();
    }

    public function test_a_person_with_no_points_gets_a_clean_empty_summary(): void
    {
        $this->signedGet((string) Str::uuid())->assertOk()->assertJson(['balance' => 0, 'total_earned' => 0, 'expiring_soon' => null, 'next_expiry' => null, 'history' => []]);
    }

    public function test_the_summary_shows_balance_history_and_lapsing_points_for_that_person_only(): void
    {
        $ledger = app(PointsLedger::class);
        $alice = (string) Str::uuid();
        $bob = (string) Str::uuid();
        $rule = RewardRule::current('infodot.answer.published');
        $ledger->award($alice, $rule, 'infodot:answer:1', 'k1');
        $ledger->award($bob, $rule, 'infodot:answer:2', 'k2');
        $ledger->redeem($alice, 2, 'spend-1', 'Redeemed: test');

        $json = $this->signedGet($alice)->assertOk()->json();

        $this->assertSame(3, $json['balance']);
        $this->assertSame(5, $json['total_earned']);
        $this->assertNull($json['expiring_soon']);
        $this->assertCount(2, $json['history']);
        $this->assertSame(['redeem', 'award'], array_column($json['history'], 'type'));
        $this->assertSame(1, $json['history'][1]['rule_version']);
        $this->assertStringContainsString('Substantive', $json['history'][1]['label']);
        $this->assertStringNotContainsString($bob, json_encode($json));
    }

    public function test_points_about_to_lapse_are_reported_once_inside_thirty_days(): void
    {
        $ledger = app(PointsLedger::class);
        $alice = (string) Str::uuid();
        $ledger->award($alice, RewardRule::current('infodot.answer.published'), 'infodot:answer:1', 'k1');

        $this->travelTo(now()->addMonths(11)->addDays(10));

        $json = $this->signedGet($alice)->assertOk()->json();
        $this->assertSame(5, $json['expiring_soon']['points']);
        $this->assertNotNull($json['expiring_soon']['first_at']);
    }

    public function test_a_malformed_user_id_is_refused(): void
    {
        $this->signedGet('not-a-uuid')->assertStatus(422);
    }
}
