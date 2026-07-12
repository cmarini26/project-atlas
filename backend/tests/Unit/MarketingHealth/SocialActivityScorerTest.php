<?php

namespace Tests\Unit\MarketingHealth;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Services\MarketingHealth\Scorers\SocialActivityScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SocialActivityScorerTest extends TestCase
{
    use RefreshDatabase;

    private SocialActivityScorer $scorer;

    private Company $company;

    private DigitalTwin $twin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new SocialActivityScorer();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'CBB Auctions', 'slug' => 'cbb-auctions']);
        $this->twin = DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
    }

    public function test_dimension_key(): void
    {
        $this->assertSame('social_activity', $this->scorer->dimension());
    }

    public function test_returns_null_without_a_posting_cadence_fact(): void
    {
        $result = $this->scorer->score($this->company, $this->makeBrain(collect()));

        $this->assertNull($result);
    }

    public function test_scores_100_when_cadence_meets_the_target(): void
    {
        $fact = $this->makeFact('instagram.posting_cadence', 2.0);

        $result = $this->scorer->score($this->company, $this->makeBrain(collect([$fact])));

        $this->assertNotNull($result);
        $this->assertSame(100, $result->score);
        $this->assertNotEmpty($result->evidence);
    }

    public function test_caps_score_at_100_when_cadence_exceeds_the_target(): void
    {
        $fact = $this->makeFact('instagram.posting_cadence', 10.0);

        $result = $this->scorer->score($this->company, $this->makeBrain(collect([$fact])));

        $this->assertSame(100, $result->score);
    }

    public function test_scores_proportionally_below_the_target(): void
    {
        $fact = $this->makeFact('instagram.posting_cadence', 1.0);

        $result = $this->scorer->score($this->company, $this->makeBrain(collect([$fact])));

        $this->assertSame(50, $result->score);
    }

    private function makeFact(string $key, float $value): Fact
    {
        return Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'key' => $key,
            'value' => json_encode($value),
            'data_type' => 'float',
            'confidence' => 90,
            'is_current' => true,
            'valid_from' => now(),
        ]);
    }

    private function makeBrain(Collection $facts): BusinessBrain
    {
        return new BusinessBrain(
            company: $this->company,
            twin: $this->twin,
            activeFacts: $facts,
            activeKnowledge: collect(),
            recentObservations: collect(),
            catalog: null,
            featuredItems: collect(),
            recentCampaigns: collect(),
        );
    }
}
