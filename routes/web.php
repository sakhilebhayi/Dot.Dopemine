<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Models\Mechanic;
use App\Models\MechanicDeployment;
use App\Models\ProhibitedMetricPattern;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->name('ecosystem.auth');

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
