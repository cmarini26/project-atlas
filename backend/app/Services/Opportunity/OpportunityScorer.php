<?php

namespace App\Services\Opportunity;

readonly class OpportunityScorer
{
    private const int MIN_COMPOSITE = 30;

    private const int AI_CONFIDENCE_CAP = 75;

    /**
     * Compute the composite score and validate component scores.
     * Returns null when the composite falls below the minimum threshold.
     *
     * @return array{relevance: int, timing: int, confidence: int, urgency: int, composite: int}|null
     */
    public function score(OpportunityCandidate $candidate): ?array
    {
        $relevance = $this->clamp($candidate->relevanceScore);
        $timing = $this->clamp($candidate->timingScore);
        $confidence = $this->clamp($candidate->confidenceScore);
        $urgency = $this->clamp($candidate->urgencyScore);

        if ($candidate->aiDetected) {
            $confidence = min($confidence, self::AI_CONFIDENCE_CAP);
        }

        $composite = (int) round(
            ($relevance * 0.30) + ($timing * 0.25) + ($confidence * 0.25) + ($urgency * 0.20)
        );

        if ($composite < self::MIN_COMPOSITE) {
            return null;
        }

        return [
            'relevance' => $relevance,
            'timing' => $timing,
            'confidence' => $confidence,
            'urgency' => $urgency,
            'composite' => $composite,
        ];
    }

    private function clamp(int $value): int
    {
        return max(0, min(100, $value));
    }
}
