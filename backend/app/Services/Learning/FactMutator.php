<?php

namespace App\Services\Learning;

use App\Models\Fact;
use App\Models\Learning;

class FactMutator
{
    /**
     * Apply fact mutations for a Learning signal.
     * Returns an array of effect descriptors for the LearningApplication audit trail.
     *
     * @return list<array<string, mixed>>
     */
    public function mutate(Learning $learning): array
    {
        return match ($learning->signal) {
            'channel_outperformed' => $this->channelAffinity($learning, 'strong', 70),
            'channel_underperformed' => $this->channelAffinity($learning, 'weak', 70),
            'email_deliverability_issue' => $this->channelHealthFact($learning, 'channel_health.email.status', 'compromised', 90),
            'high_unsubscribe_rate' => $this->channelHealthFact($learning, 'channel_health.email.list_quality', 'degraded', 80),
            'optimal_timing_signal' => $this->optimalTimingFact($learning),
            'recommendation_approved' => $this->campaignPreferenceFact($learning, 'positive', 65),
            'recommendation_rejected' => $this->campaignPreferenceFact($learning, 'negative', 65),
            default => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function channelAffinity(Learning $learning, string $affinity, int $confidence): array
    {
        /** @var array<string, mixed> $value */
        $value = $learning->value ?? [];
        $channel = (string) ($value['channel'] ?? '');

        if ($channel === '') {
            return [];
        }

        $key = "channel_performance.{$channel}.affinity";

        return $this->supersedeFact($learning, $key, $affinity, 'string', $confidence);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function channelHealthFact(Learning $learning, string $key, string $factValue, int $confidence): array
    {
        return $this->supersedeFact($learning, $key, $factValue, 'string', $confidence);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function optimalTimingFact(Learning $learning): array
    {
        /** @var array<string, mixed> $value */
        $value = $learning->value ?? [];
        $channelType = (string) ($value['channel_type'] ?? '');
        $hour = isset($value['published_hour']) ? (int) $value['published_hour'] : null;

        if ($channelType === '' || $hour === null) {
            return [];
        }

        $key = "channel_timing.{$channelType}.optimal_hour";

        return $this->supersedeFact($learning, $key, $hour, 'integer', 65);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function campaignPreferenceFact(Learning $learning, string $tendency, int $confidence): array
    {
        /** @var array<string, mixed> $value */
        $value = $learning->value ?? [];
        $campaignType = (string) ($value['campaign_type'] ?? '');

        if ($campaignType === '') {
            return [];
        }

        $key = "campaign_preference.{$campaignType}.approval_tendency";

        return $this->supersedeFact($learning, $key, $tendency, 'string', $confidence);
    }

    /**
     * Supersede any existing current Fact for this key and create a new one.
     *
     * @return list<array<string, mixed>>
     */
    private function supersedeFact(
        Learning $learning,
        string $key,
        mixed $factValue,
        string $dataType,
        int $confidence,
    ): array {
        $existing = Fact::withoutGlobalScopes()
            ->where('company_id', $learning->company_id)
            ->where('key', $key)
            ->where('is_current', true)
            ->first();

        $newFact = Fact::create([
            'company_id' => $learning->company_id,
            'observation_id' => null,
            'key' => $key,
            'value' => $factValue,
            'data_type' => $dataType,
            'confidence' => $confidence,
            'is_current' => true,
            'superseded_by_id' => null,
            'valid_from' => now(),
            'valid_until' => null,
        ]);

        if ($existing !== null) {
            $existing->update([
                'is_current' => false,
                'superseded_by_id' => $newFact->id,
                'valid_until' => now(),
            ]);
        }

        return [[
            'type' => 'fact_mutation',
            'fact_id' => $newFact->id,
            'previous_fact_id' => $existing?->id,
            'key' => $key,
            'value' => $factValue,
            'description' => "Fact '{$key}' set to '{$factValue}' from signal '{$learning->signal}'",
        ]];
    }
}
