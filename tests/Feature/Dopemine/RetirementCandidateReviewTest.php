<?php

namespace Tests\Feature\Dopemine;

use App\Enums\MechanicStatus;
use App\Livewire\MechanicCatalog;
use App\Models\Mechanic;
use App\Models\MechanicRetirementCandidate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RetirementCandidateReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_confirm_a_retirement_candidate_which_decertifies_the_mechanic(): void
    {
        $admin = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();
        $candidate = MechanicRetirementCandidate::create([
            'mechanic_id' => $mechanic->id,
            'coupling_rate' => 0.2,
            'sample_size' => 3,
            'status' => 'open',
            'detected_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(MechanicCatalog::class)
            ->call('confirmRetirementCandidate', $candidate->id);

        $mechanic->refresh();
        $this->assertSame(MechanicStatus::Decertified, $mechanic->status);
        $this->assertNotNull($mechanic->decertification_reason);

        $candidate->refresh();
        $this->assertSame('confirmed', $candidate->status);
        $this->assertSame($admin->id, $candidate->reviewed_by);
        $this->assertNotNull($candidate->reviewed_at);
    }

    public function test_an_admin_can_dismiss_a_retirement_candidate_leaving_the_mechanic_certified(): void
    {
        $admin = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();
        $candidate = MechanicRetirementCandidate::create([
            'mechanic_id' => $mechanic->id,
            'coupling_rate' => 0.2,
            'sample_size' => 3,
            'status' => 'open',
            'detected_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(MechanicCatalog::class)
            ->call('startDismissingCandidate', $candidate->id)
            ->set('dismissalNotes', 'Confirmed false positive — one deployment had a data entry error.')
            ->call('confirmDismissCandidate');

        $mechanic->refresh();
        $this->assertSame(MechanicStatus::Certified, $mechanic->status);

        $candidate->refresh();
        $this->assertSame('dismissed', $candidate->status);
        $this->assertSame($admin->id, $candidate->reviewed_by);
        $this->assertSame('Confirmed false positive — one deployment had a data entry error.', $candidate->review_notes);
    }

    public function test_dismissing_a_candidate_requires_notes(): void
    {
        $admin = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();
        $candidate = MechanicRetirementCandidate::create([
            'mechanic_id' => $mechanic->id,
            'coupling_rate' => 0.2,
            'sample_size' => 3,
            'status' => 'open',
            'detected_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(MechanicCatalog::class)
            ->call('startDismissingCandidate', $candidate->id)
            ->set('dismissalNotes', '')
            ->call('confirmDismissCandidate')
            ->assertHasErrors(['dismissalNotes']);

        $this->assertSame('open', $candidate->fresh()->status);
    }

    public function test_a_non_admin_cannot_confirm_a_retirement_candidate(): void
    {
        $member = User::factory()->create(['current_team_id' => null]);
        $mechanic = Mechanic::factory()->certified()->create();
        $candidate = MechanicRetirementCandidate::create([
            'mechanic_id' => $mechanic->id,
            'coupling_rate' => 0.2,
            'sample_size' => 3,
            'status' => 'open',
            'detected_at' => now(),
        ]);

        Livewire::actingAs($member)
            ->test(MechanicCatalog::class)
            ->call('confirmRetirementCandidate', $candidate->id)
            ->assertForbidden();

        $this->assertSame(MechanicStatus::Certified, $mechanic->fresh()->status);
        $this->assertSame('open', $candidate->fresh()->status);
    }

    public function test_the_catalog_view_lists_open_retirement_candidates_for_an_admin(): void
    {
        $admin = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create(['name' => 'Milestone Recognition']);
        MechanicRetirementCandidate::create([
            'mechanic_id' => $mechanic->id,
            'coupling_rate' => 0.25,
            'sample_size' => 4,
            'status' => 'open',
            'detected_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(MechanicCatalog::class)
            ->assertSee('Milestone Recognition')
            ->assertSee('Retirement Candidates');
    }
}
