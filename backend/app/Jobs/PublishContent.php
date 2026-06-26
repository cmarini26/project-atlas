<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Execution;
use App\Services\Publishing\ChannelPublisherRegistry;
use App\Services\Publishing\Exceptions\PublishingException;
use App\Services\Publishing\ExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4; // 1 attempt + 3 retries

    public function __construct(public readonly Execution $execution) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        ChannelPublisherRegistry $registry,
        ExecutionService $executionService,
    ): void {
        // Re-load from DB for current status (idempotency check)
        $execution = Execution::withoutGlobalScopes()->findOrFail($this->execution->id);

        if ($execution->status === 'completed') {
            Log::info('PublishContent: execution already completed, skipping.', [
                'execution_id' => $execution->id,
            ]);

            return;
        }

        if ($execution->status === 'cancelled') {
            Log::info('PublishContent: execution cancelled, skipping.', [
                'execution_id' => $execution->id,
            ]);

            return;
        }

        $execution->update(['status' => 'executing', 'executed_at' => now()]);

        $channel = Channel::withoutGlobalScopes()->findOrFail($execution->channel_id);
        $publisher = $registry->for($channel->type);

        try {
            $result = $publisher->publish($execution);

            $executionService->logAttempt($execution, 'completed');
            $executionService->markCompleted($execution, $result);

            Log::info('PublishContent: execution completed.', [
                'execution_id' => $execution->id,
                'platform_id' => $result->platformId,
            ]);
        } catch (PublishingException $e) {
            $executionService->logAttempt($execution, 'failed', $e->getMessage());

            Log::error('PublishContent: publishing failed.', [
                'execution_id' => $execution->id,
                'retryable' => $e->isRetryable(),
                'error' => $e->getMessage(),
            ]);

            if (! $e->isRetryable()) {
                $executionService->markFailed($execution, $e->getMessage());
                $this->fail($e);

                return;
            }

            // Retryable: reset status to queued and re-throw so Laravel retries
            $execution->update([
                'status' => 'queued',
                'last_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $execution = Execution::withoutGlobalScopes()->find($this->execution->id);

        if ($execution !== null && $execution->status !== 'failed') {
            app(ExecutionService::class)->markFailed($execution, $e->getMessage());
        }
    }

    public function queue(): string
    {
        return 'high';
    }
}
