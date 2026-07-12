<?php

namespace Tests\Unit\MarketingHealth;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Services\MarketingHealth\Scorers\BrandConsistencyScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class BrandConsistencyScorerTest extends TestCase
{
    use RefreshDatabase;

    private BrandConsistencyScorer $scorer;

    private DigitalTwin $twin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new BrandConsistencyScorer();
    }

    public function test_dimension_key(): void
    {
        $this->assertSame('brand_consistency', $this->scorer->dimension());
    }

    public function test_returns_null_without_a_declared_brand_voice(): void
    {
        $company = $this->makeCompany(brand: []);
        $this->makeTwin($company);
        $campaign = $this->makeCampaign($company, voice: 'playful');

        $result = $this->scorer->score($company, $this->makeBrain($company, collect([$campaign])));

        $this->assertNull($result);
    }

    public function test_returns_null_without_any_campaign_tone_to_compare(): void
    {
        $company = $this->makeCompany(brand: ['voice' => 'playful']);
        $this->makeTwin($company);

        $result = $this->scorer->score($company, $this->makeBrain($company, collect()));

        $this->assertNull($result);
    }

    public function test_scores_100_when_every_campaign_matches_the_declared_voice(): void
    {
        $company = $this->makeCompany(brand: ['voice' => 'Playful']);
        $this->makeTwin($company);
        $campaigns = collect([
            $this->makeCampaign($company, voice: 'playful'),
            $this->makeCampaign($company, voice: 'PLAYFUL'),
        ]);

        $result = $this->scorer->score($company, $this->makeBrain($company, $campaigns));

        $this->assertNotNull($result);
        $this->assertSame(100, $result->score);
    }

    public function test_scores_proportionally_on_partial_agreement(): void
    {
        $company = $this->makeCompany(brand: ['voice' => 'playful']);
        $this->makeTwin($company);
        $campaigns = collect([
            $this->makeCampaign($company, voice: 'playful'),
            $this->makeCampaign($company, voice: 'formal'),
        ]);

        $result = $this->scorer->score($company, $this->makeBrain($company, $campaigns));

        $this->assertSame(50, $result->score);
    }

    private function makeCompany(array $brand): Company
    {
        return Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions', 'slug' => 'cbb-auctions-'.uniqid(), 'brand' => $brand,
        ]);
    }

    private function makeTwin(Company $company): void
    {
        $this->twin = DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'status' => 'active', 'health_score' => 80,
        ]);
    }

    private function makeCampaign(Company $company, string $voice): Campaign
    {
        return Campaign::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_type' => 'featured_item',
            'title' => "Campaign ({$voice})",
            'blueprint' => ['goal' => 'conversion', 'tone' => ['voice' => $voice]],
            'blueprint_version' => '1.0',
            'prompt_version' => '1.0',
            'expected_asset_count' => 1,
            'generated_asset_count' => 1,
            'status' => 'completed',
        ]);
    }

    private function makeBrain(Company $company, Collection $campaigns): BusinessBrain
    {
        return new BusinessBrain(
            company: $company,
            twin: $this->twin,
            activeFacts: collect(),
            activeKnowledge: collect(),
            recentObservations: collect(),
            catalog: null,
            featuredItems: collect(),
            recentCampaigns: $campaigns,
        );
    }
}
