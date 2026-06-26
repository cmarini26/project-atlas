<?php

namespace Tests\Feature\Decision;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Events\DecisionCommitted;
use App\Models\Catalog;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use App\Services\Decision\DecisionEngine;
use App\Services\Decision\Exceptions\RationaleGenerationFailedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DecisionPipelineTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

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
    }

    public function test_full_pipeline_produces_committed_decision(): void
    {
        Event::fake([DecisionCommitted::class]);

        $this->fake->queueFixture('rationale-generation');

        $this->makeChannel();
        $opportunity = $this->makeOpportunity();
        $brain = $this->makeBrain();

        $engine = $this->app->make(DecisionEngine::class);
        $decision = $engine->evaluate($this->company, $brain);

        $this->assertInstanceOf(Decision::class, $decision);
        $this->assertSame('pending', $decision->status);
        $this->assertNotEmpty($decision->rationale['why_now']);
        $this->assertNotEmpty($decision->rationale['why_this']);
        $this->assertNotEmpty($decision->rationale['why_channel']);
        $this->assertNotEmpty($decision->rationale['why_works']);
        $this->assertIsArray($decision->rationale['expected_impact']);

        // Opportunity should be selected
        $this->assertDatabaseHas('opportunities', [
            'id' => $opportunity->id,
            'status' => 'selected',
        ]);

        // DecisionCommitted event should be fired
        Event::assertDispatched(DecisionCommitted::class, function (DecisionCommitted $event) use ($decision): bool {
            return $event->decision->id === $decision->id;
        });
    }

    public function test_rationale_failure_leaves_opportunity_open(): void
    {
        // Return rationale with missing keys
        $this->fake->queueResponse('{"why_now": "Now is good.", "expected_impact": {}}');

        $this->makeChannel();
        $opportunity = $this->makeOpportunity();
        $brain = $this->makeBrain();

        $engine = $this->app->make(DecisionEngine::class);

        $this->expectException(RationaleGenerationFailedException::class);
        $engine->evaluate($this->company, $brain);

        // No decision should be persisted
        $this->assertDatabaseCount('decisions', 0);

        // Opportunity should still be open
        $this->assertDatabaseHas('opportunities', [
            'id' => $opportunity->id,
            'status' => 'open',
        ]);
    }

    private function makeOpportunity(): Opportunity
    {
        return Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 're_engagement',
            'title' => 'Re-engage audience',
            'description' => '21 days since last campaign',
            'relevance_score' => 70,
            'timing_score' => 82,
            'confidence_score' => 60,
            'urgency_score' => 57,
            'composite_score' => 68,
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
