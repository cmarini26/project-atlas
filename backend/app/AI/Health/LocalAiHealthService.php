<?php

namespace App\AI\Health;

use GuzzleHttp\Client;
use Throwable;

/**
 * Reachability + model-availability probe for the local Ollama service.
 * Used by the /api/ready check (only when Ollama is the active AI provider)
 * and by the `ai:local:health` console command.
 */
class LocalAiHealthService
{
    private Client $http;

    private string $baseUrl;

    private string $model;

    public function __construct(?Client $http = null)
    {
        $this->baseUrl = rtrim((string) config('services.ollama.base_url', 'http://127.0.0.1:11434'), '/');
        $this->model = trim((string) config('services.ollama.model', 'qwen3:14b'));

        $timeout = max(1, (int) config('ai.local.health_timeout_seconds', 3));

        $this->http = $http ?? new Client([
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
        ]);
    }

    /**
     * @return array{
     *   status: 'ok'|'model_missing'|'unreachable',
     *   base_url: string,
     *   model: string,
     *   available_models: list<string>,
     *   latency_ms: int|null,
     *   error: string|null,
     * }
     */
    public function check(): array
    {
        $startedAt = microtime(true);

        try {
            $response = $this->http->get($this->baseUrl.'/api/tags');
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        } catch (Throwable $e) {
            return $this->result('unreachable', [], null, $this->shorten($e->getMessage()));
        }

        $decoded = json_decode((string) $response->getBody(), true);
        $models = [];

        if (is_array($decoded) && is_array($decoded['models'] ?? null)) {
            foreach ($decoded['models'] as $entry) {
                if (is_array($entry) && is_string($entry['name'] ?? null)) {
                    $models[] = $entry['name'];
                }
            }
        }

        if (! $this->modelPresent($models)) {
            return $this->result('model_missing', $models, $latencyMs, "Configured model [{$this->model}] is not pulled on the host.");
        }

        return $this->result('ok', $models, $latencyMs, null);
    }

    public function isHealthy(): bool
    {
        return $this->check()['status'] === 'ok';
    }

    /**
     * @param  list<string>  $models
     */
    private function modelPresent(array $models): bool
    {
        if ($this->model === '') {
            return false;
        }

        foreach ($models as $name) {
            // Ollama reports tags as "qwen3:14b"; accept an untagged match too.
            if ($name === $this->model || str_starts_with($name, $this->model.':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $models
     * @return array{status: 'ok'|'model_missing'|'unreachable', base_url: string, model: string, available_models: list<string>, latency_ms: int|null, error: string|null}
     */
    private function result(string $status, array $models, ?int $latencyMs, ?string $error): array
    {
        return [
            'status' => $status,
            'base_url' => $this->baseUrl,
            'model' => $this->model,
            'available_models' => $models,
            'latency_ms' => $latencyMs,
            'error' => $error,
        ];
    }

    private function shorten(string $message): string
    {
        $message = trim($message);

        return mb_strlen($message) > 200 ? mb_substr($message, 0, 200).'…' : $message;
    }
}
