<?php

namespace Tests\Feature\Dopemine;

use App\Livewire\MechanicCatalog;
use App\Models\Mechanic;
use App\Models\MechanicDeployment;
use App\Models\MechanicOutcome;
use App\Models\MechanicRetirementCandidate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScanMechanicDecouplingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mechanic_with_no_outcome_records_is_left_with_a_null_coupling_rate(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();

        $this->artisan('dopemine:scan-decoupling')->assertExitCode(0);

        $mechanic->refresh();
        $this->assertNull($mechanic->coupling_rate);
        $this->assertNull($mechanic->coupling_rate_computed_at);
    }

    public function test_a_fully_coupled_mechanic_gets_a_coupling_rate_of_one_and_no_candidate(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $deployment = MechanicDeployment::factory()->create(['mechanic_id' => $mechanic->id, 'status' => 'active']);

        MechanicOutcome::factory()->for($deployment, 'deployment')->coupled()->create([
            'period_start' => now()->subMonths(2),
            'period_end' => now()->subMonths(2)->addDays(28),
        ]);
        MechanicOutcome::factory()->for($deployment, 'deployment')->coupled()->create([
            'period_start' => now()->subMonth(),
            'period_end' => now()->subMonth()->addDays(28),
        ]);

        $this->artisan('dopemine:scan-decoupling')->assertExitCode(0);

        $mechanic->refresh();
        $this->assertSame('1.0000', $mechanic->coupling_rate);
        $this->assertNotNull($mechanic->coupling_rate_computed_at);
        $this->assertSame(0, MechanicRetirementCandidate::count());
    }

    public function test_the_catalog_view_shows_the_computed_coupling_rate_badge(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create(['name' => 'Progress Bar']);
        $deployment = MechanicDeployment::factory()->create(['mechanic_id' => $mechanic->id, 'status' => 'active']);

        MechanicOutcome::factory()->for($deployment, 'deployment')->coupled()->create([
            'period_start' => now()->subMonth(),
            'period_end' => now()->subMonth()->addDays(28),
        ]);

        $this->artisan('dopemine:scan-decoupling');

        Livewire::actingAs($user)
            ->test(MechanicCatalog::class)
            ->assertSee('coupling: 100%');
    }

    public function test_a_majority_decoupled_mechanic_gets_a_low_coupling_rate_and_a_retirement_candidate(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $deployment = MechanicDeployment::factory()->create(['mechanic_id' => $mechanic->id, 'status' => 'active']);

        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonths(2),
            'period_end' => now()->subMonths(2)->addDays(28),
        ]);
        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonth(),
            'period_end' => now()->subMonth()->addDays(28),
        ]);

        $this->artisan('dopemine:scan-decoupling')->assertExitCode(0);

        $mechanic->refresh();
        $this->assertSame('0.0000', $mechanic->coupling_rate);

        $this->assertDatabaseHas('mechanic_retirement_candidates', [
            'mechanic_id' => $mechanic->id,
            'status' => 'open',
            'sample_size' => 2,
        ]);
    }

    public function test_a_single_decoupled_record_does_not_raise_a_candidate_below_the_sample_size_floor(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $deployment = MechanicDeployment::factory()->create(['mechanic_id' => $mechanic->id, 'status' => 'active']);

        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonth(),
            'period_end' => now()->subMonth()->addDays(28),
        ]);

        $this->artisan('dopemine:scan-decoupling')->assertExitCode(0);

        $this->assertSame(0, MechanicRetirementCandidate::count());
    }

    public function test_outcome_records_older_than_three_months_are_excluded_from_the_window(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $deployment = MechanicDeployment::factory()->create(['mechanic_id' => $mechanic->id, 'status' => 'active']);

        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonths(8),
            'period_end' => now()->subMonths(8)->addDays(28),
        ]);

        $this->artisan('dopemine:scan-decoupling')->assertExitCode(0);

        $mechanic->refresh();
        $this->assertNull($mechanic->coupling_rate);
    }

    public function test_rescanning_refreshes_an_open_candidates_numbers_but_preserves_its_detected_at(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $deployment = MechanicDeployment::factory()->create(['mechanic_id' => $mechanic->id, 'status' => 'active']);

        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonths(2),
            'period_end' => now()->subMonths(2)->addDays(28),
        ]);
        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonth(),
            'period_end' => now()->subMonth()->addDays(28),
        ]);

        $this->artisan('dopemine:scan-decoupling');
        $firstDetectedAt = MechanicRetirementCandidate::first()->detected_at;

        $this->travel(1)->day();

        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subDays(2),
            'period_end' => now()->subDay(),
        ]);

        $this->artisan('dopemine:scan-decoupling');

        $this->assertSame(1, MechanicRetirementCandidate::count());
        $candidate = MechanicRetirementCandidate::first();
        $this->assertSame(3, $candidate->sample_size);
        $this->assertEquals($firstDetectedAt->timestamp, $candidate->detected_at->timestamp);
    }

    public function test_a_decertified_mechanic_is_never_scanned(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $deployment = MechanicDeployment::factory()->create(['mechanic_id' => $mechanic->id, 'status' => 'active']);
        $mechanic->forceFill(['status' => 'decertified'])->save();

        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonth(),
            'period_end' => now()->subMonth()->addDays(28),
        ]);
        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonths(2),
            'period_end' => now()->subMonths(2)->addDays(28),
        ]);

        $this->artisan('dopemine:scan-decoupling');

        $mechanic->refresh();
        $this->assertNull($mechanic->coupling_rate);
        $this->assertSame(0, MechanicRetirementCandidate::count());
    }
}
