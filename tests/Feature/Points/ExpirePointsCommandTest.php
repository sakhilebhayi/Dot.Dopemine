<?php

namespace Tests\Feature\Points;

use App\Models\LedgerEntry;
use App\Models\RewardRule;
use App\Services\Points\PointsLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpirePointsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deploy_leaves_the_owners_starting_rules_in_place(): void
    {
        $this->assertSame(2, RewardRule::current('infodot.question.published')->points);
        $this->assertSame(10, RewardRule::current('infodot.answer.accepted')->points);
    }

    public function test_the_command_expires_old_lots_once_and_leaves_young_ones(): void
    {
        $ledger = app(PointsLedger::class);
        $user = (string) Str::uuid();
        $ledger->award($user, RewardRule::current('infodot.answer.published'), 'infodot:answer:1', 'k1');
        $this->travelTo(now()->addMonths(13));
        $ledger->award($user, RewardRule::current('infodot.answer.published'), 'infodot:answer:2', 'k2');

        $this->artisan('points:expire')->expectsOutput('Expired 1 lot(s).')->assertSuccessful();
        $this->artisan('points:expire')->expectsOutput('Expired 0 lot(s).')->assertSuccessful();

        $this->assertSame(5, $ledger->balance($user));
        $this->assertSame(1, LedgerEntry::where('type', 'expire')->count());
    }
}
