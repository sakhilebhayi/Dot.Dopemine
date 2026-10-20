<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\GuardianHealthController;
use App\Http\Controllers\SchedulerProbeController;
use App\Http\Middleware\AuthenticateGuardian;
use App\Models\Mechanic;
use App\Models\MechanicDeployment;
use App\Models\ProhibitedMetricPattern;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

Route::match(['get', 'post'], '/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
    ->name('ecosystem.auth');

// Public liveness probe (no auth, no secrets): answers 503 once the cron
// scheduler has not been heard from. Watched from GitHub by
// .github/workflows/scheduler-watchdog.yml.
Route::get('/up/scheduler', SchedulerProbeController::class)->name('up.scheduler');

// Guardian observability report for Dot.Brain (dot-guardian/v1): operational
// internals, so bearer-authenticated with a dedicated token (503 when none is
// configured) rather than public like /up and /up/scheduler.
Route::get('/guardian/health', GuardianHealthController::class)
    ->middleware(AuthenticateGuardian::class)
    ->name('guardian.health');

Route::get('/', fn () => view('welcome'));

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively. There's no Jetstream equivalent for a Cookie Policy, so this one is wired by hand,
// following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard', [
            'certifiedCount' => Mechanic::where('status', 'certified')->count(),
            'proposedCount' => Mechanic::where('status', 'proposed')->count(),
            'decertifiedCount' => Mechanic::where('status', 'decertified')->count(),
            // MechanicDeployment::HasTeamScope already restricts this query
            // to the current team; no explicit where('team_id', ...) needed.
            'activeDeployments' => MechanicDeployment::where('status', 'active')->count(),
            'prohibitedPatterns' => ProhibitedMetricPattern::orderBy('pattern')->get(),
        ]);
    })->name('dashboard');

    Route::get('/mechanics', fn () => view('dopemine.catalog'))->name('mechanics.index');
    Route::get('/mechanics/deployments', fn () => view('dopemine.deployments'))->name('mechanics.deployments');
});
