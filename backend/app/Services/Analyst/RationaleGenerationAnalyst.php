<?php

namespace App\Services\Analyst;

use App\AI\Contracts\AiProvider;
use App\AI\Prompts\RationaleGenerationPrompt;
use App\AI\StructuredResponseParser;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Opportunity;
use App\Services\Analyst\Contracts\Analyst;

class RationaleGenerationAnalyst implements Analyst
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly StructuredResponseParser $parser,
    ) {}

    /**
     * Generate a structured rationale for a pending Decision.
     *
     * Returns the raw parsed array. Caller is responsible for validating
     * the five required keys (why_now, why_this, why_channel, why_works, expected_impact).
     *
     * @param  array{campaign_type: string, channel_ids: string[]}  $partialDecision
     * @return array<string, mixed>
     */
    public function analyze(
        Opportunity $opportunity,
        array $partialDecision,
        BusinessBrain $brain,
    ): array {
        $prompt = new RationaleGenerationPrompt($opportunity, $partialDecision, $brain);

        $response = $this->ai->complete($prompt);

        return $this->parser->parse($response);
    }
}
