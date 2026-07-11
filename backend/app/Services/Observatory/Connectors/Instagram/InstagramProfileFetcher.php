<?php

namespace App\Services\Observatory\Connectors\Instagram;

use App\Services\Observatory\Connectors\Instagram\Exceptions\InstagramApiException;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Fetches a single, current profile snapshot for a connected Instagram
 * account via the Instagram Graph API. Deliberately limited to the beta
 * scope: one request, one account, no media/posts, no historical import.
 */
class InstagramProfileFetcher
{
    private const FIELDS = 'id,username,name,biography,website,profile_picture_url,followers_count,follows_count';

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
     * @throws InstagramApiException if the request fails or the response is
     *                               missing the fields a snapshot requires
     */
    public function fetchProfile(string $accessToken): InstagramProfileData
    {
        try {
            $response = $this->http->get("{$this->baseUrl}/me", [
                'query' => [
                    'fields' => self::FIELDS,
                    'access_token' => $accessToken,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new InstagramApiException(
                "Instagram Graph API request failed: {$e->getMessage()}",
                previous: $e,
            );
        }

        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data) || empty($data['id']) || empty($data['username'])) {
            throw new InstagramApiException(
                'Instagram Graph API returned a response missing the required id/username fields.',
            );
        }

        return new InstagramProfileData(
            accountId: (string) $data['id'],
            username: (string) $data['username'],
            displayName: isset($data['name']) ? (string) $data['name'] : null,
            profilePictureUrl: isset($data['profile_picture_url']) ? (string) $data['profile_picture_url'] : null,
            bio: isset($data['biography']) ? (string) $data['biography'] : null,
            website: isset($data['website']) ? (string) $data['website'] : null,
            followerCount: isset($data['followers_count']) ? (int) $data['followers_count'] : null,
            followingCount: isset($data['follows_count']) ? (int) $data['follows_count'] : null,
            fetchedAt: new DateTimeImmutable(),
        );
    }
}
