<?php

namespace Tests\Feature\Dopemine;

use App\Actions\Dopemine\CertifyMechanic;
use App\Actions\Dopemine\DeployMechanic;
use App\Enums\MechanicCategory;
use App\Models\Mechanic;
use App\Models\MechanicDeployment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * The most important test suite in this codebase: it asserts the ethics
 * constraint that is this platform's entire reason for existing (wiki.md §2)
 * is structurally enforced, not merely documented.
 */
class EthicsGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_mechanic_category_is_restricted_to_the_fixed_ethical_allow_list(): void
    {
        $this->expectException(\ValueError::class);

        // "loss-framed-streak" is not a case on App\Enums\MechanicCategory —
        // there is no free-text category, so this cannot be constructed.
        Mechanic::factory()->create(['category' => 'loss-framed-streak']);
    }

    public function test_every_approved_category_is_gain_framed(): void
    {
        $prohibitedWords = ['loss', 'streak-break', 'fomo', 'urgency', 'punish', 'penalty'];

        foreach (MechanicCategory::cases() as $category) {
            foreach ($prohibitedWords as $word) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $word,
                    $category->value.' '.$category->label(),
                    "Category [{$category->value}] must not reference a loss/urgency/FOMO framing."
                );
            }
        }
    }

    public function test_a_mechanic_cannot_be_saved_as_certified_without_a_passing_acid_test(): void
    {
        $this->expectException(RuntimeException::class);

        Mechanic::factory()->create([
            'status' => 'certified',
            'acid_test_passed' => false,
        ]);
    }

    public function test_certify_mechanic_action_refuses_a_mechanic_without_a_passing_acid_test(): void
    {
        $officer = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->create(['acid_test_passed' => false]);

        $this->expectException(ValidationException::class);

        app(CertifyMechanic::class)->certify($officer, $mechanic);
    }

    public function test_certify_mechanic_action_certifies_a_mechanic_with_a_passing_acid_test(): void
    {
        $officer = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->acidTestPassed()->create();

        $certified = app(CertifyMechanic::class)->certify($officer, $mechanic);

        $this->assertTrue($certified->isCertified());
        $this->assertEquals($officer->id, $certified->certified_by);
        $this->assertNotNull($certified->certified_at);
    }

    public function test_a_team_cannot_deploy_a_mechanic_that_is_not_certified(): void
    {
        $team = Team::factory()->create();
        $proposedMechanic = Mechanic::factory()->create(); // status defaults to proposed

        $this->expectException(ValidationException::class);

        app(DeployMechanic::class)->deploy($team, $proposedMechanic);
    }

    public function test_a_team_cannot_deploy_a_decertified_mechanic(): void
    {
        $team = Team::factory()->create();
        $mechanic = Mechanic::factory()->certified()->create();
        $mechanic->forceFill(['status' => 'decertified'])->save();

        $this->expectException(ValidationException::class);

        app(DeployMechanic::class)->deploy($team, $mechanic);
    }

    public function test_a_team_can_deploy_a_certified_mechanic(): void
    {
        $team = Team::factory()->create();
        $mechanic = Mechanic::factory()->certified()->create();

        $deployment = app(DeployMechanic::class)->deploy($team, $mechanic);

        $this->assertDatabaseHas('mechanic_deployments', [
            'id' => $deployment->id,
            'team_id' => $team->id,
            'mechanic_id' => $mechanic->id,
            'status' => 'active',
        ]);
    }

    public function test_the_mechanic_deployment_model_itself_rejects_deploying_an_uncertified_mechanic(): void
    {
        // Bypasses App\Actions\Dopemine\DeployMechanic entirely to prove the
        // gate holds at the model layer too (mass assignment, tinker, etc).
        $team = Team::factory()->create();
        $proposedMechanic = Mechanic::factory()->create();

        $this->expectException(RuntimeException::class);

        MechanicDeployment::create([
            'team_id' => $team->id,
            'mechanic_id' => $proposedMechanic->id,
            'status' => 'active',
            'started_at' => now(),
        ]);
    }
}
