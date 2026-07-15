<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Execution;
use App\Models\Fact;
use App\Models\Integration;
use App\Models\Knowledge;
use App\Models\Learning;
use App\Models\Opportunity;
use App\Models\Recommendation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        if (! Integration::where('company_id', $company->id)->exists()) {
            return redirect()->route('onboarding');
        }

        $twin = DigitalTwin::where('company_id', $company->id)->first();

        $pendingCount = Recommendation::where('company_id', $company->id)
            ->where('status', 'pending')
            ->count();

        $openOpportunities = Opportunity::where('company_id', $company->id)
            ->where('status', 'open')
            ->count();

        $activeCampaigns = Campaign::where('company_id', $company->id)
            ->whereIn('status', ['approved', 'published'])
            ->count();

        $unappliedLearnings = Learning::where('company_id', $company->id)
            ->whereNull('applied_at')
            ->count();

        $pendingRecommendation = Recommendation::with(['decision', 'campaign'])
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->latest()
            ->first();

        $recentCampaigns = Campaign::where('company_id', $company->id)
            ->latest()
            ->limit(3)
            ->get();

        // Drives the Dashboard's progressive reveal: a brand-new company
        // with zero campaigns ever created gets the Health card full-width
        // instead of sharing a row with a guaranteed-empty Campaigns card.
        $hasCampaignHistory = $recentCampaigns->isNotEmpty();

        $recentExecutions = Execution::with(['channel', 'contentAsset'])
            ->where('company_id', $company->id)
            ->latest()
            ->limit(5)
            ->get();

        $health = [
            'twin_status' => $twin !== null ? $twin->status : 'initializing',
            'twin_health_score' => $twin !== null ? ($twin->health_score ?? 0) : 0,
            'twin_last_enriched_at' => $twin !== null ? ($twin->last_enriched_at !== null ? (string) $twin->last_enriched_at : null) : null,
            'fact_count' => Fact::where('company_id', $company->id)->where('is_current', true)->count(),
            'knowledge_count' => Knowledge::where('company_id', $company->id)->where('is_active', true)->count(),
            'integration_count' => Integration::where('company_id', $company->id)->count(),
            'integration_statuses' => Integration::where('company_id', $company->id)
                ->get(['type', 'status'])
                ->map(fn ($i) => ['type' => $i->type, 'status' => $i->status])
                ->values()
                ->all(),
        ];

        return Inertia::render('App/Dashboard', [
            'counts' => [
                'pending_recommendations' => $pendingCount,
                'open_opportunities' => $openOpportunities,
                'active_campaigns' => $activeCampaigns,
                'unapplied_learnings' => $unappliedLearnings,
            ],
            'pending_recommendation' => $pendingRecommendation ? [
                'id' => $pendingRecommendation->id,
                'status' => $pendingRecommendation->status,
                'campaign_type' => $pendingRecommendation->campaign_type,
                'rationale_display' => $pendingRecommendation->rationale_display,
                'expected_impact' => $pendingRecommendation->expected_impact,
                'created_at' => $pendingRecommendation->created_at?->toIso8601String() ?? '',
                'decision' => $pendingRecommendation->decision ? [
                    'confidence_score' => $pendingRecommendation->decision->confidence_score ?? 0,
                    'rationale' => $pendingRecommendation->decision->rationale,
                ] : null,
            ] : null,
            'recent_campaigns' => $recentCampaigns->map(fn (Campaign $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'campaign_type' => $c->campaign_type,
                'status' => $c->status,
                'created_at' => $c->created_at?->toIso8601String() ?? '',
            ])->values()->all(),
            'recent_executions' => $recentExecutions->map(fn ($e) => [
                'id' => $e->id,
                'status' => $e->status,
                'scheduled_at' => $e->scheduled_at?->toIso8601String(),
                'completed_at' => $e->completed_at?->toIso8601String(),
                'channel' => $e->channel ? ['type' => $e->channel->type] : null,
            ])->values()->all(),
            'health' => $health,
            'has_campaign_history' => $hasCampaignHistory,
        ]);
    }
}
