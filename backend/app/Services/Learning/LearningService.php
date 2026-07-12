<?php

namespace App\Services\Learning;

use App\Models\Campaign;
use App\Models\CampaignKpiSnapshot;
use App\Models\ExecutionMetric;
use App\Models\Learning;

class LearningService
{
    public function recordFromMetrics(Campaign $campaign, CampaignKpiSnapshot $snapshot): void
    {
        /** @var array<string, mixed> $kpis */
        $kpis = $snapshot->actual_kpis ?? [];

        /** @var array<string, array<string, mixed>> $channelBreakdown */
        $channelBreakdown = $kpis['channel_breakdown'] ?? [];

        $this->checkChannelOutperformed($campaign, $snapshot, $channelBreakdown);
        $this->checkChannelUnderperformed($campaign, $snapshot, $channelBreakdown, $kpis);
        $this->checkCampaignTypeSucceeded($campaign, $snapshot);
        $this->checkCampaignTypeUnderperformed($campaign, $snapshot);
        $this->checkEmailDeliverability($campaign, $snapshot);
        $this->checkHighUnsubscribeRate($campaign, $snapshot);
        $this->checkContentAngleEngaged($campaign, $snapshot);
        $this->checkOptimalTiming($campaign, $snapshot);
        $this->checkReachExceeded($campaign, $snapshot, $kpis);
        $this->checkEngagementLow($campaign, $snapshot, $kpis);
        $this->checkClickRateHigh($campaign, $snapshot, $kpis);
    }

    /**
     * @param  array<string, array<string, mixed>>  $channelBreakdown
     */
    private function checkChannelOutperformed(
        Campaign $campaign,
        CampaignKpiSnapshot $snapshot,
        array $channelBreakdown,
    ): void {
        $rates = [];
        foreach ($channelBreakdown as $type => $data) {
            $rate = $data['engagement_rate'] ?? null;
            if ($rate !== null) {
                $rates[$type] = (float) $rate;
            }
        }

        if (count($rates) < 2) {
            return;
        }

        arsort($rates);
        $types = array_keys($rates);
        $values = array_values($rates);

        $best = $types[0];
        $bestRate = $values[0];
        $secondRate = $values[1];

        if ($secondRate <= 0 || $bestRate < ($secondRate * 1.5)) {
            return;
        }

        $this->createIfAbsent($campaign, $snapshot, 'channel_outperformed', [
            'channel' => $best,
            'rate' => $bestRate,
            'second_best_rate' => $secondRate,
            'ratio' => $secondRate > 0 ? round($bestRate / $secondRate, 2) : null,
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $channelBreakdown
     * @param  array<string, mixed>  $kpis
     */
    private function checkChannelUnderperformed(
        Campaign $campaign,
        CampaignKpiSnapshot $snapshot,
        array $channelBreakdown,
        array $kpis,
    ): void {
        $avgRate = $kpis['total_engagement_rate'] ?? null;
        if ($avgRate === null || (float) $avgRate <= 0) {
            return;
        }

        foreach ($channelBreakdown as $type => $data) {
            $rate = $data['engagement_rate'] ?? null;
            if ($rate === null) {
                continue;
            }

            if ((float) $rate < ((float) $avgRate * 0.5)) {
                $this->createIfAbsent($campaign, $snapshot, 'channel_underperformed', [
                    'channel' => $type,
                    'rate' => (float) $rate,
                    'campaign_average_rate' => (float) $avgRate,
                ], 'channel_underperformed_'.$type);
            }
        }
    }

    private function checkCampaignTypeSucceeded(Campaign $campaign, CampaignKpiSnapshot $snapshot): void
    {
        if ($snapshot->performance_rating !== 'exceeded') {
            return;
        }

        $this->createIfAbsent($campaign, $snapshot, 'campaign_type_succeeded', [
            'campaign_type' => $campaign->campaign_type,
            'performance_rating' => $snapshot->performance_rating,
        ]);
    }

    private function checkCampaignTypeUnderperformed(Campaign $campaign, CampaignKpiSnapshot $snapshot): void
    {
        if ($snapshot->performance_rating !== 'below') {
            return;
        }

        $recentBelowCount = CampaignKpiSnapshot::withoutGlobalScopes()
            ->where('company_id', $campaign->company_id)
            ->where('snapshot_type', 'final')
            ->where('performance_rating', 'below')
            ->whereHas('campaign', function ($q) use ($campaign): void {
                $q->withoutGlobalScopes()
                    ->where('campaign_type', $campaign->campaign_type);
            })
            ->orderBy('snapshotted_at', 'desc')
            ->limit(2)
            ->count();

        if ($recentBelowCount < 2) {
            return;
        }

        $this->createIfAbsent($campaign, $snapshot, 'campaign_type_underperformed', [
            'campaign_type' => $campaign->campaign_type,
            'consecutive_below_count' => $recentBelowCount,
        ]);
    }

    private function checkEmailDeliverability(Campaign $campaign, CampaignKpiSnapshot $snapshot): void
    {
        $metrics = ExecutionMetric::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->where('channel_type', 'email')
            ->get();

        foreach ($metrics as $metric) {
            /** @var array<string, mixed> $m */
            $m = $metric->metrics ?? [];

            $hardBounces = (int) ($m['bounces_hard'] ?? 0);
            $complaints = (int) ($m['spam_complaints'] ?? 0);
            $delivered = (int) ($m['delivered'] ?? 0);

            $hasDeliverabilityIssue = $hardBounces > 0
                || ($delivered > 0 && ($complaints / $delivered) > 0.001);

            if ($hasDeliverabilityIssue) {
                $this->createIfAbsent($campaign, $snapshot, 'email_deliverability_issue', [
                    'hard_bounces' => $hardBounces,
                    'spam_complaints' => $complaints,
                    'delivered' => $delivered,
                    'spam_complaint_rate' => $delivered > 0 ? round($complaints / $delivered, 5) : null,
                ]);

                return;
            }
        }
    }

    private function checkHighUnsubscribeRate(Campaign $campaign, CampaignKpiSnapshot $snapshot): void
    {
        $metrics = ExecutionMetric::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->where('channel_type', 'email')
            ->get();

        $totalUnsubscribes = 0;
        $totalDelivered = 0;

        foreach ($metrics as $metric) {
            /** @var array<string, mixed> $m */
            $m = $metric->metrics ?? [];
            $totalUnsubscribes += (int) ($m['unsubscribes'] ?? 0);
            $totalDelivered += (int) ($m['delivered'] ?? 0);
        }

        if ($totalDelivered === 0) {
            return;
        }

        $rate = $totalUnsubscribes / $totalDelivered;
        if ($rate <= 0.01) {
            return;
        }

        $this->createIfAbsent($campaign, $snapshot, 'high_unsubscribe_rate', [
            'unsubscribes' => $totalUnsubscribes,
            'delivered' => $totalDelivered,
            'unsubscribe_rate' => round($rate, 5),
        ]);
    }

    private function checkContentAngleEngaged(Campaign $campaign, CampaignKpiSnapshot $snapshot): void
    {
        if ($snapshot->performance_rating !== 'exceeded') {
            return;
        }

        /** @var array<string, mixed> $blueprint */
        $blueprint = $campaign->blueprint ?? [];

        /** @var list<array<string, mixed>> $channelStrategies */
        $channelStrategies = $blueprint['channel_strategy'] ?? [];

        $angles = [];
        foreach ($channelStrategies as $strategy) {
            $angle = $strategy['angle'] ?? null;
            if ($angle !== null && is_string($angle) && $angle !== '') {
                $angles[] = $angle;
            }
        }

        if (empty($angles)) {
            return;
        }

        $this->createIfAbsent($campaign, $snapshot, 'content_angle_engaged', [
            'campaign_type' => $campaign->campaign_type,
            'angles' => $angles,
            'performance_rating' => $snapshot->performance_rating,
        ]);
    }

    private function checkOptimalTiming(Campaign $campaign, CampaignKpiSnapshot $snapshot): void
    {
        $priorMetrics = ExecutionMetric::withoutGlobalScopes()
            ->where('company_id', $campaign->company_id)
            ->where('channel_type', 'email')
            ->where('is_final', true)
            ->where('campaign_id', '!=', $campaign->id)
            ->orderBy('retrieved_at', 'desc')
            ->limit(20)
            ->get();

        if ($priorMetrics->count() < 4) {
            return;
        }

        $currentMetric = ExecutionMetric::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->where('channel_type', 'email')
            ->first();

        if ($currentMetric === null) {
            return;
        }

        /** @var array<string, mixed> $m */
        $m = $currentMetric->metrics ?? [];
        $currentOpenRate = $m['open_rate'] ?? null;

        if ($currentOpenRate === null) {
            return;
        }

        $priorRates = $priorMetrics
            ->map(fn (ExecutionMetric $em) => ((array) ($em->metrics ?? []))['open_rate'] ?? null)
            ->filter()
            ->sort()
            ->values();

        if ($priorRates->count() < 4) {
            return;
        }

        $q3Index = (int) floor($priorRates->count() * 0.75);
        $q3 = (float) $priorRates[$q3Index];

        if ((float) $currentOpenRate <= $q3) {
            return;
        }

        $publishedHour = $currentMetric->created_at?->hour;
        if ($publishedHour === null) {
            return;
        }

        $this->createIfAbsent($campaign, $snapshot, 'optimal_timing_signal', [
            'channel_type' => 'email',
            'open_rate' => $currentOpenRate,
            'q3_open_rate' => $q3,
            'published_hour' => $publishedHour,
        ]);
    }

    /**
     * Reuses the same exceeded/met/below comparison CampaignKpiService::
     * ratePerformance() already applies at the campaign level, but against
     * total_reach specifically rather than engagement rate.
     *
     * @param  array<string, mixed>  $kpis
     */
    private function checkReachExceeded(Campaign $campaign, CampaignKpiSnapshot $snapshot, array $kpis): void
    {
        $actual = $kpis['total_reach'] ?? null;
        $baseline = $snapshot->expected_impact['target_reach'] ?? null;

        if ($actual === null || $baseline === null || ! is_numeric($baseline) || (float) $baseline <= 0) {
            return;
        }

        if ((float) $actual < (float) $baseline * 1.25) {
            return;
        }

        $this->createIfAbsent($campaign, $snapshot, 'reach_exceeded', [
            'total_reach' => $actual,
            'target_reach' => $baseline,
        ]);
    }

    /**
     * @param  array<string, mixed>  $kpis
     */
    private function checkEngagementLow(Campaign $campaign, CampaignKpiSnapshot $snapshot, array $kpis): void
    {
        $actual = $kpis['total_engagement_rate'] ?? null;
        $baseline = $snapshot->expected_impact['target_engagement_rate'] ?? null;

        if ($actual === null || $baseline === null || ! is_numeric($baseline) || (float) $baseline <= 0) {
            return;
        }

        if ((float) $actual >= (float) $baseline * 0.75) {
            return;
        }

        $this->createIfAbsent($campaign, $snapshot, 'engagement_low', [
            'total_engagement_rate' => $actual,
            'target_engagement_rate' => $baseline,
        ]);
    }

    /**
     * @param  array<string, mixed>  $kpis
     */
    private function checkClickRateHigh(Campaign $campaign, CampaignKpiSnapshot $snapshot, array $kpis): void
    {
        $actual = $kpis['total_click_rate'] ?? null;
        $baseline = $snapshot->expected_impact['target_click_rate'] ?? null;

        if ($actual === null || $baseline === null || ! is_numeric($baseline) || (float) $baseline <= 0) {
            return;
        }

        if ((float) $actual < (float) $baseline * 1.25) {
            return;
        }

        $this->createIfAbsent($campaign, $snapshot, 'click_rate_high', [
            'total_click_rate' => $actual,
            'target_click_rate' => $baseline,
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function createIfAbsent(
        Campaign $campaign,
        CampaignKpiSnapshot $snapshot,
        string $signal,
        array $value,
        ?string $signalKey = null,
    ): void {
        $signalKey ??= $signal;

        $exists = Learning::withoutGlobalScopes()
            ->where('company_id', $campaign->company_id)
            ->where('source_id', $snapshot->id)
            ->where('signal', $signalKey)
            ->exists();

        if ($exists) {
            return;
        }

        Learning::create([
            'company_id' => $campaign->company_id,
            'source_type' => 'execution_result',
            'source_id' => $snapshot->id,
            'subject_type' => 'campaign',
            'subject_id' => $campaign->id,
            'signal' => $signal,
            'value' => $value,
            'applied_at' => null,
        ]);
    }
}
