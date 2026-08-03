<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Models\Mechanic;
use App\Models\MechanicDeployment;
use App\Models\ProhibitedMetricPattern;
use Illuminate\Support\Facades\Route;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->name('ecosystem.auth');

Route::get('/', fn () => view('welcome'));

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
