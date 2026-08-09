<?php

namespace Tests\Feature\Dopemine;

use App\Livewire\MechanicCatalog;
use App\Models\Mechanic;
use App\Models\User;
use App\Models\WellbeingObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WellbeingObservationRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_record_a_wellbeing_observation(): void
    {
        $admin = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();

        Livewire::actingAs($admin)
            ->test(MechanicCatalog::class)
            ->call('startRecordingWellbeing', $mechanic->id)
            ->set('wellbeingCohort', 'Dot.Projects — pilot team')
            ->set('wellbeingWindowStart', '2026-06-01')
            ->set('wellbeingWindowEnd', '2026-06-30')
            ->set('wellbeingCohortSize', '75')
            ->set('wellbeingMovement', '-0.01')
            ->call('saveWellbeingObservation');

        $this->assertDatabaseHas('wellbeing_observations', [
            'mechanic_id' => $mechanic->id,
            'cohort' => 'Dot.Projects — pilot team',
            'recorded_by' => $admin->id,
        ]);
    }

    public function test_a_non_admin_cannot_record_a_wellbeing_observation(): void
    {
        $member = User::factory()->create(['current_team_id' => null]);
        $mechanic = Mechanic::factory()->certified()->create();

        Livewire::actingAs($member)
            ->test(MechanicCatalog::class)
            ->call('startRecordingWellbeing', $mechanic->id)
            ->assertForbidden();

        $this->assertSame(0, WellbeingObservation::count());
    }

    public function test_a_cohort_size_under_fifty_is_rejected_at_the_form_layer(): void
    {
        $admin = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();

        Livewire::actingAs($admin)
            ->test(MechanicCatalog::class)
            ->call('startRecordingWellbeing', $mechanic->id)
            ->set('wellbeingCohort', 'Tiny cohort')
            ->set('wellbeingWindowStart', '2026-06-01')
            ->set('wellbeingWindowEnd', '2026-06-30')
            ->set('wellbeingCohortSize', '10')
            ->set('wellbeingMovement', '0.0')
            ->call('saveWellbeingObservation')
            ->assertHasErrors(['wellbeingCohortSize']);

        $this->assertSame(0, WellbeingObservation::count());
    }
}
