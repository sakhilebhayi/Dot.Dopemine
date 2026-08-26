<?php

namespace Tests\Feature\Intelligence;

use App\Enums\MechanicStatus;
use App\Models\Mechanic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dot.Dopemine acts on decisions Dot.Brain already made (ADR-0015) -- but
 * the ethics gate is not something an automated caller can talk its way
 * past. These tests exist to keep that true as the loop grows.
 */
class InterventionApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsService(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'loop_id' => 'loop-1',
            'subject_type' => 'customer',
            'subject_id' => '42',
            'mechanic_ref' => 'mech:milestone-recognition',
            'action_kind' => 'retention_followup',
            'approval_status' => 'approved',
        ], $overrides);
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/intelligence/interventions', [])->assertUnauthorized();
    }

    public function test_a_certified_mechanic_is_deployed(): void
    {
        $this->actingAsService();
        Mechanic::factory()->certified()->create([
            'key' => 'mech:milestone-recognition',
            'name' => 'Milestone recognition',
        ]);

        $this->postJson('/api/intelligence/interventions', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.execution_status', 'succeeded')
            ->assertJsonPath('data.mechanic_ref', 'mech:milestone-recognition');
    }

    public function test_an_uncertified_mechanic_is_refused(): void
    {
        $this->actingAsService();
        Mechanic::factory()->create(['key' => 'mech:streak-pressure']); // proposed, not certified

        $response = $this->postJson('/api/intelligence/interventions', $this->payload([
            'mechanic_ref' => 'mech:streak-pressure',
        ]));

        $response->assertUnprocessable()->assertJsonPath('data.execution_status', 'refused');
        $this->assertStringContainsString('not certified', $response->json('data.reason'));
    }

    public function test_a_decertified_mechanic_is_refused(): void
    {
        $this->actingAsService();
        $mechanic = Mechanic::factory()->certified()->create(['key' => 'mech:retired-one']);
        $mechanic->forceFill(['status' => MechanicStatus::Decertified->value])->save();

        $this->postJson('/api/intelligence/interventions', $this->payload(['mechanic_ref' => 'mech:retired-one']))
            ->assertUnprocessable()
            ->assertJsonPath('data.execution_status', 'refused');
    }

    public function test_an_unknown_mechanic_is_refused_rather_than_invented(): void
    {
        $this->actingAsService();

        $response = $this->postJson('/api/intelligence/interventions', $this->payload([
            'mechanic_ref' => 'mech:does-not-exist',
        ]));

        $response->assertUnprocessable()->assertJsonPath('data.execution_status', 'refused');
        $this->assertStringContainsString('No mechanic is registered', $response->json('data.reason'));
    }

    public function test_an_intervention_still_awaiting_approval_is_not_deployed(): void
    {
        $this->actingAsService();
        Mechanic::factory()->certified()->create(['key' => 'mech:milestone-recognition']);

        $response = $this->postJson('/api/intelligence/interventions', $this->payload([
            'approval_status' => 'pending',
        ]));

        $response->assertUnprocessable()->assertJsonPath('data.execution_status', 'refused');
        $this->assertStringContainsString('needs human approval', $response->json('data.reason'));
    }

    public function test_it_validates_the_envelope(): void
    {
        $this->actingAsService();

        $this->postJson('/api/intelligence/interventions', ['loop_id' => 'l'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_type', 'subject_id', 'mechanic_ref', 'action_kind']);
    }
}
