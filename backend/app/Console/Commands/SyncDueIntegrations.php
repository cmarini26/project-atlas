<?php

namespace App\Console\Commands;

use App\Jobs\SyncIntegration;
use App\Models\Integration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The recurring half of the Observe → Learn loop. Onboarding runs the first
 * sync; this command keeps the Business Brain current by re-dispatching
 * SyncIntegration for every active integration whose next_run_at has passed.
 * SyncIntegration sets next_run_at = now()+24h on success, so each integration
 * settles into a daily cadence; integrations in 'error' are excluded until a
 * manual sync from Settings reactivates them.
 */
class SyncDueIntegrations extends Command
{
    protected $signature = 'atlas:sync-due-integrations';

    protected $description = 'Dispatch a sync for every active integration whose next_run_at is due';

    public function handle(): int
    {
        $due = Integration::withoutGlobalScopes()
            ->where('status', 'active')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->get();

        foreach ($due as $integration) {
            // ShouldBeUnique on SyncIntegration drops duplicates while one
            // is already queued or running for this integration.
            SyncIntegration::dispatch($integration);
        }

        if ($due->isNotEmpty()) {
            Log::info('SyncDueIntegrations: dispatched due integration syncs.', [
                'count' => $due->count(),
            ]);
        }

        $this->info("Dispatched {$due->count()} due integration sync(s).");

        return self::SUCCESS;
    }
}
