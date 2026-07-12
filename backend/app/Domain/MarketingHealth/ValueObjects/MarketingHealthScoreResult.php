<?php

namespace App\Domain\MarketingHealth\ValueObjects;

/**
 * The output of a single MarketingHealthScorer — see
 * docs/specs/Marketing-Health.md §4.1. A scorer returns null (not this
 * object) when there isn't enough evidence to score its dimension at all.
 */
readonly class MarketingHealthScoreResult
{
    /** @param  list<MarketingHealthEvidence>  $evidence */
    public function __construct(
        public int $score,
        public int $confidence,
        public array $evidence,
    ) {}
}
