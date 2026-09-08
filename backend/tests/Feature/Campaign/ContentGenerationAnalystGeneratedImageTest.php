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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentGenerationAnalystGeneratedImageTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private ContentGenerationAnalyst $analyst;

    private Company $company;

    private DigitalTwin $twin;

    private Catalog $catalog;

    private Campaign $campaign;

    private Channel $instagram;

    private Channel $emailChannel;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config()->set('imaging.enabled', true);
        config()->set('imaging.provider', 'fake');

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
        $this->instagram = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'instagram', 'name' => 'Instagram', 'is_active' => true,
        ]);
        $this->emailChannel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true,
        ]);

        $blueprint = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/AI/campaign-blueprint.json')),
            true,
        );

        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'subject_type' => 'catalog_item', 'type' => 'featured_item',
            'title' => 'Silver Age', 'description' => 'High value', 'relevance_score' => 85, 'timing_score' => 80,
            'confidence_score' => 75, 'urgency_score' => 70, 'composite_score' => 79,
            'status' => 'selected', 'detected_at' => now()->subHour(),
        ]);
        $decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'opportunity_id' => $opportunity->id, 'campaign_type' => 'featured_item',
            'channel_ids' => [$this->instagram->id], 'rationale' => ['why_now' => 'Soon'],
            'expected_impact' => ['summary' => 'lift'], 'confidence_score' => 75, 'status' => 'pending', 'decided_at' => now(),
        ]);
        $this->campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'decision_id' => $decision->id, 'campaign_type' => 'featured_item',
            'title' => 'Silver Age Campaign', 'blueprint' => $blueprint, 'blueprint_version' => '1.0',
            'prompt_version' => '1.0', 'expected_asset_count' => 1, 'generated_asset_count' => 0, 'status' => 'draft',
        ]);
    }

    public function test_generated_image_is_used_for_a_visual_channel_over_the_crawl_fallback(): void
    {
        $this->fake->queueFixture('social-content');
        $crawl = $this->makeCrawlObservation(['https://example.com/hero.jpg']);

        $data = $this->analyst->analyze($this->campaign, $this->instagram, $this->makeBrain(collect([$crawl])));

        $this->assertNotNull($data->media);
        $this->assertSame('ai_generated', $data->media[0]['source']);
        $this->assertStringNotContainsString('example.com/hero.jpg', $data->media[0]['url']);
    }

    public function test_generated_image_marks_asset_metadata_so_the_ui_can_label_it(): void
    {
        $this->fake->queueFixture('social-content');

        $data = $this->analyst->analyze($this->campaign, $this->instagram, $this->makeBrain(collect()));

        $this->assertIsArray($data->metadata);
        $this->assertTrue($data->metadata['generated_image']);
        $this->assertSame('1.0', $data->metadata['image_prompt_version']);
    }

    public function test_crawl_fallback_image_does_not_mark_metadata_as_generated(): void
    {
        config()->set('imaging.enabled', false);
        $this->fake->queueFixture('social-content');
        $crawl = $this->makeCrawlObservation(['https://example.com/hero.jpg']);

        $data = $this->analyst->analyze($this->campaign, $this->instagram, $this->makeBrain(collect([$crawl])));

        $this->assertArrayNotHasKey('generated_image', (array) $data->metadata);
    }

    public function test_unsupported_channel_still_falls_back_to_crawl_image(): void
    {
        $this->fake->queueFixture('email-content');
        $crawl = $this->makeCrawlObservation(['https://example.com/hero.jpg']);

        $data = $this->analyst->analyze($this->campaign, $this->emailChannel, $this->makeBrain(collect([$crawl])));

        $this->assertSame([['url' => 'https://example.com/hero.jpg']], $data->media);
    }

    public function test_disabled_feature_leaves_media_behaviour_unchanged(): void
    {
        config()->set('imaging.enabled', false);
        $this->fake->queueFixture('social-content');
        $crawl = $this->makeCrawlObservation(['https://example.com/hero.jpg']);

        $data = $this->analyst->analyze($this->campaign, $this->instagram, $this->makeBrain(collect([$crawl])));

        $this->assertSame([['url' => 'https://example.com/hero.jpg']], $data->media);
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
