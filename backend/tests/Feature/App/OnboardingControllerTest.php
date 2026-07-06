<?php

namespace Tests\Feature\App;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Jobs\SyncIntegration;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Integration;
use App\Models\User;
use App\Services\Observatory\Connectors\ConnectorRegistry;
use App\Services\Observatory\Connectors\Contracts\Connector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

        Bus::fake();

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

    public function test_integration_step_queues_sync_job_instead_of_running_it_inline(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        Bus::fake();

        $this->actingAs($user)
            ->post('/onboarding/integration', [
                'website_url' => 'https://example.com',
            ]);

        Bus::assertDispatched(SyncIntegration::class, function (SyncIntegration $job) use ($company): bool {
            return $job->integration->company_id === $company->id;
        });

        // The crawl + AI pipeline must never run inside the HTTP request —
        // an inline run blocked the submit for minutes and caused 502s.
        Bus::assertNotDispatchedSync(SyncIntegration::class);
    }

    public function test_integration_step_does_not_block_on_crawl_or_ai(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $fakeAi = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $fakeAi);

        Bus::fake();

        $this->actingAs($user)
            ->post('/onboarding/integration', [
                'website_url' => 'https://example.com',
            ])
            ->assertRedirect(route('onboarding.status'));

        // No crawl work happened during the request: no observations recorded,
        // no AI calls made. All of that belongs to the queue worker.
        $this->assertDatabaseMissing('observations', ['company_id' => $company->id]);
        $fakeAi->assertNothingSent();
    }

    public function test_integration_step_marks_error_when_sync_fails(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        // SyncIntegration throws when the connector is unavailable; simulate this
        // by binding a ConnectorRegistry that always throws for the resolving step.
        // We achieve this without touching real HTTP by making Bus NOT fake so the
        // job runs, but swapping ConnectorRegistry to throw.
        $this->app->bind(
            ConnectorRegistry::class,
            fn () => new class() extends ConnectorRegistry
            {
                public function __construct() {}

                public function resolve(Integration $integration): Connector
                {
                    throw new \RuntimeException('Connector unavailable');
                }
            }
        );

        $this->actingAs($user)
            ->post('/onboarding/integration', [
                'website_url' => 'https://example.com',
            ])
            ->assertRedirect(route('onboarding.status'));

        $this->assertDatabaseHas('integrations', [
            'company_id' => $company->id,
            'status' => 'error',
        ]);
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
