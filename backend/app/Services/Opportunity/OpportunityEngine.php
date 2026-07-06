<?php

namespace App\Services\Opportunity;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Events\OpportunityDetected;
use App\Models\Company;
use App\Models\CompanyScoringWeights;
use App\Models\Opportunity;
use App\Services\Analyst\OpportunityDetectionAnalyst;
use App\Services\Opportunity\Detectors\Contracts\OpportunityDetector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class OpportunityEngine
{
    /**
     * @param  Collection<int, OpportunityDetector>  $detectors
     */
    public function __construct(
        private readonly Collection $detectors,
        private readonly OpportunityDetectionAnalyst $analyst,
        private readonly OpportunityScorer $scorer,
        private readonly OpportunityRepository $repository,
    ) {}

    /**
     * Run a full opportunity detection scan for a company.
     *
     * Orchestrates all rule-based detectors, the AI analyst, deduplication,
     * scoring, persistence, and event dispatch. Returns all newly persisted
     * Opportunity records.
     *
     * @return Collection<int, Opportunity>
     */
    public function scan(Company $company, BusinessBrain $brain): Collection
    {
        Log::info('OpportunityEngine: scanning for opportunities.', [
            'company_id' => $company->id,
        ]);

        /** @var Collection<int, OpportunityCandidate> $candidates */
        $candidates = collect();
        $detectedTypes = [];

        foreach ($this->detectors as $detector) {
            $found = $detector->detect($company, $brain);
            $candidates = $candidates->concat($found);

            foreach ($detector->appliesTo() as $type) {
                $detectedTypes[] = $type;
            }
        }

        $detectedTypes = array_unique($detectedTypes);

        $aiCandidates = $this->analyst->analyze($company, $brain, $detectedTypes);
        $candidates = $candidates->concat($aiCandidates);

        /** @var Collection<int, Opportunity> $persisted */
        $persisted = collect();

        $weights = CompanyScoringWeights::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_current', true)
            ->first();

        $typeModifiers = $weights?->typeModifiers();

        $droppedAsDuplicate = 0;
        $droppedBelowThreshold = 0;

        foreach ($candidates as $candidate) {
            if ($this->repository->hasDuplicate(
                $company->id,
                $candidate->type,
                $candidate->subjectType,
                $candidate->subjectId,
            )) {
                $droppedAsDuplicate++;

                continue;
            }

            $scores = $this->scorer->score($candidate, $typeModifiers);

            if ($scores === null) {
                $droppedBelowThreshold++;

                continue;
            }

            $opportunity = Opportunity::create([
                'company_id' => $company->id,
                'subject_type' => $candidate->subjectType,
                'subject_id' => $candidate->subjectId,
                'type' => $candidate->type,
                'title' => $candidate->title,
                'description' => $candidate->description,
                'relevance_score' => $scores['relevance'],
                'timing_score' => $scores['timing'],
                'confidence_score' => $scores['confidence'],
                'urgency_score' => $scores['urgency'],
                'composite_score' => $scores['composite'],
                'ai_detected' => $candidate->aiDetected,
                'status' => 'open',
                'expires_at' => $candidate->expiresAt,
                'detected_at' => now(),
            ]);

            $persisted->push($opportunity);

            OpportunityDetected::dispatch($opportunity);
        }

        Log::info('OpportunityEngine: scan complete.', [
            'company_id' => $company->id,
            'candidates' => $candidates->count(),
            'persisted' => $persisted->count(),
            'dropped_duplicate' => $droppedAsDuplicate,
            'dropped_below_threshold' => $droppedBelowThreshold,
        ]);

        if ($persisted->isEmpty()) {
            // Legitimate outcome (nothing new to act on), but load-bearing for
            // onboarding: with no opportunity there will be no decision and no
            // recommendation, and the status page surfaces the no-opportunity state.
            Log::info('OpportunityEngine: no opportunities persisted from this scan.', [
                'company_id' => $company->id,
            ]);
        }

        return $persisted;
    }
}
