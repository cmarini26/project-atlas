<?php

use App\Http\Controllers\Api\AnalyticsWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/analytics/webhooks/{provider}', [AnalyticsWebhookController::class, 'receive'])
    ->name('analytics.webhooks.receive');
