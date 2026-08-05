<?php

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\EmailAudience;
use App\Models\EmailContact;
use App\Models\MarketingChannel;
use App\Models\OnboardingProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedStagingProfileCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_northwind_staging_profile(): void
    {
        $this->artisan('atlas:seed-staging')
            ->expectsOutputToContain('Seeded staging profile [northwind]')
            ->assertSuccessful();

        $company = Company::withoutGlobalScopes()->where('name', 'Northwind Skin Studio')->first();
        $this->assertNotNull($company);
        $this->assertSame('Med Spa / Skincare Clinic', $company->industry);
        $this->assertSame('https://cmarini26.github.io/project-atlas/', $company->website_url);

        $owner = User::withoutGlobalScopes()->where('email', 'northwind-owner@atlas.test')->first();
        $this->assertNotNull($owner);

        $this->assertDatabaseHas('company_memberships', [
            'company_id' => $company->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $profile = OnboardingProfile::withoutGlobalScopes()->where('company_id', $company->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame(['generate_leads', 'increase_sales', 'increase_website_traffic', 'improve_seo'], $profile->business_goals);
        $this->assertTrue($profile->isComplete());

        $this->assertSame(5, MarketingChannel::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertDatabaseHas('marketing_channels', [
            'company_id' => $company->id,
            'type' => 'website',
            'handle_or_url' => 'https://cmarini26.github.io/project-atlas/',
        ]);

        $this->assertSame(4, EmailAudience::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(4, EmailContact::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }

    public function test_it_is_idempotent_on_rerun(): void
    {
        $this->artisan('atlas:seed-staging')->assertSuccessful();
        $this->artisan('atlas:seed-staging')->assertSuccessful();

        $company = Company::withoutGlobalScopes()->where('name', 'Northwind Skin Studio')->firstOrFail();

        $this->assertSame(1, Company::withoutGlobalScopes()->where('name', 'Northwind Skin Studio')->count());
        $this->assertSame(1, User::withoutGlobalScopes()->where('email', 'northwind-owner@atlas.test')->count());
        $this->assertSame(1, CompanyMembership::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(5, MarketingChannel::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(4, EmailAudience::withoutGlobalScopes()->where('company_id', $company->id)->count());
        $this->assertSame(4, EmailContact::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }

    public function test_it_rejects_an_unknown_profile(): void
    {
        $this->artisan('atlas:seed-staging does-not-exist')
            ->expectsOutputToContain('Unsupported staging profile [does-not-exist]')
            ->assertFailed();
    }

    public function test_it_refuses_to_seed_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('atlas:seed-staging')
            ->expectsOutputToContain('Refusing to seed a synthetic staging profile in the production environment.')
            ->assertFailed();

        $this->assertDatabaseMissing('companies', ['name' => 'Northwind Skin Studio']);
    }

    public function test_it_requires_an_explicit_password_in_staging(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');

        $this->artisan('atlas:seed-staging')
            ->expectsOutputToContain('The --owner-password option is required outside local/testing environments.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'northwind-owner@atlas.test']);
    }

    public function test_it_accepts_an_explicit_password_in_staging(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');

        $this->artisan('atlas:seed-staging --owner-password=staging-secret')
            ->assertSuccessful();

        $owner = User::withoutGlobalScopes()->where('email', 'northwind-owner@atlas.test')->firstOrFail();
        $this->assertTrue(password_verify('staging-secret', $owner->password));
    }
}
