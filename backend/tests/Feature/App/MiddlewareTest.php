<?php

namespace Tests\Feature\App;

use App\Models\Company;
use App\Models\CompanyMembership;
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
        [$user] = $this->userWithCompany();

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
            $companies[] = $company;
        }

        $this->actingAs($user)
            ->withSession(['selected_company_id' => $companies[0]->id])
            ->get('/app')
            ->assertOk();
    }

    /** @return array{User, Company} */
    private function userWithCompany(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $company];
    }
}
