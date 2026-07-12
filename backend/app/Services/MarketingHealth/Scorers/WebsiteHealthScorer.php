<?php

namespace App\Services\MarketingHealth\Scorers;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\MarketingHealth\ValueObjects\MarketingHealthEvidence;
use App\Domain\MarketingHealth\ValueObjects\MarketingHealthScoreResult;
use App\Models\Company;
use App\Models\Fact;
use App\Models\Observation;
use App\Services\MarketingHealth\Contracts\MarketingHealthScorer;
use Illuminate\Support\Collection;

/**
 * Website Health — docs/specs/Marketing-Health.md §3. Reads website crawl
 * Facts (business.*) and crawl Observation recency/success rate. N/A when
 * the site has never been crawled.
 */
class WebsiteHealthScorer implements MarketingHealthScorer
{
    private const CORE_FACT_KEYS = ['business.name', 'business.description', 'business.industry'];

    public function dimension(): string
    {
        return 'website';
    }

    public function score(Company $company, BusinessBrain $brain): ?MarketingHealthScoreResult
    {
        /** @var Collection<int, Observation> $crawls */
        $crawls = $brain->recentObservations
            ->where('source_type', 'crawl')
            ->sortByDesc('observed_at')
            ->values();

        if ($crawls->isEmpty()) {
            return null;
        }

        $config = config('marketing_health.website');
        $latest = $crawls->first();
        // Deliberately calling diffInDays() on now() rather than on
        // $latest->observed_at directly: Larastan does not resolve
        // Observation::casts()'s 'datetime' cast for this property, so it
        // types observed_at as string — calling the method on the
        // known-Carbon instance instead sidesteps that false positive.
        // abs() guards against Carbon's signed (not absolute) diff result.
        $daysSinceCrawl = (int) abs(now()->diffInDays($latest->observed_at));

        $recencyScore = $this->recencyScore($daysSinceCrawl, (int) $config['fresh_within_days'], (int) $config['stale_after_days']);

        $factsByKey = $brain->activeFacts->keyBy('key');
        $presentCount = 0;
        $evidence = [
            new MarketingHealthEvidence(
                label: "Last crawled {$daysSinceCrawl} day(s) ago",
                sourceType: 'observation',
                sourceId: $latest->id,
                value: (string) $latest->observed_at,
            ),
        ];

        foreach (self::CORE_FACT_KEYS as $key) {
            /** @var Fact|null $fact */
            $fact = $factsByKey->get($key);

            if ($fact !== null) {
                $presentCount++;
                $evidence[] = new MarketingHealthEvidence(
                    label: "{$key} is known",
                    sourceType: 'fact',
                    sourceId: $fact->id,
                    value: $fact->value,
                );
            }
        }

        $coreFactsScore = (int) round($presentCount / count(self::CORE_FACT_KEYS) * 100);

        $processed = $crawls->where('status', 'processed')->count();
        $failed = $crawls->where('status', 'failed')->count();
        $attempted = $processed + $failed;
        $successRateScore = $attempted > 0 ? (int) round($processed / $attempted * 100) : 100;

        $evidence[] = new MarketingHealthEvidence(
            label: "{$processed} of {$attempted} recent crawl attempt(s) succeeded",
            sourceType: 'observation',
            sourceId: null,
            value: ['processed' => $processed, 'failed' => $failed],
        );

        $score = (int) round(
            $recencyScore * (float) $config['recency_weight']
            + $coreFactsScore * (float) $config['core_facts_weight']
            + $successRateScore * (float) $config['success_rate_weight'],
        );

        $confidence = min(100, $crawls->count() * 20);

        return new MarketingHealthScoreResult(score: max(0, min(100, $score)), confidence: $confidence, evidence: $evidence);
    }

    private function recencyScore(int $daysSinceCrawl, int $freshWithinDays, int $staleAfterDays): int
    {
        if ($daysSinceCrawl <= $freshWithinDays) {
            return 100;
        }

        if ($daysSinceCrawl >= $staleAfterDays) {
            return 0;
        }

        $range = $staleAfterDays - $freshWithinDays;
        $elapsed = $daysSinceCrawl - $freshWithinDays;

        return (int) round(100 - ($elapsed / $range * 100));
    }
}
