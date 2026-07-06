<?php

namespace App\AI\Providers;

use App\AI\AiResponse;
use App\AI\Contracts\AiProvider;
use App\AI\Exceptions\AiProviderOverloadedException;
use App\AI\Prompts\Prompt;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Anthropic Claude provider.
 *
 * Uses the Messages API with tool-use to enforce structured JSON output,
 * as Claude does not have a native JSON mode. When a prompt provides a schema,
 * a tool is defined with that schema and `tool_choice` is forced so Claude
 * always calls it — returning structured JSON in `tool_use.input`.
 *
 * Config keys (in config/services.php under 'anthropic'):
 *   - api_key  : ANTHROPIC_API_KEY
 *   - model    : ANTHROPIC_MODEL (default: claude-sonnet-4-6)
 *   - base_url : optional override (default: https://api.anthropic.com)
 */
final class AnthropicProvider implements AiProvider
{
    private const API_VERSION = '2023-06-01';

    private const DEFAULT_MODEL = 'claude-sonnet-4-6';

    private const DEFAULT_BASE_URL = 'https://api.anthropic.com';

    /**
     * Backoff delays (ms) between retries of transient overloaded_error
     * responses. Three retries = four attempts, ~5s worst-case wait — short
     * enough to run inline during the onboarding request.
     */
    private const DEFAULT_RETRY_DELAYS_MS = [500, 1500, 3000];

    private Client $http;

    private string $model;

    private string $apiKey;

    /** @var array<int, int> */
    private array $retryDelaysMs;

    /**
     * @param  array<int, int>|null  $retryDelaysMs
     */
    public function __construct(
        ?Client $http = null,
        ?string $apiKey = null,
        ?string $model = null,
        ?string $baseUrl = null,
        ?array $retryDelaysMs = null,
    ) {
        $this->apiKey = $apiKey ?? (string) config('services.anthropic.api_key', '');
        $this->model = $model ?? (string) config('services.anthropic.model', self::DEFAULT_MODEL);
        $this->retryDelaysMs = $retryDelaysMs ?? self::DEFAULT_RETRY_DELAYS_MS;
        $url = $baseUrl ?? (string) config('services.anthropic.base_url', self::DEFAULT_BASE_URL);

        $this->http = $http ?? new Client([
            'base_uri' => rtrim($url, '/'),
            'timeout' => 120,
            'connect_timeout' => 10,
        ]);
    }

    public function complete(Prompt $prompt): AiResponse
    {
        $schema = $prompt->schema();

        $payload = [
            'model' => $this->model,
            'max_tokens' => $prompt->maxTokens(),
            'temperature' => $prompt->temperature(),
            'system' => $prompt->system(),
            'messages' => [
                ['role' => 'user', 'content' => $prompt->user()],
            ],
        ];

        // When the prompt defines a JSON schema, use tool-use to enforce structured output.
        if ($schema !== null) {
            $toolName = $this->toolNameFor($prompt);

            $payload['tools'] = [
                [
                    'name' => $toolName,
                    'description' => 'Return the structured result for this task.',
                    'input_schema' => $schema,
                ],
            ];

            $payload['tool_choice'] = ['type' => 'tool', 'name' => $toolName];
        }

        $responseData = $this->post('/v1/messages', $payload);

        return $this->parseResponse($responseData, $schema !== null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $maxAttempts = count($this->retryDelaysMs) + 1;

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http->post($path, [
                    'json' => $payload,
                    'headers' => [
                        'x-api-key' => $this->apiKey,
                        'anthropic-version' => self::API_VERSION,
                        'content-type' => 'application/json',
                    ],
                ]);

                break;
            } catch (RequestException $e) {
                $requestId = $this->requestIdFrom($e);

                if (! $this->isOverloaded($e)) {
                    $body = $e->hasResponse()
                        ? (string) $e->getResponse()?->getBody()
                        : $e->getMessage();

                    Log::error('AnthropicProvider: API request failed.', [
                        'request_id' => $requestId,
                        'status' => $e->getResponse()?->getStatusCode(),
                    ]);

                    throw new RuntimeException(
                        'Anthropic API request failed'
                        .($requestId !== null ? " (request_id: {$requestId})" : '')
                        .": {$body}", 0, $e,
                    );
                }

                if ($attempt >= $maxAttempts) {
                    Log::error('AnthropicProvider: API overloaded, retries exhausted.', [
                        'attempts' => $attempt,
                        'request_id' => $requestId,
                    ]);

                    throw new AiProviderOverloadedException(
                        "Anthropic API is overloaded (overloaded_error) after {$attempt} attempts"
                        .($requestId !== null ? " (request_id: {$requestId})" : '').'.',
                        requestId: $requestId,
                    );
                }

                $delayMs = $this->retryDelaysMs[$attempt - 1];

                Log::warning('AnthropicProvider: API overloaded, retrying.', [
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'delay_ms' => $delayMs,
                    'request_id' => $requestId,
                ]);

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        $body = (string) $response->getBody();

        // Raw response logging for local/debug troubleshooting only — the body can
        // contain crawled page content and must not be logged in production.
        if (config('app.debug')) {
            Log::debug('AnthropicProvider: raw API response.', [
                'request_id' => $response->getHeaderLine('request-id') ?: null,
                'body' => $body,
            ]);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($body, true);

        return $data;
    }

    /**
     * Anthropic signals temporary capacity issues with HTTP 529 and an
     * error body of type "overloaded_error". Both are transient and safe
     * to retry.
     */
    private function isOverloaded(RequestException $e): bool
    {
        $response = $e->getResponse();

        if ($response === null) {
            return false;
        }

        if ($response->getStatusCode() === 529) {
            return true;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded)
            && (($decoded['error']['type'] ?? null) === 'overloaded_error');
    }

    private function requestIdFrom(RequestException $e): ?string
    {
        $requestId = $e->getResponse()?->getHeaderLine('request-id');

        return ($requestId !== null && $requestId !== '') ? $requestId : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function parseResponse(array $data, bool $expectToolUse): AiResponse
    {
        $model = (string) ($data['model'] ?? $this->model);
        $inputTokens = (int) ($data['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($data['usage']['output_tokens'] ?? 0);
        $stopReason = isset($data['stop_reason']) ? (string) $data['stop_reason'] : null;

        // When generation is cut off at max_tokens during a forced tool call, the
        // API cannot return the partial JSON input — tool_use.input comes back empty
        // or incomplete. Treating that as a valid (empty) result silently produces
        // zero facts downstream, so fail loudly instead.
        if ($expectToolUse && $stopReason === 'max_tokens') {
            throw new RuntimeException(
                'Anthropic response was truncated at max_tokens before the structured '
                ."output completed (output_tokens={$outputTokens}). Increase the prompt's maxTokens()."
            );
        }

        $content = '';
        $foundToolUse = false;

        foreach ((array) ($data['content'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? '';

            if ($expectToolUse && $type === 'tool_use') {
                // tool_use.input is already a decoded array — re-encode to JSON string
                // so callers receive a consistent JSON string from all providers.
                // Force an object so an empty input serializes as {} rather than [].
                $content = json_encode((object) ($block['input'] ?? []), JSON_THROW_ON_ERROR);
                $foundToolUse = true;
                break;
            }

            if (! $expectToolUse && $type === 'text') {
                $content = (string) ($block['text'] ?? '');
                break;
            }
        }

        // tool_choice is forced when a schema is set, so a missing tool_use block is
        // an abnormal response (refusal, filter, or API change) — not an empty result.
        if ($expectToolUse && ! $foundToolUse) {
            throw new RuntimeException(
                'Anthropic response contained no tool_use block despite forced tool_choice '
                ."(stop_reason={$stopReason})."
            );
        }

        return new AiResponse(
            content: $content,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            stopReason: $stopReason,
        );
    }

    private function toolNameFor(Prompt $prompt): string
    {
        // Tool names must match ^[a-zA-Z0-9_]{1,64}$
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $prompt->name());

        return substr((string) $name, 0, 64);
    }
}
