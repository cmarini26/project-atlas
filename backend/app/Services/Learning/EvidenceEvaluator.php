<?php

namespace App\Services\Learning;

use App\Models\Learning;

class EvidenceEvaluator
{
    /** @var array<string, string> Signal => the JSON key used as the discriminator */
    private const array DISCRIMINATOR_KEYS = [
        'channel_outperformed' => 'channel',
        'channel_underperformed' => 'channel',
        'campaign_type_succeeded' => 'campaign_type',
        'campaign_type_underperformed' => 'campaign_type',
        'content_angle_engaged' => 'campaign_type',
        'optimal_timing_signal' => 'channel_type',
        'recommendation_approved' => 'campaign_type',
        'recommendation_rejected' => 'campaign_type',
        'recommendation_edited_and_approved' => 'campaign_type',
    ];

    /**
     * Extract the discriminator value from a Learning record's payload.
     * Returns an empty string for signals that have no sub-discriminator.
     */
    public function discriminatorFor(Learning $learning): string
    {
        $key = self::DISCRIMINATOR_KEYS[$learning->signal] ?? null;

        if ($key === null) {
            return '';
        }

        /** @var array<string, mixed> $value */
        $value = $learning->value ?? [];

        return (string) ($value[$key] ?? '');
    }

    /**
     * Count corroborating Learning records for a (company, signal, discriminator) tuple
     * within the 90-day rolling window.
     */
    public function count(string $companyId, string $signal, string $discriminator): int
    {
        $all = Learning::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('signal', $signal)
            ->where('created_at', '>=', now()->subDays(90))
            ->get();

        if ($discriminator === '') {
            return $all->count();
        }

        return $all->filter(fn (Learning $l) => $this->discriminatorFor($l) === $discriminator)->count();
    }

    /**
     * Check whether there is enough evidence to apply a given Learning.
     */
    public function meetsThreshold(Learning $learning, int $threshold, string $companyId): bool
    {
        $discriminator = $this->discriminatorFor($learning);
        $count = $this->count($companyId, $learning->signal, $discriminator);

        return $count >= $threshold;
    }
}
