<?php

namespace App\Services\Content;

use App\Domain\Content\ValueObjects\ContentAssetData;
use App\Events\CampaignAssetsReady;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ContentAsset;

class ContentGenerationService
{
    public function createAsset(Campaign $campaign, Channel $channel, ContentAssetData $data): ContentAsset
    {
        $asset = ContentAsset::create([
            'company_id' => $campaign->company_id,
            'campaign_id' => $campaign->id,
            'channel_id' => $channel->id,
            'type' => $data->type,
            'title' => $data->title,
            'body' => $data->body,
            'media' => $data->media,
            'metadata' => $data->metadata,
            'prompt_name' => $data->promptName,
            'prompt_version' => $data->promptVersion,
            'status' => 'draft',
        ]);

        $campaign->increment('generated_asset_count');
        $campaign->refresh();

        if ($campaign->allAssetsGenerated()) {
            CampaignAssetsReady::dispatch($campaign);
        }

        return $asset;
    }
}
