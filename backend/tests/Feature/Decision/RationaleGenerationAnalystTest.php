<?php

namespace Tests\Feature\Decision;

use App\AI\Contracts\AiProvider;
use App\AI\Prompts\RationaleGenerationPrompt;
use App\AI\Testing\FakeAiProvider;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use App\Services\Analyst\RationaleGenerationAnalyst;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RationaleGenerationAnalystTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private RationaleGenerationAnalyst $analyst;

    private Company $company;

    private DigitalTwin $twin;

    private Catalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->fake);
        $this->analyst = $this->app->make(RationaleGenerationAnalyst::class);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions', 'slug' => 'cbb', 'industry' => 'auction',
        ]);
        $this->twin = DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
        $this->catalog = Catalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Main', 'type' => 'inventory',
        ]);
    }

    public function test_parses_complete_rationale_from_fixture(): void
    {
        $this->fake->queueFixture('rationale-generation');

        $opportunity = $this->makeOpportunity();
        $brain = $this->makeBrain();

        $rationale = $this->analyst->analyze($opportunity, [
            'campaign_type' => 'featured_item',
            'channel_ids' => ['chan-1', 'chan-2'],
        ], $brain);

        $this->assertArrayHasKey('why_now', $rationale);
        $this->assertArrayHasKey('why_this', $rationale);
        $this->assertArrayHasKey('why_channel', $rationale);
        $this->assertArrayHasKey('why_works', $rationale);
        $this->assertArrayHasKey('expected_impact', $rationale);

        $this->assertIsArray($rationale['expected_impact']);
        $this->assertArrayHasKey('summary', $rationale['expected_impact']);
        $this->assertArrayHasKey('reach_estimate', $rationale['expected_impact']);
        $this->assertArrayHasKey('engagement_signal', $rationale['expected_impact']);
        $this->assertArrayHasKey('confidence_basis', $rationale['expected_impact']);
    }

    public function test_sends_rationale_generation_prompt(): void
    {
        $this->fake->queueFixture('rationale-generation');

        $opportunity = $this->makeOpportunity();
        $brain = $this->makeBrain();

        $this->analyst->analyze($opportunity, [
            'campaign_type' => 'featured_item',
            'channel_ids' => [],
        ], $brain);

        $this->fake->assertPromptSent(RationaleGenerationPrompt::class);
    }

    private function makeOpportunity(): Opportunity
    {
        $item = CatalogItem::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'catalog_id' => $this->catalog->id,
            'title' => 'Amazing Fantasy #15',
            'status' => 'active',
            'price' => 4800.00,
        ]);

        return Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'subject_type' => 'catalog_item',
            'subject_id' => $item->id,
            'type' => 'featured_item',
            'title' => 'Amazing Fantasy #15 — no campaign in 30 days',
            'description' => 'Test description',
            'relevance_score' => 75,
            'timing_score' => 65,
            'confidence_score' => 70,
            'urgency_score' => 40,
            'composite_score' => 65,
            'status' => 'open',
            'detected_at' => now()->subHour(),
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
