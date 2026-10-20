<?php

namespace Tests\Feature\Points\Admin;

use App\Models\AdminAudit;
use App\Models\LedgerEntry;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\RewardRule;
use App\Models\User;
use App\Services\Points\PointsLedger;
use App\Services\Points\RewardRedemptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RedemptionQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $alice;

    private Reward $reward;

    private PointsLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->alice = (string) Str::uuid();
        $this->ledger = app(PointsLedger::class);
        $this->reward = Reward::create(['key' => 'billing.credit-r50', 'title' => 'R50 billing credit', 'kind' => 'billing_credit', 'price_points' => 20, 'active' => true]);

        for ($i = 1; $i <= 4; $i++) {
            $this->ledger->award($this->alice, RewardRule::current('infodot.answer.accepted'), "infodot:answer:{$i}", "evt-{$i}");
        }
    }

    private function redeem(string $key = 'req-1'): Redemption
    {
        return app(RewardRedemptions::class)->redeem($this->alice, $this->reward, $key);
    }

    public function test_the_queue_shows_who_asked_for_what_how_much_and_how_long_ago(): void
    {
        $redemption = $this->redeem();
        $redemption->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->actingAs($this->admin)->get(route('admin.points.redemptions'))
            ->assertOk()
            ->assertSee($this->alice)
            ->assertSee('R50 billing credit')
            ->assertSee('Billing credit')
            ->assertSee('20 points')
            ->assertSee('3 days');
    }

    public function test_only_pending_redemptions_are_in_the_queue_and_an_empty_queue_says_so(): void
    {
        $this->actingAs($this->admin)->get(route('admin.points.redemptions'))->assertSee('No redemptions are waiting');

        $done = $this->redeem();
        app(RewardRedemptions::class)->fulfil($done, 'INV-1', 'admin#1');

        $page = $this->actingAs($this->admin)->get(route('admin.points.redemptions'));
        $page->assertSee('No redemptions are waiting')->assertSee('INV-1', false)->assertSee('Recently decided');
    }

    public function test_fulfilling_records_the_reference_who_did_it_and_an_audit_entry(): void
    {
        $redemption = $this->redeem();

        $this->actingAs($this->admin)->post(route('admin.points.redemptions.fulfil', $redemption->uuid), ['fulfilment_ref' => 'CN-2026-0042'])
            ->assertRedirect(route('admin.points.redemptions'))->assertSessionHas('status', 'Marked fulfilled.');

        $redemption->refresh();
        $this->assertSame(['fulfilled', 'CN-2026-0042', 'admin#'.$this->admin->id], [$redemption->status, $redemption->fulfilment_ref, $redemption->fulfilled_by]);
        $this->assertSame(20, $this->ledger->balance($this->alice), 'fulfilling does not touch the balance: the points were spent at redemption');

        $audit = AdminAudit::sole();
        $this->assertSame(['redemption.fulfilled', $redemption->uuid], [$audit->action, $audit->subject_key]);
    }

    public function test_fulfilling_twice_is_a_harmless_repeat(): void
    {
        $redemption = $this->redeem();
        $url = route('admin.points.redemptions.fulfil', $redemption->uuid);

        $this->actingAs($this->admin)->post($url, ['fulfilment_ref' => 'CN-1']);
        $this->post($url, ['fulfilment_ref' => 'CN-DIFFERENT'])->assertSessionHas('status', fn ($m) => str_contains($m, 'already marked fulfilled'));

        $this->assertSame('CN-1', $redemption->fresh()->fulfilment_ref, 'the first reference stands');
        $this->assertSame(1, AdminAudit::count());
    }

    public function test_a_reference_is_required_to_fulfil(): void
    {
        $redemption = $this->redeem();

        $this->actingAs($this->admin)->post(route('admin.points.redemptions.fulfil', $redemption->uuid), ['fulfilment_ref' => ''])->assertSessionHasErrors('fulfilment_ref');

        $this->assertSame('pending', $redemption->fresh()->status);
    }

    public function test_failing_returns_the_points_through_a_refund_entry_and_says_why(): void
    {
        $redemption = $this->redeem();
        $this->assertSame(20, $this->ledger->balance($this->alice));

        $this->actingAs($this->admin)->post(route('admin.points.redemptions.fail', $redemption->uuid), ['outcome' => 'failed', 'note' => 'Billing could not apply the credit.'])
            ->assertRedirect(route('admin.points.redemptions'))->assertSessionHas('status');

        $redemption->refresh();
        $this->assertSame(['failed', 'Billing could not apply the credit.'], [$redemption->status, $redemption->note]);
        $this->assertSame(40, $this->ledger->balance($this->alice), 'every point is back');

        $refund = LedgerEntry::where('type', LedgerEntry::REFUND)->sole();
        $this->assertSame([20, 'refund:'.$redemption->uuid, $this->alice, 'Billing could not apply the credit.'], [$refund->points, $refund->idempotency_key, $refund->user_uuid, $refund->reason]);
        $this->assertTrue($refund->isLot(), 'a refund starts a fresh lot that can be spent again');
        $this->assertSame('redemption.failed', AdminAudit::sole()->action);
    }

    public function test_cancelling_is_recorded_as_cancelled(): void
    {
        $redemption = $this->redeem();

        $this->actingAs($this->admin)->post(route('admin.points.redemptions.fail', $redemption->uuid), ['outcome' => 'cancelled', 'note' => 'The person asked to cancel.']);

        $this->assertSame('cancelled', $redemption->fresh()->status);
        $this->assertSame(40, $this->ledger->balance($this->alice));
        $this->assertSame('redemption.cancelled', AdminAudit::sole()->action);
    }

    public function test_failing_twice_returns_the_points_once(): void
    {
        $redemption = $this->redeem();
        $url = route('admin.points.redemptions.fail', $redemption->uuid);
        $input = ['outcome' => 'failed', 'note' => 'Could not be delivered.'];

        $this->actingAs($this->admin)->post($url, $input);
        $this->post($url, $input)->assertSessionHas('status', fn ($m) => str_contains($m, 'not returned twice'));

        $this->assertSame(1, LedgerEntry::where('type', LedgerEntry::REFUND)->count());
        $this->assertSame(40, $this->ledger->balance($this->alice));
        $this->assertSame(1, AdminAudit::count(), 'the repeat wrote no second audit record');
    }

    public function test_the_service_itself_is_idempotent_when_two_requests_race(): void
    {
        $redemption = $this->redeem();
        $stale = Redemption::find($redemption->id); // a second operator who loaded the page before the first one clicked
        $service = app(RewardRedemptions::class);

        $service->fail($redemption, 'First click wins');

        try {
            $service->fail($stale, 'Second click');
            $this->fail('the second decision must be refused');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(1, LedgerEntry::where('type', LedgerEntry::REFUND)->count());
        $this->assertSame(40, $this->ledger->balance($this->alice));
        $this->assertSame('First click wins', $redemption->fresh()->note);
    }

    public function test_a_contradicting_decision_is_refused_and_changes_nothing(): void
    {
        $fulfilled = $this->redeem('req-1');
        $this->actingAs($this->admin)->post(route('admin.points.redemptions.fulfil', $fulfilled->uuid), ['fulfilment_ref' => 'CN-1']);

        $this->post(route('admin.points.redemptions.fail', $fulfilled->uuid), ['outcome' => 'failed', 'note' => 'Changed my mind.'])
            ->assertSessionHas('error');
        $this->assertSame('fulfilled', $fulfilled->fresh()->status);
        $this->assertSame(0, LedgerEntry::where('type', LedgerEntry::REFUND)->count(), 'no points came back for something that was delivered');

        $failed = $this->redeem('req-2');
        $this->post(route('admin.points.redemptions.fail', $failed->uuid), ['outcome' => 'failed', 'note' => 'Could not deliver.']);

        $this->post(route('admin.points.redemptions.fulfil', $failed->uuid), ['fulfilment_ref' => 'CN-2'])->assertSessionHas('error');
        $this->post(route('admin.points.redemptions.fail', $failed->uuid), ['outcome' => 'cancelled', 'note' => 'Now cancelled instead.'])->assertSessionHas('error');
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntry::REFUND)->count());
    }

    public function test_a_reason_is_required_and_the_outcome_must_be_known(): void
    {
        $redemption = $this->redeem();
        $url = route('admin.points.redemptions.fail', $redemption->uuid);

        $this->actingAs($this->admin)->post($url, ['outcome' => 'failed', 'note' => ''])->assertSessionHasErrors('note');
        $this->post($url, ['outcome' => 'fulfilled', 'note' => 'Sneaking a different status in.'])->assertSessionHasErrors('outcome');

        $this->assertSame('pending', $redemption->fresh()->status);
        $this->assertSame(0, LedgerEntry::where('type', LedgerEntry::REFUND)->count());
    }

    public function test_an_unknown_redemption_is_a_404_and_a_non_administrator_is_turned_away_first(): void
    {
        $this->actingAs($this->admin)->post(route('admin.points.redemptions.fulfil', (string) Str::uuid()), ['fulfilment_ref' => 'x1'])->assertNotFound();

        $redemption = $this->redeem();
        $this->actingAs(User::factory()->create())
            ->post(route('admin.points.redemptions.fail', $redemption->uuid), ['outcome' => 'failed', 'note' => 'Not my call to make.'])->assertForbidden();
        $this->post(route('admin.points.redemptions.fail', (string) Str::uuid()), ['outcome' => 'failed', 'note' => 'Probing for ids.'])->assertForbidden();

        $this->assertSame('pending', $redemption->fresh()->status);
    }
}
