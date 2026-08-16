<?php

namespace App\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\LocalAiProvider;
use App\AI\Providers\OllamaAiProvider;
use App\AI\Testing\FakeAiProvider;
use App\ErrorTracking\Contracts\ErrorTracker;
use App\ErrorTracking\NullErrorTracker;
use App\ErrorTracking\SentryErrorTracker;
use App\Events\CampaignAssetsReady;
use App\Events\DecisionCommitted;
use App\Events\ExecutionCompleted;
use App\Events\FactExtracted;
use App\Events\FeedbackSubmitted;
use App\Events\IntegrationSyncCompleted;
use App\Events\IntegrationSyncFailed;
use App\Events\IntegrationSyncStarted;
use App\Events\KnowledgeSynthesized;
use App\Events\MarketingPresenceUpdated;
use App\Events\ObservationProcessed;
use App\Events\ObservationRecorded;
use App\Events\OpportunityDetected;
use App\Events\RecommendationApproved;
use App\Events\RecommendationCreated;
use App\Listeners\CreateSourceAssetOpportunity;
use App\Listeners\DispatchCampaignPreparation;
use App\Listeners\DispatchObservationProcessing;
use App\Listeners\ScheduleMetricRetrieval;
use App\Listeners\SendFeedbackNotification;
use App\Listeners\SendWelcomeEmailOnFirstRecommendation;
use App\Listeners\TriggerCampaignPublishing;
use App\Listeners\TriggerDecisionEvaluation;
use App\Listeners\TriggerOpportunityDetection;
use App\Listeners\TriggerRecommendationCreation;
use App\Listeners\UpdateDiscoveryConnectorAttempt;
use App\Models\CampaignBrief;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Models\SourceAsset;
use App\Services\Analyst\AnalystRegistry;
use App\Services\Analyst\InstagramAnalyst;
use App\Services\Analyst\SourceAssetAnalyst;
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
use InvalidArgumentException;
use Sentry\State\HubInterface;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // All listeners are registered explicitly in boot(). Disable auto-discovery
        // to prevent duplicate registrations when both mechanisms run together.
        EventServiceProvider::disableEventDiscovery();
        // AI provider selection is explicit and does not depend on credential
        // presence. Environment safety restrictions are enforced per provider.
        $this->app->singleton(AiProvider::class, function ($app): AiProvider {
            $provider = config('ai.provider');

            if (! is_string($provider) || trim($provider) === '') {
                throw new InvalidArgumentException(
                    'AI_PROVIDER must be configured. Supported values: anthropic, local, fake, ollama.'
                );
            }

            return match ($provider) {
                'anthropic' => $app->make(AnthropicProvider::class),
                'local' => $app->environment('local')
                    ? $app->make(LocalAiProvider::class)
                    : throw new InvalidArgumentException('AI_PROVIDER=local is only supported in the local environment.'),
                'fake' => $app->environment('testing')
                    ? $app->make(FakeAiProvider::class)
                    : throw new InvalidArgumentException('AI_PROVIDER=fake is only supported in the testing environment.'),
                'ollama' => $app->make(OllamaAiProvider::class),
                default => throw new InvalidArgumentException(sprintf(
                    'Unsupported AI_PROVIDER value [%s]. Supported values: anthropic, local, fake, ollama.',
                    $provider,
                )),
            };
        });

        // Resolves the right Analyst per Observation source_type — mirrors
        // ConnectorServiceProvider's ConnectorRegistry binding. Adding a new
        // observation source (Milestone 12 Phase 1: Instagram) only means
        // adding its Analyst here, never touching ProcessObservation.
        $this->app->singleton(AnalystRegistry::class, function ($app): AnalystRegistry {
            return new AnalystRegistry([
                $app->make(WebsiteAnalyst::class),
                $app->make(InstagramAnalyst::class),
                $app->make(SourceAssetAnalyst::class),
            ]);
        });

        // Testing is always inert regardless of environment configuration.
        // Production can opt into Sentry without changing exception handling.
        $this->app->singleton(ErrorTracker::class, function (): ErrorTracker {
            if ($this->app->environment('testing')) {
                return new NullErrorTracker();
            }

            return match (config('services.error_tracking.driver')) {
                'null' => new NullErrorTracker(),
                'sentry' => new SentryErrorTracker($this->app->make(HubInterface::class)),
                default => throw new InvalidArgumentException('Unsupported ERROR_TRACKING_DRIVER value.'),
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
            'source_asset' => SourceAsset::class,
            'campaign_brief' => CampaignBrief::class,
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
        Event::listen(ObservationProcessed::class, CreateSourceAssetOpportunity::class);
        Event::listen(ObservationProcessed::class, TriggerOpportunityDetection::class);
        Event::listen(OpportunityDetected::class, TriggerDecisionEvaluation::class);
        Event::listen(DecisionCommitted::class, DispatchCampaignPreparation::class);
        Event::listen(CampaignAssetsReady::class, TriggerRecommendationCreation::class);
        Event::listen(RecommendationApproved::class, TriggerCampaignPublishing::class);
        Event::listen(RecommendationCreated::class, SendWelcomeEmailOnFirstRecommendation::class);
        Event::listen(ExecutionCompleted::class, ScheduleMetricRetrieval::class);
        Event::listen(FeedbackSubmitted::class, SendFeedbackNotification::class);

        // Business Discovery orchestration (Milestone 15 Phase 2) — bookkeeping
        // only, reacting to the existing Integration sync lifecycle. Never
        // gates or mutates the pipeline above.
        Event::listen(IntegrationSyncStarted::class, [UpdateDiscoveryConnectorAttempt::class, 'onStarted']);
        Event::listen(IntegrationSyncCompleted::class, [UpdateDiscoveryConnectorAttempt::class, 'onCompleted']);
        Event::listen(IntegrationSyncFailed::class, [UpdateDiscoveryConnectorAttempt::class, 'onFailed']);

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
