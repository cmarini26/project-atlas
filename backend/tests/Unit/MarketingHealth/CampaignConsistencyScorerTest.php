<?php

namespace Tests\Unit\MarketingHealth;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Services\MarketingHealth\Scorers\CampaignConsistencyScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CampaignConsistencyScorerTest extends TestCase
{
    use RefreshDatabase;

    private CampaignConsistencyScorer $scorer;

    private Company $company;

    private DigitalTwin $twin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new CampaignConsistencyScorer();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'CBB Auctions', 'slug' => 'cbb-auctions']);
        $this->twin = DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
    }

    public function test_dimension_key(): void
    {
        $this->assertSame('campaign_consistency', $this->scorer->dimension());
    }

    public function test_returns_null_without_a_fact_or_any_campaigns(): void
    {
        $result = $this->scorer->score($this->company, $this->makeBrain(facts: collect(), campaigns: collect()));

        $this->assertNull($result);
    }

    public function test_prefers_the_days_since_last_campaign_fact(): void
    {
        $fact = Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'key' => 'marketing.days_since_last_campaign',
            'value' => json_encode(3),
            'data_type' => 'integer',
            'confidence' => 90,
            'is_current' => true,
            'valid_from' => now(),
        ]);

        $result = $this->scorer->score($this->company, $this->makeBrain(facts: collect([$fact]), campaigns: collect()));

        $this->assertNotNull($result);
        $this->assertSame(100, $result->score);
        $this->assertSame(90, $result->confidence);
    }

    public function test_falls_back_to_recent_campaigns_without_the_fact(): void
    {
        $campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_type' => 'featured_item',
            'title' => 'Old Campaign',
            'blueprint' => ['goal' => 'conversion'],
            'blueprint_version' => '1.0',
            'prompt_version' => '1.0',
            'expected_asset_count' => 1,
            'generated_asset_count' => 1,
            'status' => 'completed',
        ]);
        $campaign->forceFill(['created_at' => now()->subDays(90)])->save();

        $result = $this->scorer->score($this->company, $this->makeBrain(facts: collect(), campaigns: collect([$campaign])));

        $this->assertNotNull($result);
        $this->assertSame(0, $result->score);
        $this->assertSame(70, $result->confidence);
    }

    public function test_scores_zero_beyond_the_ceiling(): void
    {
        $fact = Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'key' => 'marketing.days_since_last_campaign',
            'value' => json_encode(120),
            'data_type' => 'integer',
            'confidence' => 90,
            'is_current' => true,
            'valid_from' => now(),
        ]);

        $result = $this->scorer->score($this->company, $this->makeBrain(facts: collect([$fact]), campaigns: collect()));

        $this->assertSame(0, $result->score);
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
