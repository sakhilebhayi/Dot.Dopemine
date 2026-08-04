<?php

namespace Tests\Feature\Dopemine;

use App\Livewire\MechanicCatalog;
use App\Models\Mechanic;
use App\Models\MechanicDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for the null-currentTeam crash pattern (see
 * Dot.Mines commit 0cc4362 and this repo's wiki.md change log): a user's
 * current_team_id can be null for an existing, authenticated account —
 * Jetstream's Team::removeUser() (called from App\Actions\Jetstream\
 * RemoveTeamMember) nulls it out when a user is removed from whichever
 * team happens to be their current one, and nothing re-points it at
 * their personal team afterward.
 *
 * Before the fix, App\Livewire\MechanicCatalog::deployToCurrentTeam()
 * read `auth()->user()->currentTeam` with no null check and passed the
 * result straight into App\Actions\Dopemine\DeployMechanic::deploy(Team
 * $team, ...), whose parameter is non-nullable — so a team-less user
 * hitting "Deploy" got a fatal TypeError instead of a clean 403.
 */
class MechanicCatalogNoTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_with_no_current_team_gets_a_403_instead_of_a_crash_when_deploying(): void
    {
        $user = User::factory()->create(['current_team_id' => null]);
        $mechanic = Mechanic::factory()->certified()->create();

        Livewire::actingAs($user)
            ->test(MechanicCatalog::class)
            ->call('deployToCurrentTeam', $mechanic->id)
            ->assertForbidden();

        $this->assertSame(0, MechanicDeployment::query()->count());
    }

    public function test_a_user_with_no_current_team_cannot_govern(): void
    {
        $user = User::factory()->create(['current_team_id' => null]);

        Livewire::actingAs($user)
            ->test(MechanicCatalog::class)
            ->call('certify', Mechanic::factory()->create()->id)
            ->assertForbidden();
    }

    public function test_a_user_with_a_current_team_can_still_deploy(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();

        Livewire::actingAs($user)
            ->test(MechanicCatalog::class)
            ->call('deployToCurrentTeam', $mechanic->id);

        $this->assertSame(1, MechanicDeployment::where('team_id', $user->currentTeam->id)->count());
    }
}
