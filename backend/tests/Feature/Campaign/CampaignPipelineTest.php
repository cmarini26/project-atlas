<?php

namespace Tests\Feature\Campaign;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Events\CampaignAssetsReady;
use App\Events\RecommendationCreated;
use App\Jobs\CreateRecommendation;
use App\Jobs\GenerateContent;
use App\Jobs\PrepareCampaign;
use App\Models\Catalog;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use App\Services\Analyst\Content\ContentGenerationAnalyst;
use App\Services\Brain\BusinessBrainService;
use App\Services\Campaign\CampaignPreparationService;
use App\Services\Content\ContentGenerationService;
use App\Services\Recommendation\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CampaignPipelineTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private Company $company;

    private Channel $channel;

    private Decision $decision;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->fake);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions', 'slug' => 'cbb', 'industry' => 'auction',
        ]);
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
        Catalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Main', 'type' => 'inventory',
        ]);

        $this->channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true,
        ]);

        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'subject_type' => 'catalog_item',
            'type' => 'featured_item',
            'title' => 'Silver Age Collection',
            'description' => 'High value items',
            'relevance_score' => 85,
            'timing_score' => 80,
            'confidence_score' => 75,
            'urgency_score' => 70,
            'composite_score' => 78,
            'status' => 'selected',
            'detected_at' => now()->subHour(),
        ]);

        $this->decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item',
            'channel_ids' => [$this->channel->id],
            'rationale' => [
                'why_now' => 'Auction closes in 7 days.',
                'why_this' => 'Rare Silver Age collection.',
                'why_channel' => 'Email converts best.',
                'why_works' => 'Subscribers have bought similar items.',
                'expected_impact' => [
                    'summary' => '15% lift',
                    'reach_estimate' => '2,000',
                    'engagement_signal' => '25% open rate',
                    'confidence_basis' => 'Past data',
                ],
            ],
            'expected_impact' => ['summary' => '15% lift'],
            'confidence_score' => 75,
            'status' => 'pending',
            'decided_at' => now(),
        ]);
    }

    public function test_prepare_campaign_job_dispatches_generate_content_per_channel(): void
    {
        Bus::fake([GenerateContent::class]);

        $this->fake->queueFixture('campaign-blueprint');

        $job = new PrepareCampaign($this->decision);
        $job->handle(
            $this->app->make(CampaignPreparationService::class),
            $this->app->make(BusinessBrainService::class),
        );

        Bus::assertDispatched(GenerateContent::class, 1);
    }

    public function test_campaign_assets_ready_event_dispatches_create_recommendation_job(): void
    {
        Bus::fake([CreateRecommendation::class]);

        $this->fake->queueFixture('campaign-blueprint');
        $this->fake->queueFixture('email-content');

        // run prepare synchronously — GenerateContent runs inline (sync queue),
        // fires CampaignAssetsReady, which triggers TriggerRecommendationCreation,
        // which dispatches CreateRecommendation (faked above)
        $prepareJob = new PrepareCampaign($this->decision);
        $prepareJob->handle(
            $this->app->make(CampaignPreparationService::class),
            $this->app->make(BusinessBrainService::class),
        );

        Bus::assertDispatched(CreateRecommendation::class);
    }

    public function test_full_pipeline_end_to_end(): void
    {
        Event::fake([CampaignAssetsReady::class, RecommendationCreated::class]);

        $this->fake->queueFixture('campaign-blueprint');
        $this->fake->queueFixture('email-content');

        $brainService = $this->app->make(BusinessBrainService::class);
        $prepService = $this->app->make(CampaignPreparationService::class);
        $contentAnalyst = $this->app->make(ContentGenerationAnalyst::class);
        $contentService = $this->app->make(ContentGenerationService::class);
        $recommendationService = $this->app->make(RecommendationService::class);

        $brain = $brainService->for($this->company);

        $campaign = $prepService->prepare($this->decision, $brain);

        $assetData = $contentAnalyst->analyze($campaign, $this->channel, $brain);
        $contentService->createAsset($campaign, $this->channel, $assetData);

        $campaign->refresh();
        $this->assertTrue($campaign->allAssetsGenerated());

        Event::assertDispatched(CampaignAssetsReady::class);

        $recommendation = $recommendationService->create($campaign);

        $this->assertEquals('pending', $recommendation->status);
        $this->assertEquals($campaign->id, $recommendation->campaign_id);

        $this->decision->refresh();
        $this->assertEquals('recommended', $this->decision->status);
    }

    public function test_no_publishing_in_full_pipeline(): void
    {
        $this->fake->queueFixture('campaign-blueprint');
        $this->fake->queueFixture('email-content');

        $brainService = $this->app->make(BusinessBrainService::class);
        $prepService = $this->app->make(CampaignPreparationService::class);
        $contentAnalyst = $this->app->make(ContentGenerationAnalyst::class);
        $contentService = $this->app->make(ContentGenerationService::class);

        $brain = $brainService->for($this->company);
        $campaign = $prepService->prepare($this->decision, $brain);

        $assetData = $contentAnalyst->analyze($campaign, $this->channel, $brain);
        $asset = $contentService->createAsset($campaign, $this->channel, $assetData);

        $this->assertNotEquals('published', $asset->status);
        $this->assertNull($asset->published_at);
    }
}
