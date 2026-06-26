<?php

namespace App\Services\Decision;

use App\Events\DecisionCommitted;
use App\Models\Decision;
use App\Services\Analyst\RationaleGenerationAnalyst;
use App\Services\Decision\Exceptions\RationaleGenerationFailedException;

class DecisionService
{
    public function __construct(
        private readonly RationaleGenerationAnalyst $analyst,
    ) {}

    /**
     * Validate rationale, persist the Decision, transition Opportunity to selected,
     * and fire DecisionCommitted.
     *
     * @throws RationaleGenerationFailedException if any required rationale key is missing or empty
     */
    public function commit(DecisionContext $context): Decision
    {
        $partialDecision = [
            'campaign_type' => $context->campaignType,
            'channel_ids' => $context->channelIds,
        ];

        $rationale = $this->analyst->analyze(
            $context->opportunity,
            $partialDecision,
            $context->brain,
        );

        $this->validateRationale($rationale);

        $decision = Decision::create([
            'company_id' => $context->opportunity->company_id,
            'opportunity_id' => $context->opportunity->id,
            'campaign_type' => $context->campaignType,
            'channel_ids' => $context->channelIds,
            'rationale' => $rationale,
            'confidence_score' => $context->opportunity->confidence_score,
            'expected_outcome' => is_array($rationale['expected_impact'])
                ? ($rationale['expected_impact']['summary'] ?? null)
                : null,
            'expected_impact' => $rationale['expected_impact'],
            'status' => 'pending',
            'prompt_version' => '1.0',
            'decided_at' => now(),
        ]);

        $context->opportunity->select();

        DecisionCommitted::dispatch($decision);

        return $decision;
    }

    /**
     * @param  array<string, mixed>  $rationale
     *
     * @throws RationaleGenerationFailedException
     */
    private function validateRationale(array $rationale): void
    {
        $required = ['why_now', 'why_this', 'why_channel', 'why_works', 'expected_impact'];

        foreach ($required as $key) {
            if (empty($rationale[$key])) {
                throw new RationaleGenerationFailedException("Missing required rationale key: {$key}");
            }
        }

        if (! is_array($rationale['expected_impact'])) {
            throw new RationaleGenerationFailedException('expected_impact must be an object.');
        }

        $requiredImpact = ['summary', 'reach_estimate', 'engagement_signal', 'confidence_basis'];

        foreach ($requiredImpact as $key) {
            if (empty($rationale['expected_impact'][$key])) {
                throw new RationaleGenerationFailedException("Missing required expected_impact key: {$key}");
            }
        }
    }
}
