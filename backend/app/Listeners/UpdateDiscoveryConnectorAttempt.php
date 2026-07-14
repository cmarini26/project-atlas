<?php

namespace App\Listeners;

use App\Enums\DiscoveryAttemptStatus;
use App\Events\IntegrationSyncCompleted;
use App\Events\IntegrationSyncFailed;
use App\Events\IntegrationSyncStarted;
use App\Models\DiscoveryConnectorAttempt;
use App\Models\Integration;
use App\Models\Observation;

/**
 * Keeps a DiscoveryConnectorAttempt's own status in sync with the
 * Integration sync it tracks. This is bookkeeping only — the DiscoveryRun's
 * overall stage is never mutated here, it is always recomputed fresh from
 * persisted state on read (BusinessDiscoveryService::refreshStage()).
 */
class UpdateDiscoveryConnectorAttempt
{
    public function onStarted(IntegrationSyncStarted $event): void
    {
        $attempt = $this->latestAttemptFor($event->integration);

        if ($attempt === null) {
            return;
        }

        $attempt->increment('attempt_count');
        $attempt->update([
            'status' => DiscoveryAttemptStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function onCompleted(IntegrationSyncCompleted $event): void
    {
        $attempt = $this->latestAttemptFor($event->integration);

        if ($attempt === null) {
            return;
        }

        $observation = Observation::withoutGlobalScopes()
            ->where('integration_id', $event->integration->id)
            ->latest('created_at')
            ->first();

        $attempt->update([
            'status' => DiscoveryAttemptStatus::Succeeded,
            'observation_id' => $observation?->id,
            'completed_at' => now(),
        ]);
    }

    public function onFailed(IntegrationSyncFailed $event): void
    {
        $attempt = $this->latestAttemptFor($event->integration);

        if ($attempt === null) {
            return;
        }

        $attempt->update([
            'status' => DiscoveryAttemptStatus::Failed,
            'error_message' => $event->errorMessage,
            'completed_at' => now(),
        ]);
    }

    private function latestAttemptFor(Integration $integration): ?DiscoveryConnectorAttempt
    {
        return DiscoveryConnectorAttempt::withoutGlobalScopes()
            ->where('integration_id', $integration->id)
            ->latest('created_at')
            ->first();
    }
}
