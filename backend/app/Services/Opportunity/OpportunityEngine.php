<?php

namespace App\Services\Opportunity;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Events\OpportunityDetected;
use App\Models\Company;
use App\Models\Opportunity;
use App\Services\Analyst\OpportunityDetectionAnalyst;
use App\Services\Opportunity\Detectors\Contracts\OpportunityDetector;
use Illuminate\Support\Collection;

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

        foreach ($candidates as $candidate) {
            if ($this->repository->hasDuplicate(
                $company->id,
                $candidate->type,
                $candidate->subjectType,
                $candidate->subjectId,
            )) {
                continue;
            }

            $scores = $this->scorer->score($candidate);

            if ($scores === null) {
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

        return $persisted;
    }
}
