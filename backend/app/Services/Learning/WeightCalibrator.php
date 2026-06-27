<?php

namespace App\Services\Learning;

use App\Models\CompanyScoringWeights;
use App\Models\Learning;

class WeightCalibrator
{
    private const float ADJUSTMENT_STEP = 0.05;

    private const float MIN_MODIFIER = 0.50;

    private const float MAX_MODIFIER = 1.50;

    private const int COOLING_DAYS = 14;

    /**
     * Calibrate type_modifiers based on a Learning signal.
     * Returns an array of effect descriptors for the LearningApplication audit trail.
     *
     * @return list<array<string, mixed>>
     */
    public function calibrate(Learning $learning, string $companyId): array
    {
        $campaignType = $this->campaignTypeFor($learning);

        if ($campaignType === null) {
            return [];
        }

        $direction = $this->directionFor($learning->signal);

        if ($direction === null) {
            return [];
        }

        // Enforce cooling period — one calibration per company every 14 days
        $current = CompanyScoringWeights::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->current()
            ->first();

        if ($current !== null && $current->created_at->gt(now()->subDays(self::COOLING_DAYS))) {
            return [];
        }

        $existingModifiers = $current !== null ? $current->typeModifiers() : [];

        $currentValue = (float) ($existingModifiers[$campaignType] ?? 1.0);
        $adjustment = $direction === 'up' ? self::ADJUSTMENT_STEP : -self::ADJUSTMENT_STEP;
        $newValue = round(max(self::MIN_MODIFIER, min(self::MAX_MODIFIER, $currentValue + $adjustment)), 4);

        if ($newValue === $currentValue) {
            return []; // Already at bounds
        }

        $newModifiers = array_merge($existingModifiers, [$campaignType => $newValue]);
        $nextVersion = $current !== null ? ($current->version + 1) : 1;

        // Retire the current row
        if ($current !== null) {
            CompanyScoringWeights::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('is_current', true)
                ->update(['is_current' => false]);
        }

        $newWeights = CompanyScoringWeights::create([
            'company_id' => $companyId,
            'weights' => ['type_modifiers' => $newModifiers],
            'version' => $nextVersion,
            'is_current' => true,
        ]);

        return [[
            'type' => 'weight_calibration',
            'previous_weights_id' => $current?->id,
            'new_weights_id' => $newWeights->id,
            'campaign_type' => $campaignType,
            'old_modifier' => $currentValue,
            'new_modifier' => $newValue,
            'description' => "type_modifier for '{$campaignType}' adjusted from {$currentValue} to {$newValue}",
        ]];
    }

    private function campaignTypeFor(Learning $learning): ?string
    {
        /** @var array<string, mixed> $value */
        $value = $learning->value ?? [];

        return match ($learning->signal) {
            'campaign_type_succeeded', 'campaign_type_underperformed',
            'recommendation_approved', 'recommendation_rejected' => isset($value['campaign_type'])
                ? (string) $value['campaign_type']
                : null,
            default => null,
        };
    }

    private function directionFor(string $signal): ?string
    {
        return match ($signal) {
            'campaign_type_succeeded', 'recommendation_approved' => 'up',
            'campaign_type_underperformed', 'recommendation_rejected' => 'down',
            default => null,
        };
    }
}
