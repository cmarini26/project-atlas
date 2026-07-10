<?php

namespace App\Jobs;

use App\Models\Execution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishScheduledContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        // Only handle scheduled executions (not null scheduled_at).
        // Immediate executions (null scheduled_at) are dispatched by PublishCampaign.
        $count = 0;

        Execution::withoutGlobalScopes()
            ->where('status', 'queued')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->each(function (Execution $execution) use (&$count): void {
                PublishContent::dispatch($execution)->onQueue('high');
                $count++;
            });

        if ($count > 0) {
            Log::info('PublishScheduledContent: dispatched scheduled executions.', [
                'count' => $count,
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('PublishScheduledContent: job failed after exhausting retries.', [
            'error' => $exception->getMessage(),
        ]);
    }
}
