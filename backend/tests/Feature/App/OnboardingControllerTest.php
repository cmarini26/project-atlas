<?php

namespace Tests\Feature\App;

use App\Jobs\SyncIntegration;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OnboardingControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_onboarding_index_redirects_unauthenticated(): void
    {
        $this->get('/onboarding')->assertRedirect('/login');
    }

    public function test_onboarding_index_shows_step_1_for_new_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/onboarding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Onboarding/Index')
                ->where('initial_step', 1)
            );
    }

    public function test_onboarding_index_shows_step_2_when_company_has_no_integration(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)
            ->get('/onboarding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Onboarding/Index')
                ->where('initial_step', 2)
            );
    }

    public function test_onboarding_index_redirects_to_status_when_integration_exists(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        Integration::create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://acme.com'],
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/onboarding')
            ->assertRedirect(route('onboarding.status'));
    }

    // ── Company step ──────────────────────────────────────────────────────────

    public function test_company_step_creates_company_and_membership(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/onboarding/company', [
                'name' => 'My New Business',
                'industry' => 'retail',
            ])
            ->assertRedirect(route('onboarding'));

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

    // ── Integration step ──────────────────────────────────────────────────────

    public function test_integration_step_creates_integration_and_redirects_to_status(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        Queue::fake();

        $this->actingAs($user)
            ->post('/onboarding/integration', [
                'website_url' => 'https://example.com',
            ])
            ->assertRedirect(route('onboarding.status'));

        $this->assertDatabaseHas('integrations', [
            'company_id' => $company->id,
            'type' => 'website_crawl',
        ]);
    }

    public function test_integration_step_dispatches_sync_job(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        Queue::fake();

        $this->actingAs($user)
            ->post('/onboarding/integration', [
                'website_url' => 'https://example.com',
            ]);

        Queue::assertPushed(SyncIntegration::class, function (SyncIntegration $job) use ($company): bool {
            return $job->integration->company_id === $company->id;
        });
    }

    public function test_integration_step_requires_valid_url(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)
            ->post('/onboarding/integration', ['website_url' => 'not-a-url'])
            ->assertSessionHasErrors('website_url');
    }

    public function test_integration_step_redirects_to_onboarding_with_no_company(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/onboarding/integration', ['website_url' => 'https://example.com'])
            ->assertRedirect(route('onboarding'));
    }

    // ── Status page ───────────────────────────────────────────────────────────

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

    public function test_status_page_redirects_unauthenticated(): void
    {
        $this->get('/onboarding/status')->assertRedirect('/login');
    }
}
