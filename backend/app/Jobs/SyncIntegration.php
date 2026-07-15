<?php

namespace App\Jobs;

use App\AI\Exceptions\AiProviderOverloadedException;
use App\Events\IntegrationSyncCompleted;
use App\Events\IntegrationSyncFailed;
use App\Events\IntegrationSyncStarted;
use App\Jobs\Exceptions\IntegrationSyncProducedNoResultsException;
use App\Models\Integration;
use App\Services\Observatory\Connectors\ConnectorRegistry;
use App\Services\Observatory\ObservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncIntegration implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly Integration $integration)
    {
        $this->onQueue('observations');
    }

    public function uniqueId(): string
    {
        return $this->integration->id;
    }

    public function handle(ConnectorRegistry $registry, ObservationService $observations): void
    {
        IntegrationSyncStarted::dispatch($this->integration);

        $integration = $this->integration;
        $integration->update(['last_run_at' => now()]);

        $connector = $registry->resolve($integration);
        $results = $connector->sync($integration);

        if ($results->isEmpty()) {
            throw IntegrationSyncProducedNoResultsException::create();
        }

        $recorded = $observations->recordAll($integration, $results);

        $integration->update([
            'last_successful_run_at' => now(),
            'next_run_at' => now()->addHours(24),
            'status' => 'active',
        ]);

        IntegrationSyncCompleted::dispatch($integration, $recorded->count());
    }

    public function failed(Throwable $exception): void
    {
        // A temporarily overloaded AI provider is not an integration failure —
        // the crawl succeeded and the observation is queued for retry. Marking
        // the integration 'error' here would show the "couldn't reach your
        // website" card for a transient provider issue.
        if ($exception instanceof AiProviderOverloadedException) {
            return;
        }

        $this->integration->markAsError($exception->getMessage());

        IntegrationSyncFailed::dispatch($this->integration, $exception->getMessage());
    }
}
