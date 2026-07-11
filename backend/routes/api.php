<?php

use App\Http\Controllers\Api\AnalyticsWebhookController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health'])->name('health');
Route::get('/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/live', [HealthController::class, 'live'])->name('health.live');

// Unauthenticated by design — the provider's payload signature (verified in
// AnalyticsWebhookController::receive()) is the real gate, not a login. The
// named 'analytics-webhook' limiter (registered in AppServiceProvider) gives
// this route its own isolated rate-limit bucket and logs on rejection.
Route::post('/analytics/webhooks/{provider}', [AnalyticsWebhookController::class, 'receive'])
    ->middleware('throttle:analytics-webhook')
    ->name('analytics.webhooks.receive');
