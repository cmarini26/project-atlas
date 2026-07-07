<?php

namespace Tests\Feature\App;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySelectorControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get('/company/select')->assertRedirect('/login');
    }

    public function test_index_lists_every_company_the_user_belongs_to(): void
    {
        $user = User::factory()->create();
        $companyA = Company::withoutGlobalScopes()->create(['name' => 'Company A', 'slug' => 'company-a']);
        $companyB = Company::withoutGlobalScopes()->create(['name' => 'Company B', 'slug' => 'company-b']);
        CompanyMembership::create(['company_id' => $companyA->id, 'user_id' => $user->id, 'role' => 'owner']);
        CompanyMembership::create(['company_id' => $companyB->id, 'user_id' => $user->id, 'role' => 'admin']);

        $this->actingAs($user)
            ->get('/company/select')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/CompanySelector')
                ->has('companies', 2)
                ->where('companies.0.name', 'Company A')
                ->where('companies.1.name', 'Company B')
            );
    }

    public function test_select_switches_session_to_the_chosen_company(): void
    {
        $user = User::factory()->create();
        $companyA = Company::withoutGlobalScopes()->create(['name' => 'Company A', 'slug' => 'company-a']);
        $companyB = Company::withoutGlobalScopes()->create(['name' => 'Company B', 'slug' => 'company-b']);
        CompanyMembership::create(['company_id' => $companyA->id, 'user_id' => $user->id, 'role' => 'owner']);
        CompanyMembership::create(['company_id' => $companyB->id, 'user_id' => $user->id, 'role' => 'admin']);

        $this->actingAs($user)
            ->post('/company/select', ['company_id' => $companyB->id])
            ->assertRedirect(route('app.dashboard'));

        $this->assertSame($companyB->id, session('selected_company_id'));
    }

    public function test_select_rejects_a_company_the_user_does_not_belong_to(): void
    {
        // Tenant-switching safety: a user must never be able to select into a
        // company they have no membership in, even by guessing/tampering
        // with the company_id in the request.
        $user = User::factory()->create();
        $ownCompany = Company::withoutGlobalScopes()->create(['name' => 'Own Co', 'slug' => 'own-co']);
        CompanyMembership::create(['company_id' => $ownCompany->id, 'user_id' => $user->id, 'role' => 'owner']);

        $otherCompany = Company::withoutGlobalScopes()->create(['name' => 'Other Co', 'slug' => 'other-co']);

        $this->actingAs($user)
            ->post('/company/select', ['company_id' => $otherCompany->id])
            ->assertNotFound();

        $this->assertNull(session('selected_company_id'));
    }

    public function test_select_requires_company_id(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/company/select', [])
            ->assertSessionHasErrors('company_id');
    }

    public function test_switching_company_changes_which_companys_data_the_dashboard_serves(): void
    {
        // End-to-end tenant-switching safety: after switching, subsequent
        // requests must be scoped to the newly selected company, not leak
        // data from the previously selected one.
        $user = User::factory()->create();
        $companyA = Company::withoutGlobalScopes()->create(['name' => 'Company A', 'slug' => 'company-a']);
        $companyB = Company::withoutGlobalScopes()->create(['name' => 'Company B', 'slug' => 'company-b']);
        CompanyMembership::create(['company_id' => $companyA->id, 'user_id' => $user->id, 'role' => 'owner']);
        CompanyMembership::create(['company_id' => $companyB->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->createIntegration($companyA);
        $this->createIntegration($companyB);

        $this->actingAs($user)
            ->withSession(['selected_company_id' => $companyA->id])
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('company.name', 'Company A'));

        $this->actingAs($user)
            ->post('/company/select', ['company_id' => $companyB->id]);

        $this->actingAs($user)
            ->get('/app')
            ->assertInertia(fn ($page) => $page->where('company.name', 'Company B'));
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
