<?php

namespace App\Services\MarketingHealth\Scorers;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\MarketingHealth\ValueObjects\MarketingHealthEvidence;
use App\Domain\MarketingHealth\ValueObjects\MarketingHealthScoreResult;
use App\Models\Campaign;
use App\Models\Company;
use App\Services\MarketingHealth\Contracts\MarketingHealthScorer;

/**
 * Campaign Consistency — docs/specs/Marketing-Health.md §3. Prefers the
 * marketing.days_since_last_campaign Fact (already maintained for
 * ReEngagementDetector); falls back to BusinessBrain.recentCampaigns when
 * that Fact hasn't been computed, mirroring ReEngagementDetector's own
 * existing fallback pattern.
 */
class CampaignConsistencyScorer implements MarketingHealthScorer
{
    public function dimension(): string
    {
        return 'campaign_consistency';
    }

    public function score(Company $company, BusinessBrain $brain): ?MarketingHealthScoreResult
    {
        $fact = $brain->activeFacts->firstWhere('key', 'marketing.days_since_last_campaign');

        if ($fact !== null) {
            $days = (int) $fact->value;

            return $this->buildResult($days, confidence: 90, evidence: [
                new MarketingHealthEvidence(
                    label: "{$days} day(s) since the last campaign",
                    sourceType: 'fact',
                    sourceId: $fact->id,
                    value: $days,
                ),
            ]);
        }

        /** @var Campaign|null $latest */
        $latest = $brain->recentCampaigns->sortByDesc('created_at')->first();

        if ($latest === null || $latest->created_at === null) {
            return null;
        }

        $days = (int) $latest->created_at->diffInDays(now());

        return $this->buildResult($days, confidence: 70, evidence: [
            new MarketingHealthEvidence(
                label: "Most recent campaign \"{$latest->title}\" was {$days} day(s) ago",
                sourceType: 'campaign',
                sourceId: $latest->id,
                value: $days,
            ),
        ]);
    }

    /** @param  list<MarketingHealthEvidence>  $evidence */
    private function buildResult(int $days, int $confidence, array $evidence): MarketingHealthScoreResult
    {
        $config = config('marketing_health.campaign_consistency');
        $fullWithin = (int) $config['full_score_within_days'];
        $zeroAfter = (int) $config['zero_score_after_days'];

        if ($days <= $fullWithin) {
            $score = 100;
        } elseif ($days >= $zeroAfter) {
            $score = 0;
        } else {
            $range = $zeroAfter - $fullWithin;
            $elapsed = $days - $fullWithin;
            $score = (int) round(100 - ($elapsed / $range * 100));
        }

        return new MarketingHealthScoreResult(score: max(0, min(100, $score)), confidence: $confidence, evidence: $evidence);
    }
}
