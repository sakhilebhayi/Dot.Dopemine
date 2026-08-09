<?php

use App\Console\Commands\ScanMechanicDecoupling;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
