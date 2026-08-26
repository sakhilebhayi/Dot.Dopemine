<?php

use App\Http\Controllers\Intelligence\InterventionController;
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
