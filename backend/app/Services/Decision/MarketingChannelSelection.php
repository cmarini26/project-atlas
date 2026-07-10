<?php

namespace App\Services\Decision;

/**
 * The result of narrowing a set of type-affinity-matched Channel candidates
 * using a company's declared Marketing Presence (MarketingChannelSelector).
 * Lets a Decision, and whatever plans a campaign from it, distinguish three
 * outcomes without any change to the Decision or CampaignBlueprint schema:
 *
 * - executable: real Channel ids safe to persist as Decision.channel_ids
 * - draft-only: declared channels with no linked Channel row — real business
 *   context, never executable, but still worth targeting with prepared content
 * - excluded: channels that were candidates but were left out, and why
 *
 * See specs/core/marketing-presence.md §9.
 */
readonly class MarketingChannelSelection
{
    /**
     * @param  list<string>  $executableChannelIds  Channel ids eligible for Decision.channel_ids / execution
     * @param  list<string>  $draftOnlyChannels  display names of declared, non-inactive MarketingChannels with no linked Channel
     * @param  list<array{name: string, reason: string}>  $excludedChannels  candidates left out of the executable set, with why
     */
    public function __construct(
        public array $executableChannelIds,
        public array $draftOnlyChannels,
        public array $excludedChannels,
    ) {}
}
