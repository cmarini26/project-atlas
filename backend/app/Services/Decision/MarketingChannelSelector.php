<?php

namespace App\Services\Decision;

use App\Enums\MarketingChannelImportance;
use App\Enums\MarketingChannelStatus;
use App\Models\Channel;
use App\Models\Company;
use App\Models\MarketingChannel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Narrows a set of type-affinity-matched Channel candidates using the
 * company's declared Marketing Presence, and reports the declared channels
 * that can never be executable. Never creates a Channel row, and never
 * treats a declared-but-unlinked MarketingChannel as executable — that
 * distinction is the entire point of this class. See
 * specs/core/marketing-presence.md §9.
 *
 * MarketingChannel rows are queried directly here rather than read off
 * BusinessBrain: the brain's `marketingPresence` is a synthesized,
 * display-name-only summary (Phase 5) — deliberately not a channel_id-keyed
 * map of raw rows — so a fresh, company-scoped query is the correct way to
 * get the structured data this deterministic selection needs.
 */
class MarketingChannelSelector
{
    /**
     * Statuses whose linked Channel must never be executable. `inactive`
     * (the business stopped using it) and `planned` (the business hasn't
     * started yet) both exclude a linked Channel from the executable set,
     * for different reasons — both are reported in the log/result so the
     * two cases stay distinguishable even though neither is executable.
     *
     * @var list<MarketingChannelStatus>
     */
    private const array NON_EXECUTABLE_STATUSES = [
        MarketingChannelStatus::Inactive,
        MarketingChannelStatus::Planned,
    ];

    /**
     * @param  Collection<int, Channel>  $affinityMatched  type-affinity candidates, pre-Marketing-Presence
     * @param  Collection<int, Channel>  $activeChannels  the full active-channel fallback set (unfiltered by affinity)
     */
    public function select(
        Company $company,
        Collection $affinityMatched,
        Collection $activeChannels,
        string $campaignType,
    ): MarketingChannelSelection {
        $marketingChannels = MarketingChannel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->get();

        /** @var Collection<string, MarketingChannel> $linkedByChannelId */
        $linkedByChannelId = $marketingChannels
            ->whereNotNull('channel_id')
            ->keyBy('channel_id');

        /** @var list<array{name: string, reason: string}> $excluded */
        $excluded = [];

        /** @var Collection<int, Channel> $eligible */
        $eligible = collect();

        foreach ($affinityMatched as $channel) {
            $linked = $linkedByChannelId->get($channel->id);

            if ($linked !== null && in_array($linked->status, self::NON_EXECUTABLE_STATUSES, true)) {
                $excluded[] = [
                    'name' => $linked->display_name,
                    'reason' => $linked->status === MarketingChannelStatus::Inactive
                        ? 'linked marketing channel is inactive'
                        : 'linked marketing channel is planned, not yet active',
                ];

                continue;
            }

            $eligible->push($channel);
        }

        $bypassedExclusion = false;
        $narrowedToPrimary = false;

        if ($eligible->isEmpty()) {
            // Excluding every non-executable-linked candidate would leave
            // nothing to recommend — fall through to the pre-Marketing-Presence
            // behavior (all active channels) rather than introduce a new
            // failure mode a company with only inactive/planned-linked
            // channels didn't have before this selector existed.
            $eligible = $activeChannels;
            $excluded = [];
            $bypassedExclusion = true;
        } else {
            $primaryEligible = $eligible->filter(
                fn (Channel $channel): bool => $linkedByChannelId->get($channel->id)?->importance === MarketingChannelImportance::Primary,
            );

            if ($primaryEligible->isNotEmpty()) {
                $eligible = $primaryEligible;
                $narrowedToPrimary = true;
            }
        }

        $executableChannelIds = array_values($eligible->map(fn (Channel $channel): string => $channel->id)->all());

        $draftOnlyChannels = array_values(
            $marketingChannels
                ->whereNull('channel_id')
                ->reject(fn (MarketingChannel $marketingChannel): bool => $marketingChannel->status === MarketingChannelStatus::Inactive)
                ->map(fn (MarketingChannel $marketingChannel): string => $marketingChannel->display_name)
                ->all(),
        );

        Log::info('DecisionEngine: marketing-presence channel selection.', [
            'company_id' => $company->id,
            'campaign_type' => $campaignType,
            'considered_channel_ids' => $affinityMatched->pluck('id')->values()->all(),
            'narrowed_to_primary' => $narrowedToPrimary,
            'excluded' => $excluded,
            'bypassed_exclusion_to_avoid_empty_selection' => $bypassedExclusion,
            'executable_channel_ids' => $executableChannelIds,
            'draft_only_channels' => $draftOnlyChannels,
        ]);

        return new MarketingChannelSelection(
            executableChannelIds: $executableChannelIds,
            draftOnlyChannels: $draftOnlyChannels,
            excludedChannels: $excluded,
        );
    }
}
