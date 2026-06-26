<?php

namespace App\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Events\CampaignAssetsReady;
use App\Events\DecisionCommitted;
use App\Events\DigitalTwinActivated;
use App\Events\ObservationRecorded;
use App\Events\OpportunityDetected;
use App\Listeners\DispatchCampaignPreparation;
use App\Listeners\DispatchObservationProcessing;
use App\Listeners\TriggerDecisionEvaluation;
use App\Listeners\TriggerOpportunityDetection;
use App\Listeners\TriggerRecommendationCreation;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Company;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // In the test environment, bind FakeAiProvider so tests can override it
        // with app()->instance(AiProvider::class, $fake). In production, a real
        // provider (AnthropicProvider) must be bound before AI jobs are dispatched.
        if ($this->app->environment('testing')) {
            $this->app->singleton(AiProvider::class, FakeAiProvider::class);
        }
    }

    public function boot(): void
    {
        Relation::morphMap([
            'catalog_item' => CatalogItem::class,
            'catalog' => Catalog::class,
            'company' => Company::class,
        ]);

        Event::listen(ObservationRecorded::class, DispatchObservationProcessing::class);
        Event::listen(DigitalTwinActivated::class, TriggerOpportunityDetection::class);
        Event::listen(OpportunityDetected::class, TriggerDecisionEvaluation::class);
        Event::listen(DecisionCommitted::class, DispatchCampaignPreparation::class);
        Event::listen(CampaignAssetsReady::class, TriggerRecommendationCreation::class);
    }
}
