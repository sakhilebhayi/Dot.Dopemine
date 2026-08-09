<?php

namespace Tests\Feature\Dopemine;

use App\Models\MechanicDeployment;
use App\Models\MechanicOutcome;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MechanicOutcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_outcome_record_requires_both_engagement_and_outcome_movement(): void
    {
        $deployment = MechanicDeployment::factory()->create();
        $recorder = User::factory()->create();

        $this->expectException(QueryException::class);

        MechanicOutcome::create([
            'deployment_id' => $deployment->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'engagement_movement' => 0.12,
            // outcome_movement omitted — must be rejected at the schema level
            'recorded_by' => $recorder->id,
        ]);
    }

    public function test_a_paired_outcome_record_can_be_created(): void
    {
        $deployment = MechanicDeployment::factory()->create();
        $recorder = User::factory()->create();

        $outcome = MechanicOutcome::create([
            'deployment_id' => $deployment->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'engagement_movement' => 0.12,
            'outcome_movement' => -0.03,
            'recorded_by' => $recorder->id,
        ]);

        $this->assertDatabaseHas('mechanic_outcomes', [
            'id' => $outcome->id,
            'deployment_id' => $deployment->id,
        ]);
        $this->assertSame($deployment->id, $outcome->deployment->id);
        $this->assertSame($recorder->id, $outcome->recordedBy->id);
    }

    public function test_the_same_deployment_and_period_cannot_be_recorded_twice(): void
    {
        $deployment = MechanicDeployment::factory()->create();
        $recorder = User::factory()->create();

        MechanicOutcome::create([
            'deployment_id' => $deployment->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'engagement_movement' => 0.1,
            'outcome_movement' => 0.1,
            'recorded_by' => $recorder->id,
        ]);

        $this->expectException(QueryException::class);

        MechanicOutcome::create([
            'deployment_id' => $deployment->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'engagement_movement' => 0.2,
            'outcome_movement' => 0.2,
            'recorded_by' => $recorder->id,
        ]);
    }

    public function test_a_deployment_can_have_multiple_outcome_records_across_periods(): void
    {
        $deployment = MechanicDeployment::factory()->create();

        MechanicOutcome::factory()->for($deployment, 'deployment')->create([
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
        ]);
        MechanicOutcome::factory()->for($deployment, 'deployment')->create([
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ]);

        $this->assertSame(2, MechanicOutcome::where('deployment_id', $deployment->id)->count());
    }
}
