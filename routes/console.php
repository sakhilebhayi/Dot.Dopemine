<?php

use App\Console\Commands\BackupSqliteDatabase;
use App\Console\Commands\ScanMechanicDecoupling;
use App\Http\Controllers\SchedulerProbeController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Scheduled Platform Jobs ──────────────────────────────────────────────────
// This platform's first scheduled process — see
// docs/superpowers/specs/2026-08-09-decoupling-detection-design.md.
Schedule::command(ScanMechanicDecoupling::class)
    ->daily()
    ->withoutOverlapping();

// Liveness of the platform itself. /up/scheduler reports this heartbeat's
// age and answers 503 once it is older than
// SchedulerProbeController::SCHEDULER_STALE_SECONDS; the Scheduler Watchdog
// workflow turns that into a failed (emailed) run every 15 minutes. Same
// shape as Dot.Central's probe so every Dot platform is watched alike.
Schedule::call(fn () => Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->toIso8601String()))
    ->everyMinute()
    ->name('scheduler-heartbeat');

// Nightly copy of the SQLite file (the certified-mechanic catalogue and deployment ledger exist nowhere else). The five SQLite-backed Dot apps
// share one host, so each one backs up at its own minute. No-op on other
// database drivers. See docs/superpowers or the deploy README for restore.
Schedule::command(BackupSqliteDatabase::class)->dailyAt('02:25')->onOneServer();
