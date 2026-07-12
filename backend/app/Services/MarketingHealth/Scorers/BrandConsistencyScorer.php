<?php

namespace App\Services\MarketingHealth\Scorers;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\MarketingHealth\ValueObjects\MarketingHealthEvidence;
use App\Domain\MarketingHealth\ValueObjects\MarketingHealthScoreResult;
use App\Models\Campaign;
use App\Models\Company;
use App\Services\MarketingHealth\Contracts\MarketingHealthScorer;

/**
 * Brand Consistency — docs/specs/Marketing-Health.md §3. Compares the
 * company's declared brand voice (Company.brand.voice) against the tone
 * actually used to generate each recent campaign's content
 * (Campaign.blueprint.tone.voice, set by CampaignBlueprint — see
 * specs/core/campaign-blueprint.md) — an agreement check, not sentiment
 * analysis.
 */
class BrandConsistencyScorer implements MarketingHealthScorer
{
    public function dimension(): string
    {
        return 'brand_consistency';
    }

    public function score(Company $company, BusinessBrain $brain): ?MarketingHealthScoreResult
    {
        /** @var array<string, mixed> $brand */
        $brand = $company->brand ?? [];
        $declaredVoice = $this->normalize((string) ($brand['voice'] ?? ''));

        if ($declaredVoice === '') {
            return null;
        }

        $campaignsWithTone = $brain->recentCampaigns->filter(
            fn (Campaign $c): bool => ! empty($c->blueprint['tone']['voice'] ?? null)
        );

        if ($campaignsWithTone->isEmpty()) {
            return null;
        }

        $matches = 0;
        $evidence = [];

        foreach ($campaignsWithTone as $campaign) {
            $campaignVoice = $this->normalize((string) $campaign->blueprint['tone']['voice']);
            $isMatch = $campaignVoice === $declaredVoice;

            if ($isMatch) {
                $matches++;
            }

            $evidence[] = new MarketingHealthEvidence(
                label: "\"{$campaign->title}\" used a {$campaignVoice} voice (declared: {$declaredVoice})",
                sourceType: 'campaign',
                sourceId: $campaign->id,
                value: ['campaign_voice' => $campaignVoice, 'declared_voice' => $declaredVoice, 'match' => $isMatch],
            );
        }

        $total = $campaignsWithTone->count();
        $score = (int) round($matches / $total * 100);
        $confidence = min(100, $total * 20);

        return new MarketingHealthScoreResult(score: $score, confidence: $confidence, evidence: $evidence);
    }

    private function normalize(string $voice): string
    {
        return mb_strtolower(trim($voice));
    }
}
