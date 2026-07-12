<?php

namespace Tests\Unit\MarketingHealth;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\MarketingChannel;
use App\Services\MarketingHealth\Scorers\PresenceCoverageScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresenceCoverageScorerTest extends TestCase
{
    use RefreshDatabase;

    private PresenceCoverageScorer $scorer;

    private Company $company;

    private DigitalTwin $twin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new PresenceCoverageScorer();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'CBB Auctions', 'slug' => 'cbb-auctions']);
        $this->twin = DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
    }

    public function test_dimension_key(): void
    {
        $this->assertSame('presence_coverage', $this->scorer->dimension());
    }

    public function test_returns_null_without_any_declared_channels(): void
    {
        $result = $this->scorer->score($this->company, $this->makeBrain());

        $this->assertNull($result);
    }

    public function test_scores_100_when_every_channel_is_active(): void
    {
        $this->makeChannel(status: 'active', importance: 'primary');
        $this->makeChannel(status: 'active', importance: 'secondary');

        $result = $this->scorer->score($this->company, $this->makeBrain());

        $this->assertNotNull($result);
        $this->assertSame(100, $result->score);
    }

    public function test_weights_primary_channels_more_than_secondary(): void
    {
        $this->makeChannel(status: 'active', importance: 'primary');
        $this->makeChannel(status: 'inactive', importance: 'secondary');

        $result = $this->scorer->score($this->company, $this->makeBrain());

        // active primary (weight 2) / (primary 2 + secondary 1) = 2/3 = 67%
        $this->assertSame(67, $result->score);
    }

    public function test_scores_0_when_no_channels_are_active(): void
    {
        $this->makeChannel(status: 'inactive', importance: 'primary');

        $result = $this->scorer->score($this->company, $this->makeBrain());

        $this->assertSame(0, $result->score);
    }

    private function makeChannel(string $status, string $importance): MarketingChannel
    {
        return MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'instagram',
            'display_name' => 'CBB Instagram',
            'status' => $status,
            'importance' => $importance,
            'objective' => ['awareness'],
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
