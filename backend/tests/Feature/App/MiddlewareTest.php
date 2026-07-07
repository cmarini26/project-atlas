<?php

namespace Tests\Feature\App;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get('/app')->assertRedirect('/login');
    }

    public function test_user_with_no_memberships_is_redirected_to_onboarding(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/app')->assertRedirect(route('onboarding'));
    }

    public function test_user_with_single_membership_accesses_app(): void
    {
        [$user] = $this->userWithCompanyAndIntegration();

        $this->actingAs($user)->get('/app')->assertOk();
    }

    public function test_user_with_multiple_memberships_and_no_session_is_redirected_to_selector(): void
    {
        $user = User::factory()->create();

        foreach (['company-a', 'company-b'] as $slug) {
            $company = Company::withoutGlobalScopes()->create(['name' => $slug, 'slug' => $slug]);
            CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        }

        $this->actingAs($user)->get('/app')->assertRedirect(route('company.select'));
    }

    public function test_user_with_multiple_memberships_and_valid_session_accesses_app(): void
    {
        $user = User::factory()->create();
        $companies = [];

        foreach (['co-x', 'co-y'] as $slug) {
            $company = Company::withoutGlobalScopes()->create(['name' => $slug, 'slug' => $slug]);
            CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
            $this->createIntegration($company);
            $companies[] = $company;
        }

        $this->actingAs($user)
            ->withSession(['selected_company_id' => $companies[0]->id])
            ->get('/app')
            ->assertOk();
    }

    public function test_shared_companies_prop_lists_all_memberships_for_switcher(): void
    {
        $user = User::factory()->create();
        $companies = [];

        foreach (['co-x', 'co-y'] as $slug) {
            $company = Company::withoutGlobalScopes()->create(['name' => $slug, 'slug' => $slug]);
            CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
            $this->createIntegration($company);
            $companies[] = $company;
        }

        $this->actingAs($user)
            ->withSession(['selected_company_id' => $companies[0]->id])
            ->get('/app')
            ->assertInertia(fn ($page) => $page
                ->has('companies', 2)
                ->where('companies.0.name', 'co-x')
                ->where('companies.1.name', 'co-y')
            );
    }

    public function test_shared_companies_prop_is_a_single_entry_for_single_membership_user(): void
    {
        [$user] = $this->userWithCompanyAndIntegration();

        $this->actingAs($user)
            ->get('/app')
            ->assertInertia(fn ($page) => $page->has('companies', 1));
    }

    public function test_shared_company_prop_reflects_the_current_tenant(): void
    {
        // Regression: HandleInertiaRequests is global 'web' middleware and
        // runs before route-level 'company' middleware sets the request
        // attribute, so an eagerly-computed 'company' prop always resolved
        // to null. It must be a closure, resolved when the response is built.
        [$user, $company] = $this->userWithCompanyAndIntegration();

        $this->actingAs($user)
            ->get('/app')
            ->assertInertia(fn ($page) => $page->where('company.name', $company->name));
    }

    /** @return array{User, Company} */
    private function userWithCompanyAndIntegration(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);
        $this->createIntegration($company);

        return [$user, $company];
    }

    private function createIntegration(Company $company): void
    {
        Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://test.com'],
            'status' => 'active',
        ]);
    }
}
