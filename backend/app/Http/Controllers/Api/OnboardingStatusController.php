<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessObservation;
use App\Models\CompanyMembership;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Models\Integration;
use App\Models\Observation;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

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
                'crawl_succeeded' => false,
                'pipeline_stalled' => false,
                'ai_failed' => false,
                'ai_retrying' => false,
                'no_opportunities' => false,
                'fact_count' => 0,
                'opportunity_count' => 0,
                'recommendation_count' => 0,
                'first_recommendation_id' => null,
            ]);
        }

        $companyId = $membership->company_id;

        // Re-dispatch observations parked in 'retrying' (AI provider was
        // overloaded) before computing counts, so a successful inline retry
        // is reflected in this poll's response.
        $aiRetrying = $this->retryStaleObservations($companyId);

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

        // crawl_succeeded: at least one Observation was recorded for this company,
        // meaning the website was reachable and the connector returned data.
        $crawlSucceeded = Observation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->exists();

        // ai_failed: an Observation was created but processing failed, meaning
        // the AI provider threw or returned an unusable response.
        $aiFailed = $crawlSucceeded && Observation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'failed')
            ->exists();

        // Stalled: no queue worker is processing the pipeline. Two shapes:
        // - the sync job was queued > 90 s ago and never started (last_run_at
        //   still null) — the onboarding submit queues the crawl instead of
        //   running it inline, so a missing worker now stalls before the crawl;
        // - the sync ran > 90 s ago but no facts were extracted afterwards.
        // Surfaced to the UI so the user gets actionable feedback.
        $syncQueuedButNeverStarted = ! $syncStarted
            && $integration !== null
            && $integration->status === 'active'
            && $integration->created_at !== null
            && Carbon::parse($integration->created_at)->lt(now()->subSeconds(90));

        $syncRanButNoFacts = $syncStarted
            && $factCount === 0
            && $integration->status !== 'error'
            && Carbon::parse($integration->last_run_at)->lt(now()->subSeconds(90));

        $pipelineStalled = ($syncQueuedButNeverStarted || $syncRanButNoFacts)
            && ! $aiFailed
            && ! $aiRetrying;

        $opportunityCount = Opportunity::where('company_id', $companyId)->where('status', 'open')->count();
        $recommendationCount = Recommendation::where('company_id', $companyId)->where('status', 'pending')->count();

        // No opportunities: Atlas learned the business (facts exist) but the
        // scan legitimately produced nothing to act on, so no recommendation
        // will appear. Only asserted once the last processed observation is
        // > 90 s old — before that, the scan/decision chain may still be running.
        $lastProcessedAt = Observation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'processed')
            ->max('processed_at');

        $noOpportunities = $factCount > 0
            && $opportunityCount === 0
            && $recommendationCount === 0
            && ! $aiFailed
            && ! $aiRetrying
            && $lastProcessedAt !== null
            && Carbon::parse($lastProcessedAt)->lt(now()->subSeconds(90));

        return response()->json([
            'twin_status' => $twin?->status,
            'integration_status' => $integration?->status,
            'sync_started' => $syncStarted,
            'crawl_succeeded' => $crawlSucceeded,
            'pipeline_stalled' => $pipelineStalled,
            'ai_failed' => $aiFailed,
            'ai_retrying' => $aiRetrying,
            'no_opportunities' => $noOpportunities,
            'fact_count' => $factCount,
            'opportunity_count' => $opportunityCount,
            'recommendation_count' => $recommendationCount,
            'first_recommendation_id' => $pendingRecommendation?->id,
        ]);
    }

    /**
     * Handle observations parked in 'retrying' after the AI provider reported
     * it was overloaded, and report whether any are still waiting.
     *
     * With an async queue the job's own $tries/$backoff drive the retries and
     * 'retrying' is a transient state between attempts — nothing to do here.
     * With the sync queue there is no worker to pick the job back up, so this
     * endpoint (polled by the onboarding status page every 5 s) re-dispatches
     * stale observations inline, throttled to one attempt per 30 s.
     */
    private function retryStaleObservations(string $companyId): bool
    {
        $retrying = Observation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'retrying')
            ->get();

        if ($retrying->isEmpty()) {
            return false;
        }

        if (config('queue.default') !== 'sync') {
            return true;
        }

        foreach ($retrying as $observation) {
            if ($observation->updated_at?->gt(now()->subSeconds(30))) {
                continue;
            }

            try {
                ProcessObservation::dispatch($observation);
            } catch (Throwable $e) {
                // Still overloaded (observation is back in 'retrying'), or a
                // non-transient error surfaced (observation now 'failed') —
                // either way the flags below reflect the fresh state.
                report($e);
            }
        }

        return Observation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'retrying')
            ->exists();
    }
}
