<?php

namespace App\Services\Analytics;

use App\Models\Campaign;
use App\Models\CampaignKpiSnapshot;
use App\Models\ExecutionMetric;
use App\Services\Learning\LearningService;

class CampaignKpiService
{
    public function __construct(private readonly LearningService $learningService) {}

    /**
     * @return array<string, mixed>
     */
    public function aggregate(string $campaignId): array
    {
        /** @var list<ExecutionMetric> $metrics */
        $metrics = ExecutionMetric::withoutGlobalScopes()
            ->where('campaign_id', $campaignId)
            ->get()
            ->all();

        $totalReach = 0;
        $totalEngagement = 0;
        $totalClicks = 0;
        $channelBreakdown = [];

        foreach ($metrics as $metric) {
            /** @var array<string, mixed> $m */
            $m = $metric->metrics ?? [];

            $reach = (int) ($m['normalised_reach'] ?? 0);
            $engagement = (int) ($m['normalised_engagement'] ?? 0);
            $clicks = (int) ($m['normalised_clicks'] ?? 0);
            $channelType = $metric->channel_type;

            $totalReach += $reach;
            $totalEngagement += $engagement;
            $totalClicks += $clicks;

            if (! isset($channelBreakdown[$channelType])) {
                $channelBreakdown[$channelType] = [
                    'reach' => 0,
                    'engagement' => 0,
                    'engagement_rate' => null,
                ];
            }

            $channelBreakdown[$channelType]['reach'] += $reach;
            $channelBreakdown[$channelType]['engagement'] += $engagement;
        }

        foreach ($channelBreakdown as $type => $data) {
            if ($data['reach'] > 0) {
                $channelBreakdown[$type]['engagement_rate'] = $data['engagement'] / $data['reach'];
            }
        }

        $totalEngagementRate = $totalReach > 0 ? ($totalEngagement / $totalReach) : null;
        $totalClickRate = $totalReach > 0 ? ($totalClicks / $totalReach) : null;

        $bestChannel = $this->bestChannel($channelBreakdown);

        return [
            'total_reach' => $totalReach,
            'total_engagement' => $totalEngagement,
            'total_engagement_rate' => $totalEngagementRate,
            'total_clicks' => $totalClicks,
            'total_click_rate' => $totalClickRate,
            'channel_breakdown' => $channelBreakdown,
            'best_channel' => $bestChannel,
        ];
    }

    public function snapshotIfReady(string $campaignId): ?CampaignKpiSnapshot
    {
        $metrics = ExecutionMetric::withoutGlobalScopes()
            ->where('campaign_id', $campaignId)
            ->get();

        if ($metrics->isEmpty()) {
            return null;
        }

        $allFinal = $metrics->every(fn (ExecutionMetric $m) => $m->is_final);

        $campaign = Campaign::withoutGlobalScopes()
            ->with('decision')
            ->findOrFail($campaignId);

        $expectedImpact = $campaign->decision?->expected_impact;
        $actualKpis = $this->aggregate($campaignId);
        $rating = $this->ratePerformance($actualKpis, $expectedImpact ?? []);

        if ($allFinal) {
            $existing = CampaignKpiSnapshot::withoutGlobalScopes()
                ->where('campaign_id', $campaignId)
                ->where('snapshot_type', 'final')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $snapshot = CampaignKpiSnapshot::create([
                'company_id' => $campaign->company_id,
                'campaign_id' => $campaignId,
                'snapshot_type' => 'final',
                'snapshotted_at' => now(),
                'channels_included' => array_keys($actualKpis['channel_breakdown'] ?? []),
                'expected_impact' => $expectedImpact,
                'actual_kpis' => $actualKpis,
                'performance_rating' => $rating,
            ]);

            $this->learningService->recordFromMetrics($campaign, $snapshot);

            return $snapshot;
        }

        $existingInterim = CampaignKpiSnapshot::withoutGlobalScopes()
            ->where('campaign_id', $campaignId)
            ->where('snapshot_type', 'interim')
            ->exists();

        if ($existingInterim) {
            return null;
        }

        return CampaignKpiSnapshot::create([
            'company_id' => $campaign->company_id,
            'campaign_id' => $campaignId,
            'snapshot_type' => 'interim',
            'snapshotted_at' => now(),
            'channels_included' => array_keys($actualKpis['channel_breakdown'] ?? []),
            'expected_impact' => $expectedImpact,
            'actual_kpis' => $actualKpis,
            'performance_rating' => $rating,
        ]);
    }

    /**
     * @param  array<string, mixed>  $actualKpis
     * @param  array<string, mixed>  $expectedImpact
     */
    public function ratePerformance(array $actualKpis, array $expectedImpact): string
    {
        $actualRate = $actualKpis['total_engagement_rate'] ?? null;

        if ($actualRate === null) {
            return 'insufficient_data';
        }

        $baseline = $expectedImpact['target_engagement_rate'] ?? $expectedImpact['target_reach'] ?? null;

        if ($baseline === null || ! is_numeric($baseline) || (float) $baseline <= 0) {
            return 'insufficient_data';
        }

        $baseline = (float) $baseline;
        $actual = (float) $actualRate;

        if ($actual >= $baseline * 1.25) {
            return 'exceeded';
        }

        if ($actual >= $baseline * 0.75) {
            return 'met';
        }

        return 'below';
    }

    /**
     * @param  array<string, array<string, mixed>>  $channelBreakdown
     */
    public function bestChannel(array $channelBreakdown): string
    {
        $best = '';
        $bestRate = -1.0;

        foreach ($channelBreakdown as $type => $data) {
            $rate = $data['engagement_rate'] ?? null;
            if ($rate !== null && (float) $rate > $bestRate) {
                $bestRate = (float) $rate;
                $best = $type;
            }
        }

        return $best;
    }
}
