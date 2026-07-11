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
 * no media/posts, no historical import, one account only.
 */
class InstagramConnector implements Connector
{
    public function __construct(private readonly InstagramProfileFetcher $fetcher) {}

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

        return collect([new ConnectorResult(
            sourceType: 'social',
            sourceIdentifier: $profile->username,
            payload: json_encode($profile->toArray(), JSON_THROW_ON_ERROR),
            observedAt: $profile->fetchedAt,
        )]);
    }
}
