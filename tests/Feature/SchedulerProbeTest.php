<?php

namespace Tests\Feature;

use App\Http\Controllers\SchedulerProbeController;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SchedulerProbeTest extends TestCase
{
    public function test_probe_is_ok_while_the_heartbeat_is_fresh_and_503_otherwise(): void
    {
        Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->subSeconds(40)->toIso8601String());

        $this->getJson('/up/scheduler')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('scheduler.age_seconds', 40)
            ->assertJsonPath('scheduler.stale_after', SchedulerProbeController::SCHEDULER_STALE_SECONDS);

        Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->subMinutes(10)->toIso8601String());
        $this->getJson('/up/scheduler')->assertStatus(503)->assertJsonPath('status', 'stale');

        Cache::forget(SchedulerProbeController::SCHEDULER_KEY);

        $this->getJson('/up/scheduler')->assertStatus(503)->assertJsonPath('scheduler.age_seconds', null);
    }

    public function test_probe_needs_no_authentication(): void
    {
        $this->getJson('/up/scheduler')->assertStatus(503);
    }
}
