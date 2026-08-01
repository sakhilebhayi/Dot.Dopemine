<?php

namespace Database\Factories;

use App\Enums\MechanicCategory;
use App\Enums\MechanicStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Mechanic>
 */
class MechanicFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(3),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(12),
            'category' => $this->faker->randomElement(MechanicCategory::cases())->value,
            'status' => MechanicStatus::Proposed->value,
            'acid_test_passed' => false,
        ];
    }

    /**
     * A mechanic with a recorded, passing acid-test verdict but not yet
     * certified (i.e. ready for CertifyMechanic).
     */
    public function acidTestPassed(): static
    {
        return $this->state(fn () => [
            'acid_test_passed' => true,
            'acid_test_notes' => 'Acid test recorded by test factory: would show this to the person it targets, intent labeled, without hesitation.',
        ]);
    }

    /**
     * A fully certified mechanic (acid test passed + status certified),
     * useful for tests that need a deployable mechanic without exercising
     * the CertifyMechanic action itself.
     */
    public function certified(): static
    {
        return $this->acidTestPassed()->state(fn () => [
            'status' => MechanicStatus::Certified->value,
            'certified_at' => now(),
        ]);
    }
}
