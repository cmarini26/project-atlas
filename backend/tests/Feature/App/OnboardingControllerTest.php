<?php

namespace Tests\Feature\App;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_index_redirects_unauthenticated(): void
    {
        $this->get('/onboarding')->assertRedirect('/login');
    }

    public function test_onboarding_index_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/onboarding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Onboarding/Index'));
    }

    public function test_user_with_existing_company_is_redirected_from_onboarding(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Existing Co', 'slug' => 'existing-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)
            ->get('/onboarding')
            ->assertRedirect(route('app.dashboard'));
    }

    public function test_company_step_creates_company_and_membership(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/onboarding/company', [
                'name' => 'My New Business',
                'industry' => 'retail',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('companies', ['name' => 'My New Business']);
        $this->assertDatabaseHas('company_memberships', ['user_id' => $user->id, 'role' => 'owner']);
    }

    public function test_company_step_requires_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/onboarding/company', ['industry' => 'retail'])
            ->assertSessionHasErrors('name');
    }

    public function test_integration_step_creates_integration(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)
            ->post('/onboarding/integration', [
                'website_url' => 'https://example.com',
            ])
            ->assertRedirect(route('onboarding.status'));

        $this->assertDatabaseHas('integrations', ['company_id' => $company->id, 'type' => 'website_crawl']);
    }

    public function test_status_page_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)
            ->get('/onboarding/status')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Onboarding/Status'));
    }
}
