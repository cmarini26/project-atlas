<?php

namespace Tests\Feature\Campaign;

use App\AI\Contracts\AiProvider;
use App\AI\Prompts\CampaignPreparationPrompt;
use App\AI\Testing\FakeAiProvider;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\Campaign\Exceptions\BlueprintGenerationFailedException;
use App\Models\Catalog;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use App\Services\Campaign\CampaignPreparationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignPreparationServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private CampaignPreparationService $service;

    private Company $company;

    private DigitalTwin $twin;

    private Catalog $catalog;

    private Decision $decision;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->fake);
        $this->service = $this->app->make(CampaignPreparationService::class);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions', 'slug' => 'cbb', 'industry' => 'auction',
        ]);
        $this->twin = DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
        $this->catalog = Catalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Main', 'type' => 'inventory',
        ]);

        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'subject_type' => 'catalog_item',
            'type' => 'featured_item',
            'title' => 'Silver Age Collection',
            'description' => 'High-value items with no recent campaign',
            'relevance_score' => 85,
            'timing_score' => 80,
            'confidence_score' => 75,
            'urgency_score' => 70,
            'composite_score' => 79,
            'status' => 'selected',
            'detected_at' => now()->subHour(),
        ]);

        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'email',
            'name' => 'Email List',
            'is_active' => true,
        ]);

        $this->decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item',
            'channel_ids' => [$channel->id],
            'rationale' => [
                'why_now' => 'Auction closes in 7 days.',
                'why_this' => 'Rare Silver Age collection.',
                'why_channel' => 'Email converts best for high-value items.',
                'why_works' => 'Subscribers have bought similar items.',
                'expected_impact' => [
                    'summary' => '15% lift in registrations',
                    'reach_estimate' => '2,000 subscribers',
                    'engagement_signal' => '25% open rate expected',
                    'confidence_basis' => 'Past auction data',
                ],
            ],
            'expected_impact' => ['summary' => '15% lift in registrations'],
            'confidence_score' => 75,
            'status' => 'pending',
            'decided_at' => now(),
        ]);
    }

    public function test_creates_campaign_with_blueprint_from_fixture(): void
    {
        $this->fake->queueFixture('campaign-blueprint');

        $brain = $this->makeBrain();
        $campaign = $this->service->prepare($this->decision, $brain);

        $this->assertNotNull($campaign->id);
        $this->assertEquals('draft', $campaign->status);
        $this->assertEquals($this->company->id, $campaign->company_id);
        $this->assertEquals($this->decision->id, $campaign->decision_id);
        $this->assertIsArray($campaign->blueprint);
        $this->assertEquals('1.0', $campaign->blueprint_version);
    }

    public function test_sets_expected_asset_count_from_channel_ids(): void
    {
        $this->fake->queueFixture('campaign-blueprint');

        $brain = $this->makeBrain();
        $campaign = $this->service->prepare($this->decision, $brain);

        $this->assertEquals(1, $campaign->expected_asset_count);
        $this->assertEquals(0, $campaign->generated_asset_count);
    }

    public function test_sends_campaign_preparation_prompt(): void
    {
        $this->fake->queueFixture('campaign-blueprint');

        $brain = $this->makeBrain();
        $this->service->prepare($this->decision, $brain);

        $this->fake->assertPromptSent(CampaignPreparationPrompt::class);
    }

    public function test_throws_on_invalid_goal(): void
    {
        $invalidBlueprint = json_decode(
            file_get_contents(base_path('tests/Fixtures/AI/campaign-blueprint.json')),
            true
        );
        $invalidBlueprint['goal'] = 'viral';
        $this->fake->queueResponse(json_encode($invalidBlueprint));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('Invalid blueprint goal');

        $this->service->prepare($this->decision, $this->makeBrain());
    }

    public function test_throws_on_audience_too_short(): void
    {
        $invalidBlueprint = json_decode(
            file_get_contents(base_path('tests/Fixtures/AI/campaign-blueprint.json')),
            true
        );
        $invalidBlueprint['audience'] = 'Too short';
        $this->fake->queueResponse(json_encode($invalidBlueprint));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('audience is too vague');

        $this->service->prepare($this->decision, $this->makeBrain());
    }

    public function test_throws_on_generic_cta(): void
    {
        $invalidBlueprint = json_decode(
            file_get_contents(base_path('tests/Fixtures/AI/campaign-blueprint.json')),
            true
        );
        $invalidBlueprint['call_to_action'] = 'Click Here';
        $this->fake->queueResponse(json_encode($invalidBlueprint));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('call_to_action is generic filler');

        $this->service->prepare($this->decision, $this->makeBrain());
    }

    public function test_throws_on_empty_channel_strategy(): void
    {
        $invalidBlueprint = json_decode(
            file_get_contents(base_path('tests/Fixtures/AI/campaign-blueprint.json')),
            true
        );
        $invalidBlueprint['channel_strategy'] = [];
        $this->fake->queueResponse(json_encode($invalidBlueprint));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('at least one channel strategy');

        $this->service->prepare($this->decision, $this->makeBrain());
    }

    public function test_blueprint_persisted_as_json_array(): void
    {
        $this->fake->queueFixture('campaign-blueprint');

        $brain = $this->makeBrain();
        $campaign = $this->service->prepare($this->decision, $brain);

        $this->assertArrayHasKey('goal', $campaign->blueprint);
        $this->assertArrayHasKey('audience', $campaign->blueprint);
        $this->assertArrayHasKey('core_message', $campaign->blueprint);
        $this->assertArrayHasKey('channel_strategy', $campaign->blueprint);
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
