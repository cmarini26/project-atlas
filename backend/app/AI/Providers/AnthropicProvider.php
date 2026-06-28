<?php

namespace App\AI\Providers;

use App\AI\AiResponse;
use App\AI\Contracts\AiProvider;
use App\AI\Prompts\Prompt;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
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

    private Client $http;

    private string $model;

    private string $apiKey;

    public function __construct(
        ?Client $http = null,
        ?string $apiKey = null,
        ?string $model = null,
        ?string $baseUrl = null,
    ) {
        $this->apiKey = $apiKey ?? (string) config('services.anthropic.api_key', '');
        $this->model = $model ?? (string) config('services.anthropic.model', self::DEFAULT_MODEL);
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
        try {
            $response = $this->http->post($path, [
                'json' => $payload,
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'content-type' => 'application/json',
                ],
            ]);
        } catch (RequestException $e) {
            $body = $e->hasResponse()
                ? (string) $e->getResponse()?->getBody()
                : $e->getMessage();
            throw new RuntimeException("Anthropic API request failed: {$body}", 0, $e);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function parseResponse(array $data, bool $expectToolUse): AiResponse
    {
        $model = (string) ($data['model'] ?? $this->model);
        $inputTokens = (int) ($data['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($data['usage']['output_tokens'] ?? 0);

        $content = '';

        foreach ((array) ($data['content'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? '';

            if ($expectToolUse && $type === 'tool_use') {
                // tool_use.input is already a decoded array — re-encode to JSON string
                // so callers receive a consistent JSON string from all providers.
                $content = json_encode($block['input'] ?? [], JSON_THROW_ON_ERROR);
                break;
            }

            if (! $expectToolUse && $type === 'text') {
                $content = (string) ($block['text'] ?? '');
                break;
            }
        }

        return new AiResponse(
            content: $content,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
        );
    }

    private function toolNameFor(Prompt $prompt): string
    {
        // Tool names must match ^[a-zA-Z0-9_]{1,64}$
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $prompt->name());

        return substr((string) $name, 0, 64);
    }
}
