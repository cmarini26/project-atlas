<?php

namespace Tests\Feature\Onboarding;

use App\Models\Company;
use App\Models\OnboardingProfile;
use App\Services\Onboarding\OnboardingProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    private OnboardingProfileService $service;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(OnboardingProfileService::class);
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
    }

    public function test_for_returns_null_when_no_profile_exists(): void
    {
        $this->assertNull($this->service->for($this->company));
    }

    public function test_save_goals_creates_a_profile(): void
    {
        $profile = $this->service->saveGoals($this->company, ['increase_sales', 'improve_seo']);

        $this->assertSame(['increase_sales', 'improve_seo'], $profile->business_goals);
        $this->assertSame($this->company->id, $profile->company_id);
    }

    public function test_save_goals_updates_an_existing_profile_rather_than_duplicating(): void
    {
        $this->service->saveGoals($this->company, ['increase_sales']);
        $this->service->saveGoals($this->company, ['improve_seo']);

        $this->assertSame(1, OnboardingProfile::where('company_id', $this->company->id)->count());
        $this->assertSame(['improve_seo'], $this->service->for($this->company)->business_goals);
    }

    public function test_save_preferences_persists_seasonal_months_when_seasonal(): void
    {
        $profile = $this->service->savePreferences($this->company, [
            'marketing_frequency' => 'weekly',
            'marketing_owner' => 'team',
            'is_seasonal' => true,
            'seasonal_months' => [6, 7, 8],
            'primary_cta' => 'book',
        ]);

        $this->assertSame([6, 7, 8], $profile->seasonal_months);
        $this->assertTrue($profile->is_seasonal);
    }

    public function test_save_preferences_nulls_seasonal_months_when_not_seasonal(): void
    {
        $profile = $this->service->savePreferences($this->company, [
            'marketing_frequency' => 'monthly',
            'marketing_owner' => 'nobody',
            'is_seasonal' => false,
            'seasonal_months' => [1, 2],
            'primary_cta' => 'request_quote',
        ]);

        $this->assertNull($profile->seasonal_months);
    }

    public function test_mark_completed_sets_completed_at(): void
    {
        $this->service->saveGoals($this->company, ['increase_sales']);

        $profile = $this->service->markCompleted($this->company);

        $this->assertNotNull($profile->completed_at);
        $this->assertTrue($profile->isComplete());
    }

    public function test_profiles_are_scoped_per_company(): void
    {
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);

        $this->service->saveGoals($this->company, ['increase_sales']);

        $this->assertNull($this->service->for($other));
    }
}
