<?php

namespace App\Domain\BusinessBrain;

use App\Models\Catalog;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Models\Knowledge;
use App\Models\Observation;
use Illuminate\Support\Collection;

/**
 * The Business Brain is assembled on demand from the Digital Twin and related
 * records. It is a pure value object — it is never persisted to the database.
 * BusinessBrainService::for(Company) is the only way to obtain an instance.
 */
readonly class BusinessBrain
{
    /**
     * @param  Collection<int, Fact>  $activeFacts
     * @param  Collection<int, Knowledge>  $activeKnowledge
     * @param  Collection<int, Observation>  $recentObservations
     * @param  Collection<int, mixed>  $featuredItems
     * @param  Collection<int, mixed>  $recentCampaigns
     */
    public function __construct(
        public Company $company,
        public DigitalTwin $twin,
        public Collection $activeFacts,
        public Collection $activeKnowledge,
        public Collection $recentObservations,
        public ?Catalog $catalog,
        public Collection $featuredItems,
        public Collection $recentCampaigns,
        public ?MarketingPresenceSummary $marketingPresence = null,
    ) {}
}
