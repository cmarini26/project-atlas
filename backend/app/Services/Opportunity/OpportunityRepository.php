<?php

namespace App\Services\Opportunity;

use App\Models\Opportunity;
use Illuminate\Support\Collection;

class OpportunityRepository
{
    /**
     * Check if an open or selected opportunity already exists for this combination.
     */
    public function hasDuplicate(string $companyId, string $type, ?string $subjectType, ?string $subjectId): bool
    {
        return Opportunity::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('type', $type)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereIn('status', ['open', 'selected'])
            ->exists();
    }

    /**
     * @return Collection<int, Opportunity>
     */
    public function openForCompany(string $companyId): Collection
    {
        return Opportunity::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'open')
            ->orderBy('composite_score', 'desc')
            ->orderBy('detected_at', 'asc')
            ->get();
    }

    /**
     * @return Collection<int, Opportunity>
     */
    public function expiredCandidates(): Collection
    {
        return Opportunity::withoutGlobalScopes()
            ->where('status', 'open')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();
    }
}
