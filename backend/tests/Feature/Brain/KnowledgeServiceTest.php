<?php

namespace Tests\Feature\Brain;

use App\Events\DigitalTwinActivated;
use App\Events\KnowledgeSynthesized;
use App\Models\Catalog;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Services\Brain\KnowledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class KnowledgeServiceTest extends TestCase
{
    use RefreshDatabase;

    private KnowledgeService $service;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(KnowledgeService::class);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions',
            'slug' => 'cbb-auctions',
        ]);

        Catalog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'mixed',
        ]);

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'status' => 'initializing',
        ]);
    }

    private function seedFacts(): void
    {
        foreach ([
            ['business.name', 'CBB Auctions', 95],
            ['business.description', 'A comic book marketplace', 80],
            ['services.primary', 'auctions', 90],
        ] as [$key, $value, $confidence]) {
            Fact::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'key' => $key,
                'value' => json_encode($value),
                'data_type' => 'string',
                'confidence' => $confidence,
                'is_current' => true,
                'valid_from' => now(),
            ]);
        }
    }

    public function test_synthesizes_knowledge_entries_from_facts(): void
    {
        $this->seedFacts();

        $entries = $this->service->synthesizeForCompany($this->company);

        $this->assertCount(2, $entries); // 'business' domain + 'services' domain
        $this->assertDatabaseHas('knowledge_entries', [
            'company_id' => $this->company->id,
            'subject' => 'business',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('knowledge_entries', [
            'company_id' => $this->company->id,
            'subject' => 'services',
            'is_active' => true,
        ]);
    }

    public function test_fires_knowledge_synthesized_event(): void
    {
        Event::fake([KnowledgeSynthesized::class, DigitalTwinActivated::class]);

        $this->seedFacts();
        $this->service->synthesizeForCompany($this->company);

        Event::assertDispatched(KnowledgeSynthesized::class);
    }

    public function test_activates_digital_twin_on_first_synthesis(): void
    {
        Event::fake([KnowledgeSynthesized::class, DigitalTwinActivated::class]);

        $this->seedFacts();
        $this->service->synthesizeForCompany($this->company);

        Event::assertDispatched(DigitalTwinActivated::class);

        $twin = DigitalTwin::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
        $this->assertEquals('active', $twin->status);
    }

    public function test_does_not_activate_already_active_twin(): void
    {
        Event::fake([KnowledgeSynthesized::class, DigitalTwinActivated::class]);

        DigitalTwin::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->update(['status' => 'active']);

        $this->seedFacts();
        $this->service->synthesizeForCompany($this->company);

        Event::assertNotDispatched(DigitalTwinActivated::class);
    }

    public function test_updates_existing_knowledge_rather_than_creating_duplicate(): void
    {
        $this->seedFacts();
        $this->service->synthesizeForCompany($this->company);
        $this->service->synthesizeForCompany($this->company);

        $this->assertDatabaseCount('knowledge_entries', 2);
    }

    public function test_returns_empty_collection_when_no_facts(): void
    {
        $entries = $this->service->synthesizeForCompany($this->company);

        $this->assertCount(0, $entries);
        $this->assertDatabaseCount('knowledge_entries', 0);
    }

    public function test_updates_last_enriched_at_on_every_synthesis(): void
    {
        $this->seedFacts();

        $this->service->synthesizeForCompany($this->company);

        $twin = DigitalTwin::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->first();

        $this->assertNotNull($twin->last_enriched_at);

        $firstEnrichedAt = $twin->last_enriched_at;

        $this->travel(1)->seconds();

        $this->service->synthesizeForCompany($this->company);

        $twin->refresh();

        $this->assertTrue($twin->last_enriched_at->isAfter($firstEnrichedAt));
    }
}
