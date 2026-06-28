<?php

namespace Tests\Feature\Decision;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Events\DecisionCommitted;
use App\Models\Campaign;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Services\Decision\DecisionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DecisionEngineTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private DecisionEngine $engine;

    private Company $company;

    private DigitalTwin $twin;

    private Catalog $catalog;

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

        $this->engine = $this->app->make(DecisionEngine::class);
    }

    public function test_guard_5_returns_null_when_no_active_channels(): void
    {
        $this->makeOpportunity(composite: 80);
        $brain = $this->makeBrain();

        // No channels seeded
        $decision = $this->engine->evaluate($this->company, $brain);

        $this->assertNull($decision);
    }

    public function test_commits_decision_when_all_guards_pass(): void
    {
        Event::fake([DecisionCommitted::class]);
        $this->fake->queueFixture('rationale-generation');

        $this->makeChannel();
        $this->makeOpportunity(composite: 80, type: 're_engagement');
        $brain = $this->makeBrain();

        $decision = $this->engine->evaluate($this->company, $brain);

        $this->assertInstanceOf(Decision::class, $decision);
        $this->assertDatabaseHas('decisions', ['company_id' => $this->company->id, 'status' => 'pending']);
    }

    public function test_guard_1_skips_opportunity_below_minimum_score(): void
    {
        $this->makeChannel();
        $this->makeOpportunity(composite: 20); // below threshold
        $brain = $this->makeBrain();

        $decision = $this->engine->evaluate($this->company, $brain);

        $this->assertNull($decision);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_guard_2_skips_when_pending_recommendation_of_same_type_exists(): void
    {
        $this->makeChannel();
        $this->makeOpportunity(composite: 80, type: 'featured_item');

        // Existing pending recommendation of same campaign_type
        Recommendation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_type' => 'featured_item',
            'status' => 'pending',
        ]);

        $brain = $this->makeBrain();
        $decision = $this->engine->evaluate($this->company, $brain);

        $this->assertNull($decision);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_guard_3_skips_when_campaign_completed_within_cooldown(): void
    {
        $this->makeChannel();
        $this->makeOpportunity(composite: 80, type: 're_engagement');

        // Completed re_engagement campaign within 14-day cooldown
        Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'title' => 'Past Re-engagement',
            'campaign_type' => 're_engagement',
            'status' => 'completed',
            'completed_at' => now()->subDays(5),
        ]);

        $brain = $this->makeBrain();
        $decision = $this->engine->evaluate($this->company, $brain);

        $this->assertNull($decision);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_guard_4_dismisses_opportunity_when_catalog_item_not_active(): void
    {
        $this->makeChannel();

        $item = CatalogItem::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'catalog_id' => $this->catalog->id,
            'title' => 'Sold Item',
            'status' => 'sold', // not active
        ]);

        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'subject_type' => 'catalog_item',
            'subject_id' => $item->id,
            'type' => 'featured_item',
            'title' => 'Sold Item Opportunity',
            'description' => 'This item was sold',
            'relevance_score' => 75,
            'timing_score' => 65,
            'confidence_score' => 70,
            'urgency_score' => 40,
            'composite_score' => 65,
            'status' => 'open',
            'detected_at' => now()->subHour(),
        ]);

        $brain = $this->makeBrain();
        $decision = $this->engine->evaluate($this->company, $brain);

        $this->assertNull($decision);

        // Opportunity should be dismissed
        $this->assertDatabaseHas('opportunities', [
            'id' => $opportunity->id,
            'status' => 'dismissed',
        ]);
    }

    public function test_selects_highest_scoring_candidate_first(): void
    {
        Event::fake([DecisionCommitted::class]);
        $this->fake->queueFixture('rationale-generation');

        $this->makeChannel();
        $lowScore = $this->makeOpportunity(composite: 50, type: 're_engagement');
        $highScore = $this->makeOpportunity(composite: 80, type: 'featured_item');

        $brain = $this->makeBrain();
        $decision = $this->engine->evaluate($this->company, $brain);

        // The high-scoring opportunity should be selected
        $this->assertNotNull($decision);
        $this->assertDatabaseHas('decisions', [
            'opportunity_id' => $highScore->id,
        ]);

        // Low-score opportunity should remain open
        $this->assertDatabaseHas('opportunities', [
            'id' => $lowScore->id,
            'status' => 'open',
        ]);
    }

    private function makeOpportunity(int $composite, string $type = 'featured_item'): Opportunity
    {
        return Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => $type,
            'title' => "Test opportunity ({$type})",
            'description' => 'Test description',
            'relevance_score' => 70,
            'timing_score' => 70,
            'confidence_score' => 70,
            'urgency_score' => 40,
            'composite_score' => $composite,
            'status' => 'open',
            'detected_at' => now()->subHour(),
        ]);
    }

    private function makeChannel(): Channel
    {
        return Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'email',
            'name' => 'Email',
            'is_active' => true,
        ]);
    }

    private function makeBrain(): BusinessBrain
    {
        return new BusinessBrain(
            company: $this->company,
            twin: $this->twin,
            activeFacts: collect(),
            activeKnowledge: collect(),
            recentObservations: collect(),
            catalog: $this->catalog,
            featuredItems: collect(),
            recentCampaigns: collect(),
        );
    }
}
