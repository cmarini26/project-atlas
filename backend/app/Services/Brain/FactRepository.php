<?php

namespace App\Services\Brain;

use App\Models\Fact;
use Illuminate\Support\Collection;

class FactRepository
{
    public function findCurrent(string $companyId, string $key): ?Fact
    {
        return Fact::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->where('is_current', true)
            ->first();
    }

    /** @return Collection<int, Fact> */
    public function currentForCompany(string $companyId): Collection
    {
        return Fact::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_current', true)
            ->orderBy('key')
            ->get();
    }
}
