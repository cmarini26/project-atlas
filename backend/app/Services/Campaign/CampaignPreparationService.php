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

        $this->validateBlueprint($blueprint, $decision);

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
    private function validateBlueprint(CampaignBlueprint $blueprint, Decision $decision): void
    {
        // --- goal ---
        if (! in_array($blueprint->goal, ['awareness', 'conversion', 're_engagement'], true)) {
            throw new BlueprintGenerationFailedException("Invalid blueprint goal: {$blueprint->goal}");
        }

        // --- audience ---
        if (strlen($blueprint->audience) < 20) {
            throw new BlueprintGenerationFailedException('Blueprint audience is too vague (minimum 20 characters).');
        }

        // --- core_message ---
        if (strlen($blueprint->coreMessage) < 30) {
            throw new BlueprintGenerationFailedException('Blueprint core_message is too short (minimum 30 characters).');
        }

        // --- supporting_points ---
        if (empty($blueprint->supportingPoints)) {
            throw new BlueprintGenerationFailedException('Blueprint must have at least one supporting point.');
        }
        if (count($blueprint->supportingPoints) > 5) {
            throw new BlueprintGenerationFailedException('Blueprint may have at most 5 supporting points.');
        }

        // --- call_to_action ---
        if (empty($blueprint->callToAction)) {
            throw new BlueprintGenerationFailedException('Blueprint call_to_action is required.');
        }
        $genericCtas = ['click here', 'learn more', 'get started', 'sign up', 'contact us'];
        if (in_array(strtolower(trim($blueprint->callToAction)), $genericCtas, true)) {
            throw new BlueprintGenerationFailedException("Blueprint call_to_action is generic filler: \"{$blueprint->callToAction}\"");
        }

        // --- tone ---
        if (empty($blueprint->tone['voice'] ?? null)) {
            throw new BlueprintGenerationFailedException('Blueprint tone.voice is required.');
        }
        if (empty($blueprint->tone['modifier'] ?? null)) {
            throw new BlueprintGenerationFailedException('Blueprint tone.modifier is required.');
        }
        if (! isset($blueprint->tone['avoid']) || ! is_array($blueprint->tone['avoid'])) {
            throw new BlueprintGenerationFailedException('Blueprint tone.avoid must be an array.');
        }

        // --- landing_page ---
        if ($blueprint->landingPage !== null && filter_var($blueprint->landingPage, FILTER_VALIDATE_URL) === false) {
            throw new BlueprintGenerationFailedException('Blueprint landing_page must be a valid URL or null.');
        }

        // --- success_metrics ---
        if (empty($blueprint->successMetrics['primary_metric'] ?? null)) {
            throw new BlueprintGenerationFailedException('Blueprint success_metrics.primary_metric is required.');
        }
        if (! isset($blueprint->successMetrics['secondary_metrics']) || ! is_array($blueprint->successMetrics['secondary_metrics'])) {
            throw new BlueprintGenerationFailedException('Blueprint success_metrics.secondary_metrics must be an array.');
        }
        if (empty($blueprint->successMetrics['baseline'] ?? null)) {
            throw new BlueprintGenerationFailedException('Blueprint success_metrics.baseline is required.');
        }
        if (empty($blueprint->successMetrics['timeframe'] ?? null)) {
            throw new BlueprintGenerationFailedException('Blueprint success_metrics.timeframe is required.');
        }

        // --- channel_strategy ---
        if (empty($blueprint->channelStrategy)) {
            throw new BlueprintGenerationFailedException('Blueprint must include at least one channel strategy.');
        }

        $decisionChannelCount = count($decision->channel_ids ?? []);
        if (count($blueprint->channelStrategy) < $decisionChannelCount) {
            throw new BlueprintGenerationFailedException(
                "Blueprint channel_strategy must have at least one entry per decision channel ({$decisionChannelCount} channel(s), ".count($blueprint->channelStrategy).' strateg'.((count($blueprint->channelStrategy) === 1) ? 'y' : 'ies').' provided).'
            );
        }

        foreach ($blueprint->channelStrategy as $key => $strategy) {
            if (! is_array($strategy)) {
                throw new BlueprintGenerationFailedException(
                    "Blueprint channel_strategy.{$key} must be an object."
                );
            }
            foreach (['format', 'angle', 'constraints', 'priority'] as $field) {
                if (! array_key_exists($field, $strategy)) {
                    throw new BlueprintGenerationFailedException(
                        "Blueprint channel_strategy.{$key} is missing required field: {$field}."
                    );
                }
            }
            if (! is_array($strategy['constraints'])) {
                throw new BlueprintGenerationFailedException(
                    "Blueprint channel_strategy.{$key}.constraints must be an array."
                );
            }
            if (! is_numeric($strategy['priority'])) {
                throw new BlueprintGenerationFailedException(
                    "Blueprint channel_strategy.{$key}.priority must be a number."
                );
            }
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
