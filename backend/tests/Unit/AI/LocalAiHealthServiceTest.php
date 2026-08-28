<?php

namespace Tests\Unit\AI;

use App\AI\Health\LocalAiHealthService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class LocalAiHealthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.ollama.base_url', 'http://127.0.0.1:11434');
        config()->set('services.ollama.model', 'qwen3:14b');
    }

    private function service(array $responses): LocalAiHealthService
    {
        $http = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);

        return new LocalAiHealthService($http);
    }

    public function test_reports_ok_when_reachable_and_model_present(): void
    {
        $report = $this->service([
            new Response(200, [], json_encode([
                'models' => [['name' => 'qwen3:14b'], ['name' => 'nomic-embed-text:latest']],
            ], JSON_THROW_ON_ERROR)),
        ])->check();

        $this->assertSame('ok', $report['status']);
        $this->assertSame('qwen3:14b', $report['model']);
        $this->assertContains('qwen3:14b', $report['available_models']);
        $this->assertNull($report['error']);
        $this->assertIsInt($report['latency_ms']);
    }

    public function test_reports_model_missing_when_reachable_but_model_absent(): void
    {
        $report = $this->service([
            new Response(200, [], json_encode(['models' => [['name' => 'llama3:8b']]], JSON_THROW_ON_ERROR)),
        ])->check();

        $this->assertSame('model_missing', $report['status']);
        $this->assertStringContainsString('qwen3:14b', (string) $report['error']);
        $this->assertFalse((new LocalAiHealthService(
            new Client(['handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], json_encode(['models' => [['name' => 'llama3:8b']]], JSON_THROW_ON_ERROR)),
            ]))])
        ))->isHealthy());
    }

    public function test_reports_unreachable_when_the_service_is_down(): void
    {
        $report = $this->service([
            new ConnectException('Connection refused', new Request('GET', 'http://127.0.0.1:11434/api/tags')),
        ])->check();

        $this->assertSame('unreachable', $report['status']);
        $this->assertNull($report['latency_ms']);
        $this->assertStringContainsString('Connection refused', (string) $report['error']);
    }

    public function test_accepts_a_tagged_variant_of_the_configured_model(): void
    {
        config()->set('services.ollama.model', 'qwen3');

        $report = $this->service([
            new Response(200, [], json_encode(['models' => [['name' => 'qwen3:14b']]], JSON_THROW_ON_ERROR)),
        ])->check();

        $this->assertSame('ok', $report['status']);
    }
}
