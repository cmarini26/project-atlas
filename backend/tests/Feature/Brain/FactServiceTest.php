<?php

namespace Tests\Feature\Brain;

use App\Models\Company;
use App\Models\Integration;
use App\Models\Observation;
use App\Services\Brain\Data\FactData;
use App\Services\Brain\FactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactServiceTest extends TestCase
{
    use RefreshDatabase;

    private FactService $service;

    private Observation $observation;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(FactService::class);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions',
            'slug' => 'cbb-auctions',
        ]);

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'website_crawl',
            'name' => 'Site',
            'config' => ['url' => 'https://cbbauctions.com'],
            'status' => 'active',
        ]);

        $this->observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'integration_id' => $integration->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://cbbauctions.com',
            'raw_payload' => '{}',
            'status' => 'pending',
            'observed_at' => now(),
        ]);
    }

    public function test_persists_facts_to_database(): void
    {
        $factData = collect([
            new FactData(key: 'business.name', value: 'CBB Auctions', dataType: 'string', confidence: 95),
            new FactData(key: 'services.primary', value: 'auctions', dataType: 'string', confidence: 85),
        ]);

        $facts = $this->service->storeExtracted($this->observation, $factData);

        $this->assertCount(2, $facts);
        $this->assertDatabaseHas('facts', [
            'company_id' => $this->company->id,
            'key' => 'business.name',
            'is_current' => true,
        ]);
        $this->assertDatabaseHas('facts', [
            'company_id' => $this->company->id,
            'key' => 'services.primary',
            'is_current' => true,
        ]);
    }

    public function test_supersedes_existing_fact_with_same_key(): void
    {
        $initial = collect([
            new FactData(key: 'business.name', value: 'Old Name', dataType: 'string', confidence: 70),
        ]);
        $this->service->storeExtracted($this->observation, $initial);

        $updated = collect([
            new FactData(key: 'business.name', value: 'CBB Auctions', dataType: 'string', confidence: 95),
        ]);
        $facts = $this->service->storeExtracted($this->observation, $updated);

        $this->assertDatabaseCount('facts', 2);

        $newFact = $facts->first();
        $this->assertTrue($newFact->is_current);

        $this->assertDatabaseHas('facts', [
            'key' => 'business.name',
            'is_current' => false,
            'superseded_by_id' => $newFact->id,
        ]);
    }

    public function test_fact_links_to_observation(): void
    {
        $factData = collect([
            new FactData(key: 'business.name', value: 'CBB Auctions', dataType: 'string', confidence: 95),
        ]);

        $facts = $this->service->storeExtracted($this->observation, $factData);

        $this->assertEquals($this->observation->id, $facts->first()->observation_id);
    }

    public function test_returns_empty_collection_for_no_fact_data(): void
    {
        $facts = $this->service->storeExtracted($this->observation, collect());

        $this->assertCount(0, $facts);
        $this->assertDatabaseCount('facts', 0);
    }
}
