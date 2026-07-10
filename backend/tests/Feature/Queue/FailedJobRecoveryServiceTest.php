<?php

namespace Tests\Feature\Queue;

use App\Jobs\PruneRawMetrics;
use App\Models\FailedJob;
use App\Services\Queue\FailedJobRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers Critical Production Blocker 5 (failed-job visibility and recovery)
 * — see docs/plans/Critical-Production-Blockers.md and
 * docs/reviews/Production-Deployment-Audit.md.
 */
class FailedJobRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeFailedJob(array $payloadOverrides = []): FailedJob
    {
        $payload = array_merge([
            'uuid' => (string) Str::uuid(),
            'displayName' => PruneRawMetrics::class,
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries' => 3,
            'attempts' => 3,
            'data' => ['commandName' => PruneRawMetrics::class, 'command' => 'serialized-job-data'],
        ], $payloadOverrides);

        return FailedJob::query()->create([
            'uuid' => $payload['uuid'],
            'connection' => 'database',
            'queue' => 'maintenance',
            'payload' => json_encode($payload),
            'exception' => "Exception: Something went wrong in /app/Jobs/PruneRawMetrics.php:29\nStack trace:\n#0 {main}",
        ]);
    }

    // ── Recovery workflow: retry ─────────────────────────────────────────────

    public function test_retry_pushes_the_job_back_onto_its_original_queue(): void
    {
        Queue::fake();

        $failedJob = $this->makeFailedJob();

        (new FailedJobRecoveryService())->retry($failedJob);

        $rawPushes = Queue::rawPushes();
        $this->assertCount(1, $rawPushes);
        $this->assertSame('maintenance', $rawPushes[0]['queue']);

        $decoded = json_decode($rawPushes[0]['payload'], true);
        $this->assertSame(0, $decoded['attempts']);
        $this->assertSame(PruneRawMetrics::class, $decoded['displayName']);
    }

    public function test_retry_removes_the_failed_job_record(): void
    {
        Queue::fake();

        $failedJob = $this->makeFailedJob();

        (new FailedJobRecoveryService())->retry($failedJob);

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $failedJob->uuid]);
    }

    public function test_retry_logs_a_structured_message(): void
    {
        Queue::fake();
        Log::spy();

        $failedJob = $this->makeFailedJob();

        (new FailedJobRecoveryService())->retry($failedJob);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'FailedJobRecoveryService: retried a failed job.'
                && $context['uuid'] === $failedJob->uuid
                && $context['queue'] === 'maintenance'
                && $context['job_class'] === PruneRawMetrics::class
            );
    }

    // ── Recovery workflow: forget/discard ────────────────────────────────────

    public function test_forget_removes_the_failed_job_record(): void
    {
        $failedJob = $this->makeFailedJob();

        (new FailedJobRecoveryService())->forget($failedJob);

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $failedJob->uuid]);
    }

    public function test_forget_does_not_push_anything_back_onto_the_queue(): void
    {
        Queue::fake();

        $failedJob = $this->makeFailedJob();

        (new FailedJobRecoveryService())->forget($failedJob);

        $this->assertCount(0, Queue::rawPushes());
    }

    public function test_forget_logs_a_structured_message(): void
    {
        Log::spy();

        $failedJob = $this->makeFailedJob();

        (new FailedJobRecoveryService())->forget($failedJob);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'FailedJobRecoveryService: discarded a failed job.'
                && $context['uuid'] === $failedJob->uuid
            );
    }

    // ── Diagnostics parsing ──────────────────────────────────────────────────

    public function test_job_class_is_parsed_from_the_payload(): void
    {
        $failedJob = $this->makeFailedJob();

        $this->assertSame(PruneRawMetrics::class, $failedJob->jobClass());
    }

    public function test_exception_summary_is_the_first_line_of_the_stack_trace(): void
    {
        $failedJob = $this->makeFailedJob();

        $this->assertSame(
            'Exception: Something went wrong in /app/Jobs/PruneRawMetrics.php:29',
            $failedJob->exceptionSummary(),
        );
    }
}
