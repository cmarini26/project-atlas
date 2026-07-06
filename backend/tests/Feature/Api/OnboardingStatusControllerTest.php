<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Fact;
use App\Models\Integration;
use App\Models\Observation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingStatusControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/onboarding/status')->assertUnauthorized();
    }

    public function test_returns_nulls_when_user_has_no_membership(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJson([
                'twin_status' => null,
                'integration_status' => null,
                'sync_started' => false,
                'fact_count' => 0,
                'opportunity_count' => 0,
                'recommendation_count' => 0,
                'first_recommendation_id' => null,
            ]);
    }

    public function test_returns_integration_status_active_before_first_sync(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://acme.com'],
            'status' => 'active',
            // last_run_at intentionally absent — sync not yet started
        ]);

        $this->actingAs($user)
            ->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJson([
                'integration_status' => 'active',
                'sync_started' => false,
                'fact_count' => 0,
            ]);
    }

    public function test_returns_sync_started_true_after_first_run(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://acme.com'],
            'status' => 'active',
            'last_run_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJson([
                'integration_status' => 'active',
                'sync_started' => true,
            ]);
    }

    public function test_no_opportunities_true_when_facts_exist_but_scan_found_nothing(): void
    {
        [$user, $company] = $this->makeOnboardedCompany();

        // Observation processed 2 minutes ago; facts exist; no opportunities
        // or recommendations followed — the scan legitimately found nothing.
        $this->makeProcessedObservationWithFact($company, processedAt: now()->subMinutes(2));

        $this->actingAs($user)
            ->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJson([
                'no_opportunities' => true,
                'pipeline_stalled' => false,
                'ai_failed' => false,
                'fact_count' => 1,
                'opportunity_count' => 0,
                'recommendation_count' => 0,
            ]);
    }

    public function test_no_opportunities_false_while_scan_may_still_be_running(): void
    {
        [$user, $company] = $this->makeOnboardedCompany();

        // Processed just now — the opportunity/decision chain may still be
        // in flight, so the no-opportunity state must not be asserted yet.
        $this->makeProcessedObservationWithFact($company, processedAt: now());

        $this->actingAs($user)
            ->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJson([
                'no_opportunities' => false,
                'fact_count' => 1,
            ]);
    }

    /** @return array{0: User, 1: Company} */
    private function makeOnboardedCompany(): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://acme.com'],
            'status' => 'active',
            'last_run_at' => now()->subMinutes(2),
        ]);

        return [$user, $company];
    }

    private function makeProcessedObservationWithFact(Company $company, mixed $processedAt): void
    {
        $observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://acme.com',
            'raw_payload' => '{}',
            'status' => 'processed',
            'observed_at' => now()->subMinutes(3),
            'processed_at' => $processedAt,
        ]);

        Fact::create([
            'company_id' => $company->id,
            'observation_id' => $observation->id,
            'key' => 'business.name',
            'value' => 'Acme',
            'data_type' => 'string',
            'confidence' => 90,
            'is_current' => true,
            'valid_from' => now(),
        ]);
    }

    public function test_returns_error_status_when_integration_failed(): void
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://bad-url.example'],
            'status' => 'error',
            'last_error' => 'Connection refused',
        ]);

        $this->actingAs($user)
            ->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJson([
                'integration_status' => 'error',
                'sync_started' => false,
            ]);
    }
}
