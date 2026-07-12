<?php

namespace App\Services\Analytics;

use App\Models\ChannelCredentials;
use App\Models\Execution;
use App\Services\Analytics\Contracts\AnalyticsProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Pulls post-level Insights data for Instagram/Facebook posts. Meta
 * finalises insights 28 days after publication (per Meta's own retention
 * window for post-level metrics) — after that, isWindowClosed() stops
 * RetrieveExecutionMetrics from re-polling forever.
 */
class MetaAnalyticsProvider implements AnalyticsProvider
{
    private const BASE_URL = 'https://graph.facebook.com/v19.0';

    private const WINDOW_DAYS = 28;

    /** Meta Insights field names this provider requests and maps. */
    private const INSIGHTS_METRICS = ['reach', 'impressions', 'engagement', 'clicks', 'saved', 'shares'];

    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['base_uri' => self::BASE_URL, 'timeout' => 30]);
    }

    /** @return array<string, mixed> */
    public function pull(string $platformId, ChannelCredentials $credentials): array
    {
        try {
            $response = $this->http->get("/{$platformId}/insights", [
                'query' => [
                    'metric' => implode(',', self::INSIGHTS_METRICS),
                    'access_token' => (string) $credentials->credentials,
                ],
            ]);

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $response->getBody(), true) ?? [];

            return $decoded;
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? (string) $e->getResponse()?->getBody() : $e->getMessage();

            return ['error' => $body];
        }
    }

    /**
     * Meta's Insights response shape is `{"data": [{"name": "reach", "values":
     * [{"value": 1234}]}, ...]}` — flatten each metric's latest value into
     * the standard normalised_* keys. Metrics Meta didn't return (a post
     * type that doesn't support "saved", for instance) are simply omitted,
     * per the contract — never defaulted to 0.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalize(array $raw): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = $raw['data'] ?? [];

        $values = [];
        foreach ($entries as $entry) {
            $name = $entry['name'] ?? null;
            /** @var list<array{value: mixed}> $entryValues */
            $entryValues = $entry['values'] ?? [];
            $value = $entryValues[0]['value'] ?? null;

            if ($name !== null && $value !== null) {
                $values[$name] = $value;
            }
        }

        $normalized = [];
        $map = [
            'reach' => 'normalised_reach',
            'engagement' => 'normalised_engagement',
            'clicks' => 'normalised_clicks',
        ];

        foreach ($map as $metaKey => $normalizedKey) {
            if (isset($values[$metaKey])) {
                $normalized[$normalizedKey] = (int) $values[$metaKey];
            }
        }

        return $normalized;
    }

    public function isWindowClosed(Execution $execution): bool
    {
        return $execution->completed_at?->addDays(self::WINDOW_DAYS)->isPast() ?? false;
    }

    public function pollingDelayHours(): int
    {
        return 6;
    }

    /**
     * Progressive backoff: check again at 18h, then 42h, then 90h after
     * publication, then weekly until the 28-day window closes — roughly the
     * 6h → 12h → 24h → 48h → 7d schedule Meta Insights data typically
     * stabilises on. Derived from elapsed time, not a stored poll count.
     */
    public function repollingIntervalHours(Execution $execution): int
    {
        $publishedAt = $execution->completed_at;

        if ($publishedAt === null) {
            return 6;
        }

        $elapsedHours = $publishedAt->diffInHours(now());

        return match (true) {
            $elapsedHours < 18 => 12,
            $elapsedHours < 42 => 24,
            $elapsedHours < 90 => 48,
            default => 168,
        };
    }

    public function supports(string $providerType): bool
    {
        return $providerType === 'meta';
    }
}
