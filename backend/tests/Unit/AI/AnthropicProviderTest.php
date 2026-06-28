<?php

namespace Tests\Unit\AI;

use App\AI\AiResponse;
use App\AI\Contracts\AiProvider;
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

    private function toolUseResponseBody(mixed $input, string $toolName = 'TestSchemaPrompt', string $model = 'claude-test-model'): string
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
            'stop_reason' => 'tool_use',
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

    // --- Empty content blocks ---

    public function test_returns_empty_string_when_no_matching_content_block(): void
    {
        // A text response body but we're using a schema prompt — no tool_use block
        $history = [];
        $provider = $this->makeProvider(
            [new Response(200, [], $this->textResponseBody('oops'))],
            $history,
        );

        // Schema prompt expects tool_use — text block is ignored → empty string
        $result = $provider->complete($this->makeSchemaPrompt());

        $this->assertSame('', $result->content);
    }
}
