<?php

namespace App\Services\MarketingHealth\Scorers;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\MarketingHealth\ValueObjects\MarketingHealthEvidence;
use App\Domain\MarketingHealth\ValueObjects\MarketingHealthScoreResult;
use App\Models\Campaign;
use App\Models\Company;
use App\Services\MarketingHealth\Contracts\MarketingHealthScorer;

/**
 * CTA Strength — docs/specs/Marketing-Health.md §3. Prefers the
 * instagram.cta_usage Fact (Milestone 12 Phase 2 — already a percentage
 * derived from real captions); falls back to the percentage of recent
 * Campaigns with a non-empty call_to_action when no social CTA Fact exists.
 */
class CtaStrengthScorer implements MarketingHealthScorer
{
    public function dimension(): string
    {
        return 'cta_strength';
    }

    public function score(Company $company, BusinessBrain $brain): ?MarketingHealthScoreResult
    {
        $fact = $brain->activeFacts->firstWhere('key', 'instagram.cta_usage');

        if ($fact !== null) {
            $percentage = (float) $fact->value;

            return new MarketingHealthScoreResult(
                score: max(0, min(100, (int) round($percentage))),
                confidence: 85,
                evidence: [
                    new MarketingHealthEvidence(
                        label: "{$percentage}% of recent Instagram posts include a call-to-action",
                        sourceType: 'fact',
                        sourceId: $fact->id,
                        value: $percentage,
                    ),
                ],
            );
        }

        $campaigns = $brain->recentCampaigns;

        if ($campaigns->isEmpty()) {
            return null;
        }

        $withCta = $campaigns->filter(fn (Campaign $c): bool => trim((string) $c->call_to_action) !== '');
        $total = $campaigns->count();
        $score = (int) round($withCta->count() / $total * 100);

        $evidence = [
            new MarketingHealthEvidence(
                label: "{$withCta->count()} of {$total} recent campaign(s) declared a call-to-action",
                sourceType: 'campaign',
                sourceId: null,
                value: ['with_cta' => $withCta->count(), 'total' => $total],
            ),
        ];

        return new MarketingHealthScoreResult(score: $score, confidence: min(100, $total * 15), evidence: $evidence);
    }
}
