<?php

namespace Tests\Feature\Analytics;

use App\Models\CampaignKpiSnapshot;
use App\Models\ExecutionMetric;
use App\Models\Learning;
use App\Services\Learning\LearningService;
use Illuminate\Support\Str;

class LearningServiceMetricsTest extends AnalyticsTestCase
{
    private LearningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(LearningService::class);

        // Update campaign to have the channel_strategy blueprint needed for learning signals
        $this->campaign->blueprint = [
            'channel_strategy' => [['channel' => 'email', 'angle' => 'urgency']],
        ];
        $this->campaign->save();

        // Update decision expected_impact for engagement rate target
        $this->decision->expected_impact = ['target_engagement_rate' => 0.05];
        $this->decision->save();
    }

    private function makeSnapshot(string $rating, array $kpis = []): CampaignKpiSnapshot
    {
        return CampaignKpiSnapshot::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'campaign_id' => $this->campaign->id,
            'snapshot_type' => 'final', 'snapshotted_at' => now(),
            'channels_included' => ['email'],
            'actual_kpis' => $kpis,
            'performance_rating' => $rating,
        ]);
    }

    private function makeEmailExecution(array $metrics, bool $isFinal = true): void
    {
        $execution = $this->makeExecution();

        ExecutionMetric::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'execution_id' => $execution->id,
            'campaign_id' => $this->campaign->id, 'channel_type' => 'email',
            'provider_type' => 'postmark', 'platform_id' => Str::ulid()->toString(),
            'is_final' => $isFinal, 'metrics' => $metrics, 'retrieved_at' => now(),
        ]);
    }

    public function test_creates_channel_outperformed_when_best_is_15x_second_best(): void
    {
        $snapshot = $this->makeSnapshot('exceeded', [
            'channel_breakdown' => [
                'email' => ['engagement_rate' => 0.15],
                'social' => ['engagement_rate' => 0.05],
            ],
        ]);

        $this->service->recordFromMetrics($this->campaign, $snapshot);

        $this->assertDatabaseHas('learnings', [
            'company_id' => $this->company->id,
            'signal' => 'channel_outperformed',
        ]);
    }

    public function test_does_not_create_channel_outperformed_when_only_one_channel(): void
    {
        $snapshot = $this->makeSnapshot('exceeded', [
            'channel_breakdown' => [
                'email' => ['engagement_rate' => 0.15],
            ],
        ]);

        $this->service->recordFromMetrics($this->campaign, $snapshot);

        $this->assertDatabaseMissing('learnings', ['signal' => 'channel_outperformed']);
    }

    public function test_creates_campaign_type_succeeded_on_exceeded(): void
    {
        $snapshot = $this->makeSnapshot('exceeded', ['channel_breakdown' => []]);

        $this->service->recordFromMetrics($this->campaign, $snapshot);

        $this->assertDatabaseHas('learnings', [
            'company_id' => $this->company->id,
            'signal' => 'campaign_type_succeeded',
        ]);
    }

    public function test_creates_email_deliverability_issue_when_hard_bounces_present(): void
    {
        $this->makeEmailExecution(['bounces_hard' => 3, 'delivered' => 500]);

        $snapshot = $this->makeSnapshot('met', ['channel_breakdown' => []]);

        $this->service->recordFromMetrics($this->campaign, $snapshot);

        $this->assertDatabaseHas('learnings', [
            'company_id' => $this->company->id,
            'signal' => 'email_deliverability_issue',
        ]);
    }

    public function test_creates_email_deliverability_issue_when_spam_complaint_rate_exceeded(): void
    {
        $this->makeEmailExecution(['spam_complaints' => 3, 'delivered' => 1000]);

        $snapshot = $this->makeSnapshot('met', ['channel_breakdown' => []]);

        $this->service->recordFromMetrics($this->campaign, $snapshot);

        $this->assertDatabaseHas('learnings', ['signal' => 'email_deliverability_issue']);
    }

    public function test_creates_high_unsubscribe_rate_signal(): void
    {
        $this->makeEmailExecution(['unsubscribes' => 15, 'delivered' => 100]);

        $snapshot = $this->makeSnapshot('met', ['channel_breakdown' => []]);

        $this->service->recordFromMetrics($this->campaign, $snapshot);

        $this->assertDatabaseHas('learnings', ['signal' => 'high_unsubscribe_rate']);
    }

    public function test_creates_content_angle_engaged_when_exceeded_with_angle(): void
    {
        $snapshot = $this->makeSnapshot('exceeded', ['channel_breakdown' => []]);

        $this->service->recordFromMetrics($this->campaign, $snapshot);

        $this->assertDatabaseHas('learnings', ['signal' => 'content_angle_engaged']);
    }

    public function test_does_not_create_content_angle_engaged_when_not_exceeded(): void
    {
        $snapshot = $this->makeSnapshot('met', ['channel_breakdown' => []]);

        $this->service->recordFromMetrics($this->campaign, $snapshot);

        $this->assertDatabaseMissing('learnings', ['signal' => 'content_angle_engaged']);
    }

    public function test_record_from_metrics_is_idempotent(): void
    {
        $snapshot = $this->makeSnapshot('exceeded', ['channel_breakdown' => []]);

        $this->service->recordFromMetrics($this->campaign, $snapshot);
        $this->service->recordFromMetrics($this->campaign, $snapshot);

        $this->assertEquals(
            1,
            Learning::withoutGlobalScopes()
                ->where('signal', 'campaign_type_succeeded')
                ->where('source_id', $snapshot->id)
                ->count(),
        );
    }

    public function test_all_learning_records_have_null_applied_at(): void
    {
        $this->makeEmailExecution(['bounces_hard' => 1]);

        $snapshot = $this->makeSnapshot('exceeded', [
            'channel_breakdown' => [
                'email' => ['engagement_rate' => 0.15],
                'social' => ['engagement_rate' => 0.05],
            ],
        ]);

        $this->service->recordFromMetrics($this->campaign, $snapshot);

        Learning::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->each(function (Learning $learning): void {
                $this->assertNull($learning->applied_at);
            });
    }

    public function test_campaign_type_underperformed_not_created_on_first_below(): void
    {
        $snapshot = $this->makeSnapshot('below', ['channel_breakdown' => []]);

        $this->service->recordFromMetrics($this->campaign, $snapshot);

        $this->assertDatabaseMissing('learnings', ['signal' => 'campaign_type_underperformed']);
    }
}
