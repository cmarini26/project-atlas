<?php

namespace App\Domain\BusinessBrain;

/**
 * A synthesized, natural-language-ready description of a company's declared
 * marketing channels — never raw MarketingChannel rows. Built by
 * App\Services\Brain\MarketingPresenceSynthesizer and attached to the
 * BusinessBrain so prompts can reference a company's marketing strategy
 * without reading MarketingChannel directly. See
 * specs/core/marketing-presence.md §8 and
 * docs/plans/Milestone-11-Marketing-Presence.md Phase 5.
 */
readonly class MarketingPresenceSummary
{
    /**
     * @param  list<string>  $primaryChannels  display names of primary-importance, non-inactive channels
     * @param  list<string>  $secondaryChannels  display names of secondary/experimental-importance, non-inactive channels
     * @param  list<string>  $inactiveChannels  display names of channels with status: inactive, regardless of importance
     * @param  list<string>  $primaryObjectives  distinct objective values declared across the company's primary channels (or, absent any, its active channels)
     */
    public function __construct(
        public array $primaryChannels,
        public array $secondaryChannels,
        public array $inactiveChannels,
        public array $primaryObjectives,
        public string $summary,
    ) {}
}
