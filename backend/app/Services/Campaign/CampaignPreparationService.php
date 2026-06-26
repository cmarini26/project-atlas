<?php

namespace App\Services\Campaign;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\Campaign\Exceptions\BlueprintGenerationFailedException;
use App\Domain\Campaign\ValueObjects\CampaignBlueprint;
use App\Models\Campaign;
use App\Models\Decision;
use App\Services\Analyst\CampaignPreparationAnalyst;

class CampaignPreparationService
{
    public function __construct(
        private readonly CampaignPreparationAnalyst $analyst,
    ) {}

    /**
     * Generate a blueprint, validate it, and create a Campaign in draft status.
     *
     * @throws BlueprintGenerationFailedException if blueprint fails validation
     */
    public function prepare(Decision $decision, BusinessBrain $brain): Campaign
    {
        $blueprint = $this->analyst->analyze($decision, $brain);

        $this->validateBlueprint($blueprint);

        $channelIds = $decision->channel_ids ?? [];
        $channelCount = count($channelIds);

        $campaign = Campaign::create([
            'company_id' => $decision->company_id,
            'decision_id' => $decision->id,
            'campaign_type' => $decision->campaign_type,
            'title' => $this->deriveTitle($blueprint),
            'target_audience' => $blueprint->audience,
            'positioning' => $blueprint->coreMessage,
            'call_to_action' => $blueprint->callToAction,
            'blueprint' => $blueprint->toArray(),
            'blueprint_version' => $blueprint->version,
            'prompt_version' => '1.0',
            'expected_asset_count' => $channelCount,
            'generated_asset_count' => 0,
            'status' => 'draft',
        ]);

        return $campaign;
    }

    /**
     * @throws BlueprintGenerationFailedException
     */
    private function validateBlueprint(CampaignBlueprint $blueprint): void
    {
        if (! in_array($blueprint->goal, ['awareness', 'conversion', 're_engagement'], true)) {
            throw new BlueprintGenerationFailedException("Invalid blueprint goal: {$blueprint->goal}");
        }

        if (strlen($blueprint->audience) < 20) {
            throw new BlueprintGenerationFailedException('Blueprint audience is too vague (minimum 20 characters).');
        }

        if (strlen($blueprint->coreMessage) < 30) {
            throw new BlueprintGenerationFailedException('Blueprint core_message is too short (minimum 30 characters).');
        }

        if (empty($blueprint->supportingPoints)) {
            throw new BlueprintGenerationFailedException('Blueprint must have at least one supporting point.');
        }

        if (count($blueprint->supportingPoints) > 5) {
            throw new BlueprintGenerationFailedException('Blueprint may have at most 5 supporting points.');
        }

        $genericCtas = ['click here', 'learn more', 'get started', 'sign up', 'contact us'];
        $ctaLower = strtolower(trim($blueprint->callToAction));
        if (in_array($ctaLower, $genericCtas, true)) {
            throw new BlueprintGenerationFailedException("Blueprint call_to_action is generic filler: \"{$blueprint->callToAction}\"");
        }

        if (empty($blueprint->callToAction)) {
            throw new BlueprintGenerationFailedException('Blueprint call_to_action is required.');
        }

        if (empty($blueprint->channelStrategy)) {
            throw new BlueprintGenerationFailedException('Blueprint must include at least one channel strategy.');
        }
    }

    private function deriveTitle(CampaignBlueprint $blueprint): string
    {
        $goal = match ($blueprint->goal) {
            'awareness' => 'Awareness',
            'conversion' => 'Conversion',
            're_engagement' => 'Re-engagement',
            default => 'Campaign',
        };

        return $goal.' — '.substr($blueprint->coreMessage, 0, 60);
    }
}
