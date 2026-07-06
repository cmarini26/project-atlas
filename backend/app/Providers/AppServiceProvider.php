<?php

namespace App\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\LocalAiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Events\CampaignAssetsReady;
use App\Events\DecisionCommitted;
use App\Events\ExecutionCompleted;
use App\Events\FactExtracted;
use App\Events\KnowledgeSynthesized;
use App\Events\ObservationProcessed;
use App\Events\ObservationRecorded;
use App\Events\OpportunityDetected;
use App\Events\RecommendationApproved;
use App\Listeners\DispatchCampaignPreparation;
use App\Listeners\DispatchObservationProcessing;
use App\Listeners\ScheduleMetricRetrieval;
use App\Listeners\TriggerCampaignPublishing;
use App\Listeners\TriggerDecisionEvaluation;
use App\Listeners\TriggerOpportunityDetection;
use App\Listeners\TriggerRecommendationCreation;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Services\Analytics\AnalyticsProviderRegistry;
use App\Services\Analytics\FakeAnalyticsProvider;
use App\Services\Brain\BusinessBrainService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Illuminate\Support\Facades\Event;
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

        Event::listen(ObservationRecorded::class, DispatchObservationProcessing::class);
        // Opportunity scans run after every processed observation — not on the
        // one-time DigitalTwinActivated event — so re-crawls and retried
        // onboardings still reach opportunities → decisions → recommendations.
        Event::listen(ObservationProcessed::class, TriggerOpportunityDetection::class);
        Event::listen(OpportunityDetected::class, TriggerDecisionEvaluation::class);
        Event::listen(DecisionCommitted::class, DispatchCampaignPreparation::class);
        Event::listen(CampaignAssetsReady::class, TriggerRecommendationCreation::class);
        Event::listen(RecommendationApproved::class, TriggerCampaignPublishing::class);
        Event::listen(ExecutionCompleted::class, ScheduleMetricRetrieval::class);
    }
}
