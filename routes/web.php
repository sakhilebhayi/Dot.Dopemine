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
        $team = auth()->user()->currentTeam;

        return view('dashboard', [
            'certifiedCount' => Mechanic::where('status', 'certified')->count(),
            'proposedCount' => Mechanic::where('status', 'proposed')->count(),
            'decertifiedCount' => Mechanic::where('status', 'decertified')->count(),
            'activeDeployments' => MechanicDeployment::where('team_id', $team->id)->where('status', 'active')->count(),
            'prohibitedPatterns' => ProhibitedMetricPattern::orderBy('pattern')->get(),
        ]);
    })->name('dashboard');

    Route::get('/mechanics', fn () => view('dopemine.catalog'))->name('mechanics.index');
    Route::get('/mechanics/deployments', fn () => view('dopemine.deployments'))->name('mechanics.deployments');
});
