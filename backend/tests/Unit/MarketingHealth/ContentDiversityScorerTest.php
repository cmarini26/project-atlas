<?php

namespace Tests\Unit\MarketingHealth;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\DigitalTwin;
use App\Services\MarketingHealth\Scorers\ContentDiversityScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentDiversityScorerTest extends TestCase
{
    use RefreshDatabase;

    private ContentDiversityScorer $scorer;

    private Company $company;

    private DigitalTwin $twin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new ContentDiversityScorer();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'CBB Auctions', 'slug' => 'cbb-auctions']);
        $this->twin = DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
    }

    public function test_dimension_key(): void
    {
        $this->assertSame('content_diversity', $this->scorer->dimension());
    }

    public function test_returns_null_without_any_content_assets(): void
    {
        $result = $this->scorer->score($this->company, $this->makeBrain());

        $this->assertNull($result);
    }

    public function test_scores_zero_when_only_one_type_is_ever_used(): void
    {
        $this->makeAsset('social_post');
        $this->makeAsset('social_post');
        $this->makeAsset('social_post');

        $result = $this->scorer->score($this->company, $this->makeBrain());

        $this->assertNotNull($result);
        $this->assertSame(0, $result->score);
    }

    public function test_scores_100_when_evenly_split_across_two_types(): void
    {
        $this->makeAsset('social_post');
        $this->makeAsset('email');

        $result = $this->scorer->score($this->company, $this->makeBrain());

        $this->assertSame(100, $result->score);
    }

    public function test_scores_between_0_and_100_for_an_uneven_mix(): void
    {
        $this->makeAsset('social_post');
        $this->makeAsset('social_post');
        $this->makeAsset('social_post');
        $this->makeAsset('email');

        $result = $this->scorer->score($this->company, $this->makeBrain());

        $this->assertGreaterThan(0, $result->score);
        $this->assertLessThan(100, $result->score);
    }

    private function makeAsset(string $type): ContentAsset
    {
        return ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => (string) Str::ulid(),
            'channel_id' => (string) Str::ulid(),
            'type' => $type,
            'body' => 'Some content.',
            'status' => 'approved',
        ]);
    }

    private function makeBrain(): BusinessBrain
    {
        return new BusinessBrain(
            company: $this->company,
            twin: $this->twin,
            activeFacts: collect(),
            activeKnowledge: collect(),
            recentObservations: collect(),
            catalog: null,
            featuredItems: collect(),
            recentCampaigns: collect(),
        );
    }
}
