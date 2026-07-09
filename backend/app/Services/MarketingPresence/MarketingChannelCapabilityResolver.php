<?php

namespace App\Services\MarketingPresence;

use App\Enums\MarketingChannelCapability;
use App\Models\MarketingChannel;

/**
 * The single place that turns a MarketingChannel's raw flags
 * (is_connected, supports_publishing, supports_analytics) plus its linked
 * Channel (if any) into a domain-level capability result. Nothing else in
 * the application should inspect those booleans directly to decide what a
 * channel can do — call resolve() instead. See
 * specs/core/marketing-presence.md §5.
 */
class MarketingChannelCapabilityResolver
{
    public function resolve(MarketingChannel $channel): MarketingChannelCapability
    {
        $linkedChannel = $channel->channel_id !== null ? $channel->channel : null;

        // "Derived from the MarketingChannel and any linked Channel": a
        // technical Channel that has been deactivated (Channel::is_active
        // false) can never justify PublishingEnabled or AnalyticsEnabled,
        // even if the MarketingChannel's own flags haven't caught up yet —
        // the linked Channel is the thing that would actually have to do
        // the work. A missing or inactive link never outranks Connected.
        $hasLiveLink = $linkedChannel !== null && $linkedChannel->is_active;

        return match (true) {
            $hasLiveLink && $channel->supports_analytics => MarketingChannelCapability::AnalyticsEnabled,
            $hasLiveLink && $channel->supports_publishing => MarketingChannelCapability::PublishingEnabled,
            $channel->is_connected => MarketingChannelCapability::Connected,
            default => MarketingChannelCapability::Declared,
        };
    }
}
