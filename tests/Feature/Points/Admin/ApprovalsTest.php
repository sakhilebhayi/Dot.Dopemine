<?php

namespace Tests\Feature\Points\Admin;

use App\Models\AdminAudit;
use App\Models\Contribution;
use App\Models\LedgerEntry;
use App\Models\RewardEvent;
use App\Models\User;
use App\Services\Points\ContributionEventProcessor;
use App\Services\Points\PointsLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApprovalsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->author = (string) Str::uuid();
    }

    private function pending(string $ref = 'infodot:question:1', string $kind = 'question', array $meta = [], ?string $author = null): Contribution
    {
        return Contribution::create([
            'platform' => 'infodot', 'ref' => $ref, 'user_uuid' => $author ?? $this->author, 'kind' => $kind,
            'status' => 'pending_approval', 'meta' => $meta ?: null, 'status_changed_at' => now()->subDays(2),
        ]);
    }

    private function approve(Contribution $contribution, array $data = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.points.approvals.approve', $contribution->uuid), $data);
    }

    public function test_the_list_shows_ref_type_author_age_rule_and_the_amount_it_would_pay(): void
    {
        $this->pending();
        $this->pending('infodot:tutorial:1', 'tutorial', ['pending_rule_key' => 'infodot.tutorial.approved', 'pending_points' => 99]);

        $this->actingAs($this->admin)->get(route('admin.points.approvals'))
            ->assertOk()
            ->assertSee('Pending approval')
            ->assertSee('infodot:question:1')->assertSee('Question')->assertSee($this->author)
            ->assertSee('infodot.question.published')->assertSee('2 points')
            ->assertSee('2 days')
            ->assertSee('infodot:tutorial:1')->assertSee('20 points', false);
    }

    public function test_a_question_recorded_before_approvals_were_tracked_is_listed_but_a_paid_or_approved_one_is_not(): void
    {
        Contribution::create(['platform' => 'infodot', 'ref' => 'infodot:question:old', 'user_uuid' => $this->author, 'kind' => 'question', 'status' => 'published']);
        $paid = Contribution::create(['platform' => 'infodot', 'ref' => 'infodot:question:paid', 'user_uuid' => $this->author, 'kind' => 'question', 'status' => 'rewarded']);
        $answer = Contribution::create(['platform' => 'infodot', 'ref' => 'infodot:answer:1', 'user_uuid' => $this->author, 'kind' => 'answer', 'status' => 'published']);

        $this->actingAs($this->admin)->get(route('admin.points.approvals'))
            ->assertSee('infodot:question:old')->assertDontSee($paid->ref)->assertDontSee($answer->ref);
    }

    public function test_an_administrator_approves_once_the_author_is_paid_and_the_audit_says_who_and_when(): void
    {
        $contribution = $this->pending();

        $this->approve($contribution, ['reason' => 'Checked it'])
            ->assertRedirect(route('admin.points.approvals'))
            ->assertSessionHas('status');

        $this->assertSame(2, app(PointsLedger::class)->balance($this->author));
        $this->assertSame('rewarded', $contribution->fresh()->status);
        $this->assertSame('admin:'.$this->admin->id, $contribution->fresh()->meta['approved_by']);
        $this->assertSame('admin', $contribution->fresh()->meta['approved_via']);

        $audit = AdminAudit::where('action', 'contribution.approved')->sole();
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame('infodot:question:1', $audit->subject_key);
        $this->assertSame('Checked it', $audit->reason);
        $this->assertSame(2, $audit->details['points']);
        $this->assertNotNull($audit->created_at);

        $this->actingAs($this->admin)->get(route('admin.points.approvals')); // the flash message is shown once
        $this->actingAs($this->admin)->get(route('admin.points.approvals'))->assertDontSee('infodot:question:1');
    }

    public function test_pressing_approve_twice_pays_once(): void
    {
        $contribution = $this->pending();

        $this->approve($contribution);
        $this->approve($contribution)->assertSessionHas('status');

        $this->assertSame(1, LedgerEntry::where('type', LedgerEntry::AWARD)->count());
        $this->assertSame(2, app(PointsLedger::class)->balance($this->author));
        $this->assertSame(1, AdminAudit::where('action', 'contribution.approved')->count());
    }

    public function test_a_moderator_approval_from_infodot_and_an_administrator_cannot_both_pay(): void
    {
        $contribution = $this->pending();

        app(ContributionEventProcessor::class)->approvePending($contribution, (string) Str::uuid(), 'evt-1', 'event');
        $this->approve($contribution)->assertSessionHas('status');

        $this->assertSame(1, LedgerEntry::where('type', LedgerEntry::AWARD)->count());
        $this->assertSame(0, AdminAudit::where('action', 'contribution.approved')->count(), 'nothing was approved here, so nothing is logged as approved here');
    }

    public function test_the_approval_uses_the_amount_remembered_and_clamps_it(): void
    {
        $contribution = $this->pending('infodot:tutorial:1', 'tutorial', ['pending_rule_key' => 'infodot.tutorial.approved', 'pending_points' => 15]);

        $this->approve($contribution);

        $this->assertSame(15, app(PointsLedger::class)->balance($this->author));
    }

    public function test_an_approver_who_is_the_author_is_refused_and_nothing_is_written(): void
    {
        $contribution = $this->pending();

        $result = app(ContributionEventProcessor::class)->approvePending($contribution, $this->author, 'admin-approve:x', 'admin');

        $this->assertSame('rejected', $result['outcome']);
        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame('pending_approval', $contribution->fresh()->status);
    }

    public function test_a_capped_approval_is_reported_and_the_contribution_stays_waiting(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->approve($this->pending("infodot:question:{$n}"));
        }
        $fourth = $this->pending('infodot:question:4');

        $this->approve($fourth)->assertSessionHas('error');

        $this->assertSame('pending_approval', $fourth->fresh()->status);
        $this->assertSame(6, app(PointsLedger::class)->balance($this->author));
        $this->assertSame(3, AdminAudit::where('action', 'contribution.approved')->count());
    }

    public function test_an_approved_award_can_be_reversed_by_the_normal_event(): void
    {
        $contribution = $this->pending();
        $this->approve($contribution);

        $award = LedgerEntry::where('type', LedgerEntry::AWARD)->sole();
        app(PointsLedger::class)->reverse($award, 'Spam', 'rev-1');

        $this->assertSame(0, app(PointsLedger::class)->balance($this->author));
        $this->approve($contribution)->assertSessionHas('status');
        $this->assertSame(0, app(PointsLedger::class)->balance($this->author), 'a reversed award is not paid again by a second press');
    }

    public function test_the_admin_action_writes_no_intake_event_row(): void
    {
        $this->approve($this->pending());

        $this->assertSame(0, RewardEvent::count());
    }

    public function test_only_administrators_can_see_or_use_it(): void
    {
        $contribution = $this->pending();

        $this->get(route('admin.points.approvals'))->assertRedirect();
        $this->post(route('admin.points.approvals.approve', $contribution->uuid))->assertRedirect();

        $member = User::factory()->withPersonalTeam()->create();
        $this->actingAs($member)->get(route('admin.points.approvals'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.points.approvals.approve', $contribution->uuid))->assertForbidden();
        $this->actingAs($member)->post(route('admin.points.approvals.approve', Str::uuid()))->assertForbidden();

        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame('pending_approval', $contribution->fresh()->status);
        $this->assertSame(0, AdminAudit::count());
    }

    public function test_an_unknown_contribution_is_a_404_for_an_administrator(): void
    {
        $this->actingAs($this->admin)->post(route('admin.points.approvals.approve', Str::uuid()))->assertNotFound();
    }

    public function test_the_navigation_links_to_the_page(): void
    {
        $this->actingAs($this->admin)->get(route('admin.points.redemptions'))->assertSee(route('admin.points.approvals'), false);
    }
}
