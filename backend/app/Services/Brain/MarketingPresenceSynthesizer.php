<?php

namespace App\Services\Brain;

use App\Domain\BusinessBrain\MarketingPresenceSummary;
use App\Enums\MarketingChannelImportance;
use App\Enums\MarketingChannelStatus;
use App\Models\MarketingChannel;
use Illuminate\Support\Collection;

/**
 * Turns a company's raw MarketingChannel rows into a MarketingPresenceSummary
 * — the only representation of a company's marketing presence that reaches
 * the BusinessBrain and, through it, prompts. Nothing downstream of this
 * class should read MarketingChannel rows to describe a company's marketing
 * strategy; see specs/core/marketing-presence.md §8.
 *
 * Deterministic bucketing and string composition, not an AI call: turning a
 * handful of enum-valued rows into a few sentences isn't a task that benefits
 * from a probabilistic model (Founding Principle 1), and the existing
 * BusinessBrain assembly step is a pure aggregation layer, not an
 * AI-invoking one — this keeps it that way.
 */
class MarketingPresenceSynthesizer
{
    private const string EMPTY_SUMMARY = 'No marketing channels have been declared yet.';

    public function synthesize(string $companyId): MarketingPresenceSummary
    {
        $channels = MarketingChannel::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get();

        if ($channels->isEmpty()) {
            return new MarketingPresenceSummary(
                primaryChannels: [],
                secondaryChannels: [],
                inactiveChannels: [],
                primaryObjectives: [],
                summary: self::EMPTY_SUMMARY,
            );
        }

        $inactive = $channels->filter(
            fn (MarketingChannel $channel): bool => $channel->status === MarketingChannelStatus::Inactive,
        );

        // Inactive takes precedence over importance for bucketing — a channel
        // the business has stopped using is "inactive," not "primary" or
        // "secondary," regardless of how important it once was.
        $active = $channels->reject(
            fn (MarketingChannel $channel): bool => $channel->status === MarketingChannelStatus::Inactive,
        );

        $primary = $active->filter(
            fn (MarketingChannel $channel): bool => $channel->importance === MarketingChannelImportance::Primary,
        );

        $secondary = $active->reject(
            fn (MarketingChannel $channel): bool => $channel->importance === MarketingChannelImportance::Primary,
        );

        // Objectives come from primary channels when there are any — those
        // are the channels the business itself flagged as most important.
        // Absent a declared primary channel, fall back to every active
        // channel so the summary isn't empty just because nobody set
        // importance carefully.
        $objectiveSource = $primary->isNotEmpty() ? $primary : $active;

        $primaryObjectives = array_values(array_unique(
            $objectiveSource->flatMap(fn (MarketingChannel $channel): array => $channel->objective)->all(),
        ));

        $primaryNames = $this->displayNames($primary);
        $secondaryNames = $this->displayNames($secondary);
        $inactiveNames = $this->displayNames($inactive);

        return new MarketingPresenceSummary(
            primaryChannels: $primaryNames,
            secondaryChannels: $secondaryNames,
            inactiveChannels: $inactiveNames,
            primaryObjectives: $primaryObjectives,
            summary: $this->composeSummary($primaryNames, $secondaryNames, $inactiveNames, $primaryObjectives),
        );
    }

    /**
     * @param  Collection<int, MarketingChannel>  $channels
     * @return list<string>
     */
    private function displayNames(Collection $channels): array
    {
        return array_values($channels->map(fn (MarketingChannel $channel): string => $channel->display_name)->all());
    }

    /**
     * @param  list<string>  $primary
     * @param  list<string>  $secondary
     * @param  list<string>  $inactive
     * @param  list<string>  $objectives
     */
    private function composeSummary(array $primary, array $secondary, array $inactive, array $objectives): string
    {
        $sentences = [];

        if ($primary !== []) {
            $sentences[] = 'Primary marketing channels: '.implode(', ', $primary).'.';
        }

        if ($secondary !== []) {
            $sentences[] = 'Secondary marketing channels: '.implode(', ', $secondary).'.';
        }

        if ($inactive !== []) {
            $sentences[] = 'No longer active on: '.implode(', ', $inactive).'.';
        }

        if ($objectives !== []) {
            $sentences[] = 'Primary marketing objectives: '.implode(', ', $objectives).'.';
        }

        return $sentences === [] ? self::EMPTY_SUMMARY : implode(' ', $sentences);
    }
}
