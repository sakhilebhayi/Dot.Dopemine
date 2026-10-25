<?php

namespace Tests\Feature\Points\Admin;

use App\Http\Middleware\EnsurePointsAdmin;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{string, string, string}> method, uri, route name of every back-office route */
    private function adminRoutes(): array
    {
        $reward = Reward::create(['key' => 'billing.credit-r50', 'title' => 'R50 billing credit', 'kind' => 'billing_credit', 'price_points' => 5, 'active' => true]);
        $redemption = Redemption::create(['idempotency_key' => 'k1', 'user_uuid' => (string) Str::uuid(), 'reward_id' => $reward->id, 'points' => 5, 'status' => 'pending']);

        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'admin.points.')) {
                continue;
            }

            $uri = '/'.ltrim(preg_replace(
                ['#\{redemption:uuid\}#', '#\{reward\}#', '#\{key\}#'],
                [$redemption->uuid, (string) $reward->id, 'infodot.answer.published'],
                $route->uri(),
            ), '/');

            $routes[] = [$route->methods()[0], $uri, (string) $route->getName()];
        }

        return $routes;
    }

    public function test_every_back_office_route_is_behind_the_admin_gate(): void
    {
        $routes = $this->adminRoutes();

        $this->assertGreaterThanOrEqual(13, count($routes));

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'admin.points.')) {
                $this->assertContains(EnsurePointsAdmin::class, $route->gatherMiddleware(), $route->getName().' is not behind the admin gate');
            }
        }
    }

    public function test_guests_are_sent_to_sign_in_on_every_route(): void
    {
        foreach ($this->adminRoutes() as [$method, $uri, $name]) {
            $response = $this->call($method, $uri);
            $this->assertContains($response->getStatusCode(), [302, 401], "{$name} let a guest through ({$response->getStatusCode()})");
            $this->assertNotSame(200, $response->getStatusCode(), $name);
        }
    }

    public function test_a_signed_in_person_who_is_not_an_administrator_gets_403_on_every_route(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ($this->adminRoutes() as [$method, $uri, $name]) {
            $this->call($method, $uri)->assertForbidden("{$name} was open to a non-administrator");
        }
    }

    public function test_a_team_owner_is_not_an_administrator(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $this->actingAs($owner)->get(route('admin.points.rules'))->assertForbidden();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertDontSee('Dot Points admin');
    }

    public function test_administrators_reach_every_page_and_see_the_navigation(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        foreach (['redemptions', 'rules', 'rewards', 'ledger', 'audit'] as $page) {
            $this->get(route('admin.points.'.$page))->assertOk()->assertSee('Dot Points back-office', false);
        }

        $this->get(route('admin.points.index'))->assertRedirect(route('admin.points.redemptions'));
        $this->get(route('dashboard'))->assertOk()->assertSee('Dot Points admin');
    }

    public function test_the_admin_flag_cannot_be_mass_assigned(): void
    {
        $user = User::create(['name' => 'Eve', 'email' => 'eve@example.test', 'password' => 'secret-password', 'is_admin' => true]);

        $this->assertFalse((bool) $user->fresh()->is_admin);
    }

    public function test_the_command_grants_and_revokes_access_and_refuses_an_unknown_email(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.test']);

        $this->artisan('points:make-admin', ['email' => 'ops@example.test'])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);
        $this->actingAs($user->fresh())->get(route('admin.points.rules'))->assertOk();

        $this->artisan('points:make-admin', ['email' => 'ops@example.test', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_admin);

        $this->artisan('points:make-admin', ['email' => 'nobody@example.test'])->assertFailed();
    }
}
