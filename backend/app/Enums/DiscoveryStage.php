<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * The four coarse stages a DiscoveryRun's progress is described in — see
 * docs/specs/Business-Discovery-Onboarding.md §4.4. Deliberately not a
 * percentage: each value is a precise, observable condition over persisted
 * state, always recomputed fresh, never incrementally mutated.
 */
enum DiscoveryStage: string
{
    use EnumValues;

    case Discovering = 'discovering';
    case Analyzing = 'analyzing';
    case Understanding = 'understanding';
    case Recommending = 'recommending';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';

    /**
     * Every attempted connector finished, Atlas understood the business,
     * but no Opportunity/Recommendation ever resulted — a legitimate,
     * final outcome (Milestone 15 Phase 3), not an indefinite "Recommend"
     * spinner. Distinct from CompletedWithErrors: here, discovery itself
     * worked; there was just nothing to act on yet.
     */
    case CompletedNoOpportunities = 'completed_no_opportunities';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::CompletedWithErrors, self::CompletedNoOpportunities => true,
            default => false,
        };
    }
}
