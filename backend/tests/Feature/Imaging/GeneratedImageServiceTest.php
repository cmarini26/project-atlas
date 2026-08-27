<?php

namespace Tests\Feature\Imaging;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\Imaging\ValueObjects\GeneratedImage;
use App\Models\Campaign;
use App\Models\Catalog;
use App\Models\Channel;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use App\Services\Imaging\Contracts\ImageGenerationProvider;
use App\Services\Imaging\Exceptions\ImageGenerationException;
use App\Services\Imaging\GeneratedImageService;
use App\Services\Imaging\ImageGenerationProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeneratedImageServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private DigitalTwin $twin;

    private Catalog $catalog;

    private Campaign $campaign;

    private Channel $instagram;

    private Channel $email;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config()->set('imaging.enabled', true);
        config()->set('imaging.provider', 'fake');
        config()->set('imaging.disk', 'public');

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
        $this->email = Channel::withoutGlobalScopes()->create([
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

    private function service(): GeneratedImageService
    {
        return $this->app->make(GeneratedImageService::class);
    }

    private function brain(): BusinessBrain
    {
        return new BusinessBrain(
            company: $this->company,
            twin: $this->twin,
            activeFacts: collect(),
            activeKnowledge: collect(),
            recentObservations: new Collection(),
            catalog: $this->catalog,
            featuredItems: collect(),
            recentCampaigns: collect(),
        );
    }

    public function test_returns_null_when_the_feature_is_disabled(): void
    {
        config()->set('imaging.enabled', false);

        $this->assertNull($this->service()->proposeFor($this->campaign, $this->instagram, $this->brain()));
    }

    public function test_generates_and_stores_an_image_for_an_eligible_channel(): void
    {
        $media = $this->service()->proposeFor($this->campaign, $this->instagram, $this->brain());

        $this->assertNotNull($media);
        $this->assertCount(1, $media);
        $this->assertSame('ai_generated', $media[0]['source']);
        $this->assertSame('image', $media[0]['type']);
        $this->assertSame(GeneratedImageService::PROMPT_VERSION, $media[0]['prompt_version']);

        $stored = Storage::disk('public')->allFiles("generated-content/{$this->company->id}/{$this->campaign->id}/{$this->instagram->id}");
        $this->assertCount(1, $stored);
        $this->assertStringEndsWith('.png', $stored[0]);
    }

    public function test_returns_null_for_an_ineligible_channel(): void
    {
        $this->assertNull($this->service()->proposeFor($this->campaign, $this->email, $this->brain()));
        $this->assertSame([], Storage::disk('public')->allFiles('generated-content'));
    }

    public function test_degrades_to_null_when_the_provider_fails(): void
    {
        $this->app->make(ImageGenerationProviderRegistry::class)->register(new class() implements ImageGenerationProvider
        {
            public function generate(string $prompt, string $size = '1024x1024'): GeneratedImage
            {
                throw new ImageGenerationException('provider exploded');
            }

            public function supports(string $providerType): bool
            {
                return $providerType === 'boom';
            }
        });
        config()->set('imaging.provider', 'boom');

        $this->assertNull($this->service()->proposeFor($this->campaign, $this->instagram, $this->brain()));
        $this->assertSame([], Storage::disk('public')->allFiles('generated-content'));
    }

    public function test_returns_null_once_the_per_company_daily_limit_is_reached(): void
    {
        config()->set('imaging.per_company_daily_limit', 2);

        foreach (range(1, 2) as $i) {
            ContentAsset::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'campaign_id' => $this->campaign->id,
                'channel_id' => $this->instagram->id,
                'type' => 'social_post',
                'body' => "existing {$i}",
                'media' => [['url' => "https://cdn.test/{$i}.png", 'source' => 'ai_generated']],
                'status' => 'draft',
            ]);
        }

        $this->assertNull($this->service()->proposeFor($this->campaign, $this->instagram, $this->brain()));
    }
}
