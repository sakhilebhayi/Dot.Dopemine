<?php

namespace Tests\Feature\Dopemine;

use App\Models\Mechanic;
use App\Models\User;
use App\Models\WellbeingObservation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WellbeingObservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cohort_smaller_than_fifty_is_rejected(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $recorder = User::factory()->create();

        $this->expectException(RuntimeException::class);

        WellbeingObservation::create([
            'mechanic_id' => $mechanic->id,
            'cohort' => 'Small pilot group',
            'window_start' => '2026-06-01',
            'window_end' => '2026-06-30',
            'cohort_size' => 49,
            'wellbeing_movement' => 0.01,
            'recorded_by' => $recorder->id,
        ]);
    }

    public function test_a_cohort_of_exactly_fifty_is_accepted(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $recorder = User::factory()->create();

        $observation = WellbeingObservation::create([
            'mechanic_id' => $mechanic->id,
            'cohort' => 'Exactly-floor cohort',
            'window_start' => '2026-06-01',
            'window_end' => '2026-06-30',
            'cohort_size' => 50,
            'wellbeing_movement' => 0.01,
            'recorded_by' => $recorder->id,
        ]);

        $this->assertDatabaseHas('wellbeing_observations', ['id' => $observation->id, 'cohort_size' => 50]);
    }

    public function test_an_observation_belongs_to_its_mechanic_and_recorder(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $recorder = User::factory()->create();

        $observation = WellbeingObservation::factory()->for($mechanic)->create(['recorded_by' => $recorder->id]);

        $this->assertSame($mechanic->id, $observation->mechanic->id);
        $this->assertSame($recorder->id, $observation->recordedBy->id);
        $this->assertTrue($mechanic->wellbeingObservations->contains($observation));
    }

    public function test_the_same_mechanic_cohort_and_window_cannot_be_recorded_twice(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $recorder = User::factory()->create();

        WellbeingObservation::create([
            'mechanic_id' => $mechanic->id,
            'cohort' => 'Repeat cohort',
            'window_start' => '2026-06-01',
            'window_end' => '2026-06-30',
            'cohort_size' => 60,
            'wellbeing_movement' => 0.01,
            'recorded_by' => $recorder->id,
        ]);

        $this->expectException(QueryException::class);

        WellbeingObservation::create([
            'mechanic_id' => $mechanic->id,
            'cohort' => 'Repeat cohort',
            'window_start' => '2026-06-01',
            'window_end' => '2026-06-30',
            'cohort_size' => 61,
            'wellbeing_movement' => 0.02,
            'recorded_by' => $recorder->id,
        ]);
    }
}
