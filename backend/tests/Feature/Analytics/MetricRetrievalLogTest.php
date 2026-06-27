<?php

namespace Tests\Feature\Analytics;

use App\Models\MetricRetrievalLog;

class MetricRetrievalLogTest extends AnalyticsTestCase
{
    public function test_can_create_retrieval_log(): void
    {
        $execution = $this->makeExecution();

        $log = MetricRetrievalLog::create([
            'execution_id' => $execution->id,
            'provider_type' => 'postmark',
            'attempted_at' => now(),
            'status' => 'success',
        ]);

        $this->assertDatabaseHas('metric_retrieval_logs', ['id' => $log->id, 'status' => 'success']);
    }

    public function test_log_has_no_updated_at(): void
    {
        $execution = $this->makeExecution();

        $log = MetricRetrievalLog::create([
            'execution_id' => $execution->id,
            'provider_type' => 'postmark',
            'attempted_at' => now(),
            'status' => 'success',
        ]);

        $this->assertNull($log->updated_at);
        $this->assertNotNull($log->created_at);
    }

    public function test_multiple_logs_can_be_appended_for_same_execution(): void
    {
        $execution = $this->makeExecution();

        MetricRetrievalLog::create([
            'execution_id' => $execution->id, 'provider_type' => 'postmark',
            'attempted_at' => now(), 'status' => 'failed', 'error' => 'Timeout',
        ]);
        MetricRetrievalLog::create([
            'execution_id' => $execution->id, 'provider_type' => 'postmark',
            'attempted_at' => now()->addMinutes(5), 'status' => 'success',
        ]);

        $this->assertDatabaseCount('metric_retrieval_logs', 2);
    }
}
