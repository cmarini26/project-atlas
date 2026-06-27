<?php

namespace App\Services\Analytics;

use App\Models\Approval;
use App\Models\Recommendation;
use Illuminate\Support\Facades\DB;

class RecommendationKpiService
{
    /**
     * @return array<string, mixed>
     */
    public function forCompany(string $companyId): array
    {
        $recommendations = Recommendation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['decision.opportunity'])
            ->get();

        $total = $recommendations->count();

        if ($total === 0) {
            return $this->emptyResult();
        }

        $approvals = Approval::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('approvable_type', ['recommendation'])
            ->get();

        $approvalCount = $approvals->where('action', 'approved')->count()
            + $approvals->where('action', 'edited_and_approved')->count();
        $rejectionCount = $approvals->where('action', 'rejected')->count();
        $editCount = $approvals->where('action', 'edited_and_approved')->count();
        $actedOn = $approvalCount + $rejectionCount;

        $approvalRate = $actedOn > 0 ? $approvalCount / $actedOn : 0.0;
        $rejectionRate = $actedOn > 0 ? $rejectionCount / $actedOn : 0.0;
        $editRate = $approvalCount > 0 ? $editCount / $approvalCount : 0.0;

        $medianHours = $this->computeMedianDecisionHours($companyId);

        $approvalByType = $this->approvalRateByOpportunityType($companyId);
        $approvalByChannel = $this->approvalRateByChannel($companyId);
        $trend = $this->approvalRateTrend30d($companyId);

        return [
            'approval_rate' => round($approvalRate, 4),
            'rejection_rate' => round($rejectionRate, 4),
            'edit_rate' => round($editRate, 4),
            'median_time_to_decision_hours' => $medianHours,
            'approval_rate_by_opportunity_type' => $approvalByType,
            'approval_rate_by_channel' => $approvalByChannel,
            'approval_rate_trend_30d' => $trend,
            'total_recommendations' => $total,
            'acted_on' => $actedOn,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyResult(): array
    {
        return [
            'approval_rate' => 0.0,
            'rejection_rate' => 0.0,
            'edit_rate' => 0.0,
            'median_time_to_decision_hours' => null,
            'approval_rate_by_opportunity_type' => [],
            'approval_rate_by_channel' => [],
            'approval_rate_trend_30d' => ['current' => 0.0, 'prior' => 0.0, 'delta' => 0.0],
            'total_recommendations' => 0,
            'acted_on' => 0,
        ];
    }

    private function computeMedianDecisionHours(string $companyId): ?float
    {
        try {
            $driver = DB::getDriverName();
            $diffExpr = $driver === 'pgsql'
                ? 'EXTRACT(EPOCH FROM (approvals.acted_at::timestamp - recommendations.created_at::timestamp)) / 3600.0 AS hours'
                : '(julianday(approvals.acted_at) - julianday(recommendations.created_at)) * 24 AS hours';

            $results = DB::table('approvals')
                ->where('company_id', $companyId)
                ->whereNotNull('acted_at')
                ->join('recommendations', function ($join): void {
                    $join->on('approvals.approvable_id', '=', 'recommendations.id')
                        ->where('approvals.approvable_type', 'recommendation');
                })
                ->whereNotNull('recommendations.created_at')
                ->selectRaw($diffExpr)
                ->pluck('hours')
                ->sort()
                ->values();
        } catch (\Throwable) {
            return null;
        }

        if ($results->isEmpty()) {
            return null;
        }

        $count = $results->count();
        $mid = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ((float) $results[$mid - 1] + (float) $results[$mid]) / 2;
        }

        return (float) $results[$mid];
    }

    /** @return array<string, float> */
    private function approvalRateByOpportunityType(string $companyId): array
    {
        $rows = DB::table('approvals')
            ->where('approvals.company_id', $companyId)
            ->where('approvals.approvable_type', 'recommendation')
            ->join('recommendations', 'approvals.approvable_id', '=', 'recommendations.id')
            ->join('decisions', 'recommendations.decision_id', '=', 'decisions.id')
            ->join('opportunities', 'decisions.opportunity_id', '=', 'opportunities.id')
            ->selectRaw('opportunities.type, approvals.action, COUNT(*) as cnt')
            ->groupBy('opportunities.type', 'approvals.action')
            ->get();

        $byType = [];
        foreach ($rows as $row) {
            $byType[$row->type][$row->action] = (int) $row->cnt;
        }

        $result = [];
        foreach ($byType as $type => $actions) {
            $approved = ($actions['approved'] ?? 0) + ($actions['edited_and_approved'] ?? 0);
            $total = array_sum($actions);
            $result[$type] = $total > 0 ? round($approved / $total, 4) : 0.0;
        }

        return $result;
    }

    /** @return array<string, float> */
    private function approvalRateByChannel(string $companyId): array
    {
        $rows = DB::table('approvals')
            ->where('approvals.company_id', $companyId)
            ->where('approvals.approvable_type', 'recommendation')
            ->join('recommendations', 'approvals.approvable_id', '=', 'recommendations.id')
            ->join('campaigns', 'recommendations.campaign_id', '=', 'campaigns.id')
            ->join('executions', 'campaigns.id', '=', 'executions.campaign_id')
            ->join('channels', 'executions.channel_id', '=', 'channels.id')
            ->selectRaw('channels.type as channel_type, approvals.action, COUNT(*) as cnt')
            ->groupBy('channels.type', 'approvals.action')
            ->get();

        $byChannel = [];
        foreach ($rows as $row) {
            $byChannel[$row->channel_type][$row->action] = (int) $row->cnt;
        }

        $result = [];
        foreach ($byChannel as $channel => $actions) {
            $approved = ($actions['approved'] ?? 0) + ($actions['edited_and_approved'] ?? 0);
            $total = array_sum($actions);
            $result[$channel] = $total > 0 ? round($approved / $total, 4) : 0.0;
        }

        return $result;
    }

    /** @return array{current: float, prior: float, delta: float} */
    private function approvalRateTrend30d(string $companyId): array
    {
        $now = now();
        $thirtyDaysAgo = $now->copy()->subDays(30);
        $sixtyDaysAgo = $now->copy()->subDays(60);

        $computeRate = function (string $start, string $end) use ($companyId): float {
            $rows = DB::table('approvals')
                ->where('company_id', $companyId)
                ->where('approvable_type', 'recommendation')
                ->whereBetween('acted_at', [$start, $end])
                ->selectRaw('action, COUNT(*) as cnt')
                ->groupBy('action')
                ->pluck('cnt', 'action');

            $approved = ((int) ($rows['approved'] ?? 0)) + ((int) ($rows['edited_and_approved'] ?? 0));
            $rejected = (int) ($rows['rejected'] ?? 0);
            $total = $approved + $rejected;

            return $total > 0 ? round($approved / $total, 4) : 0.0;
        };

        $current = $computeRate($thirtyDaysAgo->toDateTimeString(), $now->toDateTimeString());
        $prior = $computeRate($sixtyDaysAgo->toDateTimeString(), $thirtyDaysAgo->toDateTimeString());

        return [
            'current' => $current,
            'prior' => $prior,
            'delta' => round($current - $prior, 4),
        ];
    }
}
