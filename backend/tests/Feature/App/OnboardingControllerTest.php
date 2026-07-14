<?php

namespace Tests\Feature\App;

use App\Jobs\SyncIntegration;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\DiscoveryConnectorAttempt;
use App\Models\DiscoveryRun;
use App\Models\MarketingChannel;
use App\Models\OnboardingProfile;
use App\Models\User;
use App\Services\Discovery\BusinessDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * Business Discovery Onboarding — covers the seven-step wizard: Welcome
 * (client-side, no route), Company, Business Goals, Marketing Assets, Asset
 * Details, Marketing Preferences, Discovery Placeholder. Steps 1–6 are pure
 * data collection; finish() starts real Business Discovery orchestration
 * (Milestone 15 Phase 2) — see tests/Feature/Discovery/BusinessDiscoveryServiceTest.php
 * for the orchestration/pipeline behavior itself.
 */
class OnboardingControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Index step inference ─────────────────────────────────────────────────

    public function test_onboarding_index_redirects_unauthenticated(): void
    {
        $this->get('/onboarding')->assertRedirect('/login');
    }

    public function test_index_shows_step_1_for_new_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/onboarding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Onboarding/Index')->where('initial_step', 1));
    }

    public function test_index_shows_step_3_when_company_exists_but_no_goals(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/onboarding')
            ->assertInertia(fn ($page) => $page->where('initial_step', 3));
    }

    public function test_index_shows_step_4_when_goals_exist_but_no_assets(): void
    {
        [$user, $company] = $this->userWithCompany();
        OnboardingProfile::create(['company_id' => $company->id, 'business_goals' => ['increase_sales']]);

        $this->actingAs($user)
            ->get('/onboarding')
            ->assertInertia(fn ($page) => $page->where('initial_step', 4));
    }

    public function test_index_shows_step_5_when_an_enabled_asset_is_missing_details(): void
    {
        [$user, $company] = $this->userWithCompany();
        OnboardingProfile::create(['company_id' => $company->id, 'business_goals' => ['increase_sales']]);
        MarketingChannel::create([
            'company_id' => $company->id, 'type' => 'instagram', 'display_name' => 'Instagram',
            'importance' => 'secondary', 'objective' => ['awareness'],
        ]);

        $this->actingAs($user)
            ->get('/onboarding')
            ->assertInertia(fn ($page) => $page
                ->where('initial_step', 5)
                ->has('enabled_assets', 1)
                ->where('enabled_assets.0.type', 'instagram')
            );
    }

    public function test_index_shows_step_6_when_all_asset_details_are_satisfied(): void
    {
        [$user, $company] = $this->userWithCompany();
        OnboardingProfile::create(['company_id' => $company->id, 'business_goals' => ['increase_sales']]);
        MarketingChannel::create([
            'company_id' => $company->id, 'type' => 'email', 'display_name' => 'Email Newsletter',
            'importance' => 'secondary', 'objective' => ['retention'],
        ]);

        $this->actingAs($user)
            ->get('/onboarding')
            ->assertInertia(fn ($page) => $page->where('initial_step', 6));
    }

    public function test_index_shows_step_7_when_preferences_are_set_but_not_finished(): void
    {
        [$user, $company] = $this->userWithCompany();
        OnboardingProfile::create([
            'company_id' => $company->id, 'business_goals' => ['increase_sales'],
            'marketing_frequency' => 'weekly', 'marketing_owner' => 'me',
            'is_seasonal' => false, 'primary_cta' => 'call',
        ]);
        MarketingChannel::create([
            'company_id' => $company->id, 'type' => 'email', 'display_name' => 'Email Newsletter',
            'importance' => 'secondary', 'objective' => ['retention'],
        ]);

        $this->actingAs($user)
            ->get('/onboarding')
            ->assertInertia(fn ($page) => $page->where('initial_step', 7));
    }

    public function test_index_redirects_to_status_once_onboarding_is_complete(): void
    {
        [$user, $company] = $this->userWithCompany();
        OnboardingProfile::create([
            'company_id' => $company->id, 'business_goals' => ['increase_sales'],
            'marketing_frequency' => 'weekly', 'marketing_owner' => 'me',
            'is_seasonal' => false, 'primary_cta' => 'call', 'completed_at' => now(),
        ]);
        MarketingChannel::create([
            'company_id' => $company->id, 'type' => 'email', 'display_name' => 'Email Newsletter',
            'importance' => 'secondary', 'objective' => ['retention'],
        ]);

        $this->actingAs($user)
            ->get('/onboarding')
            ->assertRedirect(route('onboarding.status'));
    }

    // ── Step: Company ────────────────────────────────────────────────────────

    public function test_company_step_creates_company_with_description(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/onboarding/company', [
                'name' => 'My New Business',
                'industry' => 'retail',
                'description' => 'We sell comics.',
            ])
            ->assertRedirect(route('onboarding'));

        $this->assertDatabaseHas('companies', [
            'name' => 'My New Business',
            'industry' => 'retail',
            'description' => 'We sell comics.',
        ]);
        $this->assertDatabaseHas('company_memberships', ['user_id' => $user->id, 'role' => 'owner']);
    }

    public function test_company_step_requires_name_and_industry(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/onboarding/company', [])
            ->assertSessionHasErrors(['name', 'industry']);
    }

    public function test_company_step_does_not_create_a_second_company_on_resubmit(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/onboarding/company', ['name' => 'Another Co', 'industry' => 'retail'])
            ->assertRedirect(route('onboarding'));

        $this->assertSame(1, CompanyMembership::where('user_id', $user->id)->count());
    }

    // ── Step: Business Goals ─────────────────────────────────────────────────

    public function test_goals_step_persists_selected_goals(): void
    {
        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/onboarding/goals', ['goals' => ['increase_sales', 'grow_social_media']])
            ->assertRedirect(route('onboarding'));

        $profile = OnboardingProfile::where('company_id', $company->id)->first();
        $this->assertSame(['increase_sales', 'grow_social_media'], $profile->business_goals);
    }

    public function test_goals_step_requires_at_least_one_goal(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/onboarding/goals', ['goals' => []])
            ->assertSessionHasErrors('goals');
    }

    public function test_goals_step_rejects_an_unknown_goal(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/onboarding/goals', ['goals' => ['world-domination']])
            ->assertSessionHasErrors('goals.0');
    }

    public function test_goals_step_redirects_to_onboarding_with_no_company(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/onboarding/goals', ['goals' => ['increase_sales']])
            ->assertRedirect(route('onboarding'));
    }

    // ── Step: Marketing Assets ───────────────────────────────────────────────

    public function test_assets_step_declares_enabled_channels(): void
    {
        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/onboarding/assets', ['enabled' => ['website', 'instagram'], 'primary' => ['website']])
            ->assertRedirect(route('onboarding'));

        $this->assertSame(2, MarketingChannel::where('company_id', $company->id)->count());
        $website = MarketingChannel::where('company_id', $company->id)->where('type', 'website')->first();
        $this->assertSame('primary', $website->importance->value);
        $instagram = MarketingChannel::where('company_id', $company->id)->where('type', 'instagram')->first();
        $this->assertSame('secondary', $instagram->importance->value);
    }

    public function test_assets_step_requires_at_least_one_enabled_asset(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/onboarding/assets', ['enabled' => []])
            ->assertSessionHasErrors('enabled');
    }

    public function test_assets_step_rejects_more_than_three_primary(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/onboarding/assets', [
                'enabled' => ['website', 'instagram', 'facebook', 'linkedin'],
                'primary' => ['website', 'instagram', 'facebook', 'linkedin'],
            ])
            ->assertSessionHasErrors('primary');
    }

    public function test_assets_step_rejects_a_primary_that_is_not_enabled(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/onboarding/assets', ['enabled' => ['website'], 'primary' => ['instagram']])
            ->assertSessionHasErrors('primary');
    }

    public function test_assets_step_is_idempotent_and_reconciles_disabled_assets(): void
    {
        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)->post('/onboarding/assets', ['enabled' => ['website', 'instagram']]);
        $this->actingAs($user)->post('/onboarding/assets', ['enabled' => ['website']]);

        $this->assertSame(1, MarketingChannel::where('company_id', $company->id)->count());
        $this->assertDatabaseMissing('marketing_channels', ['company_id' => $company->id, 'type' => 'instagram']);
    }

    public function test_assets_step_declares_unlinked_unconnected_channels(): void
    {
        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)->post('/onboarding/assets', ['enabled' => ['website']]);

        $channel = MarketingChannel::where('company_id', $company->id)->first();
        $this->assertNull($channel->channel_id);
        $this->assertFalse($channel->is_connected);
    }

    // ── Step: Asset Details ──────────────────────────────────────────────────

    public function test_asset_details_step_persists_website_url_and_platform(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->declareAsset($company, 'website');

        $this->actingAs($user)
            ->post('/onboarding/asset-details', [
                'assets' => ['website' => ['url' => 'https://acme.com', 'platform' => 'wordpress']],
            ])
            ->assertRedirect(route('onboarding'));

        $channel = MarketingChannel::where('company_id', $company->id)->where('type', 'website')->first();
        $this->assertSame('https://acme.com', $channel->handle_or_url);
        $this->assertSame('wordpress', $channel->metadata['platform']);
    }

    public function test_asset_details_step_requires_url_and_platform_for_website(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->declareAsset($company, 'website');

        $this->actingAs($user)
            ->post('/onboarding/asset-details', ['assets' => ['website' => []]])
            ->assertSessionHasErrors(['assets.website.url', 'assets.website.platform']);
    }

    public function test_asset_details_step_requires_url_for_instagram(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->declareAsset($company, 'instagram');

        $this->actingAs($user)
            ->post('/onboarding/asset-details', ['assets' => ['instagram' => []]])
            ->assertSessionHasErrors('assets.instagram.url');
    }

    public function test_asset_details_step_accepts_business_name_or_url_for_google_business(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->declareAsset($company, 'google_business_profile');

        $this->actingAs($user)
            ->post('/onboarding/asset-details', [
                'assets' => ['google_business_profile' => ['business_name_or_url' => 'Acme Comics']],
            ])
            ->assertRedirect(route('onboarding'));

        $channel = MarketingChannel::where('company_id', $company->id)->where('type', 'google_business_profile')->first();
        $this->assertSame('Acme Comics', $channel->handle_or_url);
    }

    public function test_asset_details_step_allows_optional_fields_for_email(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->declareAsset($company, 'email');

        $this->actingAs($user)
            ->post('/onboarding/asset-details', ['assets' => ['email' => []]])
            ->assertRedirect(route('onboarding'));

        $channel = MarketingChannel::where('company_id', $company->id)->where('type', 'email')->first();
        $this->assertNull($channel->handle_or_url);
    }

    public function test_asset_details_step_persists_email_provider_and_signup_url(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->declareAsset($company, 'email');

        $this->actingAs($user)
            ->post('/onboarding/asset-details', [
                'assets' => ['email' => ['provider' => 'Mailchimp', 'signup_url' => 'https://acme.com/subscribe']],
            ])
            ->assertRedirect(route('onboarding'));

        $channel = MarketingChannel::where('company_id', $company->id)->where('type', 'email')->first();
        $this->assertSame('Mailchimp', $channel->metadata['provider']);
        $this->assertSame('https://acme.com/subscribe', $channel->metadata['signup_url']);
    }

    public function test_asset_details_step_persists_description_for_events(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->declareAsset($company, 'events');

        $this->actingAs($user)
            ->post('/onboarding/asset-details', ['assets' => ['events' => ['description' => 'Monthly auction night']]])
            ->assertRedirect(route('onboarding'));

        $channel = MarketingChannel::where('company_id', $company->id)->where('type', 'events')->first();
        $this->assertSame('Monthly auction night', $channel->metadata['description']);
    }

    public function test_asset_details_step_ignores_details_for_undeclared_types(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->declareAsset($company, 'website');

        $this->actingAs($user)
            ->post('/onboarding/asset-details', [
                'assets' => [
                    'website' => ['url' => 'https://acme.com', 'platform' => 'wordpress'],
                    'instagram' => ['url' => 'https://instagram.com/acme'],
                ],
            ])
            ->assertRedirect(route('onboarding'));

        $this->assertDatabaseCount('marketing_channels', 1);
    }

    // ── Step: Marketing Preferences ──────────────────────────────────────────

    public function test_preferences_step_persists_all_values(): void
    {
        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/onboarding/preferences', [
                'marketing_frequency' => 'weekly',
                'marketing_owner' => 'me',
                'is_seasonal' => true,
                'seasonal_months' => [11, 12],
                'primary_cta' => 'buy_online',
            ])
            ->assertRedirect(route('onboarding'));

        $profile = OnboardingProfile::where('company_id', $company->id)->first();
        $this->assertSame('weekly', $profile->marketing_frequency->value);
        $this->assertSame('me', $profile->marketing_owner->value);
        $this->assertTrue($profile->is_seasonal);
        $this->assertSame([11, 12], $profile->seasonal_months);
        $this->assertSame('buy_online', $profile->primary_cta->value);
    }

    public function test_preferences_step_requires_seasonal_months_when_seasonal(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/onboarding/preferences', [
                'marketing_frequency' => 'weekly',
                'marketing_owner' => 'me',
                'is_seasonal' => true,
                'primary_cta' => 'call',
            ])
            ->assertSessionHasErrors('seasonal_months');
    }

    public function test_preferences_step_requires_all_fields(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/onboarding/preferences', [])
            ->assertSessionHasErrors(['marketing_frequency', 'marketing_owner', 'is_seasonal', 'primary_cta']);
    }

    // ── Step: Finish / Discovery Placeholder ─────────────────────────────────

    public function test_finish_marks_onboarding_complete_and_redirects_to_status(): void
    {
        [$user, $company] = $this->userWithCompany();
        OnboardingProfile::create(['company_id' => $company->id, 'business_goals' => ['increase_sales']]);

        $this->actingAs($user)
            ->post('/onboarding/finish')
            ->assertRedirect(route('onboarding.status'));

        $profile = OnboardingProfile::where('company_id', $company->id)->first();
        $this->assertNotNull($profile->completed_at);
    }

    public function test_finish_starts_discovery_for_auto_discoverable_assets_only(): void
    {
        // Milestone 15 Phase 2 — finish() now starts Business Discovery.
        // Bus::fake() intercepts the dispatched job before it runs, so no
        // real HTTP crawl happens; this only proves the orchestration
        // wiring (which asset gets a job dispatched), not the pipeline
        // itself (covered by BusinessDiscoveryServiceTest).
        [$user, $company] = $this->userWithCompany();
        OnboardingProfile::create(['company_id' => $company->id, 'business_goals' => ['increase_sales']]);
        MarketingChannel::create([
            'company_id' => $company->id, 'type' => 'website', 'display_name' => 'Website',
            'handle_or_url' => 'https://acme.example.com', 'importance' => 'secondary', 'objective' => ['seo'],
        ]);
        MarketingChannel::create([
            'company_id' => $company->id, 'type' => 'facebook', 'display_name' => 'Facebook',
            'importance' => 'secondary', 'objective' => ['awareness'],
        ]);

        Bus::fake();

        $this->actingAs($user)->post('/onboarding/finish');

        // Website can auto-discover from just a URL — a connector job is dispatched.
        Bus::assertDispatched(SyncIntegration::class);
        $this->assertDatabaseCount('discovery_connector_attempts', 1);

        // Facebook has no auto-discoverable connector — declared only, no attempt.
        $this->assertDatabaseHas('marketing_channels', [
            'company_id' => $company->id, 'type' => 'facebook', 'is_connected' => false,
        ]);

        // The job was only dispatched (faked), never actually run.
        $this->assertDatabaseCount('observations', 0);
    }

    public function test_finish_invokes_business_discovery_service_as_the_single_orchestrator(): void
    {
        // Milestone 15 Phase 3 — proves finish() calls the source-agnostic
        // orchestrator directly, not some parallel/legacy dispatch path.
        [$user, $company] = $this->userWithCompany();
        OnboardingProfile::create(['company_id' => $company->id, 'business_goals' => ['increase_sales']]);

        $this->mock(BusinessDiscoveryService::class, function ($mock) use ($company) {
            $mock->shouldReceive('start')
                ->once()
                ->with(Mockery::on(fn (Company $c): bool => $c->id === $company->id))
                ->andReturn(new DiscoveryRun(['company_id' => $company->id, 'stage' => 'discovering', 'started_at' => now()]));
        });

        $this->actingAs($user)
            ->post('/onboarding/finish')
            ->assertRedirect(route('onboarding.status'));
    }

    // ── Step: Retry ────────────────────────────────────────────────────────────

    public function test_retry_discovery_invokes_the_orchestrators_retry_method_on_the_latest_run(): void
    {
        [$user, $company] = $this->userWithCompany();
        $run = DiscoveryRun::create(['company_id' => $company->id, 'stage' => 'completed_with_errors', 'started_at' => now()]);

        $this->mock(BusinessDiscoveryService::class, function ($mock) use ($company, $run) {
            $mock->shouldReceive('latestRunFor')
                ->once()
                ->with(Mockery::on(fn (Company $c): bool => $c->id === $company->id))
                ->andReturn($run);

            $mock->shouldReceive('retry')
                ->once()
                ->with(Mockery::on(fn (DiscoveryRun $r): bool => $r->id === $run->id))
                ->andReturn($run);
        });

        $this->actingAs($user)
            ->post('/onboarding/discovery/retry')
            ->assertRedirect(route('onboarding.status'));
    }

    public function test_retry_discovery_is_a_no_op_when_discovery_was_never_started(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/onboarding/discovery/retry')
            ->assertRedirect(route('onboarding.status'));

        $this->assertDatabaseCount('discovery_runs', 0);
    }

    public function test_retry_discovery_redirects_unauthenticated(): void
    {
        $this->post('/onboarding/discovery/retry')->assertRedirect('/login');
    }

    public function test_retry_discovery_never_duplicates_attempts_end_to_end(): void
    {
        // Real (unmocked) orchestrator, through the controller/route layer —
        // proves the HTTP wiring reuses the same run, not a fresh one.
        [$user, $company] = $this->userWithCompany();
        $run = app(BusinessDiscoveryService::class)->start($company);
        $this->assertSame(0, DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->count());

        $this->actingAs($user)
            ->post('/onboarding/discovery/retry')
            ->assertRedirect(route('onboarding.status'));

        $this->assertSame(1, DiscoveryRun::where('company_id', $company->id)->count(), 'Retry must reuse the existing DiscoveryRun.');
    }

    // ── Legacy cutover ───────────────────────────────────────────────────────

    public function test_legacy_website_only_onboarding_routes_no_longer_exist(): void
    {
        $user = User::factory()->create();

        // These pre-Milestone-15 routes dispatched a connector directly from
        // onboarding, bypassing Business Discovery entirely. They must no
        // longer resolve to anything — Discovery is the only execution path.
        $this->actingAs($user)->post('/onboarding/integration', ['url' => 'https://acme.example.com'])->assertNotFound();
        $this->actingAs($user)->post('/onboarding/retry')->assertNotFound();
        $this->actingAs($user)->post('/onboarding/marketing-presence')->assertNotFound();
    }

    // ── Full progression ──────────────────────────────────────────────────────

    public function test_full_onboarding_progression_starts_discovery_only_at_finish(): void
    {
        $user = User::factory()->create();

        Bus::fake();

        $this->actingAs($user)->post('/onboarding/company', ['name' => 'Progression Co', 'industry' => 'retail']);
        $company = CompanyMembership::where('user_id', $user->id)->first()->company;

        $this->actingAs($user)->post('/onboarding/goals', ['goals' => ['increase_sales']]);
        $this->actingAs($user)->post('/onboarding/assets', ['enabled' => ['website', 'instagram'], 'primary' => ['website']]);
        $this->actingAs($user)->post('/onboarding/asset-details', [
            'assets' => [
                'website' => ['url' => 'https://progression.example.com', 'platform' => 'wordpress'],
                'instagram' => ['url' => 'https://instagram.com/progression'],
            ],
        ]);
        $this->actingAs($user)->post('/onboarding/preferences', [
            'marketing_frequency' => 'weekly',
            'marketing_owner' => 'me',
            'is_seasonal' => false,
            'primary_cta' => 'call',
        ]);

        $this->actingAs($user)
            ->get('/onboarding')
            ->assertInertia(fn ($page) => $page->where('initial_step', 7));

        // Steps 1–6 are pure data collection — no connector runs until finish().
        Bus::assertNotDispatched(SyncIntegration::class);
        $this->assertDatabaseCount('discovery_runs', 0);

        $this->actingAs($user)
            ->post('/onboarding/finish')
            ->assertRedirect(route('onboarding.status'));

        $this->assertSame(2, MarketingChannel::where('company_id', $company->id)->count());
        $this->assertNotNull(OnboardingProfile::where('company_id', $company->id)->first()->completed_at);

        // finish() starts Discovery: Website can auto-discover from just a
        // URL, so exactly one connector job is dispatched for it. Instagram
        // has no auto-discoverable connector and isn't already connected, so
        // it gets no attempt row — declared only, pending connection later.
        Bus::assertDispatched(SyncIntegration::class);
        $this->assertDatabaseCount('discovery_connector_attempts', 1);
        $this->assertDatabaseHas('discovery_connector_attempts', ['connector_type' => 'website_crawl']);

        // Bus::fake() intercepts the dispatched job — it never actually runs,
        // so the Observation pipeline, Business Brain, Marketing Health, and
        // the Opportunity/Decision Engine are untouched by this test.
        $this->assertDatabaseCount('observations', 0);
        $this->assertDatabaseCount('facts', 0);
        $this->assertDatabaseCount('opportunities', 0);
        $this->assertDatabaseCount('marketing_health_scores', 0);
    }

    // ── Status page (unchanged) ──────────────────────────────────────────────

    public function test_status_page_renders_for_authenticated_user(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/onboarding/status')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Onboarding/Status'));
    }

    public function test_status_page_redirects_unauthenticated(): void
    {
        $this->get('/onboarding/status')->assertRedirect('/login');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{User, Company} */
    private function userWithCompany(): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        return [$user, $company];
    }

    private function declareAsset(Company $company, string $type): MarketingChannel
    {
        return MarketingChannel::create([
            'company_id' => $company->id,
            'type' => $type,
            'display_name' => ucfirst($type),
            'importance' => 'secondary',
            'objective' => ['awareness'],
        ]);
    }
}
