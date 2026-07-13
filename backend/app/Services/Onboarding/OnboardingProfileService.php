<?php

namespace App\Services\Onboarding;

use App\Models\Company;
use App\Models\OnboardingProfile;

/**
 * Persists onboarding-collected business goals and marketing preferences —
 * Milestone 15 Phase 1. Deliberately does not touch the
 * Observation/Fact/Knowledge pipeline, Business Brain, Marketing Health, or
 * the Opportunity/Decision Engine — this phase is UI + data collection
 * only, per docs/plans/Milestone-15-Business-Discovery-Onboarding-Plan.md.
 */
class OnboardingProfileService
{
    public function for(Company $company): ?OnboardingProfile
    {
        return OnboardingProfile::where('company_id', $company->id)->first();
    }

    /** @param  list<string>  $goals */
    public function saveGoals(Company $company, array $goals): OnboardingProfile
    {
        return $this->updateOrCreate($company, ['business_goals' => $goals]);
    }

    /**
     * @param  array{
     *     marketing_frequency: string,
     *     marketing_owner: string,
     *     is_seasonal: bool,
     *     seasonal_months: list<int>|null,
     *     primary_cta: string,
     * }  $attributes
     */
    public function savePreferences(Company $company, array $attributes): OnboardingProfile
    {
        return $this->updateOrCreate($company, [
            'marketing_frequency' => $attributes['marketing_frequency'],
            'marketing_owner' => $attributes['marketing_owner'],
            'is_seasonal' => $attributes['is_seasonal'],
            'seasonal_months' => $attributes['is_seasonal'] ? ($attributes['seasonal_months'] ?? []) : null,
            'primary_cta' => $attributes['primary_cta'],
        ]);
    }

    public function markCompleted(Company $company): OnboardingProfile
    {
        return $this->updateOrCreate($company, ['completed_at' => now()]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function updateOrCreate(Company $company, array $attributes): OnboardingProfile
    {
        $profile = $this->for($company);

        if ($profile === null) {
            return OnboardingProfile::create(array_merge(
                ['company_id' => $company->id, 'business_goals' => []],
                $attributes,
            ));
        }

        $profile->update($attributes);

        return $profile->refresh();
    }
}
