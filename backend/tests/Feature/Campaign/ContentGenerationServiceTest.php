<?php

namespace Tests\Feature\Campaign;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Events\CampaignAssetsReady;
use App\Models\Campaign;
use App\Models\Catalog;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use App\Services\Analyst\Content\ContentGenerationAnalyst;
use App\Services\Content\ContentGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ContentGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private ContentGenerationService $contentService;

    private ContentGenerationAnalyst $analyst;

    private Company $company;

    private DigitalTwin $twin;

    private Catalog $catalog;

    private Campaign $campaign;

    private Channel $emailChannel;

    private Channel $socialChannel;

    /** @var array<string, mixed> */
    private array $blueprintData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->fake);
        $this->contentService = $this->app->make(ContentGenerationService::class);
        $this->analyst = $this->app->make(ContentGenerationAnalyst::class);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions', 'slug' => 'cbb', 'industry' => 'auction',
        ]);
        $this->twin = DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
        $this->catalog = Catalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Main', 'type' => 'inventory',
        ]);

        $this->emailChannel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true,
        ]);
        $this->socialChannel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'instagram', 'name' => 'Instagram', 'is_active' => true,
        ]);

        $this->blueprintData = json_decode(
            file_get_contents(base_path('tests/Fixtures/AI/campaign-blueprint.json')),
            true
        );

        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'subject_type' => 'catalog_item',
            'type' => 'featured_item',
            'title' => 'Silver Age Collection',
            'description' => 'High-value items',
            'relevance_score' => 85,
            'timing_score' => 80,
            'confidence_score' => 75,
            'urgency_score' => 70,
            'composite_score' => 79,
            'status' => 'selected',
            'detected_at' => now()->subHour(),
        ]);

        $decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item',
            'channel_ids' => [$this->emailChannel->id, $this->socialChannel->id],
            'rationale' => ['why_now' => 'Auction closing soon.'],
            'expected_impact' => ['summary' => '15% lift'],
            'confidence_score' => 75,
            'status' => 'pending',
            'decided_at' => now(),
        ]);

        $this->campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'decision_id' => $decision->id,
            'campaign_type' => 'featured_item',
            'title' => 'Silver Age Campaign',
            'blueprint' => $this->blueprintData,
            'blueprint_version' => '1.0',
            'prompt_version' => '1.0',
            'expected_asset_count' => 2,
            'generated_asset_count' => 0,
            'status' => 'draft',
        ]);
    }

    public function test_creates_email_content_asset(): void
    {
        $this->fake->queueFixture('email-content');

        $brain = $this->makeBrain();
        $assetData = $this->analyst->analyze($this->campaign, $this->emailChannel, $brain);
        $asset = $this->contentService->createAsset($this->campaign, $this->emailChannel, $assetData);

        $this->assertEquals('email', $asset->type);
        $this->assertEquals($this->campaign->id, $asset->campaign_id);
        $this->assertEquals($this->emailChannel->id, $asset->channel_id);
        $this->assertEquals('draft', $asset->status);
        $this->assertNotEmpty($asset->body);
        $this->assertNotEmpty($asset->title);
    }

    public function test_creates_social_content_asset(): void
    {
        $this->fake->queueFixture('social-content');

        $brain = $this->makeBrain();
        $assetData = $this->analyst->analyze($this->campaign, $this->socialChannel, $brain);
        $asset = $this->contentService->createAsset($this->campaign, $this->socialChannel, $assetData);

        $this->assertEquals('social_post', $asset->type);
        $this->assertEquals($this->campaign->id, $asset->campaign_id);
        $this->assertNotEmpty($asset->body);
    }

    public function test_increments_generated_asset_count(): void
    {
        $this->fake->queueFixture('email-content');

        $brain = $this->makeBrain();
        $assetData = $this->analyst->analyze($this->campaign, $this->emailChannel, $brain);
        $this->contentService->createAsset($this->campaign, $this->emailChannel, $assetData);

        $this->campaign->refresh();
        $this->assertEquals(1, $this->campaign->generated_asset_count);
    }

    public function test_fires_campaign_assets_ready_when_all_generated(): void
    {
        Event::fake([CampaignAssetsReady::class]);

        $this->fake->queueFixture('email-content');
        $this->fake->queueFixture('social-content');

        $brain = $this->makeBrain();

        $emailData = $this->analyst->analyze($this->campaign, $this->emailChannel, $brain);
        $this->contentService->createAsset($this->campaign, $this->emailChannel, $emailData);

        Event::assertNotDispatched(CampaignAssetsReady::class);

        $socialData = $this->analyst->analyze($this->campaign, $this->socialChannel, $brain);
        $this->contentService->createAsset($this->campaign, $this->socialChannel, $socialData);

        Event::assertDispatched(CampaignAssetsReady::class, function (CampaignAssetsReady $event): bool {
            return $event->campaign->id === $this->campaign->id;
        });
    }

    public function test_does_not_fire_assets_ready_prematurely(): void
    {
        Event::fake([CampaignAssetsReady::class]);

        $this->fake->queueFixture('email-content');

        $brain = $this->makeBrain();
        $assetData = $this->analyst->analyze($this->campaign, $this->emailChannel, $brain);
        $this->contentService->createAsset($this->campaign, $this->emailChannel, $assetData);

        Event::assertNotDispatched(CampaignAssetsReady::class);
    }

    public function test_asset_stores_prompt_metadata(): void
    {
        $this->fake->queueFixture('email-content');

        $brain = $this->makeBrain();
        $assetData = $this->analyst->analyze($this->campaign, $this->emailChannel, $brain);
        $asset = $this->contentService->createAsset($this->campaign, $this->emailChannel, $assetData);

        $this->assertNotNull($asset->prompt_name);
        $this->assertNotNull($asset->prompt_version);
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
