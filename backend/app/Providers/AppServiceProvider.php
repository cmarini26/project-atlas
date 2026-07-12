<?php

namespace App\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\LocalAiProvider;
use App\AI\Testing\FakeAiProvider;
use App\ErrorTracking\Contracts\ErrorTracker;
use App\ErrorTracking\NullErrorTracker;
use App\Events\CampaignAssetsReady;
use App\Events\DecisionCommitted;
use App\Events\ExecutionCompleted;
use App\Events\FactExtracted;
use App\Events\KnowledgeSynthesized;
use App\Events\MarketingPresenceUpdated;
use App\Events\ObservationProcessed;
use App\Events\ObservationRecorded;
use App\Events\OpportunityDetected;
use App\Events\RecommendationApproved;
use App\Events\RecommendationCreated;
use App\Listeners\DispatchCampaignPreparation;
use App\Listeners\DispatchObservationProcessing;
use App\Listeners\ScheduleMetricRetrieval;
use App\Listeners\SendWelcomeEmailOnFirstRecommendation;
use App\Listeners\TriggerCampaignPublishing;
use App\Listeners\TriggerDecisionEvaluation;
use App\Listeners\TriggerOpportunityDetection;
use App\Listeners\TriggerRecommendationCreation;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Services\Analyst\AnalystRegistry;
use App\Services\Analyst\InstagramAnalyst;
use App\Services\Analyst\WebsiteAnalyst;
use App\Services\Analytics\AnalyticsProviderRegistry;
use App\Services\Analytics\FakeAnalyticsProvider;
use App\Services\Brain\BusinessBrainService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // All listeners are registered explicitly in boot(). Disable auto-discovery
        // to prevent duplicate registrations when both mechanisms run together.
        EventServiceProvider::disableEventDiscovery();
        // Bind the AI provider appropriate for each environment.
        // - testing: FakeAiProvider — test-controlled via queueFixture().
        // - local without ANTHROPIC_API_KEY: LocalAiProvider — deterministic stubs,
        //   no API key needed. Combine with QUEUE_CONNECTION=sync so the full
        //   pipeline runs end-to-end in a single HTTP request without queue workers.
        // - local with ANTHROPIC_API_KEY, production/staging: AnthropicProvider.
        if ($this->app->environment('testing')) {
            $this->app->singleton(AiProvider::class, FakeAiProvider::class);
        } elseif ($this->app->environment('local') && empty(config('services.anthropic.api_key'))) {
            $this->app->singleton(AiProvider::class, LocalAiProvider::class);
        } else {
            $this->app->singleton(AiProvider::class, AnthropicProvider::class);
        }

        // Resolves the right Analyst per Observation source_type — mirrors
        // ConnectorServiceProvider's ConnectorRegistry binding. Adding a new
        // observation source (Milestone 12 Phase 1: Instagram) only means
        // adding its Analyst here, never touching ProcessObservation.
        $this->app->singleton(AnalystRegistry::class, function ($app): AnalystRegistry {
            return new AnalystRegistry([
                $app->make(WebsiteAnalyst::class),
                $app->make(InstagramAnalyst::class),
            ]);
        });

        // Only 'null' has a real implementation today (no vendor package is
        // installed yet — see Critical-Production-Blockers.md Blocker 5).
        // Forced to null in testing regardless of config so test runs never
        // attempt to phone home; a future real driver (e.g. Sentry) only
        // needs a new case added below, not a change to withExceptions()'s
        // wiring in bootstrap/app.php.
        $this->app->singleton(ErrorTracker::class, function (): ErrorTracker {
            if ($this->app->environment('testing')) {
                return new NullErrorTracker();
            }

            return match (config('services.error_tracking.driver')) {
                // 'sentry' => new SentryErrorTracker(...),
                default => new NullErrorTracker(),
            };
        });

        if ($this->app->environment('testing')) {
            // FakeAnalyticsProvider is registered as the catch-all in testing.
            // Register it AFTER the registry is booted via AnalyticsServiceProvider,
            // using afterResolving so it is prepended before LogAnalyticsProvider.
            $this->app->singleton(FakeAnalyticsProvider::class, FakeAnalyticsProvider::class);
            $this->app->afterResolving(
                AnalyticsProviderRegistry::class,
                function (AnalyticsProviderRegistry $registry): void {
                    $registry->register($this->app->make(FakeAnalyticsProvider::class));
                },
            );
        }
    }

    public function boot(): void
    {
        Relation::morphMap([
            'catalog_item' => CatalogItem::class,
            'catalog' => Catalog::class,
            'company' => Company::class,
        ]);

        Event::listen(FactExtracted::class, function (FactExtracted $event): void {
            BusinessBrainService::invalidate($event->fact->company_id);
        });

        Event::listen(KnowledgeSynthesized::class, function (KnowledgeSynthesized $event): void {
            BusinessBrainService::invalidate($event->knowledge->company_id);
        });

        Event::listen(MarketingPresenceUpdated::class, function (MarketingPresenceUpdated $event): void {
            BusinessBrainService::invalidate($event->marketingChannel->company_id);
        });

        Event::listen(ObservationRecorded::class, DispatchObservationProcessing::class);
        // Opportunity scans run after every processed observation — not on the
        // one-time DigitalTwinActivated event — so re-crawls and retried
        // onboardings still reach opportunities → decisions → recommendations.
        Event::listen(ObservationProcessed::class, TriggerOpportunityDetection::class);
        Event::listen(OpportunityDetected::class, TriggerDecisionEvaluation::class);
        Event::listen(DecisionCommitted::class, DispatchCampaignPreparation::class);
        Event::listen(CampaignAssetsReady::class, TriggerRecommendationCreation::class);
        Event::listen(RecommendationApproved::class, TriggerCampaignPublishing::class);
        Event::listen(RecommendationCreated::class, SendWelcomeEmailOnFirstRecommendation::class);
        Event::listen(ExecutionCompleted::class, ScheduleMetricRetrieval::class);

        // Named limiter (not a bare `throttle:N,M` string) so this endpoint
        // gets its own isolated bucket and a place to log rejections — bare
        // `throttle:N,M` middleware shares one key (domain+IP only, no route
        // distinction) across every route that uses it, so a webhook sharing
        // that key with, say, the login/register routes would let one starve
        // the other's attempts. See Critical-Production-Blockers.md Blocker 2.
        RateLimiter::for('analytics-webhook', function (Request $request): Limit {
            return Limit::perMinute(60)
                ->by($request->ip())
                ->response(function (Request $request) {
                    Log::warning('AnalyticsWebhookController: rate limit exceeded.', [
                        'ip' => $request->ip(),
                        'provider' => $request->route('provider'),
                    ]);

                    return response()->json(['error' => 'Too many requests.'], 429);
                });
        });
    }
}
