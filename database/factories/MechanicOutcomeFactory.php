<?php

namespace Database\Factories;

use App\Models\MechanicDeployment;
use App\Models\MechanicOutcome;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MechanicOutcome>
 */
class MechanicOutcomeFactory extends Factory
{
    public function definition(): array
    {
        $periodStart = $this->faker->dateTimeBetween('-6 months', '-1 month');

        return [
            'deployment_id' => MechanicDeployment::factory(),
            'period_start' => $periodStart,
            'period_end' => (clone $periodStart)->modify('+1 month'),
            'engagement_movement' => $this->faker->randomFloat(4, -0.5, 0.5),
            'outcome_movement' => $this->faker->randomFloat(4, -0.5, 0.5),
            'recorded_by' => User::factory(),
            'notes' => null,
        ];
    }

    /**
     * Engagement rose, outcome did not — the exact failure mode this
     * feature exists to detect (wiki.md §2, §10, §11).
     */
    public function decoupled(): static
    {
        return $this->state(fn () => [
            'engagement_movement' => $this->faker->randomFloat(4, 0.05, 0.5),
            'outcome_movement' => $this->faker->randomFloat(4, -0.3, 0),
        ]);
    }

    /**
     * Engagement and outcome both rose together — the healthy case.
     */
    public function coupled(): static
    {
        return $this->state(fn () => [
            'engagement_movement' => $this->faker->randomFloat(4, 0.05, 0.5),
            'outcome_movement' => $this->faker->randomFloat(4, 0.05, 0.5),
        ]);
    }
}
