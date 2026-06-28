<?php

namespace Tests\Feature\App;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->get('/app/settings')->assertRedirect('/login');
    }

    public function test_index_renders_inertia_component(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Settings'));
    }

    public function test_index_includes_company_and_integrations(): void
    {
        [$user, $company] = $this->userWithCompany();

        Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website Scraper',
            'status' => 'active',
            'config' => ['url' => 'https://example.com'],
        ]);

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('company.name', 'Test Co')
                ->has('integrations', 1)
            );
    }

    public function test_update_saves_company_name(): void
    {
        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)
            ->patch('/app/settings', [
                'name' => 'Updated Business Name',
                'industry' => 'retail',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Updated Business Name',
        ]);
    }

    public function test_update_requires_name(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->patch('/app/settings', ['industry' => 'retail'])
            ->assertSessionHasErrors('name');
    }

    public function test_sync_integration_dispatches_job(): void
    {
        [$user, $company] = $this->userWithCompany();

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'status' => 'active',
            'config' => ['url' => 'https://example.com'],
        ]);

        $this->actingAs($user)
            ->post("/app/settings/integrations/{$integration->id}/sync")
            ->assertRedirect();
    }

    public function test_sync_integration_is_denied_for_other_company(): void
    {
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'status' => 'active',
            'config' => ['url' => 'https://example.com'],
        ]);

        $this->actingAs($user)
            ->post("/app/settings/integrations/{$integration->id}/sync")
            ->assertNotFound();
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
