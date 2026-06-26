<?php

namespace App\Jobs;

use App\Events\IntegrationSyncCompleted;
use App\Events\IntegrationSyncStarted;
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
        $this->integration->markAsError($exception->getMessage());
    }
}
