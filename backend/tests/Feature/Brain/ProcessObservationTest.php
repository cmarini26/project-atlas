<?php

namespace Tests\Feature\Brain;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Events\ObservationProcessed;
use App\Jobs\ProcessObservation;
use App\Models\Catalog;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Integration;
use App\Models\Observation;
use App\Services\Analyst\WebsiteAnalyst;
use App\Services\Brain\FactService;
use App\Services\Brain\KnowledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProcessObservationTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private Company $company;

    private Observation $observation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->fake);

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

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://cbbauctions.com'],
            'status' => 'active',
        ]);

        $this->observation = Observation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'integration_id' => $integration->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://cbbauctions.com',
            'raw_payload' => json_encode([
                'url' => 'https://cbbauctions.com',
                'title' => 'CBB Auctions',
                'bodyText' => 'We are a comic book auction house.',
            ], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'observed_at' => now(),
        ]);
    }

    public function test_marks_observation_processed(): void
    {
        $this->fake->queueFixture('website-facts');

        (new ProcessObservation($this->observation))->handle(
            $this->app->make(WebsiteAnalyst::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
        );

        $this->observation->refresh();
        $this->assertEquals('processed', $this->observation->status);
        $this->assertNotNull($this->observation->processed_at);
    }

    public function test_creates_facts_from_ai_response(): void
    {
        $this->fake->queueFixture('website-facts');

        (new ProcessObservation($this->observation))->handle(
            $this->app->make(WebsiteAnalyst::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
        );

        $this->assertDatabaseCount('facts', 4);
        $this->assertDatabaseHas('facts', [
            'company_id' => $this->company->id,
            'key' => 'business.name',
            'is_current' => true,
        ]);
    }

    public function test_creates_knowledge_entries(): void
    {
        $this->fake->queueFixture('website-facts');

        (new ProcessObservation($this->observation))->handle(
            $this->app->make(WebsiteAnalyst::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
        );

        $this->assertDatabaseHas('knowledge_entries', [
            'company_id' => $this->company->id,
            'subject' => 'business',
            'is_active' => true,
        ]);
    }

    public function test_activates_digital_twin(): void
    {
        $this->fake->queueFixture('website-facts');

        (new ProcessObservation($this->observation))->handle(
            $this->app->make(WebsiteAnalyst::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
        );

        $twin = DigitalTwin::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->first();

        $this->assertEquals('active', $twin->status);
    }

    public function test_fires_observation_processed_event(): void
    {
        Event::fake([ObservationProcessed::class]);
        $this->fake->queueFixture('website-facts');

        (new ProcessObservation($this->observation))->handle(
            $this->app->make(WebsiteAnalyst::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
        );

        Event::assertDispatched(ObservationProcessed::class);
    }

    public function test_marks_failed_when_ai_provider_throws(): void
    {
        // Queue no responses — FakeAiProvider will throw on empty queue

        $this->expectException(\RuntimeException::class);

        (new ProcessObservation($this->observation))->handle(
            $this->app->make(WebsiteAnalyst::class),
            $this->app->make(FactService::class),
            $this->app->make(KnowledgeService::class),
        );

        $this->observation->refresh();
        $this->assertEquals('failed', $this->observation->status);
    }
}
