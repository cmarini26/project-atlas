<?php

use App\Http\Controllers\Api\OnboardingStatusController;
use App\Http\Controllers\App\AnalyticsController;
use App\Http\Controllers\App\BusinessBrainController;
use App\Http\Controllers\App\CampaignController;
use App\Http\Controllers\App\CompanySelectorController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\FeedbackController;
use App\Http\Controllers\App\LearningController;
use App\Http\Controllers\App\MarketingHealthController;
use App\Http\Controllers\App\MarketingPresenceController;
use App\Http\Controllers\App\MetaOAuthController;
use App\Http\Controllers\App\OnboardingChecklistController;
use App\Http\Controllers\App\OpportunityController;
use App\Http\Controllers\App\ProductTourController;
use App\Http\Controllers\App\PublishingController;
use App\Http\Controllers\App\RecommendationController;
use App\Http\Controllers\App\SettingsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ── Public ──────────────────────────────────────────────────────────────────
// Signed-in visitors go straight to their dashboard; everyone else sees the
// marketing landing page (docs/marketing/Landing-Page.md).
Route::get('/', fn () => auth()->check()
    ? redirect()->route('app.dashboard')
    : Inertia::render('Marketing/Landing'))->name('home');

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
// Business Discovery Onboarding (see docs/specs/Business-Discovery-Onboarding.md).
// Seven wizard steps — Welcome (client-side only, no route), Company, Business
// Goals, Marketing Assets, Asset Details, Marketing Preferences, Discovery
// Placeholder — are pure data collection; finish() is the single point that
// starts real Discovery orchestration (App\Services\Discovery\BusinessDiscoveryService),
// and onboarding.discovery.retry is the single recovery path for a stuck or
// partially-failed run. This is the only onboarding execution path — there is
// no separate legacy website-only orchestrator.
Route::middleware('auth')->group(function (): void {
    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding');
    Route::post('/onboarding/company', [OnboardingController::class, 'saveCompany'])->name('onboarding.company');
    Route::post('/onboarding/goals', [OnboardingController::class, 'saveGoals'])->name('onboarding.goals');
    Route::post('/onboarding/assets', [OnboardingController::class, 'saveAssets'])->name('onboarding.assets');
    Route::post('/onboarding/asset-details', [OnboardingController::class, 'saveAssetDetails'])->name('onboarding.asset-details');
    Route::post('/onboarding/preferences', [OnboardingController::class, 'savePreferences'])->name('onboarding.preferences');
    Route::post('/onboarding/finish', [OnboardingController::class, 'finish'])->name('onboarding.finish');
    Route::post('/onboarding/discovery/retry', [OnboardingController::class, 'retryDiscovery'])->name('onboarding.discovery.retry');
    Route::get('/onboarding/status', [OnboardingController::class, 'status'])->name('onboarding.status');
    Route::get('/api/onboarding/status', [OnboardingStatusController::class, 'show'])->name('api.onboarding.status');
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

    Route::get('/marketing-health', [MarketingHealthController::class, 'index'])->name('marketing-health');

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
    Route::post('/settings/integrations/instagram', [SettingsController::class, 'connectInstagram'])->name('settings.integrations.instagram.connect');

    // Meta publishing OAuth (Instagram/Facebook) — distinct from the
    // Instagram *observation* integration above, which uses a manually
    // pasted access token and feeds the Business Brain, not publishing.
    Route::get('/settings/meta/connect', [MetaOAuthController::class, 'redirect'])->name('settings.meta.connect');
    Route::get('/settings/meta/callback', [MetaOAuthController::class, 'callback'])->name('settings.meta.callback');
    Route::post('/settings/meta/revoke', [MetaOAuthController::class, 'revoke'])->name('settings.meta.revoke');

    // WordPress publishing — Application Passwords (manual entry), no OAuth.
    Route::post('/settings/wordpress/connect', [SettingsController::class, 'connectWordPress'])->name('settings.wordpress.connect');
    Route::post('/settings/wordpress/revoke', [SettingsController::class, 'disconnectWordPress'])->name('settings.wordpress.revoke');


    // Email publishing (Postmark) — Server API Token (manual entry), no OAuth.
    Route::post('/settings/email/connect', [SettingsController::class, 'connectEmail'])->name('settings.email.connect');
    Route::post('/settings/email/revoke', [SettingsController::class, 'disconnectEmail'])->name('settings.email.revoke');
    Route::post('/settings/email/test', [SettingsController::class, 'sendEmailTest'])->name('settings.email.test');
    // Marketing Presence
    Route::get('/settings/marketing-presence', [MarketingPresenceController::class, 'index'])->name('settings.marketing-presence');
    Route::post('/settings/marketing-presence', [MarketingPresenceController::class, 'store'])->name('settings.marketing-presence.store');
    Route::patch('/settings/marketing-presence/{marketingChannel}', [MarketingPresenceController::class, 'update'])->name('settings.marketing-presence.update');
    Route::delete('/settings/marketing-presence/{marketingChannel}', [MarketingPresenceController::class, 'destroy'])->name('settings.marketing-presence.destroy');

    // Product tour
    Route::post('/tour/complete', [ProductTourController::class, 'complete'])->name('tour.complete');

    // Post-onboarding checklist
    Route::post('/checklist/dismiss', [OnboardingChecklistController::class, 'dismiss'])->name('checklist.dismiss');

    // Feedback
    Route::post('/feedback', [FeedbackController::class, 'store'])->name('feedback.store');
});
