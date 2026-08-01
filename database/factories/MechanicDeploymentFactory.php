<?php

namespace Database\Factories;

use App\Models\Mechanic;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\MechanicDeployment>
 */
class MechanicDeploymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'mechanic_id' => Mechanic::factory()->certified(),
            'status' => 'active',
            'started_at' => now(),
        ];
    }
}
