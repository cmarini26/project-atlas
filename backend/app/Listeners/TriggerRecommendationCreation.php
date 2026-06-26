<?php

namespace App\Listeners;

use App\Events\CampaignAssetsReady;
use App\Jobs\CreateRecommendation;

class TriggerRecommendationCreation
{
    public function handle(CampaignAssetsReady $event): void
    {
        CreateRecommendation::dispatch($event->campaign);
    }
}
