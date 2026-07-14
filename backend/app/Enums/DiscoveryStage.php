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

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::CompletedWithErrors;
    }
}
