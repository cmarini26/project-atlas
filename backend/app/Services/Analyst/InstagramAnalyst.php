<?php

namespace App\Services\Analyst;

use App\Models\Integration;
use App\Models\Observation;
use App\Services\Analyst\Contracts\ObservationAnalyst;
use App\Services\Analyst\Exceptions\FactExtractionFailedException;
use App\Services\Brain\Data\FactData;
use App\Services\Observatory\InstagramAccountService;
use Illuminate\Support\Collection;

/**
 * Turns an Instagram profile-snapshot Observation into Facts. Deliberately
 * NOT an AI-calling Analyst (does not implement the Analyst marker
 * interface, unlike WebsiteAnalyst) — an Instagram profile snapshot already
 * arrives as structured, typed fields (username, follower count, bio, ...),
 * so mapping it into Facts is deterministic key/value translation, not
 * extraction from unstructured prose. The same reasoning already used for
 * MarketingPresenceSynthesizer (deterministic string composition, not a
 * probabilistic model) applies here. This class also keeps the typed
 * InstagramAccount snapshot in sync as a side effect of processing the same
 * payload — see InstagramAccountService.
 */
class InstagramAnalyst implements ObservationAnalyst
{
    public function __construct(private readonly InstagramAccountService $accounts) {}

    public function supports(Observation $observation): bool
    {
        return $observation->source_type === 'social';
    }

    /** @return Collection<int, FactData> */
    public function analyze(Observation $observation): Collection
    {
        $payload = json_decode((string) $observation->raw_payload, true);

        if (! is_array($payload) || empty($payload['username'])) {
            throw new FactExtractionFailedException(
                "Instagram observation {$observation->id} payload is missing the required username field.",
            );
        }

        $integration = Integration::withoutGlobalScopes()->find($observation->integration_id);

        if ($integration !== null) {
            $this->accounts->syncSnapshot($integration, $payload);
        }

        return collect(array_filter([
            $this->fact('instagram.username', $payload['username'], 'string', 100),
            $this->fact('instagram.display_name', $payload['display_name'] ?? null, 'string', 90),
            $this->fact('instagram.bio', $payload['bio'] ?? null, 'string', 90),
            $this->fact('instagram.website', $payload['website'] ?? null, 'string', 85),
            $this->fact('instagram.follower_count', $payload['follower_count'] ?? null, 'integer', 95),
            $this->fact('instagram.following_count', $payload['following_count'] ?? null, 'integer', 95),
        ]))->values();
    }

    private function fact(string $key, mixed $value, string $dataType, int $confidence): ?FactData
    {
        if ($value === null || $value === '') {
            return null;
        }

        return new FactData(key: $key, value: $value, dataType: $dataType, confidence: $confidence);
    }
}
