<?php

namespace App\AI\Providers;

use App\AI\AiResponse;
use App\AI\Contracts\AiProvider;
use App\AI\Exceptions\LocalAiException;
use App\AI\Exceptions\LocalAiInvalidResponseException;
use App\AI\Exceptions\LocalAiModelMissingException;
use App\AI\Exceptions\LocalAiOutOfMemoryException;
use App\AI\Exceptions\LocalAiUnavailableException;
use App\AI\Prompts\Prompt;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class OllamaAiProvider implements AiProvider
{
    /**
     * Backoff (ms) between retries of transient failures. Three retries = four
     * attempts. Kept short so a schema-bound fact-extraction call can retry
     * inline during onboarding without stalling the request.
     */
    private const DEFAULT_RETRY_DELAYS_MS = [500, 1500, 3000];

    private Client $http;

    private readonly string $baseUrl;

    private readonly string $model;

    private readonly int $contextLength;

    private readonly bool $think;

    private readonly Validator $schemaValidator;

    /** @var array<int, int> */
    private readonly array $retryDelaysMs;

    /**
     * @param  array<int, int>|null  $retryDelaysMs
     */
    public function __construct(
        ?Client $http = null,
        ?string $model = null,
        ?string $baseUrl = null,
        ?int $contextLength = null,
        ?bool $think = null,
        ?array $retryDelaysMs = null,
    ) {
        $configuredBaseUrl = $baseUrl ?? (string) config('services.ollama.base_url', 'http://127.0.0.1:11434');

        if (! $this->isLoopbackHttpUrl($configuredBaseUrl)) {
            throw new InvalidArgumentException('OLLAMA_BASE_URL must use a loopback HTTP address.');
        }

        $configuredModel = $model ?? (string) config('services.ollama.model', 'qwen3:14b');

        if (trim($configuredModel) === '') {
            throw new InvalidArgumentException('OLLAMA_MODEL must be configured.');
        }

        $configuredContextLength = $contextLength ?? (int) config('services.ollama.context_length', 8192);

        if ($configuredContextLength <= 0) {
            throw new InvalidArgumentException('OLLAMA_CONTEXT_LENGTH must be greater than zero.');
        }

        $this->baseUrl = rtrim($configuredBaseUrl, '/');
        $this->model = trim($configuredModel);
        $this->contextLength = $configuredContextLength;
        $this->think = $think ?? (bool) config('services.ollama.think', false);
        $this->schemaValidator = new Validator(null, 10, false);
        $this->retryDelaysMs = $retryDelaysMs ?? self::DEFAULT_RETRY_DELAYS_MS;

        $this->http = $http ?? new Client([
            'timeout' => 120,
            'connect_timeout' => 10,
        ]);
    }

    public function complete(Prompt $prompt): AiResponse
    {
        $schema = $prompt->schema();
        $schemaBound = $schema !== null;

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $prompt->system()],
                ['role' => 'user', 'content' => $prompt->user()],
            ],
            'stream' => false,
            'think' => $schemaBound ? false : $this->think,
            'options' => [
                'temperature' => $schemaBound ? 0.0 : $prompt->temperature(),
                'num_predict' => $prompt->maxTokens(),
                'num_ctx' => $this->contextLength,
            ],
        ];

        if ($schemaBound) {
            $payload['format'] = $schema;
        }

        $startedAt = microtime(true);

        try {
            [$response, $attempts] = $this->send($payload);
            $result = $this->parse($response, $schema);
        } catch (LocalAiException $e) {
            Log::warning('OllamaAiProvider: completion failed.', [
                'provider' => 'ollama',
                'model' => $this->model,
                'schema_bound' => $schemaBound,
                'failure_category' => $e->category,
                'retryable' => $e->retryable,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw $e;
        }

        Log::info('OllamaAiProvider: completion succeeded.', [
            'provider' => 'ollama',
            'model' => $result->model,
            'schema_bound' => $schemaBound,
            'attempts' => $attempts,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'stop_reason' => $result->stopReason,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }

    /**
     * POST the chat request, retrying transient failures with bounded backoff.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: ResponseInterface, 1: int} the response and the attempt count
     */
    private function send(array $payload): array
    {
        $maxAttempts = count($this->retryDelaysMs) + 1;

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http->post($this->baseUrl.'/api/chat', ['json' => $payload]);

                return [$response, $attempt];
            } catch (ConnectException $e) {
                $this->retryOrFail($attempt, $maxAttempts, 'connection to Ollama failed', $e);
            } catch (RequestException $e) {
                $response = $e->getResponse();
                $status = $response?->getStatusCode();
                $body = $response !== null ? (string) $response->getBody() : '';
                $apiError = $this->apiErrorFrom($body) ?? 'request failed';

                // Permanent, operator-actionable conditions — never retried.
                if ($this->looksLikeModelMissing($status, $apiError)) {
                    throw new LocalAiModelMissingException($this->model, $apiError, $e);
                }

                if ($this->looksLikeOutOfMemory($apiError)) {
                    throw new LocalAiOutOfMemoryException($apiError, $e);
                }

                // 5xx / explicit unavailable — transient, retry with backoff.
                if ($status === null || $status >= 500) {
                    $this->retryOrFail(
                        $attempt,
                        $maxAttempts,
                        sprintf('Ollama returned HTTP %s: %s', $status ?? 'error', $apiError),
                        $e,
                    );

                    continue;
                }

                // Other 4xx — a bad request Atlas built; not retryable.
                throw new LocalAiInvalidResponseException(
                    sprintf('Ollama API rejected the request (HTTP %d): %s', $status, $apiError),
                    $e,
                );
            }
        }
    }

    private function retryOrFail(int $attempt, int $maxAttempts, string $reason, Throwable $previous): void
    {
        if ($attempt >= $maxAttempts) {
            Log::error('OllamaAiProvider: transient failure, retries exhausted.', [
                'provider' => 'ollama',
                'model' => $this->model,
                'attempts' => $attempt,
                'failure_category' => 'unavailable',
            ]);

            throw new LocalAiUnavailableException(
                sprintf('%s (after %d attempts).', $reason, $attempt),
                $previous,
            );
        }

        $delayMs = $this->retryDelaysMs[$attempt - 1];

        Log::warning('OllamaAiProvider: transient failure, retrying.', [
            'provider' => 'ollama',
            'model' => $this->model,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'delay_ms' => $delayMs,
        ]);

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    /**
     * @param  array<string, mixed>|null  $schema
     */
    private function parse(ResponseInterface $response, ?array $schema): AiResponse
    {
        $raw = (string) $response->getBody();

        // Debug-only raw body logging (local troubleshooting). The body can
        // contain crawled page content and must never be logged in production.
        if (config('app.debug')) {
            Log::debug('OllamaAiProvider: raw API response.', ['body' => $raw]);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LocalAiInvalidResponseException('Ollama API returned invalid JSON.', $exception);
        }

        if (
            ! is_array($data)
            || ($data['done'] ?? null) !== true
            || ! is_string($data['model'] ?? null)
            || ! is_array($data['message'] ?? null)
            || ! is_string($data['message']['content'] ?? null)
            || ! is_int($data['prompt_eval_count'] ?? null)
            || $data['prompt_eval_count'] < 0
            || ! is_int($data['eval_count'] ?? null)
            || $data['eval_count'] < 0
            || (isset($data['done_reason']) && ! is_string($data['done_reason']))
        ) {
            throw new LocalAiInvalidResponseException('Ollama API returned a malformed response.');
        }

        if ($schema !== null && ($data['done_reason'] ?? null) === 'length') {
            throw new LocalAiInvalidResponseException('Ollama schema-bound response was truncated (done_reason=length).');
        }

        if ($schema !== null) {
            $this->validateSchemaBoundContent($data['message']['content'], $schema);
        }

        return new AiResponse(
            content: $data['message']['content'],
            model: $data['model'],
            inputTokens: $data['prompt_eval_count'],
            outputTokens: $data['eval_count'],
            stopReason: $data['done_reason'] ?? null,
        );
    }

    private function apiErrorFrom(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded) && is_string($decoded['error'] ?? null) && $decoded['error'] !== '') {
            return $decoded['error'];
        }

        return null;
    }

    private function looksLikeModelMissing(?int $status, string $apiError): bool
    {
        $error = strtolower($apiError);

        if (str_contains($error, 'try pulling it first') || str_contains($error, 'no such model')) {
            return true;
        }

        return $status === 404
            && str_contains($error, 'model')
            && str_contains($error, 'not found');
    }

    private function looksLikeOutOfMemory(string $apiError): bool
    {
        $error = strtolower($apiError);

        return str_contains($error, 'out of memory')
            || str_contains($error, 'requires more system memory')
            || str_contains($error, 'cudamalloc')
            || str_contains($error, 'failed to allocate');
    }

    /** @param array<string, mixed> $schema */
    private function validateSchemaBoundContent(string $content, array $schema): void
    {
        try {
            $data = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
            $schemaObject = json_decode(
                json_encode($schema, JSON_THROW_ON_ERROR),
                false,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new LocalAiInvalidResponseException(
                'Ollama schema-bound response is not valid JSON: '.$exception->getMessage(),
                $exception,
            );
        }

        $result = $this->schemaValidator->validate($data, $schemaObject);

        if ($result->isValid()) {
            return;
        }

        $error = $result->error();

        if ($error === null) {
            throw new LocalAiInvalidResponseException('Ollama response failed JSON Schema validation.');
        }

        $details = (new ErrorFormatter())->formatKeyed(
            $error,
            static fn (ValidationError $validationError): string => $validationError->keyword(),
        );

        throw new LocalAiInvalidResponseException(sprintf(
            'Ollama response failed JSON Schema validation: %s',
            json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ));
    }

    private function isLoopbackHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = $parts['path'] ?? '';

        return ($parts['scheme'] ?? null) === 'http'
            && in_array($host, ['127.0.0.1', 'localhost', '[::1]'], strict: true)
            && ($path === '' || $path === '/')
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }
}
