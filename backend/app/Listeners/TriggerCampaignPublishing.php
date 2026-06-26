<?php

namespace App\Listeners;

use App\Events\RecommendationApproved;
use App\Jobs\PublishCampaign;
use App\Models\Campaign;

class TriggerCampaignPublishing
{
    public function handle(RecommendationApproved $event): void
    {
        $campaignId = $event->recommendation->campaign_id;

        if ($campaignId === null) {
            return;
        }

        $campaign = Campaign::withoutGlobalScopes()->find($campaignId);

        if ($campaign === null) {
            return;
        }

        PublishCampaign::dispatch($campaign)->onQueue('high');
    }
}
