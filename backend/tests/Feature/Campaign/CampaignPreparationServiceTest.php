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

    // --- tone validation ---

    public function test_throws_on_missing_tone_voice(): void
    {
        $bp = $this->loadFixture();
        unset($bp['tone']['voice']);
        $this->fake->queueResponse(json_encode($bp));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('tone.voice is required');

        $this->service->prepare($this->decision, $this->makeBrain());
    }

    public function test_throws_on_missing_tone_modifier(): void
    {
        $bp = $this->loadFixture();
        unset($bp['tone']['modifier']);
        $this->fake->queueResponse(json_encode($bp));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('tone.modifier is required');

        $this->service->prepare($this->decision, $this->makeBrain());
    }

    public function test_throws_when_tone_avoid_is_not_an_array(): void
    {
        $bp = $this->loadFixture();
        $bp['tone']['avoid'] = 'not-an-array';
        $this->fake->queueResponse(json_encode($bp));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('tone.avoid must be an array');

        $this->service->prepare($this->decision, $this->makeBrain());
    }

    // --- landing_page validation ---

    public function test_throws_on_invalid_landing_page_url(): void
    {
        $bp = $this->loadFixture();
        $bp['landing_page'] = 'not-a-valid-url';
        $this->fake->queueResponse(json_encode($bp));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('landing_page must be a valid URL or null');

        $this->service->prepare($this->decision, $this->makeBrain());
    }

    public function test_accepts_null_landing_page(): void
    {
        $bp = $this->loadFixture();
        $bp['landing_page'] = null;
        $this->fake->queueResponse(json_encode($bp));

        $campaign = $this->service->prepare($this->decision, $this->makeBrain());

        $this->assertEquals('draft', $campaign->status);
    }

    public function test_accepts_valid_landing_page_url(): void
    {
        $bp = $this->loadFixture();
        $bp['landing_page'] = 'https://example.com/auction';
        $this->fake->queueResponse(json_encode($bp));

        $campaign = $this->service->prepare($this->decision, $this->makeBrain());

        $this->assertEquals('draft', $campaign->status);
    }

    // --- success_metrics validation ---

    public function test_throws_on_missing_primary_metric(): void
    {
        $bp = $this->loadFixture();
        unset($bp['success_metrics']['primary_metric']);
        $this->fake->queueResponse(json_encode($bp));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('success_metrics.primary_metric is required');

        $this->service->prepare($this->decision, $this->makeBrain());
    }

    public function test_throws_when_secondary_metrics_is_not_an_array(): void
    {
        $bp = $this->loadFixture();
        $bp['success_metrics']['secondary_metrics'] = 'not-an-array';
        $this->fake->queueResponse(json_encode($bp));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('success_metrics.secondary_metrics must be an array');

        $this->service->prepare($this->decision, $this->makeBrain());
    }

    public function test_throws_on_missing_baseline(): void
    {
        $bp = $this->loadFixture();
        unset($bp['success_metrics']['baseline']);
        $this->fake->queueResponse(json_encode($bp));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('success_metrics.baseline is required');

        $this->service->prepare($this->decision, $this->makeBrain());
    }

    public function test_throws_on_missing_timeframe(): void
    {
        $bp = $this->loadFixture();
        unset($bp['success_metrics']['timeframe']);
        $this->fake->queueResponse(json_encode($bp));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('success_metrics.timeframe is required');

        $this->service->prepare($this->decision, $this->makeBrain());
    }

    // --- channel_strategy count validation ---

    public function test_throws_when_channel_strategy_count_is_less_than_decision_channels(): void
    {
        $channel2 = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'sms',
            'name' => 'SMS',
            'is_active' => true,
        ]);

        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'subject_type' => 'catalog_item',
            'type' => 'featured_item',
            'title' => 'Two-Channel',
            'description' => 'Requires two channel strategies',
            'relevance_score' => 80,
            'timing_score' => 75,
            'confidence_score' => 70,
            'urgency_score' => 65,
            'composite_score' => 73,
            'status' => 'selected',
            'detected_at' => now(),
        ]);

        $twoChannelDecision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item',
            'channel_ids' => [$this->decision->channel_ids[0], $channel2->id],
            'rationale' => ['why_now' => 'Now.'],
            'expected_impact' => ['summary' => 'Lift'],
            'confidence_score' => 70,
            'status' => 'recommended',
            'decided_at' => now(),
        ]);

        // Blueprint with only 1 channel strategy, but decision has 2 channels
        $bp = $this->loadFixture();
        $bp['channel_strategy'] = [
            'email' => $bp['channel_strategy']['email'],
        ];
        $this->fake->queueResponse(json_encode($bp));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('channel_strategy must have at least one entry per decision channel');

        $this->service->prepare($twoChannelDecision, $this->makeBrain());
    }

    // --- channel_strategy entry field validation ---

    public function test_throws_when_channel_strategy_entry_missing_format(): void
    {
        $bp = $this->loadFixture();
        unset($bp['channel_strategy']['email']['format']);
        $this->fake->queueResponse(json_encode($bp));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('missing required field: format');

        $this->service->prepare($this->decision, $this->makeBrain());
    }

    public function test_throws_when_channel_strategy_entry_missing_angle(): void
    {
        $bp = $this->loadFixture();
        unset($bp['channel_strategy']['email']['angle']);
        $this->fake->queueResponse(json_encode($bp));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('missing required field: angle');

        $this->service->prepare($this->decision, $this->makeBrain());
    }

    public function test_throws_when_channel_strategy_constraints_not_array(): void
    {
        $bp = $this->loadFixture();
        $bp['channel_strategy']['email']['constraints'] = 'not-an-array';
        $this->fake->queueResponse(json_encode($bp));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('constraints must be an array');

        $this->service->prepare($this->decision, $this->makeBrain());
    }

    public function test_throws_when_channel_strategy_priority_not_numeric(): void
    {
        $bp = $this->loadFixture();
        $bp['channel_strategy']['email']['priority'] = 'high';
        $this->fake->queueResponse(json_encode($bp));

        $this->expectException(BlueprintGenerationFailedException::class);
        $this->expectExceptionMessage('priority must be a number');

        $this->service->prepare($this->decision, $this->makeBrain());
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

    /** @return array<string, mixed> */
    private function loadFixture(): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/AI/campaign-blueprint.json')),
            true
        );
    }
}
