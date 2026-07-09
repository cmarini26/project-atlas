<?php

use App\Http\Controllers\App\AnalyticsController;
use App\Http\Controllers\App\BusinessBrainController;
use App\Http\Controllers\App\CampaignController;
use App\Http\Controllers\App\CompanySelectorController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\LearningController;
use App\Http\Controllers\App\OpportunityController;
use App\Http\Controllers\App\PublishingController;
use App\Http\Controllers\App\RecommendationController;
use App\Http\Controllers\App\SettingsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;

// ── Public ──────────────────────────────────────────────────────────────────
Route::get('/', fn () => redirect()->route('login'));

// ── Auth ─────────────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.attempt');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register.attempt');

    // Password reset
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:5,1')->name('password.update');
});

Route::middleware('auth')->post('/logout', [AuthController::class, 'logout'])->name('logout');

// ── Onboarding ────────────────────────────────────────────────────────────────
Route::middleware('auth')->group(function (): void {
    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding');
    Route::post('/onboarding/company', [OnboardingController::class, 'createCompany'])->name('onboarding.company');
    // Throttled: each submit can queue a crawl + AI pipeline run (real spend).
    Route::post('/onboarding/integration', [OnboardingController::class, 'createIntegration'])->middleware('throttle:3,1')->name('onboarding.integration');
    Route::post('/onboarding/marketing-presence', [OnboardingController::class, 'saveMarketingPresence'])->name('onboarding.marketing-presence');
    Route::get('/onboarding/status', [OnboardingController::class, 'status'])->name('onboarding.status');
});

// ── Company selector (multiple memberships) ───────────────────────────────────
Route::middleware('auth')->group(function (): void {
    Route::get('/company/select', [CompanySelectorController::class, 'index'])->name('company.select');
    Route::post('/company/select', [CompanySelectorController::class, 'select'])->name('company.select.post');
});

// ── Customer dashboard ────────────────────────────────────────────────────────
Route::middleware(['auth', 'company'])->prefix('app')->name('app.')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/brain', [BusinessBrainController::class, 'index'])->name('brain');

    Route::get('/opportunities', [OpportunityController::class, 'index'])->name('opportunities');

    // Recommendation workflow
    Route::get('/recommendations', [RecommendationController::class, 'index'])->name('recommendations.index');
    Route::get('/recommendations/{recommendation}', [RecommendationController::class, 'show'])->name('recommendations.show');
    Route::post('/recommendations/{recommendation}/approve', [RecommendationController::class, 'approve'])->name('recommendations.approve');
    Route::post('/recommendations/{recommendation}/approve-edit', [RecommendationController::class, 'approveEdit'])->name('recommendations.approve-edit');
    Route::post('/recommendations/{recommendation}/reject', [RecommendationController::class, 'reject'])->name('recommendations.reject');

    // Campaigns
    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');

    // Publishing
    Route::get('/publishing', [PublishingController::class, 'index'])->name('publishing');

    // Analytics
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/analytics/{campaign}', [AnalyticsController::class, 'show'])->name('analytics.show');

    // Learning
    Route::get('/learning', [LearningController::class, 'index'])->name('learning');

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/integrations/{integration}/sync', [SettingsController::class, 'syncIntegration'])->name('settings.integrations.sync');
});
