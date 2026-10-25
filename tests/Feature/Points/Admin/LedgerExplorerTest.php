<?php

namespace Tests\Feature\Points\Admin;

use App\Models\LedgerEntry;
use App\Models\Reward;
use App\Models\RewardRule;
use App\Models\User;
use App\Services\Points\PointsLedger;
use App\Services\Points\RewardRedemptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LedgerExplorerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $alice;

    private PointsLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->alice = (string) Str::uuid();
        $this->ledger = app(PointsLedger::class);
    }

    private function search(string $user)
    {
        return $this->actingAs($this->admin)->get(route('admin.points.ledger', ['user' => $user]));
    }

    public function test_it_shows_the_balance_the_lots_with_expiry_and_each_entry_with_its_rule_version(): void
    {
        $this->ledger->award($this->alice, RewardRule::current('infodot.answer.published'), 'infodot:answer:1', 'e1');
        RewardRule::revise('infodot.answer.published', ['points' => 8]);
        $this->ledger->award($this->alice, RewardRule::current('infodot.answer.published'), 'infodot:answer:2', 'e2');
        $reward = Reward::create(['key' => 'm.hour', 'title' => 'Tutor hour', 'kind' => 'marketplace', 'price_points' => 6, 'active' => true]);
        app(RewardRedemptions::class)->redeem($this->alice, $reward, 'r1');

        $page = $this->search($this->alice)->assertOk();

        $page->assertSee('7 ', false)->assertSee('points they can spend now');
        $page->assertSee('infodot.answer.published v1')->assertSee('infodot.answer.published v2');
        $page->assertSee('infodot:answer:1')->assertSee('infodot:answer:2');
        $page->assertSee(now()->addMonths(12)->utc()->format('Y-m-d'));
        $page->assertSee('via lot #', false)->assertSee('Redeemed: Tutor hour');
        $page->assertSee('Tutor hour')->assertSee('Pending');
        $this->assertSame(7, $page->viewData('balance'));
    }

    public function test_it_lists_every_lot_including_spent_and_expired_ones_with_what_is_left(): void
    {
        $this->ledger->award($this->alice, RewardRule::current('infodot.answer.published'), 'infodot:answer:1', 'e1');
        $this->travelTo(now()->addMonths(13));
        $this->ledger->award($this->alice, RewardRule::current('infodot.answer.published'), 'infodot:answer:2', 'e2');

        $lots = $this->search($this->alice)->viewData('lots');

        $this->assertCount(2, $lots);
        $this->assertSame([5, 5], $lots->pluck('remaining')->all(), 'the old lot still shows its 5 until the expiry job writes its entry');
        $this->search($this->alice)->assertSee('Past expiry')->assertSee('Open');

        $this->ledger->expireDue();
        $page = $this->search($this->alice);
        $this->assertSame([5, 0], $page->viewData('lots')->pluck('remaining')->all());
        $page->assertSee('Closed')->assertSee('Expired 12 months after it was granted.');
        $this->assertSame(5, $page->viewData('balance'));
    }

    public function test_a_reversal_shows_what_could_not_be_recovered(): void
    {
        $award = $this->ledger->award($this->alice, RewardRule::current('infodot.answer.accepted'), 'infodot:answer:1', 'e1')->entry;
        $reward = Reward::create(['key' => 'm.hour', 'title' => 'Tutor hour', 'kind' => 'marketplace', 'price_points' => 4, 'active' => true]);
        app(RewardRedemptions::class)->redeem($this->alice, $reward, 'r1');
        $this->ledger->reverse($award, 'Duplicate answer.', 'rev-1');

        $this->search($this->alice)->assertSee('Duplicate answer.')->assertSee('4 already spent');
    }

    public function test_it_only_shows_the_person_searched_for(): void
    {
        $bob = (string) Str::uuid();
        $this->ledger->award($this->alice, RewardRule::current('infodot.answer.published'), 'infodot:answer:A', 'ea');
        $this->ledger->award($bob, RewardRule::current('infodot.answer.accepted'), 'infodot:answer:B', 'eb');

        $page = $this->search($this->alice)->assertOk();

        $page->assertSee('infodot:answer:A')->assertDontSee('infodot:answer:B')->assertDontSee($bob);
        $this->assertSame(5, $page->viewData('balance'));
    }

    public function test_a_person_with_nothing_and_a_malformed_search_get_plain_answers(): void
    {
        $this->search((string) Str::uuid())->assertOk()->assertSee('Nothing in the ledger for');
        $this->search('not-a-uuid')->assertOk()->assertSee('That is not a uuid');
        $this->search("'; DROP TABLE ledger_entries; --")->assertOk()->assertSee('That is not a uuid');

        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_the_search_is_case_and_space_tolerant(): void
    {
        $this->ledger->award($this->alice, RewardRule::current('infodot.answer.published'), 'infodot:answer:1', 'e1');

        $this->search('  '.strtoupper($this->alice).' ')->assertOk()->assertSee('infodot:answer:1');
    }

    public function test_with_no_search_it_shows_the_latest_real_entries_or_says_the_ledger_is_empty(): void
    {
        $this->actingAs($this->admin)->get(route('admin.points.ledger'))->assertOk()->assertSee('The ledger is empty');

        $this->ledger->award($this->alice, RewardRule::current('infodot.answer.published'), 'infodot:answer:1', 'e1');

        $this->get(route('admin.points.ledger'))->assertOk()->assertSee($this->alice)->assertDontSee('The ledger is empty');
    }

    public function test_long_histories_are_paged(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            LedgerEntry::create(['user_uuid' => $this->alice, 'type' => 'award', 'points' => 1, 'rule_key' => 'infodot.answer.published', 'rule_version' => 1, 'contribution_ref' => "infodot:answer:{$i}", 'idempotency_key' => "bulk-{$i}", 'expires_at' => now()->addYear()]);
        }

        $this->search($this->alice)->assertSee('infodot:answer:55')->assertDontSee('infodot:answer:1<', false)->assertSee('Older');
        $this->actingAs($this->admin)->get(route('admin.points.ledger', ['user' => $this->alice, 'page' => 2]))->assertSee('infodot:answer:1<', false)->assertSee('Newer');
    }

    public function test_it_is_read_only(): void
    {
        $this->ledger->award($this->alice, RewardRule::current('infodot.answer.published'), 'infodot:answer:1', 'e1');
        $before = LedgerEntry::count();

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->actingAs($this->admin)->{$method}(route('admin.points.ledger', ['user' => $this->alice]))->assertStatus(405);
        }

        $this->search($this->alice)->assertOk();
        $this->assertSame($before, LedgerEntry::count());
    }

    public function test_a_non_administrator_cannot_look_anyone_up(): void
    {
        $this->ledger->award($this->alice, RewardRule::current('infodot.answer.published'), 'infodot:answer:1', 'e1');

        $this->actingAs(User::factory()->create())->get(route('admin.points.ledger', ['user' => $this->alice]))->assertForbidden();
    }
}
