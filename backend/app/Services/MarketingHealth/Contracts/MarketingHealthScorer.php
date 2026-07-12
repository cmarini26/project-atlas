<?php

namespace App\Services\MarketingHealth\Contracts;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\MarketingHealth\ValueObjects\MarketingHealthScoreResult;
use App\Models\Company;

/**
 * One implementation per Marketing Health dimension — see
 * docs/specs/Marketing-Health.md §3/§4.1. Every implementation must be
 * fully deterministic (no AI calls) and read only evidence already stored
 * by Atlas (Facts, Knowledge, Campaigns, ContentAssets, MarketingChannels).
 */
interface MarketingHealthScorer
{
    /** One of the seven dimension keys in docs/specs/Marketing-Health.md §3. */
    public function dimension(): string;

    /**
     * Null when there is not enough evidence to score this dimension at
     * all — a real, valid outcome, not an error.
     */
    public function score(Company $company, BusinessBrain $brain): ?MarketingHealthScoreResult;
}
