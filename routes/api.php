<?php

use App\Http\Controllers\Api\ContributionEventController;
use App\Http\Controllers\Api\PointsExpiryWarningController;
use App\Http\Controllers\Api\PointsRedemptionController;
use App\Http\Controllers\Api\PointsRewardsController;
use App\Http\Controllers\Api\PointsSummaryController;
use App\Http\Controllers\Intelligence\InterventionController;
use App\Http\Middleware\VerifyPointsSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Dot.Dopemine's side of the ecosystem intelligence loop (Dot.Brain
// ADR-0015): carry out an engagement intervention Dot.Brain decided on.
// Dopemine acts; it does not decide -- and it refuses any mechanic that
// is not certified, loop or no loop.
Route::middleware('auth:sanctum')->prefix('intelligence')->group(function () {
    Route::post('/interventions', [InterventionController::class, 'store']);
});

// Platforms report what happened to a contribution (signed server to server, no session). The Dot Points ledger
// decides what it is worth; see config/points.php and App\Services\Points\ContributionEventProcessor.
Route::post('/ingest/contributions', ContributionEventController::class)
    ->middleware('throttle:600,1')
    ->name('api.ingest.contributions');

// The price list and spending, for a platform's server on behalf of a signed-in person (docs/points-redemption-api.md).
// Registered BEFORE the summary route, whose {userUuid} would otherwise swallow `rewards` and `redemptions`.
Route::middleware(['throttle:300,1', VerifyPointsSignature::class])->prefix('points')->group(function () {
    Route::get('/rewards', PointsRewardsController::class)->name('api.points.rewards');
    Route::get('/redemptions', [PointsRedemptionController::class, 'index'])->name('api.points.redemptions.index');
    Route::post('/redemptions', [PointsRedemptionController::class, 'store'])->name('api.points.redemptions.store');
});

// Expiry heads-ups InfoDot pulls and delivers itself (docs/points-expiry-warnings-api.md). Own rate-limit bucket, and
// registered BEFORE the summary route like the group above.
Route::middleware(['throttle:120,1,expiry-warnings', VerifyPointsSignature::class])->prefix('points/expiry-warnings')->group(function () {
    Route::get('/', [PointsExpiryWarningController::class, 'index'])->name('api.points.expiry-warnings.index');
    Route::post('/{warning}/result', [PointsExpiryWarningController::class, 'result'])->name('api.points.expiry-warnings.result');
});

// What a person's profile on a platform shows: balance, expiring points, history. Signed like the intake.
Route::get('/points/{userUuid}', PointsSummaryController::class)
    ->middleware('throttle:300,1')
    ->name('api.points.summary');
