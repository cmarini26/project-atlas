<?php

namespace Tests\Feature\Brain;

use App\AI\Contracts\AiProvider;
use App\AI\Prompts\FactExtractionPrompt;
use App\AI\Testing\FakeAiProvider;
use App\Models\Company;
use App\Models\Integration;
use App\Models\Observation;
use App\Services\Analyst\WebsiteAnalyst;
use App\Services\Brain\Data\FactData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteAnalystTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private WebsiteAnalyst $analyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->fake);
        $this->analyst = $this->app->make(WebsiteAnalyst::class);
    }

    public function test_extracts_facts_from_observation_payload(): void
    {
        $this->fake->queueFixture('website-facts');

        $observation = $this->makeObservation([
            'url' => 'https://cbbauctions.com',
            'title' => 'CBB Auctions',
            'bodyText' => 'We are a comic book auction house.',
        ]);

        $facts = $this->analyst->analyze($observation);

        $this->assertCount(4, $facts);
        $this->assertContainsOnlyInstancesOf(FactData::class, $facts);
        $this->fake->assertPromptSent(FactExtractionPrompt::class);
    }

    public function test_returns_correct_fact_data_fields(): void
    {
        $this->fake->queueFixture('website-facts');

        $observation = $this->makeObservation([
            'url' => 'https://cbbauctions.com',
            'title' => 'CBB Auctions',
            'bodyText' => 'We are a comic book auction house.',
        ]);

        $facts = $this->analyst->analyze($observation);
        $first = $facts->first();

        $this->assertInstanceOf(FactData::class, $first);
        $this->assertSame('business.name', $first->key);
        $this->assertSame('CBB Auctions', $first->value);
        $this->assertSame('string', $first->dataType);
        $this->assertSame(95, $first->confidence);
    }

    public function test_returns_empty_collection_when_payload_has_no_body_text(): void
    {
        $observation = $this->makeObservation([
            'url' => 'https://example.com',
            'title' => 'Home',
            'bodyText' => '',
        ]);

        $facts = $this->analyst->analyze($observation);

        $this->assertCount(0, $facts);
        $this->fake->assertNothingSent();
    }

    private function makeObservation(mixed $payload): Observation
    {
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

        return Observation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'integration_id' => $integration->id,
            'source_type' => 'crawl',
            'source_identifier' => 'https://example.com',
            'raw_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'observed_at' => now(),
        ]);
    }
}
