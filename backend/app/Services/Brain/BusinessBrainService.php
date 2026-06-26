<?php

namespace App\Services\Brain;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Catalog;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Observation;
use Illuminate\Support\Collection;

class BusinessBrainService
{
    public function __construct(
        private readonly FactRepository $facts,
        private readonly KnowledgeRepository $knowledge,
    ) {}

    public function for(Company $company): BusinessBrain
    {
        $twin = DigitalTwin::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->firstOrFail();

        $catalog = Catalog::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->first();

        $activeFacts = $this->facts->currentForCompany($company->id);
        $activeKnowledge = $this->knowledge->activeForCompany($company->id);

        /** @var Collection<int, Observation> $recentObservations */
        $recentObservations = Observation::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->latest('observed_at')
            ->limit(10)
            ->get();

        return new BusinessBrain(
            company: $company,
            twin: $twin,
            activeFacts: $activeFacts,
            activeKnowledge: $activeKnowledge,
            recentObservations: $recentObservations,
            catalog: $catalog,
            featuredItems: collect(),
            recentCampaigns: collect(),
        );
    }
}
