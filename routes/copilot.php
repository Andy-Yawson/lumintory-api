<?php

use App\Http\Controllers\Api\CopilotContextController;
use Illuminate\Support\Facades\Route;

// Zinnvy AI Co-Pilot: signed page context for the signed-in user, and a membership check for Zinnvy AI.
Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::post('/copilot/context', [CopilotContextController::class, 'issue'])->middleware('throttle:60,1');
});

Route::prefix('v1/integrations/ai')->middleware(['integration.auth:ai:read', 'throttle:120,1'])->group(function () {
    Route::get('/members/{sub}', [CopilotContextController::class, 'member']);
});
