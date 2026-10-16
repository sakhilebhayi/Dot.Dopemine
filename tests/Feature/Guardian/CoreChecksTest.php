<?php

namespace Tests\Feature\Guardian;

use App\Services\Guardian\Checks\CacheCheck;
use App\Services\Guardian\Checks\DatabaseCheck;
use App\Services\Guardian\Checks\ErrorRateCheck;

use App\Services\Guardian\ErrorCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoreChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_check_is_healthy_when_the_database_answers(): void
    {
        $result = app(DatabaseCheck::class)->run();

        $this->assertSame('healthy', $result->status());
        $this->assertArrayHasKey('latency_ms', $result->toArray()['metrics']);
    }

    public function test_cache_check_round_trips_a_value(): void
    {
        $this->assertSame('healthy', app(CacheCheck::class)->run()->status());
    }

    public function test_error_rate_counts_reported_exceptions_and_escalates(): void
    {
        config(['guardian.errors.warning_per_hour' => 2, 'guardian.errors.critical_per_hour' => 4]);
        $counter = app(ErrorCounter::class);

        $this->assertSame('healthy', app(ErrorRateCheck::class)->run()->status());

        $counter->record(new \RuntimeException('one'));
        $counter->record(new \RuntimeException('two'));
        $result = app(ErrorRateCheck::class)->run();
        $this->assertSame('warning', $result->status());
        $this->assertSame('two', $result->toArray()['metrics']['last_error']['message']);

        $counter->record(new \RuntimeException('three'));
        $counter->record(new \RuntimeException('four'));
        $this->assertSame('critical', app(ErrorRateCheck::class)->run()->status());
    }

    public function test_reported_exceptions_reach_the_counter_through_the_handler(): void
    {
        report(new \RuntimeException('reported'));

        $this->assertSame(1, app(ErrorCounter::class)->countForHour(now()));
    }
}
