<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessObservation;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Observation;
use App\Models\User;
use App\Services\Discovery\BusinessDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Polled by the Discovery progress screen. Aggregates across a company's
 * whole DiscoveryRun — every declared asset Discovery attempted, not "the
 * one Integration" a pre-Milestone-15 version of this endpoint was scoped
 * to. There is only one onboarding execution path (BusinessDiscoveryService)
 * this endpoint reports on; it has no separate legacy status logic.
 */
class OnboardingStatusController extends Controller
{
    public function __construct(private readonly BusinessDiscoveryService $discovery) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $membership = CompanyMembership::with('company')->where('user_id', $user->id)->latest()->first();

        if ($membership === null || ! ($membership->company instanceof Company)) {
            return response()->json($this->emptyPayload());
        }

        $company = $membership->company;

        // Re-dispatch observations parked in 'retrying' (AI provider was
        // overloaded) before computing progress, so a successful inline
        // retry is reflected in this poll's response.
        $this->retryStaleObservations($company->id);

        $progress = $this->discovery->progressFor($company);

        return response()->json($progress ?? $this->emptyPayload());
    }

    /** @return array<string, mixed> */
    private function emptyPayload(): array
    {
        return [
            'stage' => null,
            'started_at' => null,
            'completed_at' => null,
            'connectors' => [],
            'facts_created' => 0,
            'opportunities_found' => 0,
            'recommendations_generated' => 0,
            'recommendation_count' => 0,
            'first_recommendation_id' => null,
            'retry_available' => false,
        ];
    }

    /**
     * With an async queue the job's own $tries/$backoff drive the retries and
     * 'retrying' is a transient state between attempts — nothing to do here.
     * With the sync queue there is no worker to pick the job back up, so this
     * endpoint (polled by the Discovery progress screen every few seconds)
     * re-dispatches stale observations inline, throttled to one attempt per
     * 30 s.
     */
    private function retryStaleObservations(string $companyId): void
    {
        if (config('queue.default') !== 'sync') {
            return;
        }

        $retrying = Observation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'retrying')
            ->get();

        foreach ($retrying as $observation) {
            if ($observation->updated_at?->gt(now()->subSeconds(30))) {
                continue;
            }

            try {
                ProcessObservation::dispatch($observation);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
