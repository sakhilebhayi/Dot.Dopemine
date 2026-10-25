<?php

namespace Tests\Feature\Points\Admin;

use App\Exceptions\Points\RedemptionRefused;
use App\Models\AdminAudit;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\RewardRule;
use App\Models\User;
use App\Services\Points\PointsLedger;
use App\Services\Points\RewardRedemptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RewardsCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    /** @param  array<string, mixed>  $overrides */
    private function create(array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.points.rewards.store'), array_merge([
            'key' => 'billing.credit-r50',
            'title' => 'R50 billing credit',
            'kind' => 'billing_credit',
            'price_points' => 25,
            'stock' => '',
            'monthly_cap' => '',
            'description' => 'R50 off a Dot invoice.',
            'reason' => 'First item on the price list.',
        ], $overrides));
    }

    private function fund(string $user, int $points): void
    {
        // The accepted-answer rule pays 10 a time, so fund in tens.
        for ($i = 0; $i < intdiv($points, 10); $i++) {
            app(PointsLedger::class)->award($user, RewardRule::current('infodot.answer.accepted'), 'infodot:answer:'.Str::random(8), 'fund-'.Str::random(12));
        }
    }

    public function test_an_administrator_adds_a_reward_and_it_is_audited(): void
    {
        $this->create(['stock' => 10, 'monthly_cap' => 4])->assertRedirect(route('admin.points.rewards'));

        $reward = Reward::sole();
        $this->assertSame(['billing.credit-r50', 'billing_credit', 25, 10, 4, true], [$reward->key, $reward->kind, $reward->price_points, $reward->stock, $reward->monthly_cap, $reward->active]);

        $audit = AdminAudit::sole();
        $this->assertSame(['reward.created', 'billing.credit-r50', 'First item on the price list.'], [$audit->action, $audit->subject_key, $audit->reason]);
        $this->assertSame($this->admin->id, $audit->actor_id);

        $this->get(route('admin.points.rewards'))->assertOk()->assertSee('R50 billing credit')->assertSee('25 points')->assertSee('10 left');
    }

    public function test_an_empty_stock_means_unlimited(): void
    {
        $this->create();

        $this->assertNull(Reward::sole()->stock);
        $this->get(route('admin.points.rewards'))->assertSee('Unlimited');
    }

    public function test_only_redeemable_kinds_can_be_listed_and_recognition_is_not_one_of_them(): void
    {
        foreach (['recognition', 'badge', 'cash', ''] as $kind) {
            $this->create(['kind' => $kind])->assertSessionHasErrors('kind');
        }

        $this->assertSame(0, Reward::count());

        $page = $this->get(route('admin.points.rewards'))->assertOk();
        $page->assertSee('Billing credit')->assertSee('Premium capacity')->assertSee('Marketplace item');
        $page->assertDontSee('<option value="recognition"', false);
        $this->assertSame(['billing_credit', 'capacity', 'marketplace'], Reward::KINDS);
    }

    public function test_bad_input_is_refused(): void
    {
        $this->create(['price_points' => 0])->assertSessionHasErrors('price_points');
        $this->create(['price_points' => -5])->assertSessionHasErrors('price_points');
        $this->create(['title' => ''])->assertSessionHasErrors('title');
        $this->create(['key' => 'Has Spaces!'])->assertSessionHasErrors('key');
        $this->create(['reason' => ''])->assertSessionHasErrors('reason');
        $this->create(['stock' => -1])->assertSessionHasErrors('stock');

        $this->create()->assertSessionHasNoErrors();
        $this->create()->assertSessionHasErrors('key');

        $this->assertSame(1, Reward::count());
    }

    public function test_editing_changes_the_price_and_keeps_past_redemptions_at_the_price_they_were_made(): void
    {
        $this->create();
        $reward = Reward::sole();
        $user = (string) Str::uuid();
        $this->fund($user, 30);
        $redemption = app(RewardRedemptions::class)->redeem($user, $reward, 'req-1');

        $this->actingAs($this->admin)->put(route('admin.points.rewards.update', $reward), [
            'title' => 'R50 billing credit', 'kind' => 'billing_credit', 'price_points' => 40, 'stock' => '', 'monthly_cap' => '',
            'description' => 'R50 off a Dot invoice.', 'reason' => 'Costs us more than we thought.',
        ])->assertRedirect(route('admin.points.rewards'));

        $this->assertSame(40, $reward->fresh()->price_points);
        $this->assertSame(25, $redemption->fresh()->points);

        $audit = AdminAudit::where('action', 'reward.updated')->sole();
        $this->assertSame([25, 40], [$audit->details['before']['price_points'], $audit->details['after']['price_points']]);
    }

    public function test_retiring_stops_redemption_but_leaves_waiting_redemptions_alone_and_is_reversible_and_idempotent(): void
    {
        $this->create();
        $reward = Reward::sole();
        $user = (string) Str::uuid();
        $this->fund($user, 60);
        $waiting = app(RewardRedemptions::class)->redeem($user, $reward, 'req-1');

        $this->actingAs($this->admin)->post(route('admin.points.rewards.retire', $reward), ['reason' => 'Supplier stopped.'])->assertRedirect();
        $this->assertFalse($reward->fresh()->active);
        $this->assertSame('pending', $waiting->fresh()->status);

        try {
            app(RewardRedemptions::class)->redeem($user, $reward->fresh(), 'req-2');
            $this->fail('a retired reward must not be redeemable');
        } catch (RedemptionRefused) {
            $this->addToAssertionCount(1);
        }

        $this->post(route('admin.points.rewards.retire', $reward), ['reason' => 'Pressed twice.']);
        $this->assertSame(1, AdminAudit::where('action', 'reward.retired')->count(), 'the repeat changed nothing and wrote no second record');

        $this->post(route('admin.points.rewards.reinstate', $reward), ['reason' => 'Supplier is back.']);
        $this->assertTrue($reward->fresh()->active);
        $this->assertSame(1, AdminAudit::where('action', 'reward.reinstated')->count());

        $this->post(route('admin.points.rewards.retire', $reward), [])->assertSessionHasErrors('reason');
    }

    public function test_limited_stock_is_taken_one_unit_at_a_time_and_given_back_on_failure(): void
    {
        $this->create(['stock' => 1, 'price_points' => 5]);
        $reward = Reward::sole();
        $alice = (string) Str::uuid();
        $bob = (string) Str::uuid();
        $this->fund($alice, 10);
        $this->fund($bob, 10);
        $service = app(RewardRedemptions::class);

        $first = $service->redeem($alice, $reward, 'a-1');
        $this->assertSame(0, $reward->fresh()->stock);

        try {
            $service->redeem($bob, $reward, 'b-1');
            $this->fail('the last unit is gone');
        } catch (RedemptionRefused $e) {
            $this->assertStringContainsString('out of stock', $e->getMessage());
        }
        $this->assertSame(10, app(PointsLedger::class)->balance($bob), 'a refused redemption costs nothing');

        $this->assertSame($first->id, $service->redeem($alice, $reward, 'a-1')->id, 'a replay of the same request takes no second unit');
        $this->assertSame(0, $reward->fresh()->stock);

        $service->fail($first, 'Could not deliver');
        $this->assertSame(1, $reward->fresh()->stock, 'the unit came back with the points');
        $this->assertTrue($service->redeem($bob, $reward, 'b-2') instanceof Redemption);
    }

    public function test_unlimited_stock_is_never_touched(): void
    {
        $this->create(['price_points' => 5]);
        $reward = Reward::sole();
        $user = (string) Str::uuid();
        $this->fund($user, 20);

        app(RewardRedemptions::class)->redeem($user, $reward, 'r-1');

        $this->assertNull($reward->fresh()->stock);
    }

    public function test_a_non_administrator_cannot_change_the_price_list(): void
    {
        $this->actingAs(User::factory()->create())->post(route('admin.points.rewards.store'), [
            'key' => 'free.money', 'title' => 'Free money', 'kind' => 'billing_credit', 'price_points' => 1, 'reason' => 'Because.',
        ])->assertForbidden();

        $this->assertSame(0, Reward::count());
    }
}
