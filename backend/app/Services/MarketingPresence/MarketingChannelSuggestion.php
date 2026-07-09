<?php

namespace App\Services\MarketingPresence;

use App\Enums\MarketingChannelType;

/**
 * A candidate MarketingChannel the company could declare, surfaced by
 * MarketingPresenceService::suggestChannels(). Never persisted automatically
 * — see specs/core/marketing-presence.md's Phase 2 plan: "Do not
 * automatically create suggestions yet."
 */
readonly class MarketingChannelSuggestion
{
    public function __construct(
        public MarketingChannelType $type,
        public string $displayName,
        public ?string $handleOrUrl,
        public string $reason,
        public ?string $channelId,
    ) {}
}
