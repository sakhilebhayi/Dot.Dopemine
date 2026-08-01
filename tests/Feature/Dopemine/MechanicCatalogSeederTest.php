<?php

namespace Tests\Feature\Dopemine;

use App\Models\Mechanic;
use App\Models\ProhibitedMetricPattern;
use App\Models\User;
use Database\Seeders\MechanicCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MechanicCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_only_certified_ethical_mechanics(): void
    {
        User::factory()->withPersonalTeam()->create();

        $this->seed(MechanicCatalogSeeder::class);

        $this->assertGreaterThanOrEqual(5, Mechanic::count());

        // Every seeded mechanic must be certified with a passing acid test —
        // the seeder should not be able to smuggle in an uncertified or
        // unverified example.
        $this->assertSame(0, Mechanic::where('acid_test_passed', false)->count());
        $this->assertSame(0, Mechanic::where('status', '!=', 'certified')->count());
    }

    public function test_seeder_publishes_the_prohibited_metric_list(): void
    {
        User::factory()->withPersonalTeam()->create();

        $this->seed(MechanicCatalogSeeder::class);

        $this->assertTrue(
            ProhibitedMetricPattern::where('pattern', 'like', '%loss framing%')->exists()
        );
        $this->assertGreaterThanOrEqual(5, ProhibitedMetricPattern::count());
    }
}
