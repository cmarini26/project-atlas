<?php

namespace Tests\Feature\Analytics;

use App\Models\Campaign;
use App\Models\CampaignKpiSnapshot;
use App\Models\Decision;
use App\Services\Analytics\DecisionEffectivenessService;

class DecisionEffectivenessServiceTest extends AnalyticsTestCase
{
    private DecisionEffectivenessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(DecisionEffectivenessService::class);
    }

    private function makeSnapshotWithRating(string $rating, string $campaignType = 'featured_item'): void
    {
        $opportunity = $this->makeOpportunity();

        $decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'opportunity_id' => $opportunity->id,
            'campaign_type' => $campaignType, 'channel_ids' => [$this->channel->id],
            'rationale' => ['why_now' => 'Now'], 'expected_impact' => ['summary' => 'Lift'],
            'confidence_score' => 70, 'status' => 'recommended', 'decided_at' => now(),
        ]);

        $campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'decision_id' => $decision->id,
            'campaign_type' => $campaignType, 'title' => 'Campaign',
            'blueprint' => [], 'blueprint_version' => '1.0', 'prompt_version' => '1.0',
            'expected_asset_count' => 1, 'generated_asset_count' => 1, 'status' => 'completed',
        ]);

        CampaignKpiSnapshot::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'campaign_id' => $campaign->id,
            'snapshot_type' => 'final', 'snapshotted_at' => now(),
            'channels_included' => ['email'], 'actual_kpis' => [],
            'performance_rating' => $rating,
        ]);
    }

    public function test_returns_empty_result_when_no_decisions(): void
    {
        $result = $this->service->forCompany($this->company->id);

        $this->assertEquals(0, $result['decisions_total']);
        $this->assertEquals(0.0, $result['accuracy_rate']);
    }

    public function test_accuracy_rate_is_one_when_all_exceeded(): void
    {
        $this->makeSnapshotWithRating('exceeded');
        $this->makeSnapshotWithRating('exceeded');

        $result = $this->service->forCompany($this->company->id);

        $this->assertEquals(1.0, $result['accuracy_rate']);
        $this->assertEquals(2, $result['decisions_total']);
    }

    public function test_accuracy_rate_is_zero_when_all_below(): void
    {
        $this->makeSnapshotWithRating('below');

        $result = $this->service->forCompany($this->company->id);

        $this->assertEquals(0.0, $result['accuracy_rate']);
    }

    public function test_mixed_ratings_compute_correct_accuracy(): void
    {
        $this->makeSnapshotWithRating('exceeded');
        $this->makeSnapshotWithRating('met');
        $this->makeSnapshotWithRating('below');

        $result = $this->service->forCompany($this->company->id);

        $this->assertEqualsWithDelta(0.6667, $result['accuracy_rate'], 0.001);
        $this->assertEquals(3, $result['decisions_total']);
    }

    public function test_accuracy_by_campaign_type_breakdown(): void
    {
        $this->makeSnapshotWithRating('exceeded', 'featured_item');
        $this->makeSnapshotWithRating('below', 'urgency_promotion');

        $result = $this->service->forCompany($this->company->id);

        $this->assertArrayHasKey('featured_item', $result['accuracy_by_campaign_type']);
        $this->assertArrayHasKey('urgency_promotion', $result['accuracy_by_campaign_type']);
        $this->assertEquals(1.0, $result['accuracy_by_campaign_type']['featured_item']);
        $this->assertEquals(0.0, $result['accuracy_by_campaign_type']['urgency_promotion']);
    }
}
