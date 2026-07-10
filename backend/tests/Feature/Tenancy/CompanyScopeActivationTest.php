<?php

namespace Tests\Feature\Tenancy;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\MarketingChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves EnsureCompanyMembership actually binds `current_company_id` during a
 * real web request — not merely that manual company_id filtering happens to
 * produce the correct result, which would be true with or without this
 * binding and wouldn't prove the global scope is genuinely engaged. See
 * docs/plans/Critical-Production-Blockers.md, Blocker 1, and
 * docs/reviews/Production-Deployment-Audit.md's headline finding.
 */
class CompanyScopeActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_middleware_binds_current_company_id_for_a_single_membership_user(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->assertFalse($this->app->has('current_company_id'));

        $this->actingAs($user)->get('/app/opportunities')->assertOk();

        $this->assertTrue($this->app->has('current_company_id'));
        $this->assertSame($company->id, $this->app->make('current_company_id'));
    }

    public function test_middleware_binds_current_company_id_for_the_session_selected_company(): void
    {
        $user = User::factory()->create();
        $companyA = Company::withoutGlobalScopes()->create(['name' => 'Company A', 'slug' => 'company-a']);
        $companyB = Company::withoutGlobalScopes()->create(['name' => 'Company B', 'slug' => 'company-b']);
        CompanyMembership::create(['company_id' => $companyA->id, 'user_id' => $user->id, 'role' => 'owner']);
        CompanyMembership::create(['company_id' => $companyB->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->withSession(['selected_company_id' => $companyB->id])
            ->actingAs($user)
            ->get('/app/opportunities')
            ->assertOk();

        $this->assertSame($companyB->id, $this->app->make('current_company_id'));
    }

    public function test_global_scope_actively_filters_a_tenant_model_during_a_real_request(): void
    {
        $user = User::factory()->create();
        $companyA = Company::withoutGlobalScopes()->create(['name' => 'Company A', 'slug' => 'company-a']);
        $companyB = Company::withoutGlobalScopes()->create(['name' => 'Company B', 'slug' => 'company-b']);
        CompanyMembership::create(['company_id' => $companyA->id, 'user_id' => $user->id, 'role' => 'owner']);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $companyA->id,
            'type' => 'instagram',
            'display_name' => "Company A's Instagram",
            'objective' => ['awareness'],
        ]);

        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'type' => 'instagram',
            'display_name' => "Company B's Instagram",
            'objective' => ['awareness'],
        ]);

        $this->actingAs($user)->get('/app/opportunities')->assertOk();

        // No explicit company_id filter here at all — this is the global
        // scope doing the work, not a controller's manual `where()`.
        $this->assertCount(1, MarketingChannel::all());
        $this->assertSame("Company A's Instagram", MarketingChannel::first()->display_name);
    }

    public function test_redirect_paths_never_bind_current_company_id(): void
    {
        $user = User::factory()->create();

        // No membership at all — middleware redirects to onboarding.
        $this->actingAs($user)->get('/app/opportunities')->assertRedirect(route('onboarding'));

        $this->assertFalse($this->app->has('current_company_id'));
    }

    public function test_unresolved_multi_membership_never_binds_current_company_id(): void
    {
        $user = User::factory()->create();
        $companyA = Company::withoutGlobalScopes()->create(['name' => 'Company A', 'slug' => 'company-a']);
        $companyB = Company::withoutGlobalScopes()->create(['name' => 'Company B', 'slug' => 'company-b']);
        CompanyMembership::create(['company_id' => $companyA->id, 'user_id' => $user->id, 'role' => 'owner']);
        CompanyMembership::create(['company_id' => $companyB->id, 'user_id' => $user->id, 'role' => 'owner']);

        // No selected_company_id in session — middleware redirects to the selector.
        $this->actingAs($user)->get('/app/opportunities')->assertRedirect(route('company.select'));

        $this->assertFalse($this->app->has('current_company_id'));
    }
}
