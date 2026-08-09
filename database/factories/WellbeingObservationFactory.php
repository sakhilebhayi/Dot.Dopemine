<?php

namespace Database\Factories;

use App\Models\Mechanic;
use App\Models\User;
use App\Models\WellbeingObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WellbeingObservation>
 */
class WellbeingObservationFactory extends Factory
{
    public function definition(): array
    {
        $windowStart = $this->faker->dateTimeBetween('-6 months', '-1 month');

        return [
            'mechanic_id' => Mechanic::factory()->certified(),
            'cohort' => $this->faker->company().' — pilot cohort',
            'window_start' => $windowStart,
            'window_end' => (clone $windowStart)->modify('+1 month'),
            'cohort_size' => $this->faker->numberBetween(50, 500),
            'wellbeing_movement' => $this->faker->randomFloat(4, -0.2, 0.2),
            'recorded_by' => User::factory(),
            'notes' => null,
        ];
    }
}
