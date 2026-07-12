<?php

namespace Tests\Unit\MarketingHealth;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Models\Observation;
use App\Services\MarketingHealth\Scorers\WebsiteHealthScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class WebsiteHealthScorerTest extends TestCase
{
    use RefreshDatabase;

    private WebsiteHealthScorer $scorer;

    private Company $company;

    private DigitalTwin $twin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new WebsiteHealthScorer();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'CBB Auctions', 'slug' => 'cbb-auctions']);
        $this->twin = DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
    }

    public function test_dimension_key(): void
    {
        $this->assertSame('website', $this->scorer->dimension());
    }

    public function test_returns_null_when_never_crawled(): void
    {
        $result = $this->scorer->score($this->company, $this->makeBrain(observations: collect(), facts: collect()));

        $this->assertNull($result);
    }

    public function test_scores_high_for_a_recent_crawl_with_all_core_facts(): void
    {
        $observation = $this->makeCrawlObservation(daysAgo: 1, status: 'processed');
        $facts = collect([
            $this->makeFact('business.name', 'CBB Auctions'),
            $this->makeFact('business.description', 'Comic book auctions'),
            $this->makeFact('business.industry', 'auction'),
        ]);

        $result = $this->scorer->score($this->company, $this->makeBrain(observations: collect([$observation]), facts: $facts));

        $this->assertNotNull($result);
        $this->assertSame(100, $result->score);
        $this->assertNotEmpty($result->evidence);
    }

    public function test_scores_lower_for_a_stale_crawl_and_missing_facts(): void
    {
        $observation = $this->makeCrawlObservation(daysAgo: 45, status: 'processed');

        $result = $this->scorer->score($this->company, $this->makeBrain(observations: collect([$observation]), facts: collect()));

        $this->assertNotNull($result);
        $this->assertLessThan(50, $result->score);
    }

    public function test_confidence_scales_with_number_of_crawl_observations(): void
    {
        $one = $this->makeBrain(observations: collect([$this->makeCrawlObservation(daysAgo: 1, status: 'processed')]), facts: collect());
        $many = $this->makeBrain(
            observations: collect(array_map(fn () => $this->makeCrawlObservation(daysAgo: 1, status: 'processed'), range(1, 6))),
            facts: collect(),
        );

        $oneResult = $this->scorer->score($this->company, $one);
        $manyResult = $this->scorer->score($this->company, $many);

        $this->assertLessThan($manyResult->confidence, $oneResult->confidence);
    }

    public function test_ignores_non_crawl_observations(): void
    {
        $social = Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'source_type' => 'social',
            'source_identifier' => 'cbb_auctions',
            'raw_payload' => '{}',
            'status' => 'processed',
            'observed_at' => now(),
        ]);

        $result = $this->scorer->score($this->company, $this->makeBrain(observations: collect([$social]), facts: collect()));

        $this->assertNull($result);
    }

    private function makeCrawlObservation(int $daysAgo, string $status): Observation
    {
        return Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://cbbauctions.com',
            'raw_payload' => '{}',
            'status' => $status,
            'observed_at' => now()->subDays($daysAgo),
        ]);
    }

    private function makeFact(string $key, string $value): Fact
    {
        return Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'key' => $key,
            'value' => json_encode($value),
            'data_type' => 'string',
            'confidence' => 90,
            'is_current' => true,
            'valid_from' => now(),
        ]);
    }

    private function makeBrain(Collection $observations, Collection $facts): BusinessBrain
    {
        return new BusinessBrain(
            company: $this->company,
            twin: $this->twin,
            activeFacts: $facts,
            activeKnowledge: collect(),
            recentObservations: $observations,
            catalog: null,
            featuredItems: collect(),
            recentCampaigns: collect(),
        );
    }
}
