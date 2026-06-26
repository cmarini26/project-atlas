<?php

namespace App\Services\Decision;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Opportunity;

readonly class DecisionContext
{
    /**
     * @param  string[]  $channelIds
     */
    public function __construct(
        public Opportunity $opportunity,
        public BusinessBrain $brain,
        public string $campaignType,
        public array $channelIds,
    ) {}
}
