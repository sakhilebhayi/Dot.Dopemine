<?php

namespace Tests\Feature\Dopemine;

use App\Livewire\MechanicDeployments;
use App\Models\Mechanic;
use App\Models\MechanicDeployment;
use App\Models\MechanicOutcome;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MechanicOutcomeRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_team_member_can_record_a_paired_outcome_for_their_own_deployment(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();
        $deployment = MechanicDeployment::factory()->create([
            'team_id' => $user->currentTeam->id,
            'mechanic_id' => $mechanic->id,
        ]);

        Livewire::actingAs($user)
            ->test(MechanicDeployments::class)
            ->call('startRecordingOutcome', $deployment->id)
            ->set('outcomePeriodStart', '2026-06-01')
            ->set('outcomePeriodEnd', '2026-06-30')
            ->set('outcomeEngagementMovement', '0.15')
            ->set('outcomeOutcomeMovement', '-0.02')
            ->call('saveOutcome');

        $this->assertDatabaseHas('mechanic_outcomes', [
            'deployment_id' => $deployment->id,
            'recorded_by' => $user->id,
        ]);
    }

    public function test_recording_an_outcome_requires_both_movement_fields(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();
        $deployment = MechanicDeployment::factory()->create([
            'team_id' => $user->currentTeam->id,
            'mechanic_id' => $mechanic->id,
        ]);

        Livewire::actingAs($user)
            ->test(MechanicDeployments::class)
            ->call('startRecordingOutcome', $deployment->id)
            ->set('outcomePeriodStart', '2026-06-01')
            ->set('outcomePeriodEnd', '2026-06-30')
            ->set('outcomeEngagementMovement', '')
            ->set('outcomeOutcomeMovement', '')
            ->call('saveOutcome')
            ->assertHasErrors(['outcomeEngagementMovement', 'outcomeOutcomeMovement']);

        $this->assertSame(0, MechanicOutcome::count());
    }

    public function test_a_user_cannot_record_an_outcome_against_another_teams_deployment(): void
    {
        $ownerTeam = Team::factory()->create();
        $mechanic = Mechanic::factory()->certified()->create();
        $otherTeamsDeployment = MechanicDeployment::factory()->create([
            'team_id' => $ownerTeam->id,
            'mechanic_id' => $mechanic->id,
        ]);

        $attacker = User::factory()->withPersonalTeam()->create();

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($attacker)
            ->test(MechanicDeployments::class)
            ->call('startRecordingOutcome', $otherTeamsDeployment->id)
            ->set('outcomePeriodStart', '2026-06-01')
            ->set('outcomePeriodEnd', '2026-06-30')
            ->set('outcomeEngagementMovement', '0.1')
            ->set('outcomeOutcomeMovement', '0.1')
            ->call('saveOutcome');
    }
}
