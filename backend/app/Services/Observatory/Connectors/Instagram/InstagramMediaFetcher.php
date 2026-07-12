<?php

namespace App\Services\Observatory\Connectors\Instagram;

use App\Services\Observatory\Connectors\Instagram\Exceptions\InstagramApiException;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Collection;

/**
 * Fetches a company's most recent Instagram posts via the Graph API —
 * Milestone 12 Phase 2 (Instagram Content Intelligence). Mirrors
 * InstagramProfileFetcher's shape and Guzzle-injection convention exactly.
 */
class InstagramMediaFetcher
{
    private const FIELDS = 'id,caption,timestamp,media_type,permalink,like_count,comments_count';

    private Client $http;

    public function __construct(
        private readonly string $baseUrl = 'https://graph.instagram.com',
        int $requestTimeout = 10,
        int $connectTimeout = 5,
        ?Client $client = null,
    ) {
        $this->http = $client ?? new Client([
            'timeout' => $requestTimeout,
            'connect_timeout' => $connectTimeout,
        ]);
    }

    /**
     * @return Collection<int, InstagramMediaItemData>
     *
     * @throws InstagramApiException if the request fails or the response is malformed
     */
    public function fetchRecentMedia(string $accessToken, int $limit = 20): Collection
    {
        try {
            $response = $this->http->get("{$this->baseUrl}/me/media", [
                'query' => [
                    'fields' => self::FIELDS,
                    'access_token' => $accessToken,
                    'limit' => $limit,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new InstagramApiException(
                "Instagram Graph API media request failed: {$e->getMessage()}",
                previous: $e,
            );
        }

        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data) || ! isset($data['data']) || ! is_array($data['data'])) {
            throw new InstagramApiException(
                'Instagram Graph API returned a media response missing the required data array.',
            );
        }

        return collect($data['data'])
            ->filter(fn ($item): bool => is_array($item) && ! empty($item['id']) && ! empty($item['timestamp']))
            ->map(fn (array $item): InstagramMediaItemData => $this->mapItem($item))
            ->values();
    }

    /** @param  array<string, mixed>  $item */
    private function mapItem(array $item): InstagramMediaItemData
    {
        $caption = isset($item['caption']) ? (string) $item['caption'] : '';

        return new InstagramMediaItemData(
            id: (string) $item['id'],
            caption: $caption !== '' ? $caption : null,
            timestamp: new DateTimeImmutable((string) $item['timestamp']),
            mediaType: isset($item['media_type']) ? (string) $item['media_type'] : 'UNKNOWN',
            permalink: isset($item['permalink']) ? (string) $item['permalink'] : null,
            likeCount: isset($item['like_count']) ? (int) $item['like_count'] : null,
            commentsCount: isset($item['comments_count']) ? (int) $item['comments_count'] : null,
            hashtags: InstagramMediaItemData::extractHashtags($caption),
            mentions: InstagramMediaItemData::extractMentions($caption),
        );
    }
}
