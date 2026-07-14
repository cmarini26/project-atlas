<?php

namespace App\Services\Discovery;

use App\Enums\DiscoveryAttemptStatus;
use App\Enums\DiscoveryStage;
use App\Enums\MarketingChannelStatus;
use App\Jobs\SyncIntegration;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\DiscoveryConnectorAttempt;
use App\Models\DiscoveryRun;
use App\Models\Fact;
use App\Models\Integration;
use App\Models\Knowledge;
use App\Models\MarketingChannel;
use App\Models\Observation;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Services\MarketingPresence\MarketingPresenceService;
use App\Services\Observatory\IntegrationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Orchestrates Business Discovery: given a company's already-declared
 * Marketing Assets, dispatches the existing connector/observation pipeline
 * for every one that can currently be observed, and tracks progress as a
 * pure observability layer alongside it. Never observes, extracts facts,
 * synthesizes knowledge, detects opportunities, or generates recommendations
 * itself — that is entirely the existing, unchanged pipeline's job. See
 * docs/specs/Business-Discovery-Onboarding.md §4.
 */
class BusinessDiscoveryService
{
    public function __construct(
        private readonly DiscoveryPlanner $planner,
        private readonly IntegrationService $integrations,
        private readonly MarketingPresenceService $marketingPresence,
    ) {}

    /**
     * Start (or restart) Discovery for a company. Safe to call repeatedly:
     * an asset already linked to a connected Integration is resynced via
     * that same Integration rather than a new one being created, so no
     * declared asset or Integration is ever duplicated across runs.
     */
    public function start(Company $company): DiscoveryRun
    {
        $run = DiscoveryRun::create([
            'company_id' => $company->id,
            'stage' => DiscoveryStage::Discovering,
            'started_at' => now(),
        ]);

        $channels = MarketingChannel::where('company_id', $company->id)
            ->where('status', MarketingChannelStatus::Active)
            ->get();

        foreach ($channels as $channel) {
            $this->attempt($run, $company, $channel);
        }

        $this->refreshStage($run);

        return $run;
    }

    private function attempt(DiscoveryRun $run, Company $company, MarketingChannel $channel): void
    {
        $plan = $this->planner->planFor($channel);

        if ($plan === null) {
            return;
        }

        if ($plan->isReuse()) {
            $integration = $plan->existingIntegration;
        } else {
            /** @var string $connectorType */
            $connectorType = $plan->connectorType;
            $integration = $this->integrations->create($company, $connectorType, $plan->config);
            $this->marketingPresence->linkIntegration($channel, $integration);
        }

        /** @var Integration $integration */
        DiscoveryConnectorAttempt::create([
            'discovery_run_id' => $run->id,
            'company_id' => $company->id,
            'marketing_channel_id' => $channel->id,
            'integration_id' => $integration->id,
            'connector_type' => $integration->type,
            'status' => DiscoveryAttemptStatus::Pending,
            'attempt_count' => 0,
        ]);

        // A connector that throws (unreachable site, API error) must never
        // abort the whole Discovery run — SyncIntegration::failed() has
        // already marked the Integration + this attempt as failed by the
        // time the exception reaches here; every other declared asset must
        // still get its chance.
        try {
            SyncIntegration::dispatch($integration);
        } catch (Throwable) {
            // Swallowed deliberately — see comment above.
        }
    }

    /**
     * Recompute this run's stage from scratch, from current persisted state
     * only — never incrementally mutated. Safe (and cheap) to call as often
     * as needed; recomputing to the same stage is a no-op.
     */
    public function refreshStage(DiscoveryRun $run): DiscoveryRun
    {
        $attempts = DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->get();

        $stage = $this->computeStage($run, $attempts);

        $run->stage = $stage;

        if ($stage->isTerminal() && $run->completed_at === null) {
            $run->completed_at = now();
        }

        $run->save();

        return $run;
    }

    /** @param Collection<int, DiscoveryConnectorAttempt> $attempts */
    private function computeStage(DiscoveryRun $run, Collection $attempts): DiscoveryStage
    {
        if ($attempts->isEmpty()) {
            // Nothing declared was observable at all — an honest terminal
            // state, not an infinite "discovering" spinner.
            return DiscoveryStage::CompletedWithErrors;
        }

        $anySucceeded = $attempts->contains(fn (DiscoveryConnectorAttempt $a): bool => $a->status === DiscoveryAttemptStatus::Succeeded);
        $allTerminal = $attempts->every(fn (DiscoveryConnectorAttempt $a): bool => $a->status->isTerminal());

        if (! $anySucceeded && $allTerminal) {
            return DiscoveryStage::CompletedWithErrors;
        }

        $recommendationExists = Recommendation::withoutGlobalScopes()
            ->where('company_id', $run->company_id)
            ->where('status', 'pending')
            ->exists();

        if ($anySucceeded && $recommendationExists) {
            return DiscoveryStage::Completed;
        }

        if (! $anySucceeded) {
            return DiscoveryStage::Discovering;
        }

        /** @var list<string> $integrationIds */
        $integrationIds = $attempts->pluck('integration_id')->filter()->unique()->values()->all();

        $hasProcessedObservation = Observation::withoutGlobalScopes()
            ->whereIn('integration_id', $integrationIds)
            ->where('status', 'processed')
            ->exists();

        if (! $hasProcessedObservation) {
            return DiscoveryStage::Analyzing;
        }

        $twin = DigitalTwin::withoutGlobalScopes()->where('company_id', $run->company_id)->first();

        $hasKnowledgeSinceStart = Knowledge::withoutGlobalScopes()
            ->where('company_id', $run->company_id)
            ->where('generated_at', '>=', $run->started_at)
            ->exists();

        if (! ($twin?->isActive() ?? false) || ! $hasKnowledgeSinceStart) {
            return DiscoveryStage::Understanding;
        }

        return DiscoveryStage::Recommending;
    }

    /** Most recent DiscoveryRun for a company, or null if Discovery was never started. */
    public function latestRunFor(Company $company): ?DiscoveryRun
    {
        return DiscoveryRun::where('company_id', $company->id)->latest('started_at')->first();
    }

    /**
     * A read model for the progress UI: current stage, per-asset connector
     * status (including declared assets Discovery never attempted at all),
     * and real, persisted summary counts. Recomputed fresh on every call.
     *
     * @return array{
     *     stage: string,
     *     started_at: string,
     *     completed_at: string|null,
     *     connectors: list<array{type: string, label: string, status: string, error_message: string|null}>,
     *     facts_created: int,
     *     opportunities_found: int,
     *     recommendations_generated: int,
     *     recommendation_count: int,
     *     first_recommendation_id: string|null,
     * }|null
     */
    public function progressFor(Company $company): ?array
    {
        $run = $this->latestRunFor($company);

        if ($run === null) {
            return null;
        }

        $run = $this->refreshStage($run);

        $attempts = DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)->get()->keyBy('marketing_channel_id');

        $channels = MarketingChannel::where('company_id', $company->id)
            ->where('status', MarketingChannelStatus::Active)
            ->get();

        $connectors = array_values($channels->map(function (MarketingChannel $channel) use ($attempts): array {
            $attempt = $attempts->get($channel->id);

            return [
                'type' => $channel->type->value,
                'label' => $channel->type->label(),
                'status' => $attempt?->status->value ?? 'not_attempted',
                'error_message' => $attempt?->error_message,
            ];
        })->all());

        $factCount = Fact::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_current', true)
            ->where('created_at', '>=', $run->started_at)
            ->count();

        $opportunityCount = Opportunity::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('created_at', '>=', $run->started_at)
            ->count();

        $recommendationsSinceStart = Recommendation::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('created_at', '>=', $run->started_at)
            ->count();

        $pendingRecommendation = Recommendation::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->oldest()
            ->first();

        return [
            'stage' => $run->stage->value,
            'started_at' => Carbon::parse($run->started_at)->toIso8601String(),
            'completed_at' => $run->completed_at !== null ? Carbon::parse($run->completed_at)->toIso8601String() : null,
            'connectors' => $connectors,
            'facts_created' => $factCount,
            'opportunities_found' => $opportunityCount,
            'recommendations_generated' => $recommendationsSinceStart,
            'recommendation_count' => Recommendation::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('status', 'pending')
                ->count(),
            'first_recommendation_id' => $pendingRecommendation?->id,
        ];
    }
}
