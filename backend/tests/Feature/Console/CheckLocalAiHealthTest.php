<?php

namespace Tests\Feature\Console;

use App\AI\Health\LocalAiHealthService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class CheckLocalAiHealthTest extends TestCase
{
    private function bindHealth(array $responses): void
    {
        config()->set('services.ollama.base_url', 'http://127.0.0.1:11434');
        config()->set('services.ollama.model', 'qwen3:14b');

        $this->app->instance(LocalAiHealthService::class, new LocalAiHealthService(
            new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
        ));
    }

    public function test_it_succeeds_when_local_ai_is_healthy(): void
    {
        $this->bindHealth([
            new Response(200, [], json_encode(['models' => [['name' => 'qwen3:14b']]], JSON_THROW_ON_ERROR)),
        ]);

        $this->artisan('ai:local:health')
            ->assertExitCode(0)
            ->expectsOutputToContain('Local AI is healthy.');
    }

    public function test_it_fails_with_a_pull_hint_when_the_model_is_missing(): void
    {
        $this->bindHealth([
            new Response(200, [], json_encode(['models' => [['name' => 'llama3:8b']]], JSON_THROW_ON_ERROR)),
        ]);

        $this->artisan('ai:local:health')
            ->assertExitCode(1)
            ->expectsOutputToContain('ollama pull qwen3:14b');
    }

    public function test_it_fails_when_ollama_is_unreachable(): void
    {
        $this->bindHealth([
            new ConnectException('Connection refused', new Request('GET', 'http://127.0.0.1:11434/api/tags')),
        ]);

        $this->artisan('ai:local:health')
            ->assertExitCode(1)
            ->expectsOutputToContain('unreachable');
    }

    public function test_json_flag_emits_a_machine_readable_report(): void
    {
        $this->bindHealth([
            new Response(200, [], json_encode(['models' => [['name' => 'qwen3:14b']]], JSON_THROW_ON_ERROR)),
        ]);

        $this->artisan('ai:local:health --json')
            ->assertExitCode(0)
            ->expectsOutputToContain('"status": "ok"');
    }
}
