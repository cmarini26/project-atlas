<?php

namespace App\Services\Campaign;

use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\Decision;
use Illuminate\Support\Collection;

class CampaignChannelSelectionService
{
    /**
     * Persist which generated assets/channels remain active for a campaign.
     * Unselected draft/approved assets are archived; re-selected archived
     * assets are restored to draft so they can be approved/queued again.
     *
     * @param  list<string>  $selectedContentAssetIds
     */
    public function sync(Campaign $campaign, array $selectedContentAssetIds): void
    {
        /** @var Collection<int, ContentAsset> $assets */
        $assets = ContentAsset::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->orderBy('created_at')
            ->get();

        if ($assets->isEmpty()) {
            return;
        }

        $selectedLookup = collect($selectedContentAssetIds)
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();

        foreach ($assets as $asset) {
            $isSelected = $selectedLookup->contains($asset->id);

            if ($isSelected && $asset->status === 'archived') {
                $asset->update(['status' => 'draft']);
                continue;
            }

            if (! $isSelected && in_array($asset->status, ['draft', 'approved'], true)) {
                $asset->update(['status' => 'archived']);
            }
        }

        if ($campaign->decision_id === null) {
            return;
        }

        $decision = Decision::withoutGlobalScopes()->find($campaign->decision_id);

        if ($decision === null) {
            return;
        }

        $selectedChannelIds = $assets
            ->filter(fn (ContentAsset $asset): bool => $selectedLookup->contains($asset->id))
            ->pluck('channel_id')
            ->filter(fn (mixed $channelId): bool => is_string($channelId) && $channelId !== '')
            ->unique()
            ->values();

        $orderedChannelIds = collect($decision->channel_ids ?? [])
            ->map(fn (mixed $channelId): string => (string) $channelId)
            ->filter(fn (string $channelId): bool => $selectedChannelIds->contains($channelId))
            ->values();

        foreach ($selectedChannelIds as $channelId) {
            if (! $orderedChannelIds->contains($channelId)) {
                $orderedChannelIds->push($channelId);
            }
        }

        $decision->update(['channel_ids' => $orderedChannelIds->all()]);
    }
}