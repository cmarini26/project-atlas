<?php

namespace Tests\Feature\Analytics;

use App\Models\ExecutionMetric;
use App\Services\Analytics\CampaignKpiService;
use App\Services\Analytics\MetaAnalyticsProvider;
use App\Services\Analytics\PostmarkAnalyticsProvider;
use Illuminate\Support\Str;

class CampaignKpiServiceTest extends AnalyticsTestCase
{
    private CampaignKpiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(CampaignKpiService::class);
    }

    private function makeMetric(array $metrics, bool $isFinal = true, string $channelType = 'email'): ExecutionMetric
    {
        $execution = $this->makeExecution();

        return ExecutionMetric::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'execution_id' => $execution->id,
            'campaign_id' => $this->campaign->id, 'channel_type' => $channelType,
            'provider_type' => 'postmark', 'platform_id' => Str::ulid()->toString(),
            'is_final' => $isFinal, 'metrics' => $metrics,
        ]);
    }

    public function test_aggregate_sums_reach_and_engagement_across_executions(): void
    {
        $this->makeMetric(['normalised_reach' => 500, 'normalised_engagement' => 25]);
        $this->makeMetric(['normalised_reach' => 300, 'normalised_engagement' => 15]);

        $result = $this->service->aggregate($this->campaign->id);

        $this->assertEquals(800, $result['total_reach']);
        $this->assertEquals(40, $result['total_engagement']);
    }

    public function test_aggregate_computes_engagement_rate(): void
    {
        $this->makeMetric(['normalised_reach' => 1000, 'normalised_engagement' => 50]);

        $result = $this->service->aggregate($this->campaign->id);

        $this->assertEquals(0.05, $result['total_engagement_rate']);
    }

    public function test_aggregate_sums_clicks_and_computes_click_rate(): void
    {
        $this->makeMetric(['normalised_reach' => 1000, 'normalised_clicks' => 40]);
        $this->makeMetric(['normalised_reach' => 500, 'normalised_clicks' => 10]);

        $result = $this->service->aggregate($this->campaign->id);

        $this->assertEquals(50, $result['total_clicks']);
        $this->assertEqualsWithDelta(0.0333, $result['total_click_rate'], 0.001);
    }

    public function test_click_rate_is_null_without_reach(): void
    {
        $this->makeMetric(['normalised_clicks' => 10]);

        $result = $this->service->aggregate($this->campaign->id);

        $this->assertNull($result['total_click_rate']);
    }

    public function test_aggregate_produces_non_zero_reach_and_engagement_from_real_postmark_normalization(): void
    {
        // End-to-end regression for the analytics-contract bug: a real
        // Postmark MessageEvents payload, run through the real provider's
        // normalize(), must actually contribute non-zero reach/engagement —
        // not silently aggregate to zero because of a missing key.
        $provider = new PostmarkAnalyticsProvider();

        $normalized = $provider->normalize(['MessageEvents' => [
            ['Type' => 'Delivered'],
            ['Type' => 'Opened'],
            ['Type' => 'Click'],
        ]]);

        $this->makeMetric($normalized, true, 'email');

        $result = $this->service->aggregate($this->campaign->id);

        $this->assertEquals(1, $result['total_reach']);
        $this->assertEquals(2, $result['total_engagement']);
        $this->assertEquals(1, $result['total_clicks']);
    }

    public function test_aggregate_combines_postmark_and_meta_channels_correctly(): void
    {
        $postmark = new PostmarkAnalyticsProvider();
        $meta = new MetaAnalyticsProvider();

        $emailMetrics = $postmark->normalize(['MessageEvents' => [
            ['Type' => 'Delivered'],
            ['Type' => 'Opened'],
            ['Type' => 'Click'],
        ]]);

        $socialMetrics = $meta->normalize(['data' => [
            ['name' => 'reach', 'values' => [['value' => 500]]],
            ['name' => 'engagement', 'values' => [['value' => 25]]],
            ['name' => 'clicks', 'values' => [['value' => 10]]],
        ]]);

        $this->makeMetric($emailMetrics, true, 'email');
        $this->makeMetric($socialMetrics, true, 'instagram');

        $result = $this->service->aggregate($this->campaign->id);

        $this->assertEquals(501, $result['total_reach']);
        $this->assertEquals(27, $result['total_engagement']);
        $this->assertEquals(11, $result['total_clicks']);
        $this->assertArrayHasKey('email', $result['channel_breakdown']);
        $this->assertArrayHasKey('instagram', $result['channel_breakdown']);
        $this->assertEquals(1, $result['channel_breakdown']['email']['reach']);
        $this->assertEquals(500, $result['channel_breakdown']['instagram']['reach']);
    }

    public function test_meta_normalization_is_unchanged_by_the_postmark_fix(): void
    {
        $meta = new MetaAnalyticsProvider();

        $normalized = $meta->normalize(['data' => [
            ['name' => 'reach', 'values' => [['value' => 1500]]],
            ['name' => 'engagement', 'values' => [['value' => 45]]],
            ['name' => 'clicks', 'values' => [['value' => 5]]],
        ]]);

        $this->assertSame([
            'normalised_reach' => 1500,
            'normalised_engagement' => 45,
            'normalised_clicks' => 5,
        ], $normalized);
    }

    public function test_best_channel_returns_channel_with_highest_rate(): void
    {
        $channelBreakdown = [
            'email' => ['engagement_rate' => 0.05],
            'social' => ['engagement_rate' => 0.12],
            'sms' => ['engagement_rate' => 0.03],
        ];

        $result = $this->service->bestChannel($channelBreakdown);

        $this->assertEquals('social', $result);
    }

    public function test_best_channel_returns_empty_string_for_empty_breakdown(): void
    {
        $result = $this->service->bestChannel([]);

        $this->assertEquals('', $result);
    }

    public function test_snapshot_if_ready_returns_null_when_no_metrics(): void
    {
        $result = $this->service->snapshotIfReady($this->campaign->id);

        $this->assertNull($result);
    }

    public function test_snapshot_if_ready_creates_final_when_all_windows_closed(): void
    {
        $this->makeMetric(['normalised_reach' => 500], true);

        $snapshot = $this->service->snapshotIfReady($this->campaign->id);

        $this->assertNotNull($snapshot);
        $this->assertEquals('final', $snapshot->snapshot_type);
    }

    public function test_snapshot_if_ready_creates_interim_when_some_windows_open(): void
    {
        $this->makeMetric(['normalised_reach' => 500], true);
        $this->makeMetric(['normalised_reach' => 300], false);

        $snapshot = $this->service->snapshotIfReady($this->campaign->id);

        $this->assertNotNull($snapshot);
        $this->assertEquals('interim', $snapshot->snapshot_type);
    }

    public function test_snapshot_if_ready_does_not_create_duplicate_final(): void
    {
        $this->makeMetric(['normalised_reach' => 500], true);

        $this->service->snapshotIfReady($this->campaign->id);
        $second = $this->service->snapshotIfReady($this->campaign->id);

        $this->assertDatabaseCount('campaign_kpi_snapshots', 1);
        $this->assertNotNull($second);
    }

    public function test_rate_performance_returns_exceeded_at_125_percent(): void
    {
        $result = $this->service->ratePerformance(
            ['total_engagement_rate' => 0.0625],
            ['target_engagement_rate' => 0.05],
        );

        $this->assertEquals('exceeded', $result);
    }

    public function test_rate_performance_returns_met_at_100_percent(): void
    {
        $result = $this->service->ratePerformance(
            ['total_engagement_rate' => 0.05],
            ['target_engagement_rate' => 0.05],
        );

        $this->assertEquals('met', $result);
    }

    public function test_rate_performance_returns_below_at_74_percent(): void
    {
        $result = $this->service->ratePerformance(
            ['total_engagement_rate' => 0.037],
            ['target_engagement_rate' => 0.05],
        );

        $this->assertEquals('below', $result);
    }

    public function test_rate_performance_returns_insufficient_data_when_no_baseline(): void
    {
        $result = $this->service->ratePerformance(
            ['total_engagement_rate' => 0.05],
            ['summary' => 'qualitative only'],
        );

        $this->assertEquals('insufficient_data', $result);
    }

    public function test_rate_performance_returns_insufficient_data_when_no_actual_rate(): void
    {
        $result = $this->service->ratePerformance(
            [],
            ['target_engagement_rate' => 0.05],
        );

        $this->assertEquals('insufficient_data', $result);
    }
}
