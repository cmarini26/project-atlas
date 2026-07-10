<?php

namespace App\Services\Queue;

use App\Models\FailedJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * The operator-facing recovery workflow for Critical Production Blocker 5 —
 * see docs/plans/Critical-Production-Blockers.md. Wraps the same mechanism
 * Laravel's own `queue:retry`/`queue:forget` commands use, so it behaves
 * identically to the framework's documented recovery path, just reachable
 * from the Filament admin panel instead of the CLI.
 */
class FailedJobRecoveryService
{
    /**
     * Re-pushes the job onto its original connection/queue with a reset
     * attempt count, then removes the failed_jobs record — mirroring
     * `artisan queue:retry`. If the retry fails again, a new failed_jobs row
     * is created with a new UUID; this one is simply gone once re-dispatched.
     */
    public function retry(FailedJob $failedJob): void
    {
        Queue::connection($failedJob->connection)->pushRaw(
            $this->resetAttempts((string) $failedJob->payload),
            $failedJob->queue,
        );

        Log::info('FailedJobRecoveryService: retried a failed job.', [
            'uuid' => $failedJob->uuid,
            'queue' => $failedJob->queue,
            'job_class' => $failedJob->jobClass(),
        ]);

        $failedJob->delete();
    }

    /**
     * Discards a failed job permanently — mirroring `artisan queue:forget`.
     */
    public function forget(FailedJob $failedJob): void
    {
        Log::info('FailedJobRecoveryService: discarded a failed job.', [
            'uuid' => $failedJob->uuid,
            'queue' => $failedJob->queue,
            'job_class' => $failedJob->jobClass(),
        ]);

        $failedJob->delete();
    }

    /**
     * Mirrors Laravel's own `queue:retry` command — see
     * Illuminate\Queue\Console\RetryCommand::resetAttempts().
     */
    private function resetAttempts(string $payload): string
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true) ?? [];

        if (isset($decoded['attempts'])) {
            $decoded['attempts'] = 0;
        }

        return json_encode($decoded) ?: $payload;
    }
}
