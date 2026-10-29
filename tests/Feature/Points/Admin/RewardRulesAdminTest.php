<?php

namespace Tests\Feature\Points\Admin;

use App\Models\AdminAudit;
use App\Models\RewardRule;
use App\Models\User;
use App\Services\Points\PointsLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class RewardRulesAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'email' => 'ada@example.test']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function publish(string $key, array $overrides = [])
    {
        $latest = RewardRule::latestVersion($key);

        return $this->actingAs($this->admin)->post(route('admin.points.rules.publish', $key), array_merge([
            'based_on_version' => $latest->version,
            'points' => $latest->points,
            'min_points' => $latest->min_points,
            'max_points' => $latest->max_points,
            'daily_cap_points' => $latest->daily_cap_points,
            'max_awards_per_contribution' => $latest->max_awards_per_contribution,
            'requires_approval' => $latest->requires_approval ? 1 : 0,
            'active' => $latest->active ? 1 : 0,
            'reason' => 'Answers deserve more because they are the scarce thing.',
        ], $overrides));
    }

    public function test_the_page_lists_every_rule_with_its_version_history(): void
    {
        RewardRule::revise('infodot.answer.published', ['points' => 8], 'Ada', 'Raised after the first month.');

        $page = $this->actingAs($this->admin)->get(route('admin.points.rules'))->assertOk();

        foreach (RewardRule::distinct()->pluck('key') as $key) {
            $page->assertSee($key);
        }

        $page->assertSee('Version history (2)')->assertSee('Raised after the first month.')->assertSee('Version 2');
        $this->assertSame(11, RewardRule::distinct()->count('key'));
    }

    public function test_publishing_writes_a_new_version_and_closes_the_old_one(): void
    {
        $this->travelTo(now()->startOfSecond());
        $v1 = RewardRule::latestVersion('infodot.answer.published');

        $this->publish('infodot.answer.published', ['points' => 8, 'daily_cap_points' => 40])
            ->assertRedirect()->assertSessionHas('status');

        $v1 = $v1->fresh();
        $v2 = RewardRule::latestVersion('infodot.answer.published');

        $this->assertSame(2, $v2->version);
        $this->assertSame([8, 40], [$v2->points, $v2->daily_cap_points]);
        $this->assertSame('Ada Admin (#'.$this->admin->id.')', $v2->changed_by);
        $this->assertSame('Answers deserve more because they are the scarce thing.', $v2->note);
        $this->assertTrue($v1->effective_to->equalTo($v2->effective_from), 'the old version ends exactly when the new one starts');
        $this->assertSame([5, 25], [$v1->points, $v1->daily_cap_points], 'v1 keeps its values');
        $this->assertSame(2, RewardRule::where('key', 'infodot.answer.published')->count());
        $this->assertSame(8, RewardRule::current('infodot.answer.published')->points);
    }

    public function test_the_audit_trail_records_who_when_why_and_what_changed(): void
    {
        $this->publish('infodot.answer.published', ['points' => 8, 'reason' => 'Beginners need a push to answer.']);

        $audit = AdminAudit::sole();

        $this->assertSame('rule.published', $audit->action);
        $this->assertSame('infodot.answer.published', $audit->subject_key);
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertStringContainsString('ada@example.test', $audit->actor_label);
        $this->assertSame('Beginners need a push to answer.', $audit->reason);
        $this->assertSame(['from' => 5, 'to' => 8], $audit->details['changed']['points']);
        $this->assertSame([1, 2], [$audit->details['from_version'], $audit->details['to_version']]);
        $this->assertNotNull($audit->created_at);

        $this->actingAs($this->admin)->get(route('admin.points.audit'))->assertOk()->assertSee('rule.published')->assertSee('Beginners need a push to answer.');
    }

    public function test_points_already_awarded_keep_the_version_that_produced_them(): void
    {
        $ledger = app(PointsLedger::class);
        $user = (string) Str::uuid();

        $before = $ledger->award($user, RewardRule::current('infodot.answer.published'), 'infodot:answer:1', 'k1')->entry;
        $this->publish('infodot.answer.published', ['points' => 8]);
        $after = $ledger->award($user, RewardRule::current('infodot.answer.published'), 'infodot:answer:2', 'k2')->entry;

        $this->assertSame([1, 5], [$before->fresh()->rule_version, $before->fresh()->points]);
        $this->assertSame([2, 8], [$after->rule_version, $after->points]);
        $this->assertSame(13, $ledger->balance($user));
    }

    public function test_a_stored_rule_version_cannot_be_edited_or_deleted(): void
    {
        $rule = RewardRule::latestVersion('infodot.answer.published');

        try {
            $rule->update(['points' => 99]);
            $this->fail('editing a version must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $rule->delete();
            $this->fail('deleting a version must be refused');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(5, $rule->fresh()->points);
        $this->assertSame(1, RewardRule::where('key', 'infodot.answer.published')->count());
    }

    public function test_the_only_change_a_version_accepts_is_a_single_end_stamp(): void
    {
        $this->publish('infodot.answer.published', ['points' => 8]);
        $v1 = RewardRule::where('key', 'infodot.answer.published')->where('version', 1)->first();

        $this->assertNotNull($v1->effective_to);

        $this->expectException(LogicException::class);
        $v1->update(['effective_to' => now()->addYear()]);
    }

    public function test_an_end_stamp_cannot_be_combined_with_another_change(): void
    {
        $rule = RewardRule::latestVersion('infodot.answer.published');

        $this->expectException(LogicException::class);
        $rule->update(['effective_to' => now(), 'points' => 99]);
    }

    public function test_a_reason_is_required_and_nothing_is_written_without_it(): void
    {
        $this->publish('infodot.answer.published', ['points' => 8, 'reason' => 'short'])
            ->assertSessionHasErrors('reason', null, 'rule:infodot.answer.published');

        $this->assertSame(1, RewardRule::where('key', 'infodot.answer.published')->count());
        $this->assertSame(0, AdminAudit::count());
    }

    public function test_invalid_values_are_refused(): void
    {
        $cases = [
            'range with only one end' => ['min_points' => 3, 'max_points' => null, 'points' => 5],
            'range upside down' => ['min_points' => 9, 'max_points' => 3, 'points' => 5],
            'default outside the range' => ['min_points' => 1, 'max_points' => 3, 'points' => 5],
            'negative points' => ['points' => -1],
            'on but pays nothing' => ['points' => 0, 'active' => 1],
            'zero daily cap' => ['daily_cap_points' => 0],
            'no awards per contribution' => ['max_awards_per_contribution' => 0],
            'words for points' => ['points' => 'lots'],
        ];

        foreach ($cases as $name => $input) {
            $this->publish('infodot.answer.published', $input)->assertSessionHasErrors(null, null, 'rule:infodot.answer.published');
            $this->assertSame(1, RewardRule::where('key', 'infodot.answer.published')->count(), $name);
        }
    }

    public function test_publishing_nothing_new_is_refused(): void
    {
        $this->publish('infodot.answer.published')->assertSessionHasErrors('reason', null, 'rule:infodot.answer.published');

        $this->assertSame(1, RewardRule::where('key', 'infodot.answer.published')->count());
    }

    public function test_a_form_based_on_a_stale_version_is_refused(): void
    {
        RewardRule::revise('infodot.answer.published', ['points' => 6]);

        $this->actingAs($this->admin)->post(route('admin.points.rules.publish', 'infodot.answer.published'), [
            'based_on_version' => 1, 'points' => 9, 'max_awards_per_contribution' => 1, 'reason' => 'I was looking at an old page.',
        ])->assertSessionHasErrors('based_on_version', null, 'rule:infodot.answer.published');

        $this->assertSame(2, RewardRule::latestVersion('infodot.answer.published')->version);
    }

    public function test_a_rule_can_be_switched_off_and_then_pays_nothing(): void
    {
        $this->publish('infodot.answer.published', ['active' => 0, 'reason' => 'Paused while we review spam reports.'])->assertSessionHasNoErrors();

        $this->assertNull(RewardRule::current('infodot.answer.published'));
        $this->assertFalse(RewardRule::latestVersion('infodot.answer.published')->active);
    }

    public function test_a_rule_with_a_range_can_be_published(): void
    {
        $this->publish('infodot.tutorial.approved', ['min_points' => 12, 'max_points' => 30, 'points' => 15])->assertSessionHasNoErrors();

        $v2 = RewardRule::latestVersion('infodot.tutorial.approved');
        $this->assertSame([12, 30, 15], [$v2->min_points, $v2->max_points, $v2->points]);
    }

    public function test_an_unknown_rule_is_a_404_and_a_non_administrator_cannot_publish(): void
    {
        $this->actingAs($this->admin)->post(route('admin.points.rules.publish', 'no.such.rule'), ['based_on_version' => 1, 'points' => 1, 'max_awards_per_contribution' => 1, 'reason' => 'This rule does not exist.'])->assertNotFound();

        $this->actingAs(User::factory()->create())->post(route('admin.points.rules.publish', 'infodot.answer.published'), [
            'based_on_version' => 1, 'points' => 500, 'max_awards_per_contribution' => 1, 'reason' => 'I would like more points please.',
        ])->assertForbidden();

        $this->assertSame(1, RewardRule::where('key', 'infodot.answer.published')->count());
    }

    public function test_the_migration_closes_versions_that_were_replaced_before_the_column_existed(): void
    {
        RewardRule::revise('infodot.answer.published', ['points' => 6]);
        RewardRule::revise('infodot.answer.published', ['points' => 7]);
        DB::table('reward_rules')->update(['effective_to' => null]);

        (require database_path('migrations/2026_10_22_100002_add_rule_effective_to_and_reward_stock.php'))->up();

        $versions = RewardRule::where('key', 'infodot.answer.published')->orderBy('version')->get();
        $this->assertTrue($versions[0]->effective_to->equalTo($versions[1]->effective_from));
        $this->assertTrue($versions[1]->effective_to->equalTo($versions[2]->effective_from));
        $this->assertNull($versions[2]->effective_to, 'the newest version stays open');
    }
}
