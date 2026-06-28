<?php

use App\Http\Controllers\Api\AnalyticsWebhookController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OnboardingStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health'])->name('health');
Route::get('/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/live', [HealthController::class, 'live'])->name('health.live');

Route::post('/analytics/webhooks/{provider}', [AnalyticsWebhookController::class, 'receive'])
    ->name('analytics.webhooks.receive');

Route::middleware('auth:sanctum')->get('/onboarding/status', [OnboardingStatusController::class, 'show'])
    ->name('api.onboarding.status');
