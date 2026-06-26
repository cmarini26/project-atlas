<?php

namespace App\Services\Opportunity\Detectors\Contracts;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Company;
use App\Services\Opportunity\OpportunityCandidate;
use Illuminate\Support\Collection;

interface OpportunityDetector
{
    /**
     * The opportunity types this detector can produce.
     *
     * @return string[]
     */
    public function appliesTo(): array;

    /**
     * Inspect the BusinessBrain and return opportunity candidates.
     * Must not perform database writes.
     * Must not call AiProvider.
     * Must return empty Collection when no candidates found.
     *
     * @return Collection<int, OpportunityCandidate>
     */
    public function detect(Company $company, BusinessBrain $brain): Collection;
}
