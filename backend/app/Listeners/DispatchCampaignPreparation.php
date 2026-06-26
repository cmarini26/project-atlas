<?php

namespace App\Listeners;

use App\Events\DecisionCommitted;
use App\Jobs\PrepareCampaign;

class DispatchCampaignPreparation
{
    public function handle(DecisionCommitted $event): void
    {
        PrepareCampaign::dispatch($event->decision);
    }
}
