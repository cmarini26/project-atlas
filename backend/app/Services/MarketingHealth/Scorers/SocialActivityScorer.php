<?php

namespace App\Services\MarketingHealth\Scorers;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\MarketingHealth\ValueObjects\MarketingHealthEvidence;
use App\Domain\MarketingHealth\ValueObjects\MarketingHealthScoreResult;
use App\Models\Company;
use App\Services\MarketingHealth\Contracts\MarketingHealthScorer;

/**
 * Social Activity — docs/specs/Marketing-Health.md §3. Reads posting-cadence
 * Facts. Source-agnostic by construction (per spec §7): the Fact-key prefix
 * list below is the only thing that grows when a future social connector
 * ships — currently just Instagram (Milestone 12 Phase 2).
 */
class SocialActivityScorer implements MarketingHealthScorer
{
    /** @var list<string> Fact-key prefixes checked for a posting-cadence signal. */
    private const PLATFORM_PREFIXES = ['instagram'];

    public function dimension(): string
    {
        return 'social_activity';
    }

    public function score(Company $company, BusinessBrain $brain): ?MarketingHealthScoreResult
    {
        $factsByKey = $brain->activeFacts->keyBy('key');
        $target = (float) config('marketing_health.social_activity.target_posts_per_week');

        foreach (self::PLATFORM_PREFIXES as $platform) {
            $fact = $factsByKey->get("{$platform}.posting_cadence");

            if ($fact === null) {
                continue;
            }

            $cadence = (float) $fact->value;
            $score = (int) min(100, round($cadence / $target * 100));

            $evidence = [
                new MarketingHealthEvidence(
                    label: "Posting {$cadence}x/week on {$platform}",
                    sourceType: 'fact',
                    sourceId: $fact->id,
                    value: $cadence,
                ),
            ];

            return new MarketingHealthScoreResult(score: max(0, $score), confidence: 85, evidence: $evidence);
        }

        return null;
    }
}
