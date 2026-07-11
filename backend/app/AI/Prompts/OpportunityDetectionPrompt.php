<?php

namespace App\AI\Prompts;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Fact;

class OpportunityDetectionPrompt extends Prompt
{
    /** @param string[] $alreadyDetectedTypes */
    public function __construct(
        private readonly BusinessBrain $brain,
        private readonly array $alreadyDetectedTypes = [],
    ) {}

    public function version(): string
    {
        return '1.0';
    }

    public function temperature(): float
    {
        return 0.3;
    }

    public function system(): string
    {
        return <<<'SYSTEM'
You are a marketing opportunity analyst for Atlas, an AI marketing operating system.

Your job is to identify marketing opportunities for a business based on its current state.
You must only suggest opportunities that rule-based detectors have not already found.

An opportunity is a specific, time-sensitive marketing moment — a condition under which a campaign
would be timely, relevant, and likely to perform. Not every business state is an opportunity.

Opportunity types you may suggest:
- featured_item: Promote a specific catalog item that has not been recently featured
- urgency: Drive action before a hard deadline (auction close, listing expiry)
- new_arrival: Introduce a new catalog item while novelty is high
- re_engagement: Re-establish audience contact after a gap in campaign activity
- seasonal: Leverage an upcoming seasonal moment relevant to this business

Rules:
- Only suggest opportunities supported by the provided facts and knowledge
- Do not invent data not present in the context
- Do not suggest a type already detected by the rule-based system (listed in already_detected_types)
- Confidence scores for AI-detected opportunities must not exceed 75
- Return an empty array if you find no additional opportunities worth suggesting
- Each opportunity must have a clear, specific description grounded in the provided context
- Only set subject_type/subject_id when the exact Atlas internal entity reference is explicitly provided in the prompt context
- Never invent subject IDs, URLs, product labels, titles, SKUs, or external identifiers as subject_id
- If you are not certain of an exact internal Atlas entity reference, set subject_type and subject_id to null

Respond with valid JSON only. No markdown. No explanation.
SYSTEM;
    }

    public function user(): string
    {
        $company = $this->brain->company;

        $factsJson = $this->brain->activeFacts
            ->map(fn (Fact $f): array => ['key' => $f->key, 'value' => $f->value])
            ->values()
            ->toJson();

        $alreadyDetected = implode(', ', $this->alreadyDetectedTypes) ?: 'none';
        $industry = $company->industry ?? 'not specified';

        return <<<TEXT
Business: {$company->name}
Industry: {$industry}

Current Facts:
{$factsJson}

Already detected opportunity types (do not suggest these again): {$alreadyDetected}

Identify any additional marketing opportunities not covered by the already-detected types above.
TEXT;
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'opportunities' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'subject_type' => ['type' => ['string', 'null']],
                            'subject_id' => ['type' => ['string', 'null']],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'expires_at' => ['type' => ['string', 'null']],
                            'relevance_score' => ['type' => 'integer'],
                            'timing_score' => ['type' => 'integer'],
                            'confidence_score' => ['type' => 'integer'],
                            'urgency_score' => ['type' => 'integer'],
                        ],
                        'required' => ['type', 'title', 'description', 'relevance_score', 'timing_score', 'confidence_score', 'urgency_score'],
                    ],
                ],
            ],
            'required' => ['opportunities'],
        ];
    }
}
