<?php

namespace App\Services\Analyst;

use App\Models\Integration;
use App\Models\Observation;
use App\Services\Analyst\Contracts\ObservationAnalyst;
use App\Services\Analyst\Exceptions\FactExtractionFailedException;
use App\Services\Brain\Data\FactData;
use App\Services\Observatory\InstagramAccountService;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * Turns Instagram Observations into Facts. Deliberately NOT an AI-calling
 * Analyst (does not implement the Analyst marker interface, unlike
 * WebsiteAnalyst) — Instagram data already arrives as structured, typed
 * fields, so mapping it into Facts is deterministic translation, not
 * extraction from unstructured prose. The same reasoning already used for
 * MarketingPresenceSynthesizer (deterministic string composition, not a
 * probabilistic model) applies here.
 *
 * Handles two Observation shapes, both under the same `instagram.*` Fact
 * namespace:
 * - Milestone 12 Phase 1: `source_type: 'social'` — a single profile
 *   snapshot. Also keeps the typed InstagramAccount row in sync as a side
 *   effect — see InstagramAccountService.
 * - Milestone 12 Phase 2: `source_type: 'social_content'` — the account's
 *   recent posts, from which posting cadence, media mix, hashtag usage,
 *   CTA usage, content distribution, and (where available) engagement
 *   trend are computed deterministically.
 */
class InstagramAnalyst implements ObservationAnalyst
{
    /**
     * Deliberately simple substring matching — a real caption-language model
     * is out of scope for a deterministic analyst. Not exhaustive; a
     * reasonable starting set to revisit once real caption data surfaces
     * phrasing this list misses.
     */
    private const CTA_PHRASES = [
        'link in bio', 'shop now', 'dm us', 'swipe up', 'comment below',
        'shop the link', 'tap the link', 'order now', 'book now', 'sign up',
        'click the link', 'message us',
    ];

    public function __construct(private readonly InstagramAccountService $accounts) {}

    public function supports(Observation $observation): bool
    {
        return in_array($observation->source_type, ['social', 'social_content'], true);
    }

    /** @return Collection<int, FactData> */
    public function analyze(Observation $observation): Collection
    {
        if ($observation->source_type === 'social_content') {
            return $this->analyzeContent($observation);
        }

        return $this->analyzeProfile($observation);
    }

    /** @return Collection<int, FactData> */
    private function analyzeProfile(Observation $observation): Collection
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

    /** @return Collection<int, FactData> */
    private function analyzeContent(Observation $observation): Collection
    {
        $payload = json_decode((string) $observation->raw_payload, true);

        if (! is_array($payload) || ! isset($payload['posts']) || ! is_array($payload['posts'])) {
            throw new FactExtractionFailedException(
                "Instagram content observation {$observation->id} payload is missing the required posts array.",
            );
        }

        $posts = $payload['posts'];

        // No recent posts is a valid, real state (the account just hasn't
        // posted) — not malformed data, so no facts and no error.
        if ($posts === []) {
            return collect();
        }

        $timestamps = array_map(fn (array $p): DateTimeImmutable => new DateTimeImmutable((string) $p['timestamp']), $posts);

        return collect(array_filter([
            $this->postingCadenceFact($timestamps),
            $this->fact('instagram.media_mix', $this->mediaMix($posts), 'json', 100),
            $this->fact('instagram.hashtag_usage', $this->hashtagUsage($posts), 'json', 100),
            $this->fact('instagram.cta_usage', $this->ctaUsage($posts), 'float', 90),
            $this->fact('instagram.content_distribution', $this->contentDistribution($timestamps), 'json', 100),
            $this->engagementTrendFact($posts, $timestamps),
        ]))->values();
    }

    /** @param  DateTimeImmutable[]  $timestamps */
    private function postingCadenceFact(array $timestamps): ?FactData
    {
        if (count($timestamps) < 2) {
            return null;
        }

        sort($timestamps);
        $spanDays = ($timestamps[count($timestamps) - 1]->getTimestamp() - $timestamps[0]->getTimestamp()) / 86400;

        if ($spanDays < 1) {
            return null;
        }

        $postsPerWeek = round((count($timestamps) - 1) / $spanDays * 7, 2);

        return $this->fact('instagram.posting_cadence', $postsPerWeek, 'float', 90);
    }

    /**
     * @param  array<int, array<string, mixed>>  $posts
     * @return array<string, int>
     */
    private function mediaMix(array $posts): array
    {
        $mix = [];

        foreach ($posts as $post) {
            $type = (string) ($post['media_type'] ?? 'UNKNOWN');
            $mix[$type] = ($mix[$type] ?? 0) + 1;
        }

        return $mix;
    }

    /**
     * @param  array<int, array<string, mixed>>  $posts
     * @return array{avg_per_post: float, top: list<array{tag: string, count: int}>}
     */
    private function hashtagUsage(array $posts): array
    {
        $counts = [];
        $total = 0;

        foreach ($posts as $post) {
            $hashtags = is_array($post['hashtags'] ?? null) ? $post['hashtags'] : [];
            $total += count($hashtags);

            foreach ($hashtags as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        arsort($counts);
        $top = [];

        foreach (array_slice($counts, 0, 5, true) as $tag => $count) {
            $top[] = ['tag' => $tag, 'count' => $count];
        }

        return [
            'avg_per_post' => round($total / count($posts), 2),
            'top' => $top,
        ];
    }

    /** @param  array<int, array<string, mixed>>  $posts */
    private function ctaUsage(array $posts): float
    {
        $matches = 0;

        foreach ($posts as $post) {
            $caption = mb_strtolower((string) ($post['caption'] ?? ''));

            foreach (self::CTA_PHRASES as $phrase) {
                if (str_contains($caption, $phrase)) {
                    $matches++;
                    break;
                }
            }
        }

        return round($matches / count($posts) * 100, 1);
    }

    /**
     * @param  DateTimeImmutable[]  $timestamps
     * @return array<string, int>
     */
    private function contentDistribution(array $timestamps): array
    {
        $days = ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0, 'Saturday' => 0, 'Sunday' => 0];

        foreach ($timestamps as $timestamp) {
            $days[$timestamp->format('l')]++;
        }

        return $days;
    }

    /**
     * @param  array<int, array<string, mixed>>  $posts
     * @param  DateTimeImmutable[]  $timestamps
     */
    private function engagementTrendFact(array $posts, array $timestamps): ?FactData
    {
        $withEngagement = [];

        foreach ($posts as $i => $post) {
            $likes = $post['like_count'] ?? null;
            $comments = $post['comments_count'] ?? null;

            if ($likes === null && $comments === null) {
                continue;
            }

            $withEngagement[] = [
                'timestamp' => $timestamps[$i],
                'likes' => (int) ($likes ?? 0),
                'comments' => (int) ($comments ?? 0),
            ];
        }

        if ($withEngagement === []) {
            return null;
        }

        usort($withEngagement, fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

        $avgLikes = round(array_sum(array_column($withEngagement, 'likes')) / count($withEngagement), 1);
        $avgComments = round(array_sum(array_column($withEngagement, 'comments')) / count($withEngagement), 1);

        $trend = 'flat';

        if (count($withEngagement) >= 2) {
            $mid = (int) floor(count($withEngagement) / 2);
            $firstHalf = array_slice($withEngagement, 0, $mid);
            $secondHalf = array_slice($withEngagement, $mid);

            $firstAvg = array_sum(array_map(fn (array $p): int => $p['likes'] + $p['comments'], $firstHalf)) / count($firstHalf);
            $secondAvg = array_sum(array_map(fn (array $p): int => $p['likes'] + $p['comments'], $secondHalf)) / count($secondHalf);

            if ($firstAvg > 0 && $secondAvg > $firstAvg * 1.1) {
                $trend = 'increasing';
            } elseif ($firstAvg > 0 && $secondAvg < $firstAvg * 0.9) {
                $trend = 'decreasing';
            }
        }

        return $this->fact('instagram.engagement_trend', [
            'avg_likes' => $avgLikes,
            'avg_comments' => $avgComments,
            'trend' => $trend,
        ], 'json', 85);
    }

    private function fact(string $key, mixed $value, string $dataType, int $confidence): ?FactData
    {
        if ($value === null || $value === '') {
            return null;
        }

        return new FactData(key: $key, value: $value, dataType: $dataType, confidence: $confidence);
    }
}
