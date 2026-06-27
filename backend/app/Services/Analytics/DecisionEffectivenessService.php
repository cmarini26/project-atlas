<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

class DecisionEffectivenessService
{
    /**
     * @return array<string, mixed>
     */
    public function forCompany(string $companyId): array
    {
        $rows = DB::table('campaign_kpi_snapshots')
            ->where('campaign_kpi_snapshots.company_id', $companyId)
            ->where('campaign_kpi_snapshots.snapshot_type', 'final')
            ->join('campaigns', 'campaign_kpi_snapshots.campaign_id', '=', 'campaigns.id')
            ->join('decisions', 'campaigns.decision_id', '=', 'decisions.id')
            ->join('opportunities', 'decisions.opportunity_id', '=', 'opportunities.id')
            ->select(
                'campaign_kpi_snapshots.performance_rating',
                'campaigns.campaign_type',
                'opportunities.type as opportunity_type',
                'opportunities.composite_score',
            )
            ->get();

        $total = $rows->count();

        if ($total === 0) {
            return $this->emptyResult();
        }

        $exceeded = $rows->where('performance_rating', 'exceeded')->count();
        $met = $rows->where('performance_rating', 'met')->count();
        $below = $rows->where('performance_rating', 'below')->count();

        $accuracyRate = ($exceeded + $met) / $total;

        $byDetector = [];
        $byType = [];

        foreach ($rows as $row) {
            $type = $row->campaign_type;
            $detector = $row->opportunity_type;
            $isAccurate = in_array($row->performance_rating, ['exceeded', 'met'], true);

            if (! isset($byType[$type])) {
                $byType[$type] = ['accurate' => 0, 'total' => 0];
            }
            $byType[$type]['total']++;
            if ($isAccurate) {
                $byType[$type]['accurate']++;
            }

            if (! isset($byDetector[$detector])) {
                $byDetector[$detector] = ['accurate' => 0, 'total' => 0];
            }
            $byDetector[$detector]['total']++;
            if ($isAccurate) {
                $byDetector[$detector]['accurate']++;
            }
        }

        $accuracyByType = [];
        foreach ($byType as $type => $counts) {
            $accuracyByType[$type] = round($counts['accurate'] / $counts['total'], 4);
        }

        $accuracyByDetector = [];
        foreach ($byDetector as $detector => $counts) {
            $accuracyByDetector[$detector] = round($counts['accurate'] / $counts['total'], 4);
        }

        $exceededRows = $rows->where('performance_rating', 'exceeded');
        $belowRows = $rows->where('performance_rating', 'below');

        $exceededAvg = $exceededRows->avg('composite_score');
        $avgScoreExceeded = $exceededAvg !== null ? round($exceededAvg, 2) : null;

        $belowAvg = $belowRows->avg('composite_score');
        $avgScoreBelow = $belowAvg !== null ? round($belowAvg, 2) : null;

        return [
            'decisions_total' => $total,
            'exceeded_pct' => round($exceeded / $total, 4),
            'met_pct' => round($met / $total, 4),
            'below_pct' => round($below / $total, 4),
            'accuracy_rate' => round($accuracyRate, 4),
            'accuracy_by_detector' => $accuracyByDetector,
            'accuracy_by_campaign_type' => $accuracyByType,
            'avg_composite_score_for_exceeded' => $avgScoreExceeded,
            'avg_composite_score_for_below' => $avgScoreBelow,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyResult(): array
    {
        return [
            'decisions_total' => 0,
            'exceeded_pct' => 0.0,
            'met_pct' => 0.0,
            'below_pct' => 0.0,
            'accuracy_rate' => 0.0,
            'accuracy_by_detector' => [],
            'accuracy_by_campaign_type' => [],
            'avg_composite_score_for_exceeded' => null,
            'avg_composite_score_for_below' => null,
        ];
    }
}
