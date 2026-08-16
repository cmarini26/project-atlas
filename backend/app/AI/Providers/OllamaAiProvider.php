<?php

namespace App\AI\Providers;

use App\AI\AiResponse;
use App\AI\Contracts\AiProvider;
use App\AI\Prompts\Prompt;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class OllamaAiProvider implements AiProvider
{
    private Client $http;

    private readonly string $baseUrl;

    private readonly string $model;

    private readonly int $contextLength;

    private readonly bool $think;

    private readonly Validator $schemaValidator;

    public function __construct(
        ?Client $http = null,
        ?string $model = null,
        ?string $baseUrl = null,
        ?int $contextLength = null,
        ?bool $think = null,
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

        $this->http = $http ?? new Client([
            'timeout' => 120,
            'connect_timeout' => 10,
        ]);
    }

    public function complete(Prompt $prompt): AiResponse
    {
        $schema = $prompt->schema();

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $prompt->system()],
                ['role' => 'user', 'content' => $prompt->user()],
            ],
            'stream' => false,
            'think' => $schema === null ? $this->think : false,
            'options' => [
                'temperature' => $schema === null ? $prompt->temperature() : 0.0,
                'num_predict' => $prompt->maxTokens(),
                'num_ctx' => $this->contextLength,
            ],
        ];

        if ($schema !== null) {
            $payload['format'] = $schema;
        }

        try {
            $response = $this->http->post($this->baseUrl.'/api/chat', ['json' => $payload]);
        } catch (RequestException $exception) {
            $response = $exception->getResponse();
            $status = $response?->getStatusCode();
            $detail = 'request failed';

            if ($response !== null) {
                $error = json_decode((string) $response->getBody(), true);

                if (is_array($error) && is_string($error['error'] ?? null)) {
                    $detail = $error['error'];
                }
            }

            $statusText = $status === null ? '' : sprintf(' (HTTP %d)', $status);

            throw new RuntimeException(
                sprintf('Ollama API request failed%s: %s', $statusText, $detail),
                previous: $exception,
            );
        }

        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Ollama API returned invalid JSON.', previous: $exception);
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
            throw new RuntimeException('Ollama API returned a malformed response.');
        }

        if ($schema !== null && ($data['done_reason'] ?? null) === 'length') {
            throw new RuntimeException('Ollama schema-bound response was truncated (done_reason=length).');
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
            throw new RuntimeException(
                'Ollama schema-bound response is not valid JSON: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $result = $this->schemaValidator->validate($data, $schemaObject);

        if ($result->isValid()) {
            return;
        }

        $error = $result->error();

        if ($error === null) {
            throw new RuntimeException('Ollama response failed JSON Schema validation.');
        }

        $details = (new ErrorFormatter())->formatKeyed(
            $error,
            static fn (ValidationError $validationError): string => $validationError->keyword(),
        );

        throw new RuntimeException(sprintf(
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
