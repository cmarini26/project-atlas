<?php

namespace Tests\Feature\Analytics;

use App\Events\ExecutionCompleted;
use App\Jobs\RetrieveExecutionMetrics;
use Illuminate\Support\Facades\Queue;

class ScheduleMetricRetrievalTest extends AnalyticsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCredentials('email', 'postmark');
    }

    public function test_dispatches_retrieve_job_when_platform_id_present(): void
    {
        Queue::fake();

        $execution = $this->makeExecution('completed', ['platform_id' => 'msg-abc123']);

        event(new ExecutionCompleted($execution));

        Queue::assertPushed(RetrieveExecutionMetrics::class, function (RetrieveExecutionMetrics $job) use ($execution): bool {
            return $job->executionId === $execution->id;
        });
    }

    public function test_skips_dispatch_when_platform_id_is_null(): void
    {
        Queue::fake();

        $execution = $this->makeExecution('completed', ['platform_id' => null]);

        event(new ExecutionCompleted($execution));

        Queue::assertNotPushed(RetrieveExecutionMetrics::class);
    }

    public function test_skips_dispatch_when_result_is_empty(): void
    {
        Queue::fake();

        $execution = $this->makeExecution('completed', []);

        event(new ExecutionCompleted($execution));

        Queue::assertNotPushed(RetrieveExecutionMetrics::class);
    }
}
