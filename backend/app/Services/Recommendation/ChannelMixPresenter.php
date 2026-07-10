<?php

namespace App\Services\Recommendation;

use App\Enums\MarketingChannelImportance;
use App\Enums\MarketingChannelStatus;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\MarketingChannel;

/**
 * Assembles the "channel mix" shown on a Recommendation's detail page: which
 * of the company's channels this campaign actually executes on, versus which
 * declared channels are draft-only or currently unavailable. Read-only and
 * computed fresh at display time — deliberately not a replay of whatever
 * MarketingChannelSelector decided when the Decision was committed (Phase 6),
 * since Settings changes made after that moment (a channel going inactive, a
 * new one being linked) should be reflected in what a user sees today. See
 * specs/core/marketing-presence.md §9 and §11.
 *
 * Never invents an executable channel: the primary/supporting buckets are
 * built exclusively from `Decision.channel_ids`, real Channel rows that were
 * already selected. A MarketingChannel is never treated as executable here.
 */
class ChannelMixPresenter
{
    /** @return array{primary: list<array<string, mixed>>, supporting: list<array<string, mixed>>, draft_only: list<array<string, mixed>>, unavailable: list<array<string, mixed>>} */
    public function present(Company $company, ?Decision $decision): array
    {
        $channelIds = $decision !== null ? $decision->channel_ids : [];

        $executableChannels = $channelIds === []
            ? collect()
            : Channel::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereIn('id', $channelIds)
                ->get();

        $marketingChannels = MarketingChannel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->get();

        $linkedByChannelId = $marketingChannels->whereNotNull('channel_id')->keyBy('channel_id');

        $primary = [];
        $supporting = [];

        foreach ($executableChannels as $channel) {
            $linked = $linkedByChannelId->get($channel->id);
            $entry = $this->executableEntry($channel, $linked);

            if ($linked !== null && $linked->importance === MarketingChannelImportance::Primary) {
                $primary[] = $entry;
            } else {
                $supporting[] = $entry;
            }
        }

        $draftOnly = array_values(
            $marketingChannels
                ->whereNull('channel_id')
                ->reject(fn (MarketingChannel $mc): bool => in_array($mc->status, [MarketingChannelStatus::Inactive, MarketingChannelStatus::Planned], true))
                ->map(fn (MarketingChannel $mc): array => [
                    'type' => $mc->type->value,
                    'name' => $mc->display_name,
                ])
                ->all(),
        );

        $unavailable = array_values(
            $marketingChannels
                ->filter(fn (MarketingChannel $mc): bool => in_array($mc->status, [MarketingChannelStatus::Inactive, MarketingChannelStatus::Planned], true))
                // Don't call a channel "unavailable" if it's actually executing in
                // this campaign right now (possible if MarketingChannelSelector's
                // empty-set bypass fired when the Decision was committed).
                ->reject(fn (MarketingChannel $mc): bool => $mc->channel_id !== null && in_array($mc->channel_id, $channelIds, true))
                ->map(fn (MarketingChannel $mc): array => [
                    'type' => $mc->type->value,
                    'name' => $mc->display_name,
                    'reason' => $mc->status === MarketingChannelStatus::Inactive ? 'inactive' : 'planned',
                ])
                ->all(),
        );

        return [
            'primary' => $primary,
            'supporting' => $supporting,
            'draft_only' => $draftOnly,
            'unavailable' => $unavailable,
        ];
    }

    /** @return array<string, mixed> */
    private function executableEntry(Channel $channel, ?MarketingChannel $linked): array
    {
        return [
            'type' => $channel->type,
            'name' => $linked->display_name ?? $channel->name ?? $channel->type,
            'marketing_channel' => $linked !== null
                ? ['supports_publishing' => (bool) $linked->supports_publishing]
                : null,
        ];
    }
}
