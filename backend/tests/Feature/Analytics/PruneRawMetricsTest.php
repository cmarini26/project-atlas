<?php

namespace Tests\Feature\Analytics;

use App\Jobs\PruneRawMetrics;
use App\Models\ExecutionMetric;
use Illuminate\Support\Str;

class PruneRawMetricsTest extends AnalyticsTestCase
{
    private function makeMetric(array $overrides = []): ExecutionMetric
    {
        $execution = $this->makeExecution();

        return ExecutionMetric::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id, 'execution_id' => $execution->id,
            'campaign_id' => $this->campaign->id, 'channel_type' => 'email',
            'provider_type' => 'postmark', 'platform_id' => Str::ulid()->toString(),
            'is_final' => true,
        ], $overrides));
    }

    public function test_nulls_raw_on_records_older_than_one_year(): void
    {
        $oldMetric = $this->makeMetric([
            'retrieved_at' => now()->subYear()->subDay(),
            'raw' => ['old_data' => true],
            'metrics' => ['normalised_reach' => 100],
        ]);

        (new PruneRawMetrics())->handle();

        $oldMetric->refresh();
        $this->assertNull($oldMetric->raw);
    }

    public function test_does_not_touch_metrics_column(): void
    {
        $oldMetric = $this->makeMetric([
            'retrieved_at' => now()->subYear()->subDay(),
            'raw' => ['old_data' => true],
            'metrics' => ['normalised_reach' => 200],
        ]);

        (new PruneRawMetrics())->handle();

        $oldMetric->refresh();
        $this->assertEquals(['normalised_reach' => 200], $oldMetric->metrics);
    }

    public function test_does_not_prune_recent_records(): void
    {
        $recentMetric = $this->makeMetric([
            'retrieved_at' => now()->subMonths(6),
            'raw' => ['recent_data' => true],
            'metrics' => ['normalised_reach' => 50],
            'is_final' => false,
        ]);

        (new PruneRawMetrics())->handle();

        $recentMetric->refresh();
        $this->assertNotNull($recentMetric->raw);
    }
}
