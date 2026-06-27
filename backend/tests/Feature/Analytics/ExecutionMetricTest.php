<?php

namespace Tests\Feature\Analytics;

use App\Models\ExecutionMetric;

class ExecutionMetricTest extends AnalyticsTestCase
{
    public function test_can_create_execution_metric(): void
    {
        $execution = $this->makeExecution();

        $metric = ExecutionMetric::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'execution_id' => $execution->id,
            'campaign_id' => $this->campaign->id,
            'channel_type' => 'email',
            'provider_type' => 'postmark',
            'platform_id' => 'msg-abc',
            'is_final' => false,
            'metrics' => ['normalised_reach' => 100],
        ]);

        $this->assertDatabaseHas('execution_metrics', ['id' => $metric->id]);
        $this->assertEquals(['normalised_reach' => 100], $metric->metrics);
        $this->assertFalse($metric->is_final);
    }

    public function test_scope_for_campaign_filters_correctly(): void
    {
        $execution = $this->makeExecution();

        ExecutionMetric::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'execution_id' => $execution->id,
            'campaign_id' => $this->campaign->id, 'channel_type' => 'email',
            'provider_type' => 'postmark', 'platform_id' => 'msg-1', 'is_final' => true,
        ]);

        $results = ExecutionMetric::withoutGlobalScopes()->forCampaign($this->campaign->id)->get();

        $this->assertCount(1, $results);
    }

    public function test_scope_pending_excludes_final_records(): void
    {
        $execution = $this->makeExecution();

        ExecutionMetric::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'execution_id' => $execution->id,
            'campaign_id' => $this->campaign->id, 'channel_type' => 'email',
            'provider_type' => 'postmark', 'platform_id' => 'msg-1', 'is_final' => true,
        ]);

        $pending = ExecutionMetric::withoutGlobalScopes()->pending()->get();

        $this->assertCount(0, $pending);
    }

    public function test_metrics_column_casts_to_array(): void
    {
        $execution = $this->makeExecution();

        $metric = ExecutionMetric::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'execution_id' => $execution->id,
            'campaign_id' => $this->campaign->id, 'channel_type' => 'email',
            'provider_type' => 'postmark', 'platform_id' => 'msg-1', 'is_final' => false,
            'metrics' => ['key' => 'value'],
        ]);

        $this->assertIsArray($metric->metrics);
    }

    public function test_execution_metric_has_created_at(): void
    {
        $execution = $this->makeExecution();

        $metric = ExecutionMetric::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'execution_id' => $execution->id,
            'campaign_id' => $this->campaign->id, 'channel_type' => 'email',
            'provider_type' => 'postmark', 'platform_id' => 'msg-abc2', 'is_final' => false,
        ]);

        $this->assertNotNull($metric->id);
        $this->assertNotNull($metric->created_at);
    }
}
