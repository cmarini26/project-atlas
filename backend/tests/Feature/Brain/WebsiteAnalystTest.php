<?php

namespace Tests\Feature\Brain;

use App\AI\Contracts\AiProvider;
use App\AI\Prompts\FactExtractionPrompt;
use App\AI\Providers\AnthropicProvider;
use App\AI\Testing\FakeAiProvider;
use App\Models\Company;
use App\Models\Integration;
use App\Models\Observation;
use App\Services\Analyst\Exceptions\FactExtractionFailedException;
use App\Services\Analyst\WebsiteAnalyst;
use App\Services\Brain\Data\FactData;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
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
            'body_text' => 'We are a comic book auction house.',
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
            'body_text' => 'We are a comic book auction house.',
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
            'body_text' => '',
        ]);

        $facts = $this->analyst->analyze($observation);

        $this->assertCount(0, $facts);
        $this->fake->assertNothingSent();
    }

    public function test_throws_when_ai_returns_invalid_json(): void
    {
        $this->fake->queueResponse('this is not json {facts:');

        $observation = $this->makeCrawlObservation();

        $this->expectException(FactExtractionFailedException::class);
        $this->expectExceptionMessage('unparseable output');

        $this->analyst->analyze($observation);
    }

    public function test_throws_when_ai_returns_empty_facts_array(): void
    {
        $this->fake->queueResponse('{"facts": []}');

        $observation = $this->makeCrawlObservation();

        $this->expectException(FactExtractionFailedException::class);
        $this->expectExceptionMessage('zero usable facts');

        $this->analyst->analyze($observation);
    }

    public function test_throws_when_ai_response_is_missing_facts_key(): void
    {
        // A truncated tool call surfaces as an empty JSON object.
        $this->fake->queueResponse('{}');

        $observation = $this->makeCrawlObservation();

        $this->expectException(FactExtractionFailedException::class);
        $this->expectExceptionMessage("missing the required 'facts' array");

        $this->analyst->analyze($observation);
    }

    public function test_skips_malformed_fact_entries_but_keeps_valid_ones(): void
    {
        $this->fake->queueResponse((string) json_encode([
            'facts' => [
                ['key' => 'business.name', 'value' => 'CBB Auctions', 'data_type' => 'string', 'confidence' => 95],
                ['key' => 'broken.fact'], // missing value/data_type/confidence
                'not even an object',
                ['key' => 'contact.email', 'value' => 'hello@cbbauctions.com', 'data_type' => 'string', 'confidence' => 80],
            ],
        ]));

        $facts = $this->analyst->analyze($this->makeCrawlObservation());

        $this->assertCount(2, $facts);
        $this->assertSame('business.name', $facts[0]->key);
        $this->assertSame('contact.email', $facts[1]->key);
    }

    public function test_throws_when_all_fact_entries_are_malformed(): void
    {
        $this->fake->queueResponse((string) json_encode([
            'facts' => [
                ['key' => 'broken.fact'],
                ['value' => 'orphan value'],
            ],
        ]));

        $this->expectException(FactExtractionFailedException::class);
        $this->expectExceptionMessage('zero usable facts');

        $this->analyst->analyze($this->makeCrawlObservation());
    }

    public function test_extracts_facts_from_realistic_anthropic_response(): void
    {
        // End-to-end through the real AnthropicProvider and StructuredResponseParser,
        // with only the HTTP layer mocked using a realistic Messages API payload.
        $apiResponse = json_encode([
            'id' => 'msg_01XFDUDYJgAACzvnptvVoYEL',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'FactExtractionPrompt', 'input' => [
                    'facts' => [
                        ['key' => 'business.name', 'value' => 'CBB Auctions', 'data_type' => 'string', 'confidence' => 95],
                        ['key' => 'business.industry', 'value' => 'comic book auctions and collectibles', 'data_type' => 'string', 'confidence' => 90],
                        ['key' => 'audience.segment', 'value' => 'comic book collectors and investors', 'data_type' => 'string', 'confidence' => 75],
                        ['key' => 'services.primary', 'value' => '["periodic auctions","seller stores","marketplace"]', 'data_type' => 'json', 'confidence' => 85],
                        ['key' => 'brand.positioning', 'value' => 'trusted marketplace for high-value comic books', 'data_type' => 'string', 'confidence' => 70],
                    ],
                ]],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 3120, 'output_tokens' => 388],
        ], JSON_THROW_ON_ERROR);

        $mock = new MockHandler([new Response(200, [], $apiResponse)]);
        $provider = new AnthropicProvider(
            http: new Client(['handler' => HandlerStack::create($mock)]),
            apiKey: 'test-key',
            model: 'claude-sonnet-4-6',
        );
        $this->app->instance(AiProvider::class, $provider);
        $analyst = $this->app->make(WebsiteAnalyst::class);

        $facts = $analyst->analyze($this->makeCrawlObservation());

        $this->assertCount(5, $facts);
        $this->assertContainsOnlyInstancesOf(FactData::class, $facts);
        $this->assertSame('business.name', $facts[0]->key);
        $this->assertSame('CBB Auctions', $facts[0]->value);
        $this->assertSame(95, $facts[0]->confidence);
        $this->assertSame('json', $facts[3]->dataType);
    }

    private function makeCrawlObservation(): Observation
    {
        return $this->makeObservation([
            'url' => 'https://cbbauctions.com',
            'title' => 'CBB Auctions',
            'body_text' => 'We are a comic book auction house serving collectors nationwide.',
        ]);
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
