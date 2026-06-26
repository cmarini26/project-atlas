<?php

namespace App\Services\Brain;

use App\Models\Knowledge;
use Illuminate\Support\Collection;

class KnowledgeRepository
{
    /** @return Collection<int, Knowledge> */
    public function activeForCompany(string $companyId): Collection
    {
        return Knowledge::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->active()
            ->get();
    }

    public function findActiveForSubject(string $companyId, string $subject): ?Knowledge
    {
        return Knowledge::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('subject', $subject)
            ->active()
            ->first();
    }
}
