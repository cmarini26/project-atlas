<?php

use App\Http\Controllers\Api\AnalyticsWebhookController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health'])->name('health');
Route::get('/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/live', [HealthController::class, 'live'])->name('health.live');

Route::post('/analytics/webhooks/{provider}', [AnalyticsWebhookController::class, 'receive'])
    ->name('analytics.webhooks.receive');
