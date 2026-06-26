<?php

namespace App\Listeners;

use App\Events\OpportunityDetected;
use App\Jobs\CommitDecision;

class TriggerDecisionEvaluation
{
    public function handle(OpportunityDetected $event): void
    {
        $company = $event->opportunity->company;

        if ($company === null) {
            return;
        }

        CommitDecision::dispatch($company);
    }
}
