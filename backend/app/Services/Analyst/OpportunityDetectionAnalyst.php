<?php

namespace App\Services\Analyst;

use App\AI\Contracts\AiProvider;
use App\AI\Prompts\OpportunityDetectionPrompt;
use App\AI\StructuredResponseParser;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Company;
use App\Services\Analyst\Contracts\Analyst;
use App\Services\Opportunity\OpportunityCandidate;
use Illuminate\Support\Collection;

class OpportunityDetectionAnalyst implements Analyst
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly StructuredResponseParser $parser,
    ) {}

    /**
     * Suggest additional OpportunityCandidates not found by rule-based detectors.
     *
     * This analyst MUST NOT: bypass scoring, bypass deduplication, create Decisions,
     * or create Campaigns. It only returns candidate objects for the engine to evaluate.
     *
     * @param  string[]  $alreadyDetectedTypes
     * @return Collection<int, OpportunityCandidate>
     */
    public function analyze(
        Company $company,
        BusinessBrain $brain,
        array $alreadyDetectedTypes = [],
    ): Collection {
        $prompt = new OpportunityDetectionPrompt($brain, $alreadyDetectedTypes);

        $response = $this->ai->complete($prompt);
        $data = $this->parser->parse($response);

        /** @var array<int, array<string, mixed>> $raw */
        $raw = $data['opportunities'] ?? [];

        return collect($raw)
            ->filter(fn (array $item): bool => $this->isValid($item))
            ->map(fn (array $item): OpportunityCandidate => new OpportunityCandidate(
                type: (string) $item['type'],
                subjectType: isset($item['subject_type']) ? (string) $item['subject_type'] : null,
                subjectId: isset($item['subject_id']) ? (string) $item['subject_id'] : null,
                title: (string) $item['title'],
                description: (string) $item['description'],
                expiresAt: isset($item['expires_at']) ? (string) $item['expires_at'] : null,
                relevanceScore: min(100, max(0, (int) ($item['relevance_score'] ?? 0))),
                timingScore: min(100, max(0, (int) ($item['timing_score'] ?? 0))),
                confidenceScore: min(100, max(0, (int) ($item['confidence_score'] ?? 0))),
                urgencyScore: min(100, max(0, (int) ($item['urgency_score'] ?? 0))),
                aiDetected: true,
            ))
            ->values();
    }

    /** @param array<string, mixed> $item */
    private function isValid(array $item): bool
    {
        return isset($item['type'], $item['title'], $item['description'])
            && is_string($item['type'])
            && is_string($item['title'])
            && is_string($item['description'])
            && $item['type'] !== ''
            && $item['title'] !== ''
            && $item['description'] !== '';
    }
}
