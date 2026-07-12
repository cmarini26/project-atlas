<?php

namespace App\Services\MarketingHealth\Scorers;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\MarketingHealth\ValueObjects\MarketingHealthEvidence;
use App\Domain\MarketingHealth\ValueObjects\MarketingHealthScoreResult;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Services\MarketingHealth\Contracts\MarketingHealthScorer;

/**
 * Content Diversity — docs/specs/Marketing-Health.md §3. A Shannon-evenness
 * measure over recent ContentAsset.type distribution: a business posting
 * only one type every time scores 0, independent of volume; a business
 * spread evenly across several types scores near 100.
 */
class ContentDiversityScorer implements MarketingHealthScorer
{
    private const SAMPLE_SIZE = 20;

    public function dimension(): string
    {
        return 'content_diversity';
    }

    public function score(Company $company, BusinessBrain $brain): ?MarketingHealthScoreResult
    {
        $assets = ContentAsset::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->latest()
            ->limit(self::SAMPLE_SIZE)
            ->get();

        if ($assets->isEmpty()) {
            return null;
        }

        $counts = $assets->countBy('type');
        $total = $assets->count();
        $distinctTypes = $counts->count();

        /** @var list<MarketingHealthEvidence> $evidence */
        $evidence = array_values($counts->map(
            fn (int $count, string $type): MarketingHealthEvidence => new MarketingHealthEvidence(
                label: "{$count} of {$total} recent asset(s) are {$type}",
                sourceType: 'content_asset',
                sourceId: null,
                value: $count,
            )
        )->all());

        if ($distinctTypes < 2) {
            return new MarketingHealthScoreResult(score: 0, confidence: min(100, $total * 5), evidence: $evidence);
        }

        $entropy = 0.0;

        foreach ($counts as $count) {
            $p = $count / $total;
            $entropy -= $p * log($p);
        }

        $maxEntropy = log($distinctTypes);
        $evenness = $maxEntropy > 0 ? $entropy / $maxEntropy : 0.0;

        return new MarketingHealthScoreResult(
            score: (int) round($evenness * 100),
            confidence: min(100, $total * 5),
            evidence: $evidence,
        );
    }
}
