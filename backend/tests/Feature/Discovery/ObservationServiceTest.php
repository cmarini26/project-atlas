<?php

namespace Tests\Feature\Discovery;

use App\Events\ObservationRecorded;
use App\Models\Company;
use App\Models\Integration;
use App\Services\Observatory\Connectors\ConnectorResult;
use App\Services\Observatory\ObservationService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ObservationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co',
            'slug' => 'test-co',
        ]);

        $this->integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'website_crawl',
            'name' => 'Site',
            'config' => ['url' => 'https://example.com'],
            'status' => 'active',
        ]);
    }

    public function test_records_connector_result_as_observation(): void
    {
        $result = new ConnectorResult(
            sourceType: 'crawl',
            sourceIdentifier: 'https://example.com',
            payload: json_encode(['url' => 'https://example.com', 'title' => 'Home'], JSON_THROW_ON_ERROR),
            observedAt: new DateTimeImmutable(),
        );

        $service = $this->app->make(ObservationService::class);
        $observation = $service->record($this->integration, $result);

        $this->assertEquals('crawl', $observation->source_type);
        $this->assertEquals('https://example.com', $observation->source_identifier);
        $this->assertEquals('pending', $observation->status);
        $this->assertDatabaseHas('observations', ['id' => $observation->id]);
    }

    public function test_fires_observation_recorded_event(): void
    {
        Event::fake([ObservationRecorded::class]);

        $result = new ConnectorResult(
            sourceType: 'crawl',
            sourceIdentifier: 'https://example.com',
            payload: '{}',
            observedAt: new DateTimeImmutable(),
        );

        $service = $this->app->make(ObservationService::class);
        $service->record($this->integration, $result);

        Event::assertDispatched(ObservationRecorded::class);
    }

    public function test_records_all_connector_results(): void
    {
        Event::fake([ObservationRecorded::class]);

        $results = collect([
            new ConnectorResult('crawl', 'https://example.com', '{}', new DateTimeImmutable()),
            new ConnectorResult('crawl', 'https://example.com/about', '{}', new DateTimeImmutable()),
            new ConnectorResult('crawl', 'https://example.com/contact', '{}', new DateTimeImmutable()),
        ]);

        $service = $this->app->make(ObservationService::class);
        $observations = $service->recordAll($this->integration, $results);

        $this->assertCount(3, $observations);
        $this->assertDatabaseCount('observations', 3);
        Event::assertDispatchedTimes(ObservationRecorded::class, 3);
    }
}
