<?php

namespace Tests\Feature\Campaign;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Campaign;
use App\Models\Catalog;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Observation;
use App\Models\Opportunity;
use App\Services\Analyst\Content\ContentGenerationAnalyst;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ContentGenerationAnalystMediaTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private ContentGenerationAnalyst $analyst;

    private Company $company;

    private DigitalTwin $twin;

    private Catalog $catalog;

    private Campaign $campaign;

    private Channel $socialChannel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->fake);
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
        $this->socialChannel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'instagram', 'name' => 'Instagram', 'is_active' => true,
        ]);

        $blueprintData = json_decode(
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
            'channel_ids' => [$this->socialChannel->id],
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
            'blueprint' => $blueprintData,
            'blueprint_version' => '1.0',
            'prompt_version' => '1.0',
            'expected_asset_count' => 1,
            'generated_asset_count' => 0,
            'status' => 'draft',
        ]);
    }

    public function test_media_is_null_when_nothing_has_been_crawled(): void
    {
        $this->fake->queueFixture('social-content');

        $assetData = $this->analyst->analyze($this->campaign, $this->socialChannel, $this->makeBrain(collect()));

        $this->assertNull($assetData->media);
    }

    public function test_media_pulls_first_image_from_most_recent_crawl(): void
    {
        $this->fake->queueFixture('social-content');

        $observation = $this->makeCrawlObservation(['https://example.com/hero.jpg', 'https://example.com/other.jpg']);

        $assetData = $this->analyst->analyze($this->campaign, $this->socialChannel, $this->makeBrain(collect([$observation])));

        $this->assertSame([['url' => 'https://example.com/hero.jpg']], $assetData->media);
    }

    public function test_media_ignores_non_crawl_observations(): void
    {
        $this->fake->queueFixture('social-content');

        $observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'source_type' => 'social',
            'source_identifier' => 'acme',
            'raw_payload' => json_encode(['images' => ['https://example.com/ig.jpg']]),
            'status' => 'processed',
            'observed_at' => now(),
        ]);

        $assetData = $this->analyst->analyze($this->campaign, $this->socialChannel, $this->makeBrain(collect([$observation])));

        $this->assertNull($assetData->media);
    }

    public function test_media_falls_through_to_next_crawl_when_first_has_no_images(): void
    {
        $this->fake->queueFixture('social-content');

        $emptyCrawl = $this->makeCrawlObservation([]);
        $withImage = $this->makeCrawlObservation(['https://example.com/product.jpg']);

        $assetData = $this->analyst->analyze(
            $this->campaign,
            $this->socialChannel,
            $this->makeBrain(collect([$emptyCrawl, $withImage])),
        );

        $this->assertSame([['url' => 'https://example.com/product.jpg']], $assetData->media);
    }

    /** @param string[] $images */
    private function makeCrawlObservation(array $images): Observation
    {
        return Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://example.com',
            'raw_payload' => json_encode(['body_text' => 'Welcome', 'images' => $images]),
            'status' => 'processed',
            'observed_at' => now(),
        ]);
    }

    private function makeBrain(Collection $recentObservations): BusinessBrain
    {
        return new BusinessBrain(
            company: $this->company,
            twin: $this->twin,
            activeFacts: collect(),
            activeKnowledge: collect(),
            recentObservations: $recentObservations,
            catalog: $this->catalog,
            featuredItems: collect(),
            recentCampaigns: collect(),
        );
    }
}
