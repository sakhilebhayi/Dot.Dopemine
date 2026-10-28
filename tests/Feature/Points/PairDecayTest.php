<?php

namespace Tests\Feature\Points;

use App\Models\LedgerEntry;
use App\Models\RewardRule;
use App\Services\Points\AwardOutcome;
use App\Services\Points\PointsLedger;
use Database\Seeders\RewardRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The collusion safeguard (brief section 9): two accounts that reward each other repeatedly earn less and less from each
 * other, down to nothing, while one-way help is never reduced. The rule is data in config('points.pair_decay').
 */
class PairDecayTest extends TestCase
{
    use RefreshDatabase;

    private PointsLedger $ledger;

    private string $a;

    private string $b;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RewardRuleSeeder::class);
        $this->ledger = app(PointsLedger::class);
        $this->a = (string) Str::uuid();
        $this->b = (string) Str::uuid();
    }

    /** `$actor` accepts one of `$author`'s answers (10 points under the starting schedule). */
    private function accept(string $author, string $actor, ?string $key = null): AwardOutcome
    {
        $this->n++;

        return $this->ledger->award($author, RewardRule::current('infodot.answer.accepted'), "infodot:answer:{$this->n}", $key ?? "evt:{$this->n}", null, ['accepted_by' => $actor], true);
    }

    public function test_one_way_help_is_never_reduced(): void
    {
        foreach (range(1, 8) as $i) {
            $outcome = $this->accept($this->a, $this->b);
            $this->assertSame(10, $outcome->entry->points, "Award {$i} must pay in full: B never gets anything from A.");
        }

        $this->assertSame(80, $this->ledger->balance($this->a));
    }

    public function test_two_accounts_that_reward_each_other_earn_less_and_less_then_nothing(): void
    {
        $paid = [
            $this->accept($this->a, $this->b)->entry->points,
            $this->accept($this->b, $this->a)->entry->points,
            $this->accept($this->a, $this->b)->entry->points,
        ];
        $this->assertSame([10, 10, 10], $paid, 'The first three awards between the pair pay in full.');

        $fourth = $this->accept($this->b, $this->a);
        $this->assertSame(5, $fourth->entry->points);
        $this->assertSame(['count' => 3, 'multiplier' => 0.5, 'full_points' => 10], $fourth->entry->meta['pair_decay']);

        $fifth = $this->accept($this->a, $this->b);
        $this->assertSame(2, $fifth->entry->points, 'A quarter of 10, rounded down.');

        $sixth = $this->accept($this->b, $this->a);
        $this->assertSame(AwardOutcome::PAIR_LIMITED, $sixth->status);
        $this->assertNull($sixth->entry);
        $this->assertSame(10 + 10 + 2, $this->ledger->balance($this->a));
        $this->assertSame(10 + 5, $this->ledger->balance($this->b), 'B was paid 10 and 5; the sixth paid nothing.');
    }

    public function test_a_third_person_is_not_affected_by_the_pair(): void
    {
        foreach (range(1, 4) as $i) {
            $this->accept($this->a, $this->b);
            $this->accept($this->b, $this->a);
        }

        $c = (string) Str::uuid();
        $this->assertSame(10, $this->accept($this->a, $c)->entry->points, 'A is paid in full when someone else accepts.');
        $this->assertSame(10, $this->accept($c, $this->a)->entry->points);
    }

    public function test_awards_older_than_the_window_do_not_count(): void
    {
        foreach (range(1, 4) as $i) {
            $this->accept($this->a, $this->b);
            $this->accept($this->b, $this->a);
        }
        // the model refuses updates, so age the rows underneath it
        DB::table('ledger_entries')->update(['created_at' => now()->subDays(40)]);

        $this->assertSame(10, $this->accept($this->a, $this->b)->entry->points);
    }

    public function test_a_reversed_award_no_longer_counts(): void
    {
        $first = $this->accept($this->a, $this->b);
        $this->accept($this->b, $this->a);
        $this->accept($this->a, $this->b);
        $this->ledger->reverse($first->entry, 'invalid', 'rev-1');

        $this->assertSame(10, $this->accept($this->b, $this->a)->entry->points, 'Only two live awards remain between the pair, under the free three.');
    }

    public function test_a_back_office_approval_is_not_counted(): void
    {
        $rule = RewardRule::current('infodot.answer.accepted');
        foreach (range(1, 6) as $i) {
            $out = $this->ledger->award($this->a, $rule, "infodot:answer:adm{$i}", "adm:{$i}", null, ['approved_by' => 'admin:7'], true);
            $this->assertSame(10, $out->entry->points);
        }
    }

    public function test_the_rule_can_be_switched_off(): void
    {
        config(['points.pair_decay.enabled' => false]);

        foreach (range(1, 6) as $i) {
            $this->assertSame(10, $this->accept($this->a, $this->b)->entry->points);
            $this->assertSame(10, $this->accept($this->b, $this->a)->entry->points);
        }
    }

    public function test_replaying_an_event_still_returns_the_original_award_and_does_not_decay(): void
    {
        $first = $this->accept($this->a, $this->b, 'same-key');
        foreach (range(1, 4) as $i) {
            $this->accept($this->b, $this->a);
            $this->accept($this->a, $this->b);
        }

        $replay = $this->ledger->award($this->a, RewardRule::current('infodot.answer.accepted'), 'infodot:answer:1', 'same-key', null, ['accepted_by' => $this->b], true);

        $this->assertSame(AwardOutcome::DUPLICATE, $replay->status);
        $this->assertSame($first->entry->id, $replay->entry->id);
        $this->assertSame(10, LedgerEntry::find($first->entry->id)->points);
    }
}
