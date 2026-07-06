<?php

namespace App\Listeners;

use App\Events\ObservationProcessed;
use App\Jobs\DetectOpportunities;
use App\Models\Company;
use App\Models\Fact;
use Illuminate\Support\Facades\Log;

/**
 * Dispatch an opportunity scan after every successfully processed observation.
 *
 * This used to listen to DigitalTwinActivated, which fires exactly once per
 * company (initializing → active). Any company whose twin was already active —
 * a retried onboarding, a recurring sync, or an earlier run whose downstream
 * chain failed — extracted facts but never scanned for opportunities again,
 * dead-ending the pipeline after fact extraction. Scanning per processed
 * observation is safe: DetectOpportunities is unique per company while queued,
 * and the OpportunityEngine deduplicates against existing opportunities.
 */
class TriggerOpportunityDetection
{
    public function handle(ObservationProcessed $event): void
    {
        $observation = $event->observation;

        $company = Company::withoutGlobalScopes()->find($observation->company_id);

        if ($company === null) {
            return;
        }

        $hasFacts = Fact::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_current', true)
            ->exists();

        if (! $hasFacts) {
            Log::info('TriggerOpportunityDetection: no current facts yet, skipping scan.', [
                'company_id' => $company->id,
                'observation_id' => $observation->id,
            ]);

            return;
        }

        Log::info('TriggerOpportunityDetection: dispatching opportunity scan.', [
            'company_id' => $company->id,
            'observation_id' => $observation->id,
        ]);

        DetectOpportunities::dispatch($company);
    }
}
