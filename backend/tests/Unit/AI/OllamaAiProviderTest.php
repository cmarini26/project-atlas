<?php

namespace Tests\Unit\AI;

use App\AI\AiResponse;
use App\AI\Exceptions\LocalAiModelMissingException;
use App\AI\Exceptions\LocalAiOutOfMemoryException;
use App\AI\Exceptions\LocalAiUnavailableException;
use App\AI\Exceptions\RetryableAiException;
use App\AI\Prompts\FactExtractionPrompt;
use App\AI\Prompts\Prompt;
use App\AI\Providers\OllamaAiProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OllamaAiProviderTest extends TestCase
{
    public function test_maps_a_successful_chat_response_to_ai_response(): void
    {
        $provider = $this->makeProvider([
            new Response(200, [], json_encode([
                'model' => 'qwen3:14b',
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Hello from Ollama.',
                ],
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 17,
                'eval_count' => 6,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = $provider->complete($this->plainPrompt());

        $this->assertInstanceOf(AiResponse::class, $result);
        $this->assertSame('Hello from Ollama.', $result->content);
        $this->assertSame('qwen3:14b', $result->model);
        $this->assertSame(17, $result->inputTokens);
        $this->assertSame(6, $result->outputTokens);
        $this->assertSame('stop', $result->stopReason);
    }

    public function test_sends_prompt_and_generation_settings_to_chat_api(): void
    {
        $history = [];
        $provider = $this->makeProvider([
            new Response(200, [], json_encode([
                'model' => 'qwen3:14b',
                'message' => ['role' => 'assistant', 'content' => 'Hello.'],
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 10,
                'eval_count' => 2,
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $provider->complete($this->plainPrompt());

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('/api/chat', $request->getUri()->getPath());
        $this->assertSame('qwen3:14b', $body['model']);
        $this->assertSame([
            ['role' => 'system', 'content' => 'You are concise.'],
            ['role' => 'user', 'content' => 'Say hello.'],
        ], $body['messages']);
        $this->assertFalse($body['stream']);
        $this->assertFalse($body['think']);
        $this->assertSame([
            'temperature' => 0.2,
            'num_predict' => 2048,
            'num_ctx' => 8192,
        ], $body['options']);
        $this->assertArrayNotHasKey('format', $body);
    }

    public function test_uses_laravel_ollama_configuration_defaults(): void
    {
        config()->set('services.ollama', [
            'base_url' => 'http://127.0.0.1:11555',
            'model' => 'configured-model',
            'context_length' => 4096,
            'think' => true,
        ]);

        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'model' => 'configured-model',
                'message' => ['role' => 'assistant', 'content' => 'Configured.'],
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 8,
                'eval_count' => 2,
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));

        $provider = new OllamaAiProvider(http: new Client(['handler' => $stack]));
        $provider->complete($this->plainPrompt());

        $request = $history[0]['request'];
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('http://127.0.0.1:11555/api/chat', (string) $request->getUri());
        $this->assertSame('configured-model', $body['model']);
        $this->assertSame(4096, $body['options']['num_ctx']);
        $this->assertTrue($body['think']);
    }

    #[DataProvider('unsafeBaseUrls')]
    public function test_rejects_non_loopback_base_urls(string $baseUrl): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OLLAMA_BASE_URL must use a loopback HTTP address.');

        new OllamaAiProvider(baseUrl: $baseUrl);
    }

    /** @return array<string, array{string}> */
    public static function unsafeBaseUrls(): array
    {
        return [
            'remote host' => ['http://ollama.example.com:11434'],
            'non-http scheme' => ['https://127.0.0.1:11434'],
            'embedded credentials' => ['http://user@127.0.0.1:11434'],
            'path' => ['http://127.0.0.1:11434/api'],
            'query' => ['http://127.0.0.1:11434?target=remote'],
            'fragment' => ['http://127.0.0.1:11434#remote'],
            'double IPv6 brackets' => ['http://[[::1]]:11434'],
            'extra closing IPv6 bracket' => ['http://[::1]]:11434'],
            'extra opening IPv6 bracket' => ['http://[[::1]:11434'],
            'rearranged IPv6 brackets' => ['http://[]::1[]:11434'],
        ];
    }

    #[DataProvider('loopbackBaseUrls')]
    public function test_accepts_bare_loopback_http_origins(string $baseUrl): void
    {
        $provider = new OllamaAiProvider(baseUrl: $baseUrl);

        $this->assertInstanceOf(OllamaAiProvider::class, $provider);
    }

    /** @return array<string, array{string}> */
    public static function loopbackBaseUrls(): array
    {
        return [
            'IPv4 loopback' => ['http://127.0.0.1:11434'],
            'localhost' => ['http://localhost:11434'],
            'IPv6 loopback' => ['http://[::1]:11434'],
        ];
    }

    public function test_rejects_blank_model_names(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OLLAMA_MODEL must be configured.');

        new OllamaAiProvider(model: '   ');
    }

    #[DataProvider('nonPositiveContextLengths')]
    public function test_rejects_non_positive_context_lengths(int $contextLength): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OLLAMA_CONTEXT_LENGTH must be greater than zero.');

        new OllamaAiProvider(contextLength: $contextLength);
    }

    /** @return array<string, array{int}> */
    public static function nonPositiveContextLengths(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    public function test_sends_prompt_json_schema_as_ollama_format(): void
    {
        $history = [];
        $provider = $this->makeProvider([
            new Response(200, [], json_encode([
                'model' => 'qwen3:14b',
                'message' => ['role' => 'assistant', 'content' => '{"name":"Atlas"}'],
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 12,
                'eval_count' => 4,
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $provider->complete($this->schemaPrompt());

        $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($this->schemaPrompt()->schema(), $body['format']);
    }

    public function test_forces_deterministic_generation_settings_for_schema_bound_prompts(): void
    {
        $history = [];
        $provider = $this->makeProvider([
            new Response(200, [], json_encode([
                'model' => 'qwen3:14b',
                'message' => ['role' => 'assistant', 'content' => '{"name":"Atlas"}'],
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 12,
                'eval_count' => 4,
            ], JSON_THROW_ON_ERROR)),
        ], $history, think: true);

        $provider->complete($this->schemaPrompt());

        $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($body['think']);
        $this->assertEquals(0.0, $body['options']['temperature']);
    }

    public function test_rejects_truncated_schema_bound_responses(): void
    {
        $provider = $this->makeProvider([
            new Response(200, [], json_encode([
                'model' => 'qwen3:14b',
                'message' => ['role' => 'assistant', 'content' => '{"name":"Atlas"}'],
                'done' => true,
                'done_reason' => 'length',
                'prompt_eval_count' => 12,
                'eval_count' => 2048,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ollama schema-bound response was truncated (done_reason=length).');

        $provider->complete($this->schemaPrompt());
    }

    public function test_rejects_schema_bound_content_that_does_not_match_the_prompt_schema(): void
    {
        $provider = $this->makeProvider([
            new Response(200, [], json_encode([
                'model' => 'qwen3:14b',
                'message' => ['role' => 'assistant', 'content' => '{"name":42}'],
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 12,
                'eval_count' => 4,
            ], JSON_THROW_ON_ERROR)),
        ]);

        try {
            $provider->complete($this->schemaPrompt());
            $this->fail('Schema-invalid content was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Ollama response failed JSON Schema validation', $exception->getMessage());
            $this->assertStringContainsString('/name', $exception->getMessage());
            $this->assertStringContainsString('type', $exception->getMessage());
            $this->assertStringNotContainsString('42', $exception->getMessage());
        }
    }

    public function test_rejects_malformed_json_for_schema_bound_responses(): void
    {
        $provider = $this->makeProvider([
            new Response(200, [], json_encode([
                'model' => 'qwen3:14b',
                'message' => ['role' => 'assistant', 'content' => '{"name":'],
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 12,
                'eval_count' => 4,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ollama schema-bound response is not valid JSON:');

        $provider->complete($this->schemaPrompt());
    }

    public function test_validates_nested_arrays_enums_and_bounds_from_real_atlas_schema(): void
    {
        $provider = $this->makeProvider([
            new Response(200, [], json_encode([
                'model' => 'qwen3:14b',
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'facts' => [[
                            'key' => 'business.name',
                            'value' => 'Atlas',
                            'data_type' => 'unsupported',
                            'confidence' => 101,
                        ]],
                    ], JSON_THROW_ON_ERROR),
                ],
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 20,
                'eval_count' => 12,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $prompt = new FactExtractionPrompt('https://example.com', 'Example', 'Example page.');

        try {
            $provider->complete($prompt);
            $this->fail('Schema-invalid fact extraction content was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('/facts/0/data_type', $exception->getMessage());
            $this->assertStringContainsString('enum', $exception->getMessage());
            $this->assertStringContainsString('/facts/0/confidence', $exception->getMessage());
            $this->assertStringContainsString('maximum', $exception->getMessage());
            $this->assertStringNotContainsString('unsupported', $exception->getMessage());
        }
    }

    public function test_model_not_found_is_a_non_retryable_model_missing_error(): void
    {
        $history = [];
        $provider = $this->makeProvider([
            new Response(404, [], json_encode(['error' => 'model "qwen3:14b" not found, try pulling it first'], JSON_THROW_ON_ERROR)),
        ], $history);

        try {
            $provider->complete($this->plainPrompt());
            $this->fail('Expected LocalAiModelMissingException.');
        } catch (LocalAiModelMissingException $e) {
            $this->assertSame('model_missing', $e->category);
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('ollama pull qwen3:14b', $e->guidance);
        }

        $this->assertCount(1, $history, 'A missing model must not be retried.');
    }

    public function test_out_of_memory_is_a_non_retryable_error(): void
    {
        $history = [];
        $provider = $this->makeProvider([
            new Response(500, [], json_encode(['error' => 'model requires more system memory than is available'], JSON_THROW_ON_ERROR)),
        ], $history);

        $this->expectException(LocalAiOutOfMemoryException::class);

        try {
            $provider->complete($this->plainPrompt());
        } finally {
            $this->assertCount(1, $history, 'An out-of-memory error must not be retried.');
        }
    }

    public function test_retries_a_transient_server_error_then_succeeds(): void
    {
        $history = [];
        $provider = $this->makeProvider([
            new Response(503, [], json_encode(['error' => 'server busy'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'model' => 'qwen3:14b',
                'message' => ['role' => 'assistant', 'content' => 'Recovered.'],
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 5,
                'eval_count' => 1,
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $result = $provider->complete($this->plainPrompt());

        $this->assertSame('Recovered.', $result->content);
        $this->assertCount(2, $history);
    }

    public function test_persistent_transient_failure_throws_bounded_retryable_error(): void
    {
        $history = [];
        $provider = $this->makeProvider([
            new Response(503, [], 'busy'),
            new Response(503, [], 'busy'),
            new Response(503, [], 'busy'),
            new Response(503, [], 'busy'),
            new Response(503, [], 'busy'),
        ], $history);

        try {
            $provider->complete($this->plainPrompt());
            $this->fail('Expected LocalAiUnavailableException.');
        } catch (LocalAiUnavailableException $e) {
            $this->assertInstanceOf(RetryableAiException::class, $e);
            $this->assertTrue($e->retryable);
        }

        $this->assertCount(4, $history, 'Retries are bounded to 4 attempts total.');
    }

    public function test_connection_failures_are_retried_then_surfaced_as_unavailable(): void
    {
        $history = [];
        $provider = $this->makeProvider([
            new ConnectException('Connection refused', $this->request()),
            new ConnectException('Connection refused', $this->request()),
            new ConnectException('Connection refused', $this->request()),
            new ConnectException('Connection refused', $this->request()),
        ], $history);

        $this->expectException(LocalAiUnavailableException::class);

        try {
            $provider->complete($this->plainPrompt());
        } finally {
            $this->assertCount(4, $history);
        }
    }

    public function test_rejects_invalid_json_responses(): void
    {
        $provider = $this->makeProvider([
            new Response(200, [], '{invalid'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ollama API returned invalid JSON.');

        $provider->complete($this->plainPrompt());
    }

    #[DataProvider('malformedSuccessResponses')]
    public function test_rejects_malformed_success_responses(array $body): void
    {
        $provider = $this->makeProvider([
            new Response(200, [], json_encode($body, JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ollama API returned a malformed response.');

        $provider->complete($this->plainPrompt());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedSuccessResponses(): array
    {
        $valid = [
            'model' => 'qwen3:14b',
            'message' => ['role' => 'assistant', 'content' => 'Hello.'],
            'done' => true,
            'done_reason' => 'stop',
            'prompt_eval_count' => 10,
            'eval_count' => 2,
        ];

        return [
            'missing message and token metadata' => [[
                'model' => 'qwen3:14b',
                'done' => true,
            ]],
            'not finished' => [[...$valid, 'done' => false]],
            'negative input tokens' => [[...$valid, 'prompt_eval_count' => -1]],
            'negative output tokens' => [[...$valid, 'eval_count' => -1]],
            'non-integer input tokens' => [[...$valid, 'prompt_eval_count' => '10']],
            'non-integer output tokens' => [[...$valid, 'eval_count' => '2']],
            'non-string stop reason' => [[...$valid, 'done_reason' => ['stop']]],
        ];
    }

    /**
     * @param  Response[]  $responses
     * @param  array<int, mixed>  $history
     */
    private function makeProvider(array $responses, array &$history = [], bool $think = false): OllamaAiProvider
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $http = new Client(['handler' => $stack]);

        return new OllamaAiProvider(
            http: $http,
            model: 'qwen3:14b',
            baseUrl: 'http://127.0.0.1:11434',
            contextLength: 8192,
            think: $think,
            retryDelaysMs: [0, 0, 0],
        );
    }

    private function request(): Request
    {
        return new Request('POST', 'http://127.0.0.1:11434/api/chat');
    }

    private function plainPrompt(): Prompt
    {
        return new class() extends Prompt
        {
            public function system(): string
            {
                return 'You are concise.';
            }

            public function user(): string
            {
                return 'Say hello.';
            }
        };
    }

    private function schemaPrompt(): Prompt
    {
        return new class() extends Prompt
        {
            public function system(): string
            {
                return 'Extract structured data.';
            }

            public function user(): string
            {
                return 'Extract the name Atlas.';
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
}
