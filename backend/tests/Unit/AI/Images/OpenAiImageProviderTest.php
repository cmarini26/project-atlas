<?php

namespace Tests\Unit\AI\Images;

use App\AI\Images\Exceptions\ImageGenerationException;
use App\AI\Images\ImageGenerationRequest;
use App\AI\Images\Providers\OpenAiImageProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class OpenAiImageProviderTest extends TestCase
{
    /**
     * @param  list<mixed>  $responses
     * @param  array<int, mixed>  $history
     */
    private function makeProvider(array $responses, array &$history = [], ?array $retryDelaysMs = null): OpenAiImageProvider
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new OpenAiImageProvider(
            http: new Client(['handler' => $stack]),
            apiKey: 'test-key',
            model: 'gpt-image-1',
            quality: 'low',
            costPerImageUsd: 0.011,
            retryDelaysMs: $retryDelaysMs ?? [0, 0, 0],
        );
    }

    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+P+/HgAFhAJ/wlseKgAAAABJRU5ErkJggg==',
            true,
        );
    }

    public function test_it_maps_a_successful_response_to_a_generated_image(): void
    {
        $body = json_encode([
            'model' => 'gpt-image-1',
            'data' => [['b64_json' => base64_encode($this->pngBytes())]],
        ]);

        $history = [];
        $provider = $this->makeProvider([new Response(200, [], $body)], $history);

        $images = $provider->generate(new ImageGenerationRequest('a bright storefront'));

        $this->assertCount(1, $images);
        $this->assertSame($this->pngBytes(), $images[0]->binary);
        $this->assertSame('openai', $images[0]->provider);
        $this->assertSame('gpt-image-1', $images[0]->model);
        $this->assertSame(0.011, $images[0]->costUsd);

        $sent = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('1024x1024', $sent['size']);
        $this->assertSame('low', $sent['quality']);
        $this->assertSame('Bearer test-key', $history[0]['request']->getHeaderLine('Authorization'));
    }

    public function test_it_retries_a_transient_error_then_succeeds(): void
    {
        $ok = json_encode(['data' => [['b64_json' => base64_encode($this->pngBytes())]]]);

        $history = [];
        $provider = $this->makeProvider([
            new Response(429, [], '{"error":{"type":"rate_limit_exceeded"}}'),
            new Response(200, [], $ok),
        ], $history);

        $images = $provider->generate(new ImageGenerationRequest('x'));

        $this->assertCount(1, $images);
        $this->assertCount(2, $history, 'Expected one retry before success.');
    }

    public function test_persistent_server_error_throws_provider_agnostic_exception_without_leaking_body(): void
    {
        $provider = $this->makeProvider([
            new Response(500, [], '{"error":{"message":"internal vendor detail"}}'),
            new Response(500, [], '{"error":{"message":"internal vendor detail"}}'),
            new Response(500, [], '{"error":{"message":"internal vendor detail"}}'),
            new Response(500, [], '{"error":{"message":"internal vendor detail"}}'),
        ]);

        try {
            $provider->generate(new ImageGenerationRequest('x'));
            $this->fail('Expected ImageGenerationException.');
        } catch (ImageGenerationException $e) {
            $this->assertSame('openai', $e->provider);
            $this->assertTrue($e->retryable);
            $this->assertStringNotContainsString('internal vendor detail', $e->getMessage());
        }
    }

    public function test_client_error_is_not_retried(): void
    {
        $history = [];
        $provider = $this->makeProvider([
            new Response(400, [], '{"error":{"type":"invalid_request_error"}}'),
        ], $history);

        $this->expectException(ImageGenerationException::class);

        try {
            $provider->generate(new ImageGenerationRequest('x'));
        } finally {
            $this->assertCount(1, $history, 'A 400 must not be retried.');
        }
    }

    public function test_missing_api_key_is_a_configuration_error(): void
    {
        $provider = new OpenAiImageProvider(
            http: new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
            apiKey: '',
        );

        try {
            $provider->generate(new ImageGenerationRequest('x'));
            $this->fail('Expected ImageGenerationException.');
        } catch (ImageGenerationException $e) {
            $this->assertFalse($e->retryable);
        }
    }
}
