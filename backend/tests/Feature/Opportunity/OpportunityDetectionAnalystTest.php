<?php

namespace Tests\Feature\Opportunity;

use App\AI\Contracts\AiProvider;
use App\AI\Prompts\OpportunityDetectionPrompt;
use App\AI\Testing\FakeAiProvider;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Catalog;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Services\Analyst\OpportunityDetectionAnalyst;
use App\Services\Opportunity\OpportunityCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityDetectionAnalystTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private OpportunityDetectionAnalyst $analyst;

    private Company $company;

    private DigitalTwin $twin;

    private Catalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->fake);
        $this->analyst = $this->app->make(OpportunityDetectionAnalyst::class);

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

    public function test_returns_candidates_from_ai_fixture(): void
    {
        $this->fake->queueFixture('opportunity-detection');

        $brain = $this->makeBrain();
        $candidates = $this->analyst->analyze($this->company, $brain);

        $this->assertCount(1, $candidates);
        $this->assertInstanceOf(OpportunityCandidate::class, $candidates->first());
        $this->assertSame('seasonal', $candidates->first()->type);
    }

    public function test_all_candidates_are_marked_as_ai_detected(): void
    {
        $this->fake->queueFixture('opportunity-detection');

        $brain = $this->makeBrain();
        $candidates = $this->analyst->analyze($this->company, $brain);

        foreach ($candidates as $candidate) {
            $this->assertTrue($candidate->aiDetected);
        }
    }

    public function test_sends_correct_prompt_class(): void
    {
        $this->fake->queueFixture('opportunity-detection');

        $brain = $this->makeBrain();
        $this->analyst->analyze($this->company, $brain, ['featured_item', 'urgency']);

        $this->fake->assertPromptSent(OpportunityDetectionPrompt::class);
    }

    public function test_returns_empty_collection_when_ai_returns_no_opportunities(): void
    {
        $this->fake->queueResponse('{"opportunities": []}');

        $brain = $this->makeBrain();
        $candidates = $this->analyst->analyze($this->company, $brain);

        $this->assertCount(0, $candidates);
    }

    public function test_filters_candidates_with_missing_required_fields(): void
    {
        // Missing title and description
        $this->fake->queueResponse('{"opportunities": [{"type": "seasonal"}]}');

        $brain = $this->makeBrain();
        $candidates = $this->analyst->analyze($this->company, $brain);

        $this->assertCount(0, $candidates);
    }

    public function test_clamps_score_values_to_valid_range(): void
    {
        $this->fake->queueResponse(json_encode([
            'opportunities' => [[
                'type' => 'seasonal',
                'title' => 'Test',
                'description' => 'Test description',
                'relevance_score' => 200, // over 100
                'timing_score' => -10,    // under 0
                'confidence_score' => 80,
                'urgency_score' => 50,
            ]],
        ]));

        $brain = $this->makeBrain();
        $candidates = $this->analyst->analyze($this->company, $brain);

        $this->assertCount(1, $candidates);
        $this->assertSame(100, $candidates->first()->relevanceScore);
        $this->assertSame(0, $candidates->first()->timingScore);
    }

    public function test_invalid_ai_subject_references_are_sanitized_to_null(): void
    {
        $this->fake->queueResponse(json_encode([
            'opportunities' => [[
                'type' => 'featured_item',
                'subject_type' => 'product',
                'subject_id' => 'Uncanny X-Men #60 CGC 9.6 1st App Sauron',
                'title' => 'Spotlight a high-interest key issue',
                'description' => 'This key issue looks like a strong candidate for promotion.',
                'relevance_score' => 74,
                'timing_score' => 71,
                'confidence_score' => 70,
                'urgency_score' => 40,
            ]],
        ]));

        $brain = $this->makeBrain();
        $candidate = $this->analyst->analyze($this->company, $brain)->first();

        $this->assertNotNull($candidate);
        $this->assertSame('featured_item', $candidate->type);
        $this->assertNull($candidate->subjectType);
        $this->assertNull($candidate->subjectId);
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
