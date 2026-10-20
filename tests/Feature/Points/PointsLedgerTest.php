<?php

namespace Tests\Feature\Points;

use App\Exceptions\Points\InsufficientPoints;
use App\Exceptions\Points\RedemptionRefused;
use App\Models\LedgerEntry;
use App\Models\Reward;
use App\Models\RewardRule;
use App\Services\Points\AwardOutcome;
use App\Services\Points\PointsLedger;
use App\Services\Points\RewardRedemptions;
use Database\Seeders\RewardRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PointsLedgerTest extends TestCase
{
    use RefreshDatabase;

    private PointsLedger $ledger;

    private string $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RewardRuleSeeder::class);
        $this->ledger = app(PointsLedger::class);
        $this->alice = (string) Str::uuid();
    }

    private function award(string $rule, string $ref, ?string $key = null, ?int $points = null, bool $approved = true, ?string $user = null): AwardOutcome
    {
        return $this->ledger->award($user ?? $this->alice, RewardRule::current($rule), $ref, $key ?? "evt:{$rule}:{$ref}", $points, [], $approved);
    }

    public function test_the_default_schedule_matches_the_owners_table_and_is_data(): void
    {
        $this->assertSame(2, RewardRule::current('infodot.question.published')->points);
        $this->assertSame(5, RewardRule::current('infodot.answer.published')->points);
        $this->assertSame(10, RewardRule::current('infodot.answer.accepted')->points);
        $this->assertSame(10, RewardRule::current('infodot.solution.verified')->points);
        $this->assertSame([10, 20], [RewardRule::current('infodot.tutorial.approved')->min_points, RewardRule::current('infodot.tutorial.approved')->max_points]);
        $this->assertSame([10, 25], [RewardRule::current('infodot.troubleshooting.approved')->min_points, RewardRule::current('infodot.troubleshooting.approved')->max_points]);
        $this->assertSame([1, 3], [RewardRule::current('infodot.contribution.helpful')->min_points, RewardRule::current('infodot.contribution.helpful')->max_points]);
    }

    public function test_an_award_is_recorded_with_its_rule_version_and_expires_in_twelve_months(): void
    {
        $outcome = $this->award('infodot.answer.published', 'infodot:answer:1');

        $this->assertTrue($outcome->awarded());
        $this->assertSame(5, $outcome->entry->points);
        $this->assertSame(1, $outcome->entry->rule_version);
        $this->assertTrue($outcome->entry->expires_at->isSameDay(now()->addMonths(12)));
        $this->assertSame(5, $this->ledger->balance($this->alice));
    }

    public function test_the_same_event_twice_never_pays_twice(): void
    {
        $first = $this->award('infodot.answer.published', 'infodot:answer:1', 'evt-1');
        $again = $this->award('infodot.answer.published', 'infodot:answer:1', 'evt-1');

        $this->assertSame(AwardOutcome::DUPLICATE, $again->status);
        $this->assertSame($first->entry->id, $again->entry->id);
        $this->assertSame(5, $this->ledger->balance($this->alice));
    }

    public function test_an_edit_or_a_second_event_for_the_same_contribution_does_not_pay_again(): void
    {
        $this->award('infodot.answer.published', 'infodot:answer:1', 'evt-1');
        $edited = $this->award('infodot.answer.published', 'infodot:answer:1', 'evt-2-after-edit');

        $this->assertSame(AwardOutcome::ALREADY_REWARDED, $edited->status);
        $this->assertSame(5, $this->ledger->balance($this->alice));
    }

    public function test_a_rule_needing_approval_pays_nothing_until_it_is_approved(): void
    {
        $this->assertSame(AwardOutcome::NEEDS_APPROVAL, $this->award('infodot.tutorial.approved', 'infodot:solution:9', approved: false)->status);
        $this->assertSame(0, $this->ledger->balance($this->alice));

        $this->assertTrue($this->award('infodot.tutorial.approved', 'infodot:solution:9', points: 15)->awarded());
        $this->assertSame(15, $this->ledger->balance($this->alice));
    }

    public function test_a_ranged_rule_is_clamped_into_its_range(): void
    {
        $this->assertSame(20, $this->award('infodot.tutorial.approved', 'infodot:solution:1', points: 99)->entry->points);
        $this->assertSame(10, $this->award('infodot.tutorial.approved', 'infodot:solution:2', points: 1)->entry->points);
    }

    public function test_the_daily_cap_stops_farming_but_only_for_that_rule(): void
    {
        // question cap is 6 points a day = 3 questions
        foreach ([1, 2, 3] as $n) {
            $this->assertTrue($this->award('infodot.question.published', "infodot:question:{$n}")->awarded());
        }

        $this->assertSame(AwardOutcome::DAILY_CAP, $this->award('infodot.question.published', 'infodot:question:4')->status);
        $this->assertTrue($this->award('infodot.answer.published', 'infodot:answer:1')->awarded(), 'another rule is not affected');
        $this->assertSame(11, $this->ledger->balance($this->alice));

        $this->travel(1)->day();
        $this->assertTrue($this->award('infodot.question.published', 'infodot:question:4')->awarded(), 'the cap resets the next day');
    }

    public function test_changing_a_rule_writes_a_new_version_and_old_entries_keep_theirs(): void
    {
        $old = $this->award('infodot.answer.published', 'infodot:answer:1')->entry;

        $v2 = RewardRule::revise('infodot.answer.published', ['points' => 8], 'admin@example.test', 'Answers are worth more now.');
        $new = $this->award('infodot.answer.published', 'infodot:answer:2')->entry;

        $this->assertSame(2, $v2->version);
        $this->assertSame([1, 5], [$old->fresh()->rule_version, $old->fresh()->points]);
        $this->assertSame([2, 8], [$new->rule_version, $new->points]);
        $this->assertSame(1, RewardRule::where('key', 'infodot.answer.published')->where('version', 1)->count(), 'v1 is untouched');
    }

    public function test_a_switched_off_rule_pays_nothing(): void
    {
        RewardRule::revise('infodot.answer.published', ['active' => false]);

        $this->assertNull(RewardRule::current('infodot.answer.published'));
    }

    public function test_ledger_entries_cannot_be_edited_or_deleted(): void
    {
        $entry = $this->award('infodot.answer.published', 'infodot:answer:1')->entry;

        $this->expectException(LogicException::class);
        $entry->update(['points' => 500]);
    }

    public function test_ledger_entries_cannot_be_deleted(): void
    {
        $entry = $this->award('infodot.answer.published', 'infodot:answer:1')->entry;

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_points_expire_twelve_months_after_the_award_and_expiry_is_written_once(): void
    {
        $this->award('infodot.answer.published', 'infodot:answer:1');

        $this->travel(11)->months();
        $this->assertSame(5, $this->ledger->balance($this->alice));

        $this->travel(2)->months();
        $this->assertSame(0, $this->ledger->balance($this->alice), 'expired points are not spendable even before the job runs');
        $this->assertSame(1, $this->ledger->expireDue());
        $this->assertSame(0, $this->ledger->expireDue(), 'running it again writes nothing');

        $this->assertSame(['award', 'expire'], $this->ledger->history($this->alice)->pluck('type')->reverse()->values()->all(), 'history is kept, not erased');
    }

    public function test_spending_takes_the_oldest_expiring_points_first(): void
    {
        $this->award('infodot.answer.published', 'infodot:answer:1'); // 5, expires first
        $this->travel(2)->months();
        $this->award('infodot.answer.published', 'infodot:answer:2'); // 5, expires later

        $entries = $this->ledger->redeem($this->alice, 7, 'spend-1');

        $this->assertCount(2, $entries);
        $this->assertSame([-5, -2], array_map(fn ($e) => $e->points, $entries), 'the older lot is emptied first');
        $this->assertSame(3, $this->ledger->balance($this->alice));
    }

    public function test_you_cannot_spend_more_than_you_have_and_nothing_is_written_when_you_try(): void
    {
        $this->award('infodot.answer.published', 'infodot:answer:1');
        $before = LedgerEntry::count();

        try {
            $this->ledger->redeem($this->alice, 6, 'spend-too-much');
            $this->fail('should have refused');
        } catch (InsufficientPoints $e) {
            $this->assertSame([6, 5], [$e->needed, $e->available]);
        }

        $this->assertSame($before, LedgerEntry::count());
        $this->assertSame(5, $this->ledger->balance($this->alice));
    }

    public function test_the_same_spend_twice_is_one_spend(): void
    {
        $this->award('infodot.answer.published', 'infodot:answer:1');

        $this->ledger->redeem($this->alice, 3, 'spend-1');
        $this->ledger->redeem($this->alice, 3, 'spend-1');

        $this->assertSame(2, $this->ledger->balance($this->alice));
    }

    public function test_reversing_an_unspent_award_takes_all_of_it_back(): void
    {
        $award = $this->award('infodot.answer.published', 'infodot:answer:1')->entry;

        $result = $this->ledger->reverse($award, 'Plagiarised answer', 'rev-1');

        $this->assertSame([5, 0], [$result['reversed'], $result['unrecovered']]);
        $this->assertSame(0, $this->ledger->balance($this->alice));
        $this->assertSame(1, LedgerEntry::where('type', 'award')->count(), 'the award stays in the history');
    }

    public function test_reversing_a_partly_spent_award_takes_back_only_the_unspent_part_and_never_goes_negative(): void
    {
        $award = $this->award('infodot.answer.published', 'infodot:answer:1')->entry;
        $this->ledger->redeem($this->alice, 4, 'spend-1');

        $result = $this->ledger->reverse($award, 'Fraudulent', 'rev-1');

        $this->assertSame([1, 4], [$result['reversed'], $result['unrecovered']], '4 were already spent: reported, not clawed into a negative');
        $this->assertSame(0, $this->ledger->balance($this->alice));
        $this->assertSame(0, (int) LedgerEntry::where('user_uuid', $this->alice)->sum('points'), 'the entries still add up: 5 - 4 - 1 = 0');
    }

    public function test_a_reversal_twice_is_one_reversal(): void
    {
        $award = $this->award('infodot.answer.published', 'infodot:answer:1')->entry;

        $this->ledger->reverse($award, 'Spam', 'rev-1');
        $second = $this->ledger->reverse($award, 'Spam again', 'rev-2');

        $this->assertSame(0, $second['reversed']);
        $this->assertSame(1, LedgerEntry::where('type', 'reverse')->count());
    }

    public function test_a_reversed_contribution_can_be_rewarded_again_only_after_a_fresh_decision(): void
    {
        $award = $this->award('infodot.answer.published', 'infodot:answer:1')->entry;
        $this->ledger->reverse($award, 'Wrongly flagged', 'rev-1');

        $this->assertTrue($this->award('infodot.answer.published', 'infodot:answer:1', 'evt-after-appeal')->awarded());
        $this->assertSame(5, $this->ledger->balance($this->alice));
    }

    public function test_expiry_warnings_are_due_once_per_lot_in_the_last_thirty_days(): void
    {
        $this->award('infodot.answer.published', 'infodot:answer:1');

        $this->assertCount(0, $this->ledger->dueForExpiryWarning());

        $this->travelTo(now()->addMonths(11)->addDays(5));
        $due = $this->ledger->dueForExpiryWarning();
        $this->assertCount(1, $due);

        $this->ledger->markWarned($due->first());
        $this->assertCount(0, $this->ledger->dueForExpiryWarning(), 'one heads-up per lot, not a nag');
    }

    public function test_redeeming_a_reward_spends_points_and_a_failed_delivery_gives_them_back(): void
    {
        $reward = Reward::create(['key' => 'capacity.ai-boost', 'title' => 'AI boost, 30 days', 'kind' => 'capacity', 'price_points' => 8, 'active' => true]);
        $this->award('infodot.answer.accepted', 'infodot:answer:1', points: 10);
        $service = app(RewardRedemptions::class);

        $redemption = $service->redeem($this->alice, $reward, 'req-1');

        $this->assertSame('pending', $redemption->status);
        $this->assertSame(2, $this->ledger->balance($this->alice));
        $this->assertSame($redemption->id, $service->redeem($this->alice, $reward, 'req-1')->id, 'the same request is one redemption');

        $service->fail($redemption, 'Could not apply the capacity');

        $this->assertSame('failed', $redemption->fresh()->status);
        $this->assertSame(10, $this->ledger->balance($this->alice), 'the points came back');
    }

    public function test_a_fulfilled_redemption_cannot_be_failed_afterwards(): void
    {
        $reward = Reward::create(['key' => 'billing.credit-r50', 'title' => 'R50 billing credit', 'kind' => 'billing_credit', 'price_points' => 5, 'active' => true]);
        $this->award('infodot.answer.published', 'infodot:answer:1');
        $service = app(RewardRedemptions::class);
        $redemption = $service->redeem($this->alice, $reward, 'req-1');

        $service->fulfil($redemption, 'invoice-credit-77', 'operator@example.test');

        $this->assertSame('fulfilled', $redemption->fresh()->status);
        $this->expectException(HttpException::class);
        $service->fail($redemption->fresh(), 'too late');
    }

    public function test_a_reward_with_a_monthly_cap_and_an_inactive_reward_are_refused(): void
    {
        $capped = Reward::create(['key' => 'market.tutor-hour', 'title' => 'Tutor session', 'kind' => 'marketplace', 'price_points' => 2, 'monthly_cap' => 1, 'active' => true]);
        $off = Reward::create(['key' => 'old.reward', 'title' => 'Old', 'kind' => 'capacity', 'price_points' => 1, 'active' => false]);
        $bob = (string) Str::uuid();
        $this->award('infodot.answer.published', 'infodot:answer:1');
        $this->award('infodot.answer.published', 'infodot:answer:2', user: $bob);
        $service = app(RewardRedemptions::class);

        $service->redeem($this->alice, $capped, 'a-1');

        try {
            $service->redeem($bob, $capped, 'b-1');
            $this->fail('the monthly cap should have refused');
        } catch (RedemptionRefused $e) {
            $this->assertStringContainsString('limit for this month', $e->getMessage());
        }

        $this->expectException(RedemptionRefused::class);
        $service->redeem($this->alice, $off, 'a-2');
    }

    public function test_one_persons_points_are_not_another_persons(): void
    {
        $bob = (string) Str::uuid();
        $this->award('infodot.answer.published', 'infodot:answer:1');

        $this->assertSame(0, $this->ledger->balance($bob));
        $this->expectException(InsufficientPoints::class);
        $this->ledger->redeem($bob, 1, 'steal');
    }
}
