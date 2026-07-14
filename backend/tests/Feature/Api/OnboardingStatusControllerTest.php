<?php

namespace Tests\Feature\Api;

use App\Enums\DiscoveryAttemptStatus;
use App\Enums\DiscoveryStage;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\DiscoveryConnectorAttempt;
use App\Models\DiscoveryRun;
use App\Models\Integration;
use App\Models\MarketingChannel;
use App\Models\Observation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Milestone 15 Phase 2 — polls App\Services\Discovery\BusinessDiscoveryService::progressFor(),
 * aggregating across a company's whole DiscoveryRun rather than "the one
 * Integration" the pre-Phase-2 version of this endpoint was scoped to.
 * BusinessDiscoveryServiceTest covers progressFor()'s own correctness in
 * depth; this file covers the HTTP contract around it.
 */
class OnboardingStatusControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/onboarding/status')->assertUnauthorized();
    }

    public function test_returns_empty_payload_when_user_has_no_membership(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJson([
                'stage' => null,
                'started_at' => null,
                'completed_at' => null,
                'connectors' => [],
                'facts_created' => 0,
                'opportunities_found' => 0,
                'recommendations_generated' => 0,
                'recommendation_count' => 0,
                'first_recommendation_id' => null,
            ]);
    }

    public function test_returns_empty_payload_when_discovery_never_started(): void
    {
        [$user] = $this->makeCompanyWithMembership();

        $this->actingAs($user)
            ->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJson(['stage' => null]);
    }

    public function test_returns_discovering_stage_while_an_attempt_is_still_running(): void
    {
        [$user, $company] = $this->makeCompanyWithMembership();
        $run = DiscoveryRun::create(['company_id' => $company->id, 'stage' => DiscoveryStage::Discovering, 'started_at' => now()]);
        $channel = MarketingChannel::factory()->for($company)->create();

        DiscoveryConnectorAttempt::create([
            'discovery_run_id' => $run->id,
            'company_id' => $company->id,
            'marketing_channel_id' => $channel->id,
            'connector_type' => 'website_crawl',
            'status' => DiscoveryAttemptStatus::Running,
            'attempt_count' => 1,
        ]);

        $this->actingAs($user)
            ->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJson([
                'stage' => 'discovering',
                'facts_created' => 0,
                'recommendation_count' => 0,
            ]);
    }

    public function test_reports_the_declared_asset_and_its_connector_status(): void
    {
        [$user, $company] = $this->makeCompanyWithMembership();
        $run = DiscoveryRun::create(['company_id' => $company->id, 'stage' => DiscoveryStage::Discovering, 'started_at' => now()]);
        $channel = MarketingChannel::factory()->for($company)->create(['type' => 'website']);

        DiscoveryConnectorAttempt::create([
            'discovery_run_id' => $run->id,
            'company_id' => $company->id,
            'marketing_channel_id' => $channel->id,
            'connector_type' => 'website_crawl',
            'status' => DiscoveryAttemptStatus::Failed,
            'attempt_count' => 3,
            'error_message' => 'Connection refused',
        ]);

        $response = $this->actingAs($user)->getJson('/api/onboarding/status')->assertOk();

        $response->assertJsonFragment([
            'type' => 'website',
            'label' => 'Website',
            'status' => 'failed',
            'error_message' => 'Connection refused',
        ]);
    }

    public function test_redispatches_a_stale_retrying_observation_before_reporting_progress(): void
    {
        [$user, $company] = $this->makeCompanyWithMembership();
        DiscoveryRun::create(['company_id' => $company->id, 'stage' => DiscoveryStage::Analyzing, 'started_at' => now()]);

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'website_crawl', 'name' => 'Website',
            'config' => ['url' => 'https://acme.example.com'], 'status' => 'active',
        ]);

        // Parked in 'retrying' long enough ago that the endpoint's stale-retry
        // redispatch (throttled to one attempt per 30 s) should pick it up.
        $observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'integration_id' => $integration->id,
            'source_type' => 'crawl', 'source_identifier' => 'https://acme.example.com',
            'raw_payload' => json_encode(['url' => 'https://acme.example.com', 'body_text' => 'Hello']),
            'status' => 'retrying', 'observed_at' => now()->subMinutes(2),
        ]);
        $observation->updated_at = now()->subMinutes(2);
        $observation->save();

        $this->actingAs($user)->getJson('/api/onboarding/status')->assertOk();

        // A redispatch attempt was made — with FakeAiProvider bound in the
        // testing environment (no fixture queued), it moves out of the
        // exact 'retrying'-since-2-minutes-ago state it started in.
        $this->assertNotEquals($observation->updated_at, $observation->fresh()->updated_at);
    }

    /** @return array{0: User, 1: Company} */
    private function makeCompanyWithMembership(): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        return [$user, $company];
    }
}
