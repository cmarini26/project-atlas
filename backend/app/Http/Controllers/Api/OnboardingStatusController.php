<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyMembership;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Models\Integration;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OnboardingStatusController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $membership = CompanyMembership::where('user_id', $user->id)
            ->latest()
            ->first();

        if (! $membership) {
            return response()->json([
                'twin_status' => null,
                'integration_status' => null,
                'sync_started' => false,
                'fact_count' => 0,
                'opportunity_count' => 0,
                'recommendation_count' => 0,
                'first_recommendation_id' => null,
            ]);
        }

        $companyId = $membership->company_id;

        $integration = Integration::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->latest()
            ->first();

        $twin = DigitalTwin::where('company_id', $companyId)->first();

        $pendingRecommendation = Recommendation::where('company_id', $companyId)
            ->where('status', 'pending')
            ->oldest()
            ->first();

        $syncStarted = $integration?->last_run_at !== null;
        $factCount = Fact::where('company_id', $companyId)->where('is_current', true)->count();

        // Stalled: sync ran > 90 s ago but no facts were extracted. Most likely
        // cause is a queue worker that is not running (QUEUE_CONNECTION=redis with
        // no active worker). Surfaced to the UI so the user gets actionable feedback.
        $pipelineStalled = $syncStarted
            && $factCount === 0
            && $integration->status !== 'error'
            && Carbon::parse($integration->last_run_at)->lt(now()->subSeconds(90));

        return response()->json([
            'twin_status' => $twin?->status,
            'integration_status' => $integration?->status,
            'sync_started' => $syncStarted,
            'pipeline_stalled' => $pipelineStalled,
            'fact_count' => $factCount,
            'opportunity_count' => Opportunity::where('company_id', $companyId)->where('status', 'open')->count(),
            'recommendation_count' => Recommendation::where('company_id', $companyId)->where('status', 'pending')->count(),
            'first_recommendation_id' => $pendingRecommendation?->id,
        ]);
    }
}
