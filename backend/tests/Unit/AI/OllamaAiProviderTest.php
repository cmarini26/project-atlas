<?php

namespace Tests\Unit\AI;

use App\AI\AiResponse;
use App\AI\Prompts\Prompt;
use App\AI\Providers\OllamaAiProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
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

    public function test_wraps_ollama_http_errors_with_status_and_api_error(): void
    {
        $provider = $this->makeProvider([
            new Response(404, [], json_encode([
                'error' => 'model qwen3:14b not found',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ollama API request failed (HTTP 404): model qwen3:14b not found');

        $provider->complete($this->plainPrompt());
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
    private function makeProvider(array $responses, array &$history = []): OllamaAiProvider
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $http = new Client(['handler' => $stack]);

        return new OllamaAiProvider(
            http: $http,
            model: 'qwen3:14b',
            baseUrl: 'http://127.0.0.1:11434',
            contextLength: 8192,
            think: false,
        );
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
