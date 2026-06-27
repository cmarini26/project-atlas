<?php

namespace Tests\Feature\Analytics;

use App\Models\Approval;
use App\Models\Decision;
use App\Models\Recommendation;
use App\Services\Analytics\RecommendationKpiService;
use Illuminate\Support\Str;

class RecommendationKpiServiceTest extends AnalyticsTestCase
{
    private RecommendationKpiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(RecommendationKpiService::class);
    }

    private function makeFreshDecision(): Decision
    {
        $opportunity = $this->makeOpportunity();

        return Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item', 'channel_ids' => [$this->channel->id],
            'rationale' => ['why_now' => 'Now'], 'expected_impact' => ['summary' => 'Lift'],
            'confidence_score' => 70, 'status' => 'recommended', 'decided_at' => now(),
        ]);
    }

    private function makeRecommendationWithApproval(string $action): void
    {
        $decision = $this->makeFreshDecision();

        $recommendation = Recommendation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'decision_id' => $decision->id,
            'campaign_type' => 'featured_item', 'title' => 'Rec',
            'summary' => 'Summary', 'confidence_score' => 70,
            'expected_impact' => ['summary' => 'Lift'], 'status' => $action,
            'responded_at' => now(),
        ]);

        Approval::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'approvable_type' => 'recommendation',
            'approvable_id' => $recommendation->id,
            'user_id' => Str::ulid()->toString(),
            'action' => $action,
            'acted_at' => now(),
        ]);
    }

    public function test_returns_zero_approval_rate_when_no_recommendations(): void
    {
        $result = $this->service->forCompany($this->company->id);

        $this->assertEquals(0, $result['total_recommendations']);
        $this->assertEquals(0.0, $result['approval_rate']);
    }

    public function test_computes_correct_approval_rate(): void
    {
        $this->makeRecommendationWithApproval('approved');
        $this->makeRecommendationWithApproval('approved');
        $this->makeRecommendationWithApproval('rejected');

        $result = $this->service->forCompany($this->company->id);

        $this->assertEqualsWithDelta(0.6667, $result['approval_rate'], 0.001);
    }

    public function test_computes_correct_edit_rate(): void
    {
        $this->makeRecommendationWithApproval('approved');
        $this->makeRecommendationWithApproval('edited_and_approved');

        $result = $this->service->forCompany($this->company->id);

        $this->assertEqualsWithDelta(0.5, $result['edit_rate'], 0.001);
    }

    public function test_returns_correct_total_recommendations(): void
    {
        $this->makeRecommendationWithApproval('approved');
        $this->makeRecommendationWithApproval('rejected');

        $result = $this->service->forCompany($this->company->id);

        $this->assertEquals(2, $result['total_recommendations']);
    }

    public function test_trend_delta_detects_improvement(): void
    {
        $decision = $this->makeFreshDecision();

        Recommendation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'decision_id' => $decision->id,
            'campaign_type' => 'featured_item', 'title' => 'Rec',
            'summary' => 'Summary', 'confidence_score' => 70,
            'expected_impact' => ['summary' => 'Lift'], 'status' => 'pending',
        ]);

        $result = $this->service->forCompany($this->company->id);

        $this->assertArrayHasKey('approval_rate_trend_30d', $result);
        $this->assertArrayHasKey('delta', $result['approval_rate_trend_30d']);
    }
}
