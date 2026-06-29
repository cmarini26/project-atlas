<?php

namespace App\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\LocalAiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Events\CampaignAssetsReady;
use App\Events\DecisionCommitted;
use App\Events\DigitalTwinActivated;
use App\Events\ExecutionCompleted;
use App\Events\FactExtracted;
use App\Events\KnowledgeSynthesized;
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
        // - production/staging: AnthropicProvider (requires ANTHROPIC_API_KEY)
        // - local: LocalAiProvider — deterministic stubs, no API key needed.
        //   Combine with QUEUE_CONNECTION=sync in .env so the full pipeline
        //   runs end-to-end in a single HTTP request without queue workers.
        // - testing: FakeAiProvider — test-controlled via queueFixture().
        if ($this->app->environment('local')) {
            $this->app->singleton(AiProvider::class, LocalAiProvider::class);
        } elseif (! $this->app->environment('testing')) {
            $this->app->singleton(AiProvider::class, AnthropicProvider::class);
        }

        if ($this->app->environment('testing')) {
            $this->app->singleton(AiProvider::class, FakeAiProvider::class);
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
        Event::listen(DigitalTwinActivated::class, TriggerOpportunityDetection::class);
        Event::listen(OpportunityDetected::class, TriggerDecisionEvaluation::class);
        Event::listen(DecisionCommitted::class, DispatchCampaignPreparation::class);
        Event::listen(CampaignAssetsReady::class, TriggerRecommendationCreation::class);
        Event::listen(RecommendationApproved::class, TriggerCampaignPublishing::class);
        Event::listen(ExecutionCompleted::class, ScheduleMetricRetrieval::class);
    }
}
