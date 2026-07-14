<?php

namespace App\Services\Company;

use App\Models\Catalog;
use App\Models\Channel;
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
                'description' => $data['description'] ?? null,
                'website_url' => $data['website_url'] ?? null,
                'brand' => $data['brand'] ?? null,
                'settings' => $data['settings'] ?? null,
            ]);

            Catalog::create([
                'company_id' => $company->id,
                'type' => $data['catalog_type'] ?? 'mixed',
            ]);

            DigitalTwin::create([
                'company_id' => $company->id,
                'status' => 'initializing',
            ]);

            // DecisionEngine::evaluate() refuses to commit a Decision (and
            // therefore never produces a Recommendation) for a company with
            // zero active Channel rows — a real publishing destination, not
            // a declared MarketingChannel asset. Seed a default draft-only
            // blog channel so every company can reach a Recommendation from
            // day one; the user can add real connected channels (email,
            // social) through Settings later. This restores behavior the
            // pre-Milestone-15 onboarding flow used to seed directly in
            // OnboardingController before the wizard was rewritten.
            Channel::create([
                'company_id' => $company->id,
                'type' => 'blog',
                'name' => 'Blog',
                'is_active' => true,
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
