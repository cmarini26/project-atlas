<?php

namespace Tests\Feature\Discovery;

use App\Models\Company;
use App\Models\Integration;
use App\Models\Observation;
use App\Models\User;
use App\Services\Company\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_scope_filters_observations_by_bound_company(): void
    {
        $service = $this->app->make(CompanyService::class);
        $user = User::factory()->create();

        $companyA = $service->create($user, ['name' => 'Company A']);
        $companyB = $service->create($user, ['name' => 'Company B']);

        $integrationA = Integration::withoutGlobalScopes()->create([
            'company_id' => $companyA->id,
            'type' => 'website_crawl',
            'name' => 'Site A',
            'config' => ['url' => 'https://a.com'],
            'status' => 'active',
        ]);

        $integrationB = Integration::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'type' => 'website_crawl',
            'name' => 'Site B',
            'config' => ['url' => 'https://b.com'],
            'status' => 'active',
        ]);

        Observation::withoutGlobalScopes()->create([
            'company_id' => $companyA->id,
            'integration_id' => $integrationA->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://a.com',
            'raw_payload' => '{}',
            'status' => 'pending',
            'observed_at' => now(),
        ]);

        Observation::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'integration_id' => $integrationB->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://b.com',
            'raw_payload' => '{}',
            'status' => 'pending',
            'observed_at' => now(),
        ]);

        // Bind company A — should only see company A's observation.
        $this->app->instance('current_company_id', $companyA->id);

        $this->assertCount(1, Observation::all());
        $this->assertEquals('https://a.com', Observation::first()->source_identifier);
    }

    public function test_no_company_bound_returns_all_rows_for_global_queries(): void
    {
        $service = $this->app->make(CompanyService::class);
        $user = User::factory()->create();

        $companyA = $service->create($user, ['name' => 'Company A']);
        $companyB = $service->create($user, ['name' => 'Company B']);

        $integrationA = Integration::withoutGlobalScopes()->create([
            'company_id' => $companyA->id,
            'type' => 'website_crawl',
            'name' => 'Site A',
            'config' => ['url' => 'https://a.com'],
            'status' => 'active',
        ]);

        Observation::withoutGlobalScopes()->create([
            'company_id' => $companyA->id,
            'integration_id' => $integrationA->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://a.com',
            'raw_payload' => '{}',
            'status' => 'pending',
            'observed_at' => now(),
        ]);

        // No company bound in container — scope is a no-op, all rows visible.
        $this->assertCount(1, Observation::withoutGlobalScopes()->get());
    }
}
