<?php

namespace Tests\Feature\Dopemine;

use App\Livewire\MechanicDeployments;
use App\Models\Mechanic;
use App\Models\MechanicDeployment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for the cross-team IDOR bug class this session found
 * repeatedly elsewhere in the ecosystem (Dot.Tasks, Dot.Finance, etc.): a
 * team-scoped record looked up by ID with no ownership check, letting any
 * authenticated user read/mutate another team's data.
 *
 * MechanicDeployment carries team_id (wiki.md §4.1) and App\Livewire\
 * MechanicDeployments::retire() already scopes its lookup by the current
 * user's team before findOrFail(). This test encodes that guarantee as a
 * permanent regression test rather than leaving it as something re-verified
 * by inspection each pass.
 */
class MechanicDeploymentTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_cannot_retire_another_teams_deployment_by_guessing_its_id(): void
    {
        $ownerTeam = Team::factory()->create();
        $mechanic = Mechanic::factory()->certified()->create();

        $otherTeamsDeployment = MechanicDeployment::factory()->create([
            'team_id' => $ownerTeam->id,
            'mechanic_id' => $mechanic->id,
            'status' => 'active',
        ]);

        $attacker = User::factory()->withPersonalTeam()->create();

        // retire() scopes the query to the current team — findOrFail throws
        // ModelNotFoundException for cross-tenant IDs (becomes 404 in the
        // HTTP layer), matching the convention used across the ecosystem
        // (e.g. Dot.Agents WorkflowList::deleteWorkflow).
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($attacker)
            ->test(MechanicDeployments::class)
            ->call('retire', $otherTeamsDeployment->id);
    }

    public function test_another_teams_deployment_status_is_unchanged_after_a_rejected_retire_attempt(): void
    {
        $ownerTeam = Team::factory()->create();
        $mechanic = Mechanic::factory()->certified()->create();

        $otherTeamsDeployment = MechanicDeployment::factory()->create([
            'team_id' => $ownerTeam->id,
            'mechanic_id' => $mechanic->id,
            'status' => 'active',
        ]);

        $attacker = User::factory()->withPersonalTeam()->create();

        try {
            Livewire::actingAs($attacker)
                ->test(MechanicDeployments::class)
                ->call('retire', $otherTeamsDeployment->id);
        } catch (ModelNotFoundException) {
            // expected — assert the guarded state below
        }

        $this->assertSame(
            'active',
            $otherTeamsDeployment->fresh()->status->value,
            'A deployment belonging to another team must not be retirable by ID guessing.'
        );
    }

    public function test_a_user_can_retire_their_own_teams_deployment(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();

        $deployment = MechanicDeployment::factory()->create([
            'team_id' => $user->currentTeam->id,
            'mechanic_id' => $mechanic->id,
            'status' => 'active',
        ]);

        Livewire::actingAs($user)
            ->test(MechanicDeployments::class)
            ->call('retire', $deployment->id);

        $this->assertSame('retired', $deployment->fresh()->status->value);
    }

    public function test_the_deployments_list_only_shows_the_current_teams_deployments(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $otherTeam = Team::factory()->create();

        $ownMechanic = Mechanic::factory()->certified()->create(['name' => 'Own Team Mechanic']);
        $otherMechanic = Mechanic::factory()->certified()->create(['name' => 'Other Team Mechanic']);

        MechanicDeployment::factory()->create([
            'team_id' => $user->currentTeam->id,
            'mechanic_id' => $ownMechanic->id,
        ]);

        MechanicDeployment::factory()->create([
            'team_id' => $otherTeam->id,
            'mechanic_id' => $otherMechanic->id,
        ]);

        Livewire::actingAs($user)
            ->test(MechanicDeployments::class)
            ->assertSee('Own Team Mechanic')
            ->assertDontSee('Other Team Mechanic');
    }

    /**
     * MechanicDeployments::retire() and ::deployments() no longer contain an
     * explicit where('team_id', ...) call — that guard now lives solely in
     * MechanicDeployment's HasTeamScope global scope (app/Models/Concerns/
     * HasTeamScope.php). This test bypasses the Livewire component entirely
     * and queries the model directly, proving the scope itself — not a
     * controller/component-level check — is what makes another team's row
     * invisible. Mirrors Dot.Finance's
     * test_scope_alone_blocks_cross_user_access_even_without_a_policy_check.
     */
    public function test_scope_alone_blocks_cross_team_access_even_without_a_policy_check(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $attacker = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();

        $deployment = MechanicDeployment::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'mechanic_id' => $mechanic->id,
            'status' => 'active',
        ]);

        $this->actingAs($attacker);

        $this->assertNull(MechanicDeployment::find($deployment->id));
        $this->assertSame(0, MechanicDeployment::query()->count());

        $this->actingAs($owner);

        $this->assertNotNull(MechanicDeployment::find($deployment->id));
        $this->assertSame(1, MechanicDeployment::query()->count());
    }
}
