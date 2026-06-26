<?php

namespace App\Listeners;

use App\Events\DigitalTwinActivated;
use App\Jobs\DetectOpportunities;

class TriggerOpportunityDetection
{
    public function handle(DigitalTwinActivated $event): void
    {
        $company = $event->twin->company;

        if ($company === null) {
            return;
        }

        DetectOpportunities::dispatch($company);
    }
}
