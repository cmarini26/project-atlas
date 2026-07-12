<?php

namespace Tests\Unit\MarketingHealth;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Services\MarketingHealth\Scorers\CtaStrengthScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CtaStrengthScorerTest extends TestCase
{
    use RefreshDatabase;

    private CtaStrengthScorer $scorer;

    private Company $company;

    private DigitalTwin $twin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new CtaStrengthScorer();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'CBB Auctions', 'slug' => 'cbb-auctions']);
        $this->twin = DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
    }

    public function test_dimension_key(): void
    {
        $this->assertSame('cta_strength', $this->scorer->dimension());
    }

    public function test_returns_null_without_a_fact_or_any_campaigns(): void
    {
        $result = $this->scorer->score($this->company, $this->makeBrain(facts: collect(), campaigns: collect()));

        $this->assertNull($result);
    }

    public function test_prefers_the_instagram_cta_usage_fact(): void
    {
        $fact = Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'key' => 'instagram.cta_usage',
            'value' => json_encode(66.7),
            'data_type' => 'float',
            'confidence' => 90,
            'is_current' => true,
            'valid_from' => now(),
        ]);

        $result = $this->scorer->score($this->company, $this->makeBrain(facts: collect([$fact]), campaigns: collect()));

        $this->assertNotNull($result);
        $this->assertSame(67, $result->score);
    }

    public function test_falls_back_to_campaign_call_to_action_presence(): void
    {
        $withCta = $this->makeCampaign(cta: 'Bid now');
        $withoutCta = $this->makeCampaign(cta: '');

        $result = $this->scorer->score($this->company, $this->makeBrain(facts: collect(), campaigns: collect([$withCta, $withoutCta])));

        $this->assertNotNull($result);
        $this->assertSame(50, $result->score);
    }

    private function makeCampaign(string $cta): Campaign
    {
        return Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_type' => 'featured_item',
            'title' => 'Campaign',
            'call_to_action' => $cta,
            'blueprint' => ['goal' => 'conversion'],
            'blueprint_version' => '1.0',
            'prompt_version' => '1.0',
            'expected_asset_count' => 1,
            'generated_asset_count' => 1,
            'status' => 'completed',
        ]);
    }

    private function makeBrain(Collection $facts, Collection $campaigns): BusinessBrain
    {
        return new BusinessBrain(
            company: $this->company,
            twin: $this->twin,
            activeFacts: $facts,
            activeKnowledge: collect(),
            recentObservations: collect(),
            catalog: null,
            featuredItems: collect(),
            recentCampaigns: $campaigns,
        );
    }
}
