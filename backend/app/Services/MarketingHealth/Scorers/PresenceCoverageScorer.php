<?php

namespace App\Services\MarketingHealth\Scorers;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\MarketingHealth\ValueObjects\MarketingHealthEvidence;
use App\Domain\MarketingHealth\ValueObjects\MarketingHealthScoreResult;
use App\Models\Company;
use App\Models\MarketingChannel;
use App\Services\MarketingHealth\Contracts\MarketingHealthScorer;

/**
 * Marketing Presence Coverage — docs/specs/Marketing-Health.md §3. Reads
 * declared MarketingChannel rows (Milestone 11) — the ratio of active,
 * importance-weighted channels to all declared channels.
 */
class PresenceCoverageScorer implements MarketingHealthScorer
{
    public function dimension(): string
    {
        return 'presence_coverage';
    }

    public function score(Company $company, BusinessBrain $brain): ?MarketingHealthScoreResult
    {
        $channels = MarketingChannel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->get();

        if ($channels->isEmpty()) {
            return null;
        }

        $weights = config('marketing_health.presence_coverage.importance_weights');

        $totalWeight = 0.0;
        $activeWeight = 0.0;
        $evidence = [];

        foreach ($channels as $channel) {
            $weight = (float) ($weights[$channel->importance->value] ?? 1.0);
            $totalWeight += $weight;
            $isActive = $channel->status->value === 'active';

            if ($isActive) {
                $activeWeight += $weight;
            }

            $evidence[] = new MarketingHealthEvidence(
                label: "{$channel->display_name} is {$channel->status->value} ({$channel->importance->value})",
                sourceType: 'marketing_channel',
                sourceId: $channel->id,
                value: ['status' => $channel->status->value, 'importance' => $channel->importance->value],
            );
        }

        $score = $totalWeight > 0 ? (int) round($activeWeight / $totalWeight * 100) : 0;
        $confidence = min(100, $channels->count() * 25);

        return new MarketingHealthScoreResult(score: $score, confidence: $confidence, evidence: $evidence);
    }
}
