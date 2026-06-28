<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Execution;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $campaigns = Campaign::where('company_id', $company->id)
            ->latest()
            ->paginate(15);

        return Inertia::render('App/Campaigns/Index', [
            'campaigns' => [
                'data' => collect($campaigns->items())->map(fn (Campaign $c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'campaign_type' => $c->campaign_type,
                    'status' => $c->status,
                    'created_at' => $c->created_at?->toIso8601String() ?? '',
                    'completed_at' => $c->completed_at?->toIso8601String(),
                ])->values()->all(),
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    public function show(Request $request, Campaign $campaign): Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        abort_if($campaign->company_id !== $company->id, 404);

        $campaign->load(['decision', 'kpiSnapshots']);

        $contentAssets = ContentAsset::with('channel')
            ->where('company_id', $company->id)
            ->where('campaign_id', $campaign->id)
            ->get();

        $executions = Execution::with('channel')
            ->where('company_id', $company->id)
            ->where('campaign_id', $campaign->id)
            ->get();

        $kpiSnapshot = $campaign->kpiSnapshots()
            ->where('snapshot_type', 'final')
            ->latest('snapshotted_at')
            ->first()
            ?? $campaign->kpiSnapshots()
                ->where('snapshot_type', 'interim')
                ->latest('snapshotted_at')
                ->first();

        return Inertia::render('App/Campaigns/Show', [
            'campaign' => [
                'id' => $campaign->id,
                'title' => $campaign->title,
                'campaign_type' => $campaign->campaign_type,
                'status' => $campaign->status,
                'created_at' => $campaign->created_at?->toIso8601String() ?? '',
                'completed_at' => $campaign->completed_at?->toIso8601String(),
                'blueprint' => $campaign->blueprint,
            ],
            'content_assets' => $contentAssets->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'body' => $a->body,
                'title' => $a->title,
                'status' => $a->status,
                'metadata' => $a->metadata ?? [],
                'channel' => $a->channel ? ['type' => $a->channel->type] : null,
            ])->values()->all(),
            'executions' => $executions->map(fn ($e) => [
                'id' => $e->id,
                'status' => $e->status,
                'scheduled_at' => $e->scheduled_at?->toIso8601String(),
                'executed_at' => $e->executed_at?->toIso8601String(),
                'completed_at' => $e->completed_at?->toIso8601String(),
                'last_error' => $e->last_error,
                'channel' => $e->channel ? ['type' => $e->channel->type] : null,
            ])->values()->all(),
            'kpi_snapshot' => $kpiSnapshot ? [
                'id' => $kpiSnapshot->id,
                'snapshot_type' => $kpiSnapshot->snapshot_type,
                'actual_kpis' => $kpiSnapshot->actual_kpis ?? [],
                'performance_rating' => $kpiSnapshot->performance_rating,
                'snapshotted_at' => $kpiSnapshot->snapshotted_at->toIso8601String(),
            ] : null,
            'decision' => $campaign->decision ? [
                'rationale' => $campaign->decision->rationale,
                'expected_impact' => $campaign->decision->expected_impact,
                'confidence_score' => $campaign->decision->confidence_score ?? 0,
            ] : null,
        ]);
    }
}
