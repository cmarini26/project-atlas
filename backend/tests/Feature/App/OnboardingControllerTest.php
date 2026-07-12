<?php

namespace Tests\Feature\App;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Jobs\SyncIntegration;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Integration;
use App\Models\MarketingChannel;
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

    public function test_onboarding_index_shows_step_3_when_integration_exists_but_no_marketing_presence(): void
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
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Onboarding/Index')
                ->where('initial_step', 3)
            );
    }

    public function test_onboarding_index_redirects_to_status_when_integration_and_marketing_presence_exist(): void
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
        MarketingChannel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website',
            'display_name' => 'Website',
            'objective' => ['seo'],
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

    public function test_integration_step_creates_integration_and_redirects_to_marketing_presence_step(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        Bus::fake();

        // Redirects back to /onboarding, not straight to the status page —
        // the marketing-presence step still needs to run first.
        $this->actingAs($user)
            ->post('/onboarding/integration', [
                'website_url' => 'https://example.com',
            ])
            ->assertRedirect(route('onboarding'));

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
            ->assertRedirect(route('onboarding'));

        // No crawl work happened during the request: no observations recorded,
        // no AI calls made. All of that belongs to the queue worker.
        $this->assertDatabaseMissing('observations', ['company_id' => $company->id]);
        $fakeAi->assertNothingSent();
    }

    public function test_resubmitting_website_reuses_existing_integration(): void
    {
        // AI spend protection: repeat submits (retry with a different URL,
        // double-clicks) must not create a new integration + queued pipeline
        // run each time. The existing integration is updated in place, so
        // SyncIntegration's per-integration uniqueness can deduplicate.
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        Bus::fake();

        $this->actingAs($user)
            ->post('/onboarding/integration', ['website_url' => 'https://first.example.com']);

        $this->actingAs($user)
            ->post('/onboarding/integration', ['website_url' => 'https://second.example.com']);

        $integrations = Integration::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', 'website_crawl')
            ->get();

        $this->assertCount(1, $integrations, 'Resubmit must reuse the existing website integration.');
        $this->assertSame('https://second.example.com', $integrations->first()->config['url']);
        $this->assertSame('active', $integrations->first()->status);
    }

    public function test_resubmit_clears_previous_error_state(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://unreachable.example.com'],
            'status' => 'error',
            'last_error' => 'Connection refused',
        ]);

        Bus::fake();

        // "Try a different URL" flow: the retry must reset the integration
        // to active so the queued sync (and stall detection) treat it fresh.
        $this->actingAs($user)
            ->post('/onboarding/integration', ['website_url' => 'https://working.example.com'])
            ->assertRedirect(route('onboarding'));

        $integration->refresh();
        $this->assertSame('active', $integration->status);
        $this->assertNull($integration->last_error);
        $this->assertSame('https://working.example.com', $integration->config['url']);

        Bus::assertDispatched(SyncIntegration::class);
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
            ->assertRedirect(route('onboarding'));

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

    // ── Marketing presence step ──────────────────────────────────────────────

    public function test_marketing_presence_step_declares_selected_channels(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)
            ->post('/onboarding/marketing-presence', [
                'channels' => ['website', 'instagram', 'events'],
            ])
            ->assertRedirect(route('onboarding.status'));

        $this->assertDatabaseHas('marketing_channels', ['company_id' => $company->id, 'type' => 'website', 'display_name' => 'Website']);
        $this->assertDatabaseHas('marketing_channels', ['company_id' => $company->id, 'type' => 'instagram', 'display_name' => 'Instagram']);
        $this->assertDatabaseHas('marketing_channels', ['company_id' => $company->id, 'type' => 'events', 'display_name' => 'Events']);
        $this->assertSame(3, MarketingChannel::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }

    public function test_marketing_presence_step_creates_no_channel_or_integration_records(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)->post('/onboarding/marketing-presence', ['channels' => ['website', 'facebook']]);

        $this->assertDatabaseCount('channels', 0);
        $this->assertDatabaseCount('integrations', 0);
    }

    public function test_marketing_presence_step_declares_unlinked_unconnected_channels(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)->post('/onboarding/marketing-presence', ['channels' => ['website']]);

        $channel = MarketingChannel::withoutGlobalScopes()->where('company_id', $company->id)->first();
        $this->assertNull($channel->channel_id);
        $this->assertFalse($channel->is_connected);
        $this->assertSame('active', $channel->status->value);
    }

    public function test_marketing_presence_step_allows_an_empty_selection(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)
            ->post('/onboarding/marketing-presence', ['channels' => []])
            ->assertRedirect(route('onboarding.status'));

        $this->assertDatabaseCount('marketing_channels', 0);
    }

    public function test_marketing_presence_step_rejects_an_unknown_channel_type(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)
            ->post('/onboarding/marketing-presence', ['channels' => ['carrier-pigeon']])
            ->assertSessionHasErrors('channels.0');

        $this->assertDatabaseCount('marketing_channels', 0);
    }

    public function test_marketing_presence_step_requires_the_channels_key(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)
            ->post('/onboarding/marketing-presence', [])
            ->assertSessionHasErrors('channels');
    }

    public function test_marketing_presence_step_is_idempotent_on_resubmit(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)->post('/onboarding/marketing-presence', ['channels' => ['website']]);
        $this->actingAs($user)->post('/onboarding/marketing-presence', ['channels' => ['website']]);

        $this->assertSame(1, MarketingChannel::withoutGlobalScopes()->where('company_id', $company->id)->where('type', 'website')->count());
    }

    public function test_marketing_presence_step_redirects_to_onboarding_with_no_company(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/onboarding/marketing-presence', ['channels' => ['website']])
            ->assertRedirect(route('onboarding'));
    }

    public function test_marketing_presence_step_is_scoped_to_the_acting_users_company(): void
    {
        $userA = User::factory()->create();
        $companyA = Company::withoutGlobalScopes()->create(['name' => 'Company A', 'slug' => 'company-a']);
        CompanyMembership::create(['company_id' => $companyA->id, 'user_id' => $userA->id, 'role' => 'owner']);

        $userB = User::factory()->create();
        $companyB = Company::withoutGlobalScopes()->create(['name' => 'Company B', 'slug' => 'company-b']);
        CompanyMembership::create(['company_id' => $companyB->id, 'user_id' => $userB->id, 'role' => 'owner']);

        $this->actingAs($userA)->post('/onboarding/marketing-presence', ['channels' => ['website']]);

        $this->assertSame(1, MarketingChannel::withoutGlobalScopes()->where('company_id', $companyA->id)->count());
        $this->assertSame(0, MarketingChannel::withoutGlobalScopes()->where('company_id', $companyB->id)->count());
    }

    // ── Onboarding progression ───────────────────────────────────────────────

    public function test_full_onboarding_progression_advances_through_all_three_steps(): void
    {
        $user = User::factory()->create();

        Bus::fake();

        // Step 1: no membership yet.
        $this->actingAs($user)
            ->get('/onboarding')
            ->assertInertia(fn ($page) => $page->where('initial_step', 1));

        $this->actingAs($user)->post('/onboarding/company', ['name' => 'Progression Co']);

        // Step 2: company exists, no integration yet.
        $this->actingAs($user)
            ->get('/onboarding')
            ->assertInertia(fn ($page) => $page->where('initial_step', 2));

        $this->actingAs($user)->post('/onboarding/integration', ['website_url' => 'https://progression.example.com']);

        // Step 3: integration exists, no marketing presence yet.
        $this->actingAs($user)
            ->get('/onboarding')
            ->assertInertia(fn ($page) => $page->where('initial_step', 3));

        $this->actingAs($user)->post('/onboarding/marketing-presence', ['channels' => ['website', 'instagram']]);

        // Both steps done — onboarding is complete, status page takes over.
        $this->actingAs($user)
            ->get('/onboarding')
            ->assertRedirect(route('onboarding.status'));

        $company = CompanyMembership::where('user_id', $user->id)->first()->company;
        $this->assertSame(2, MarketingChannel::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }

    // ── Retry ─────────────────────────────────────────────────────────────────

    public function test_retry_redispatches_the_existing_integration_and_clears_error_state(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://flaky.example.com'],
            'status' => 'error',
            'last_error' => 'Connection refused',
        ]);

        Bus::fake();

        $this->actingAs($user)
            ->post('/onboarding/retry')
            ->assertRedirect(route('onboarding.status'));

        $integration->refresh();
        $this->assertSame('active', $integration->status);
        $this->assertNull($integration->last_error);

        Bus::assertDispatched(SyncIntegration::class, fn (SyncIntegration $job): bool => $job->integration->id === $integration->id);
    }

    public function test_retry_redirects_to_onboarding_with_no_integration(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'My Co', 'slug' => 'my-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)
            ->post('/onboarding/retry')
            ->assertRedirect(route('onboarding'));
    }

    public function test_retry_redirects_to_onboarding_with_no_company(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/onboarding/retry')
            ->assertRedirect(route('onboarding'));
    }

    public function test_retry_requires_auth(): void
    {
        $this->post('/onboarding/retry')->assertRedirect('/login');
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
