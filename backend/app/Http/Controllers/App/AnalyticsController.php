<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignKpiSnapshot;
use App\Models\Company;
use App\Models\ExecutionMetric;
use App\Services\Analytics\RecommendationKpiService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function __construct(private readonly RecommendationKpiService $kpiService) {}

    public function index(Request $request): Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $recommendationKpis = $this->kpiService->forCompany($company->id);

        $snapshots = CampaignKpiSnapshot::where('company_id', $company->id)
            ->where('snapshot_type', 'final')
            ->with('campaign')
            ->latest('snapshotted_at')
            ->limit(10)
            ->get()
            ->map(fn (CampaignKpiSnapshot $s) => [
                'id' => $s->id,
                'snapshot_type' => $s->snapshot_type,
                'actual_kpis' => $s->actual_kpis ?? [],
                'performance_rating' => $s->performance_rating,
                'snapshotted_at' => $s->snapshotted_at->toIso8601String(),
                'campaign' => $s->campaign ? [
                    'id' => $s->campaign->id,
                    'title' => $s->campaign->title,
                    'campaign_type' => $s->campaign->campaign_type,
                ] : null,
            ]);

        return Inertia::render('App/Analytics/Index', [
            'recommendation_kpis' => $recommendationKpis,
            'campaign_snapshots' => $snapshots->values()->all(),
        ]);
    }

    public function show(Request $request, Campaign $campaign): Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        abort_if($campaign->company_id !== $company->id, 404);

        $campaign->load('decision');

        $snapshot = CampaignKpiSnapshot::where('campaign_id', $campaign->id)
            ->where('snapshot_type', 'final')
            ->latest('snapshotted_at')
            ->first()
            ?? CampaignKpiSnapshot::where('campaign_id', $campaign->id)
                ->where('snapshot_type', 'interim')
                ->latest('snapshotted_at')
                ->first();

        $metrics = ExecutionMetric::where('campaign_id', $campaign->id)
            ->orderByDesc('retrieved_at')
            ->get()
            ->map(fn (ExecutionMetric $m) => [
                'id' => $m->id,
                'channel_type' => $m->channel_type,
                'provider_type' => $m->provider_type,
                'metrics' => $m->metrics ?? [],
                'retrieved_at' => $m->retrieved_at?->toIso8601String() ?? '',
                'is_final' => $m->is_final,
            ]);

        return Inertia::render('App/Analytics/Show', [
            'campaign' => [
                'id' => $campaign->id,
                'title' => $campaign->title,
                'campaign_type' => $campaign->campaign_type,
                'status' => $campaign->status,
            ],
            'decision' => $campaign->decision ? [
                'expected_impact' => $campaign->decision->expected_impact,
                'confidence_score' => $campaign->decision->confidence_score ?? 0,
            ] : null,
            'snapshot' => $snapshot ? [
                'id' => $snapshot->id,
                'snapshot_type' => $snapshot->snapshot_type,
                'actual_kpis' => $snapshot->actual_kpis ?? [],
                'performance_rating' => $snapshot->performance_rating,
                'snapshotted_at' => $snapshot->snapshotted_at->toIso8601String(),
            ] : null,
            'metrics' => $metrics->values()->all(),
        ]);
    }
}
