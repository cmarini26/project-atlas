<?php

namespace App\Services\Learning;

use App\Models\Learning;
use Illuminate\Support\Collection;

class SignalTier
{
    public const int SAFETY = 1;

    public const int PERFORMANCE = 2;

    public const int PREFERENCE = 3;

    /** @var array<string, int> */
    private const array SIGNAL_TIERS = [
        'email_deliverability_issue' => self::SAFETY,
        'high_unsubscribe_rate' => self::SAFETY,
        'channel_outperformed' => self::PERFORMANCE,
        'channel_underperformed' => self::PERFORMANCE,
        'campaign_type_succeeded' => self::PERFORMANCE,
        'campaign_type_underperformed' => self::PERFORMANCE,
        'optimal_timing_signal' => self::PERFORMANCE,
        'reach_exceeded' => self::PERFORMANCE,
        'engagement_low' => self::PERFORMANCE,
        'click_rate_high' => self::PERFORMANCE,
        'content_angle_engaged' => self::PREFERENCE,
        'recommendation_approved' => self::PREFERENCE,
        'recommendation_rejected' => self::PREFERENCE,
        'recommendation_edited_and_approved' => self::PREFERENCE,
    ];

    /** @var array<int, int> Evidence threshold per tier (minimum corroborating signals needed) */
    private const array TIER_THRESHOLDS = [
        self::SAFETY => 1,
        self::PERFORMANCE => 2,
        self::PREFERENCE => 3,
    ];

    public function tierFor(string $signal): int
    {
        return self::SIGNAL_TIERS[$signal] ?? self::PREFERENCE;
    }

    public function thresholdFor(int $tier): int
    {
        return self::TIER_THRESHOLDS[$tier] ?? 3;
    }

    /**
     * Return the collection sorted by tier (Tier 1 first) then by created_at ascending.
     *
     * @param  Collection<int, Learning>  $learnings
     * @return Collection<int, Learning>
     */
    public function prioritise(Collection $learnings): Collection
    {
        return $learnings->sortBy([
            fn (Learning $a, Learning $b) => $this->tierFor($a->signal) <=> $this->tierFor($b->signal),
            fn (Learning $a, Learning $b) => $a->created_at <=> $b->created_at,
        ])->values();
    }
}
