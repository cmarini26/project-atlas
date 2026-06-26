<?php

namespace App\Services\Company;

use App\Models\Catalog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\DigitalTwin;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CompanyService
{
    /**
     * Create a Company with its Catalog, DigitalTwin, and owner CompanyMembership
     * in a single atomic transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $owner, array $data): Company
    {
        return DB::transaction(function () use ($owner, $data): Company {
            $company = Company::create([
                'name' => $data['name'],
                'industry' => $data['industry'] ?? null,
                'website_url' => $data['website_url'] ?? null,
                'brand' => $data['brand'] ?? null,
                'settings' => $data['settings'] ?? null,
            ]);

            Catalog::create([
                'company_id' => $company->id,
                'type' => $data['catalog_type'] ?? 'inventory',
            ]);

            DigitalTwin::create([
                'company_id' => $company->id,
                'status' => 'initializing',
            ]);

            CompanyMembership::create([
                'company_id' => $company->id,
                'user_id' => $owner->id,
                'role' => 'owner',
                'joined_at' => now(),
            ]);

            $company->refresh();

            return $company;
        });
    }
}
