<?php

namespace App\Services\MarketingHealth;

use App\Domain\MarketingHealth\ValueObjects\MarketingHealthScoreResult;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\MarketingHealthScore;
use App\Services\Brain\BusinessBrainService;
use Illuminate\Support\Collection;

/**
 * Runs every registered MarketingHealthScorer for a company and persists
 * the result — Milestone 13 Phase 1. Mirrors FactService's supersession
 * pattern: a re-computation never updates or deletes the prior row, it
 * creates a new one and flips is_current on the old one.
 */
class MarketingHealthService
{
    public function __construct(
        private readonly MarketingHealthRegistry $registry,
        private readonly BusinessBrainService $brainService,
    ) {}

    /**
     * Recompute every dimension for a company. Silently does nothing (and
     * returns the prior current scores unchanged) when the company has no
     * DigitalTwin yet — the same "not initialized" state BusinessBrain
     * itself requires, not an error condition.
     *
     * @return Collection<int, MarketingHealthScore>
     */
    public function recompute(Company $company): Collection
    {
        $hasTwin = DigitalTwin::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->exists();

        if (! $hasTwin) {
            return $this->currentFor($company);
        }

        $brain = $this->brainService->for($company);

        foreach ($this->registry->all() as $scorer) {
            $result = $scorer->score($company, $brain);

            if ($result !== null) {
                $this->storeScore($company, $scorer->dimension(), $result);
            }
        }

        return $this->currentFor($company);
    }

    /** @return Collection<int, MarketingHealthScore> */
    public function currentFor(Company $company): Collection
    {
        return MarketingHealthScore::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->current()
            ->get();
    }

    /**
     * Confidence-weighted average across every current, non-N/A dimension —
     * docs/specs/Marketing-Health.md §4.2. Null when no dimension has ever
     * been scored (there is nothing to average).
     *
     * @return array{score: int, confidence: int}|null
     */
    public function compositeFor(Company $company): ?array
    {
        $scores = $this->currentFor($company);

        if ($scores->isEmpty()) {
            return null;
        }

        $totalConfidence = (int) $scores->sum('confidence');

        if ($totalConfidence === 0) {
            return null;
        }

        $weightedSum = $scores->sum(fn (MarketingHealthScore $s): float => $s->score * $s->confidence);
        $averageConfidence = (int) round((float) ($scores->avg('confidence') ?? 0));

        return [
            'score' => (int) round($weightedSum / $totalConfidence),
            'confidence' => $averageConfidence,
        ];
    }

    private function storeScore(Company $company, string $dimension, MarketingHealthScoreResult $result): void
    {
        $existing = MarketingHealthScore::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('dimension', $dimension)
            ->current()
            ->first();

        $new = MarketingHealthScore::create([
            'company_id' => $company->id,
            'dimension' => $dimension,
            'score' => $result->score,
            'confidence' => $result->confidence,
            'evidence' => array_map(fn ($e) => $e->toArray(), $result->evidence),
            'computed_at' => now(),
            'is_current' => true,
        ]);

        if ($existing !== null) {
            $existing->update(['is_current' => false, 'superseded_by_id' => $new->id]);
        }
    }
}
