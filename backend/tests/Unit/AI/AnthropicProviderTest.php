<?php

namespace Tests\Unit\AI;

use App\AI\AiResponse;
use App\AI\Contracts\AiProvider;
use App\AI\Prompts\FactExtractionPrompt;
use App\AI\Prompts\Prompt;
use App\AI\Providers\AnthropicProvider;
use App\AI\Testing\FakeAiProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use RuntimeException;
use Tests\TestCase;

class AnthropicProviderTest extends TestCase
{
    // --- Helpers ---

    private function makePlainPrompt(): Prompt
    {
        return new class() extends Prompt
        {
            public function system(): string
            {
                return 'You are a helpful assistant.';
            }

            public function user(): string
            {
                return 'Say hello.';
            }

            public function name(): string
            {
                return 'TestPlainPrompt';
            }
        };
    }

    private function makeSchemaPrompt(): Prompt
    {
        return new class() extends Prompt
        {
            public function system(): string
            {
                return 'Extract data.';
            }

            public function user(): string
            {
                return 'Extract the name.';
            }

            public function name(): string
            {
                return 'TestSchemaPrompt';
            }

            /** @return array<string, mixed> */
            public function schema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                    ],
                    'required' => ['name'],
                ];
            }
        };
    }

    /**
     * Build a provider backed by a Guzzle MockHandler.
     *
     * @param  Response[]  $responses
     * @param  array<int, mixed>  &$history
     */
    private function makeProvider(array $responses, array &$history = []): AnthropicProvider
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $http = new Client(['handler' => $stack]);

        return new AnthropicProvider(
            http: $http,
            apiKey: 'test-key',
            model: 'claude-test-model',
        );
    }

    private function textResponseBody(string $text, string $model = 'claude-test-model'): string
    {
        return json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'stop_reason' => 'end_turn',
        ]);
    }

    private function toolUseResponseBody(mixed $input, string $toolName = 'TestSchemaPrompt', string $model = 'claude-test-model', string $stopReason = 'tool_use'): string
    {
        return json_encode([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => $toolName, 'input' => $input],
            ],
            'usage' => ['input_tokens' => 20, 'output_tokens' => 8],
            'stop_reason' => $stopReason,
        ]);
    }

    // --- Plain text response ---

    public function test_returns_text_content_for_plain_prompt(): void
    {
        $history = [];
        $provider = $this->makeProvider(
            [new Response(200, [], $this->textResponseBody('Hello, world!'))],
            $history,
        );

        $result = $provider->complete($this->makePlainPrompt());

        $this->assertInstanceOf(AiResponse::class, $result);
        $this->assertSame('Hello, world!', $result->content);
        $this->assertSame('claude-test-model', $result->model);
        $this->assertSame(10, $result->inputTokens);
        $this->assertSame(5, $result->outputTokens);
    }

    public function test_sends_correct_messages_payload_for_plain_prompt(): void
    {
        $history = [];
        $provider = $this->makeProvider(
            [new Response(200, [], $this->textResponseBody('hi'))],
            $history,
        );

        $provider->complete($this->makePlainPrompt());

        $this->assertCount(1, $history);
        $body = json_decode((string) $history[0]['request']->getBody(), true);

        $this->assertSame('claude-test-model', $body['model']);
        $this->assertSame('You are a helpful assistant.', $body['system']);
        $this->assertSame('Say hello.', $body['messages'][0]['content']);
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertArrayNotHasKey('tool_choice', $body);
    }

    // --- Tool-use (structured JSON) response ---

    public function test_returns_json_content_for_schema_prompt(): void
    {
        $history = [];
        $provider = $this->makeProvider(
            [new Response(200, [], $this->toolUseResponseBody(['name' => 'Alice']))],
            $history,
        );

        $result = $provider->complete($this->makeSchemaPrompt());

        $decoded = json_decode($result->content, true);
        $this->assertSame(['name' => 'Alice'], $decoded);
        $this->assertSame(20, $result->inputTokens);
        $this->assertSame(8, $result->outputTokens);
    }

    public function test_sends_tool_use_payload_for_schema_prompt(): void
    {
        $history = [];
        $provider = $this->makeProvider(
            [new Response(200, [], $this->toolUseResponseBody(['name' => 'Alice']))],
            $history,
        );

        $provider->complete($this->makeSchemaPrompt());

        $body = json_decode((string) $history[0]['request']->getBody(), true);

        $this->assertArrayHasKey('tools', $body);
        $this->assertArrayHasKey('tool_choice', $body);
        $this->assertSame('tool', $body['tool_choice']['type']);
        $this->assertSame('TestSchemaPrompt', $body['tool_choice']['name']);

        $tool = $body['tools'][0];
        $this->assertSame('TestSchemaPrompt', $tool['name']);
        $this->assertSame('object', $tool['input_schema']['type']);
    }

    // --- Auth header ---

    public function test_sends_anthropic_api_key_header(): void
    {
        $history = [];
        $provider = $this->makeProvider(
            [new Response(200, [], $this->textResponseBody('hi'))],
            $history,
        );

        $provider->complete($this->makePlainPrompt());

        $apiKey = $history[0]['request']->getHeaderLine('x-api-key');
        $this->assertSame('test-key', $apiKey);
    }

    public function test_sends_anthropic_version_header(): void
    {
        $history = [];
        $provider = $this->makeProvider(
            [new Response(200, [], $this->textResponseBody('hi'))],
            $history,
        );

        $provider->complete($this->makePlainPrompt());

        $version = $history[0]['request']->getHeaderLine('anthropic-version');
        $this->assertSame('2023-06-01', $version);
    }

    // --- Error handling ---

    public function test_throws_runtime_exception_on_api_error(): void
    {
        $mock = new MockHandler([
            new RequestException(
                'Connection failed',
                new Request('POST', '/v1/messages'),
            ),
        ]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $provider = new AnthropicProvider(http: $http, apiKey: 'key', model: 'model');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anthropic API request failed');

        $provider->complete($this->makePlainPrompt());
    }

    public function test_throws_runtime_exception_with_api_error_body(): void
    {
        $errorBody = json_encode(['error' => ['type' => 'authentication_error', 'message' => 'Invalid API key']]);
        $mock = new MockHandler([
            new RequestException(
                'Client error',
                new Request('POST', '/v1/messages'),
                new Response(401, [], $errorBody),
            ),
        ]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $provider = new AnthropicProvider(http: $http, apiKey: 'bad-key', model: 'model');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/authentication_error|Invalid API key/');

        $provider->complete($this->makePlainPrompt());
    }

    // --- Tool name sanitization ---

    public function test_tool_name_strips_invalid_characters(): void
    {
        $prompt = new class() extends Prompt
        {
            public function system(): string
            {
                return 'sys';
            }

            public function user(): string
            {
                return 'usr';
            }

            public function name(): string
            {
                return 'My Prompt: v2.0!';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }
        };

        $history = [];
        $provider = $this->makeProvider(
            [new Response(200, [], $this->toolUseResponseBody([], 'My_Prompt__v2_0_'))],
            $history,
        );

        $provider->complete($prompt);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $toolName = $body['tools'][0]['name'];

        // Must only contain alphanumeric and underscore
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_]+$/', $toolName);
    }

    public function test_tool_name_truncated_to_64_characters(): void
    {
        $longName = str_repeat('A', 100);

        $prompt = new class($longName) extends Prompt
        {
            public function __construct(private string $n) {}

            public function system(): string
            {
                return 'sys';
            }

            public function user(): string
            {
                return 'usr';
            }

            public function name(): string
            {
                return $this->n;
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }
        };

        $history = [];
        $provider = $this->makeProvider(
            [new Response(200, [], $this->toolUseResponseBody([]))],
            $history,
        );

        $provider->complete($prompt);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertLessThanOrEqual(64, strlen($body['tools'][0]['name']));
    }

    // --- Container binding ---

    public function test_container_binds_fake_provider_in_testing_environment(): void
    {
        // The test environment is 'testing', so FakeAiProvider must be bound.
        $resolved = $this->app->make(AiProvider::class);

        $this->assertInstanceOf(FakeAiProvider::class, $resolved);
    }

    public function test_anthropic_provider_implements_ai_provider_interface(): void
    {
        $this->assertInstanceOf(AiProvider::class, new AnthropicProvider(
            http: new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
            apiKey: 'key',
            model: 'model',
        ));
    }

    // --- Abnormal structured responses ---

    public function test_throws_when_schema_prompt_response_has_no_tool_use_block(): void
    {
        // tool_choice is forced for schema prompts, so a text-only response is
        // abnormal (refusal or API change) and must not be treated as valid output.
        $history = [];
        $provider = $this->makeProvider(
            [new Response(200, [], $this->textResponseBody('oops'))],
            $history,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no tool_use block');

        $provider->complete($this->makeSchemaPrompt());
    }

    public function test_throws_when_structured_response_truncated_at_max_tokens(): void
    {
        // Truncated tool-use input comes back empty — previously this silently
        // became zero facts. It must fail loudly instead.
        $history = [];
        $provider = $this->makeProvider(
            [new Response(200, [], $this->toolUseResponseBody([], stopReason: 'max_tokens'))],
            $history,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('truncated at max_tokens');

        $provider->complete($this->makeSchemaPrompt());
    }

    public function test_max_tokens_stop_reason_is_fine_for_plain_text_prompt(): void
    {
        $body = json_decode($this->textResponseBody('partial answer'), true);
        $body['stop_reason'] = 'max_tokens';

        $provider = $this->makeProvider([new Response(200, [], (string) json_encode($body))]);

        $result = $provider->complete($this->makePlainPrompt());

        $this->assertSame('partial answer', $result->content);
        $this->assertSame('max_tokens', $result->stopReason);
    }

    public function test_empty_tool_input_serializes_as_json_object(): void
    {
        // Claude's empty tool input {} decodes to [] in PHP; re-encoding must
        // produce "{}" so downstream parsers see an object, not a list.
        $provider = $this->makeProvider(
            [new Response(200, [], $this->toolUseResponseBody([]))],
        );

        $result = $provider->complete($this->makeSchemaPrompt());

        $this->assertSame('{}', $result->content);
    }

    // --- Request parameters ---

    public function test_sends_prompt_temperature(): void
    {
        $history = [];
        $provider = $this->makeProvider(
            [new Response(200, [], $this->textResponseBody('hi'))],
            $history,
        );

        $provider->complete($this->makePlainPrompt());

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(0.2, $body['temperature']);
    }

    public function test_captures_stop_reason_on_response(): void
    {
        $provider = $this->makeProvider(
            [new Response(200, [], $this->toolUseResponseBody(['name' => 'Alice']))],
        );

        $result = $provider->complete($this->makeSchemaPrompt());

        $this->assertSame('tool_use', $result->stopReason);
    }

    // --- Realistic Anthropic fact-extraction payload ---

    public function test_parses_realistic_fact_extraction_response(): void
    {
        // Mirrors a real Messages API response for FactExtractionPrompt: forced
        // tool call, multiple content-ordering quirks, realistic usage numbers.
        $body = json_encode([
            'id' => 'msg_01XFDUDYJgAACzvnptvVoYEL',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_01A09q90qw90lq917835lq9', 'name' => 'FactExtractionPrompt', 'input' => [
                    'facts' => [
                        ['key' => 'business.name', 'value' => 'Velocity Exotics', 'data_type' => 'string', 'confidence' => 95],
                        ['key' => 'business.industry', 'value' => 'exotic used car dealership', 'data_type' => 'string', 'confidence' => 90],
                        ['key' => 'services.primary', 'value' => '["vehicle sales","financing","trade-ins"]', 'data_type' => 'json', 'confidence' => 85],
                        ['key' => 'contact.phone', 'value' => '(555) 010-4477', 'data_type' => 'string', 'confidence' => 90],
                        ['key' => 'brand.positioning', 'value' => 'curated high-end inventory with white-glove service', 'data_type' => 'string', 'confidence' => 70],
                    ],
                ]],
            ],
            'stop_reason' => 'tool_use',
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => 2841,
                'output_tokens' => 412,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => 0,
            ],
        ], JSON_THROW_ON_ERROR);

        $provider = $this->makeProvider([new Response(200, [], $body)]);

        $prompt = new FactExtractionPrompt(
            pageUrl: 'https://velocityexotics.example',
            pageTitle: 'Velocity Exotics — Exotic & Luxury Used Cars',
            bodyText: 'Velocity Exotics is a family-owned exotic car dealership…',
        );

        $result = $provider->complete($prompt);

        $decoded = json_decode($result->content, true);
        $this->assertCount(5, $decoded['facts']);
        $this->assertSame('business.name', $decoded['facts'][0]['key']);
        $this->assertSame('Velocity Exotics', $decoded['facts'][0]['value']);
        $this->assertSame('tool_use', $result->stopReason);
        $this->assertSame(2841, $result->inputTokens);
        $this->assertSame(412, $result->outputTokens);
    }
}
