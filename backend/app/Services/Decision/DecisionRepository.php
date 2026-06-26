<?php

namespace App\Services\Decision;

use App\Models\Decision;
use App\Models\Opportunity;
use Illuminate\Support\Collection;

class DecisionRepository
{
    /** @return Collection<int, Decision> */
    public function openForCompany(string $companyId): Collection
    {
        return Decision::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->orderBy('decided_at', 'desc')
            ->get();
    }

    public function findByOpportunity(Opportunity $opportunity): ?Decision
    {
        return Decision::withoutGlobalScopes()
            ->where('opportunity_id', $opportunity->id)
            ->first();
    }
}
