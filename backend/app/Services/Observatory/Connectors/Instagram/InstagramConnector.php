<?php

namespace App\Services\Observatory\Connectors\Instagram;

use App\Models\Integration;
use App\Services\Observatory\Connectors\ConnectorResult;
use App\Services\Observatory\Connectors\Contracts\Connector;
use App\Services\Observatory\Connectors\Instagram\Exceptions\InstagramApiException;
use Illuminate\Support\Collection;

/**
 * Milestone 12 Phase 1 — Instagram Observation (Beta). Fetches a single
 * current profile snapshot for the company's connected Instagram account —
 * no historical import, one account only.
 *
 * Milestone 12 Phase 2 — Instagram Content Intelligence. Also fetches a
 * configurable number of recent posts (default 20) alongside the profile
 * snapshot, recorded as a second, separately-typed Observation.
 */
class InstagramConnector implements Connector
{
    public function __construct(
        private readonly InstagramProfileFetcher $fetcher,
        private readonly InstagramMediaFetcher $mediaFetcher,
        private readonly int $mediaLimit = 20,
    ) {}

    public function supports(Integration $integration): bool
    {
        return $integration->type === 'instagram';
    }

    /** @return Collection<int, ConnectorResult> */
    public function sync(Integration $integration): Collection
    {
        $accessToken = (string) ($integration->config['access_token'] ?? '');

        if ($accessToken === '') {
            throw new InstagramApiException(
                "Integration {$integration->id} has no Instagram access token configured.",
            );
        }

        $profile = $this->fetcher->fetchProfile($accessToken);
        $media = $this->mediaFetcher->fetchRecentMedia($accessToken, $this->mediaLimit);

        return collect([
            new ConnectorResult(
                sourceType: 'social',
                sourceIdentifier: $profile->username,
                payload: json_encode($profile->toArray(), JSON_THROW_ON_ERROR),
                observedAt: $profile->fetchedAt,
            ),
            new ConnectorResult(
                sourceType: 'social_content',
                sourceIdentifier: "{$profile->username}-recent-media",
                payload: json_encode([
                    'posts' => $media->map(fn ($item) => $item->toArray())->all(),
                    'fetched_at' => $profile->fetchedAt->format('c'),
                    'media_limit' => $this->mediaLimit,
                ], JSON_THROW_ON_ERROR),
                observedAt: $profile->fetchedAt,
            ),
        ]);
    }
}
