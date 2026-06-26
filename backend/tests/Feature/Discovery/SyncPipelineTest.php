<?php

namespace Tests\Feature\Discovery;

use App\Jobs\ProcessObservation;
use App\Jobs\SyncIntegration;
use App\Models\Company;
use App\Models\Integration;
use App\Models\Observation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_integration_dispatches_to_observations_queue(): void
    {
        Queue::fake();

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co',
            'slug' => 'test-co',
        ]);

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Site',
            'config' => ['url' => 'https://example.com'],
            'status' => 'active',
        ]);

        SyncIntegration::dispatch($integration);

        Queue::assertPushedOn('observations', SyncIntegration::class);
    }

    public function test_process_observation_dispatches_to_ai_queue(): void
    {
        Queue::fake();

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co',
            'slug' => 'test-co',
        ]);

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Site',
            'config' => ['url' => 'https://example.com'],
            'status' => 'active',
        ]);

        $observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'integration_id' => $integration->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://example.com',
            'raw_payload' => '{}',
            'status' => 'pending',
            'observed_at' => now(),
        ]);

        ProcessObservation::dispatch($observation);

        Queue::assertPushedOn('ai', ProcessObservation::class);
    }
}
