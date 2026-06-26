<?php

namespace Tests\Feature\Opportunity;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Events\OpportunityDetected;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use App\Services\Analyst\OpportunityDetectionAnalyst;
use App\Services\Opportunity\Detectors\FeaturedItemDetector;
use App\Services\Opportunity\Detectors\NewArrivalDetector;
use App\Services\Opportunity\Detectors\ReEngagementDetector;
use App\Services\Opportunity\Detectors\UrgencyDetector;
use App\Services\Opportunity\OpportunityEngine;
use App\Services\Opportunity\OpportunityRepository;
use App\Services\Opportunity\OpportunityScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OpportunityEngineTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private OpportunityEngine $engine;

    private Company $company;

    private Catalog $catalog;

    private DigitalTwin $twin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->fake);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co', 'slug' => 'test-co',
        ]);
        $this->twin = DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
        $this->catalog = Catalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Main', 'type' => 'inventory',
        ]);

        $this->engine = new OpportunityEngine(
            detectors: collect([
                new FeaturedItemDetector(),
                new UrgencyDetector(),
                new NewArrivalDetector(),
                new ReEngagementDetector(),
            ]),
            analyst: $this->app->make(OpportunityDetectionAnalyst::class),
            scorer: new OpportunityScorer(),
            repository: new OpportunityRepository(),
        );
    }

    public function test_persists_opportunities_for_qualifying_candidates(): void
    {
        // Queue empty AI response so analyst returns nothing
        $this->fake->queueResponse('{"opportunities": []}');

        $item = CatalogItem::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'catalog_id' => $this->catalog->id,
            'title' => 'Amazing Fantasy #15',
            'status' => 'active',
            'price' => 4800.00,
            'promoted_at' => null, // never promoted → detected
        ]);

        $brain = $this->makeBrain(collect([$item]));
        $opportunities = $this->engine->scan($this->company, $brain);

        $this->assertGreaterThan(0, $opportunities->count());
        $this->assertDatabaseHas('opportunities', [
            'company_id' => $this->company->id,
            'type' => 'featured_item',
            'subject_id' => $item->id,
            'status' => 'open',
        ]);
    }

    public function test_deduplicates_existing_open_opportunities(): void
    {
        $this->fake->queueResponse('{"opportunities": []}');

        $item = CatalogItem::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'catalog_id' => $this->catalog->id,
            'title' => 'Duplicate Item',
            'status' => 'active',
            'promoted_at' => null,
        ]);

        // Pre-seed an existing open opportunity for this item
        Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'subject_type' => 'catalog_item',
            'subject_id' => $item->id,
            'type' => 'featured_item',
            'title' => 'Existing',
            'description' => 'Pre-existing',
            'relevance_score' => 75,
            'timing_score' => 65,
            'confidence_score' => 70,
            'urgency_score' => 40,
            'composite_score' => 65,
            'status' => 'open',
            'detected_at' => now()->subHour(),
        ]);

        $brain = $this->makeBrain(collect([$item]));
        $this->engine->scan($this->company, $brain);

        // The duplicate should be filtered — only one featured_item for this subject
        $count = Opportunity::withoutGlobalScopes()
            ->where('type', 'featured_item')
            ->where('subject_id', $item->id)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_fires_opportunity_detected_event_per_persisted_opportunity(): void
    {
        Event::fake();
        $this->fake->queueResponse('{"opportunities": []}');

        $item = CatalogItem::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'catalog_id' => $this->catalog->id,
            'title' => 'Event Test Item',
            'status' => 'active',
            'promoted_at' => null,
        ]);

        $brain = $this->makeBrain(collect([$item]));
        $opportunities = $this->engine->scan($this->company, $brain);

        Event::assertDispatched(OpportunityDetected::class, $opportunities->count());
    }

    public function test_ai_detected_candidates_are_marked_correctly(): void
    {
        $this->fake->queueFixture('opportunity-detection');

        // No items → no rule-based detections, only AI candidates
        $brain = $this->makeBrain(collect());
        $opportunities = $this->engine->scan($this->company, $brain);

        $aiOpportunities = $opportunities->filter(fn (Opportunity $o): bool => (bool) $o->ai_detected);
        $this->assertGreaterThan(0, $aiOpportunities->count());
    }

    private function makeBrain(Collection $items): BusinessBrain
    {
        return new BusinessBrain(
            company: $this->company,
            twin: $this->twin,
            activeFacts: collect(),
            activeKnowledge: collect(),
            recentObservations: collect(),
            catalog: $this->catalog,
            featuredItems: $items,
            recentCampaigns: collect(),
        );
    }
}
