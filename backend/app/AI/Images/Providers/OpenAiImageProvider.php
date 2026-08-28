<?php

namespace App\AI\Images\Providers;

use App\AI\Images\Contracts\ImageProvider;
use App\AI\Images\Exceptions\ImageGenerationException;
use App\AI\Images\GeneratedImage;
use App\AI\Images\ImageGenerationRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpenAI Images API provider.
 *
 * Ships as the default because it is broadly available and well documented,
 * not because it is the only sensible choice — per-image pricing across
 * vendors spans an order of magnitude. The model, output quality, and the
 * per-image cost used for accounting are all config-driven so the choice is
 * reversible without code changes. See docs/technical/AI.md.
 *
 * Config (config/services.php → 'openai'):
 *   - api_key          : OPENAI_API_KEY
 *   - base_url          : optional override (default https://api.openai.com)
 *   - image.model       : OPENAI_IMAGE_MODEL   (default gpt-image-1)
 *   - image.quality     : OPENAI_IMAGE_QUALITY (default low — the cheapest tier)
 *   - image.cost_usd    : OPENAI_IMAGE_COST_USD list-price estimate per image
 */
final class OpenAiImageProvider implements ImageProvider
{
    private const IDENTIFIER = 'openai';

    private const DEFAULT_BASE_URL = 'https://api.openai.com';

    private const DEFAULT_MODEL = 'gpt-image-1';

    private const DEFAULT_QUALITY = 'low';

    /** Backoff between retries of transient failures. 3 retries = 4 attempts. */
    private const DEFAULT_RETRY_DELAYS_MS = [500, 1500, 3000];

    private Client $http;

    private string $apiKey;

    private string $model;

    private string $quality;

    private float $costPerImageUsd;

    /** @var array<int, int> */
    private array $retryDelaysMs;

    /**
     * @param  array<int, int>|null  $retryDelaysMs
     */
    public function __construct(
        ?Client $http = null,
        ?string $apiKey = null,
        ?string $model = null,
        ?string $quality = null,
        ?float $costPerImageUsd = null,
        ?string $baseUrl = null,
        ?array $retryDelaysMs = null,
    ) {
        $this->apiKey = $apiKey ?? (string) config('services.openai.api_key', '');
        $this->model = $model ?? (string) config('services.openai.image.model', self::DEFAULT_MODEL);
        $this->quality = $quality ?? (string) config('services.openai.image.quality', self::DEFAULT_QUALITY);
        $this->costPerImageUsd = $costPerImageUsd ?? (float) config('services.openai.image.cost_usd', 0.0);
        $this->retryDelaysMs = $retryDelaysMs ?? self::DEFAULT_RETRY_DELAYS_MS;

        $url = $baseUrl ?? (string) config('services.openai.base_url', self::DEFAULT_BASE_URL);

        $this->http = $http ?? new Client([
            'base_uri' => rtrim($url, '/'),
            'timeout' => 120,
            'connect_timeout' => 10,
        ]);
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function generate(ImageGenerationRequest $request): array
    {
        if (trim($this->apiKey) === '') {
            throw ImageGenerationException::configuration(self::IDENTIFIER, 'OPENAI_API_KEY is not set.');
        }

        $payload = [
            'model' => $this->model,
            'prompt' => $request->prompt,
            'n' => $request->count,
            'size' => $request->aspectRatio->pixelSize(),
        ];

        if (str_starts_with($this->model, 'gpt-image')) {
            $payload['quality'] = $this->quality;
        } else {
            // dall-e-* return a URL by default; ask for inline bytes instead.
            $payload['response_format'] = 'b64_json';
        }

        $startedAt = microtime(true);
        $data = $this->post('/v1/images/generations', $payload);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $images = $this->parse($data, $request, $latencyMs);

        Log::info('OpenAiImageProvider: generated images.', [
            'provider' => self::IDENTIFIER,
            'model' => $this->model,
            'quality' => $this->quality,
            'count' => count($images),
            'aspect_ratio' => $request->aspectRatio->value,
            'latency_ms' => $latencyMs,
            'cost_usd' => round($this->costPerImageUsd * count($images), 6),
        ]);

        return $images;
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
                        'Authorization' => 'Bearer '.$this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                ]);

                $decoded = json_decode((string) $response->getBody(), true);

                if (! is_array($decoded)) {
                    throw ImageGenerationException::failed(self::IDENTIFIER, 'response body was not valid JSON.');
                }

                return $decoded;
            } catch (ConnectException $e) {
                $this->retryOrFail($attempt, $maxAttempts, 'connection error', null, $e);
            } catch (RequestException $e) {
                $status = $e->getResponse()?->getStatusCode();

                if ($status !== null && ! $this->isRetryableStatus($status)) {
                    Log::error('OpenAiImageProvider: request failed.', [
                        'provider' => self::IDENTIFIER,
                        'status' => $status,
                        'error_type' => $this->errorTypeFrom($e),
                    ]);

                    throw ImageGenerationException::failed(
                        self::IDENTIFIER,
                        "the API returned HTTP {$status}.",
                        $e,
                    );
                }

                $this->retryOrFail($attempt, $maxAttempts, "HTTP {$status}", $status, $e);
            }
        }
    }

    private function retryOrFail(int $attempt, int $maxAttempts, string $reason, ?int $status, Throwable $previous): void
    {
        if ($attempt >= $maxAttempts) {
            Log::error('OpenAiImageProvider: transient failure, retries exhausted.', [
                'provider' => self::IDENTIFIER,
                'attempts' => $attempt,
                'status' => $status,
            ]);

            throw ImageGenerationException::transient(
                self::IDENTIFIER,
                "{$reason} after {$attempt} attempts.",
                $previous,
            );
        }

        $delayMs = $this->retryDelaysMs[$attempt - 1];

        Log::warning('OpenAiImageProvider: transient failure, retrying.', [
            'provider' => self::IDENTIFIER,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'delay_ms' => $delayMs,
            'status' => $status,
        ]);

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status === 529 || ($status >= 500 && $status < 600);
    }

    private function errorTypeFrom(RequestException $e): ?string
    {
        $decoded = json_decode((string) $e->getResponse()?->getBody(), true);

        return is_array($decoded) ? ($decoded['error']['type'] ?? $decoded['error']['code'] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<GeneratedImage>
     */
    private function parse(array $data, ImageGenerationRequest $request, int $latencyMs): array
    {
        $entries = $data['data'] ?? null;

        if (! is_array($entries) || $entries === []) {
            throw ImageGenerationException::failed(self::IDENTIFIER, 'the API returned no image data.');
        }

        $images = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $binary = $this->binaryFrom($entry);

            $images[] = new GeneratedImage(
                binary: $binary,
                mimeType: 'image/png',
                width: $request->aspectRatio->width(),
                height: $request->aspectRatio->height(),
                provider: self::IDENTIFIER,
                model: is_string($data['model'] ?? null) ? $data['model'] : $this->model,
                costUsd: $this->costPerImageUsd,
            );
        }

        if ($images === []) {
            throw ImageGenerationException::failed(self::IDENTIFIER, 'no usable image was returned.');
        }

        return $images;
    }

    /** @param array<string, mixed> $entry */
    private function binaryFrom(array $entry): string
    {
        if (is_string($entry['b64_json'] ?? null) && $entry['b64_json'] !== '') {
            $decoded = base64_decode($entry['b64_json'], true);

            if ($decoded === false || $decoded === '') {
                throw ImageGenerationException::failed(self::IDENTIFIER, 'returned image data was not decodable.');
            }

            return $decoded;
        }

        if (is_string($entry['url'] ?? null) && $entry['url'] !== '') {
            try {
                return (string) $this->http->get($entry['url'])->getBody();
            } catch (Throwable $e) {
                throw ImageGenerationException::transient(self::IDENTIFIER, 'could not download the generated image.', $e);
            }
        }

        throw ImageGenerationException::failed(self::IDENTIFIER, 'response entry contained neither inline data nor a URL.');
    }
}
