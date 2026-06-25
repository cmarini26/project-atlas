<?php

namespace App\Services\Opportunity\Detectors\Contracts;

use App\Domain\BusinessBrain\BusinessBrain;
use Illuminate\Support\Collection;

interface OpportunityDetector
{
    /**
     * Returns the vertical slugs this detector applies to.
     * Return ['*'] to apply to all verticals.
     *
     * @return string[]
     */
    public function appliesTo(): array;

    /**
     * Detect opportunities from the given Business Brain snapshot.
     * Returns a collection of unsaved Opportunity-like value objects
     * for the OpportunityEngine to score and persist.
     *
     * @return Collection<int, mixed>
     */
    public function detect(BusinessBrain $brain): Collection;
}
