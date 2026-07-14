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

    /**
     * Retry only the appropriate failed/pending work on an existing
     * DiscoveryRun — never a whole new run, and never touching attempts
     * that already succeeded. Also picks up any declared asset that has
     * since become observable (e.g. Instagram connected for real from
     * Settings after this run's original attempt never ran at all), since
     * that's exactly the same "what can currently be observed?" question
     * start() already asks, just scoped to one existing run instead of a
     * fresh one. Safe to call repeatedly, and never duplicates an
     * Integration, MarketingChannel, or Observation.
     */
    public function retry(DiscoveryRun $run): DiscoveryRun
    {
        $company = Company::withoutGlobalScopes()->find($run->company_id);

        if ($company === null) {
            return $this->refreshStage($run);
        }

        $existingAttempts = DiscoveryConnectorAttempt::where('discovery_run_id', $run->id)
            ->get()
            ->keyBy('marketing_channel_id');

        $channels = MarketingChannel::where('company_id', $company->id)
            ->where('status', MarketingChannelStatus::Active)
            ->get();

        foreach ($channels as $channel) {
            $existing = $existingAttempts->get($channel->id);

            if ($existing !== null && $existing->status === DiscoveryAttemptStatus::Succeeded) {
                // Preserve successful connector results — never re-touch them.
                continue;
            }

            if ($existing !== null) {
                $this->retryAttempt($existing);

                continue;
            }

            // No attempt existed in this run at all (e.g. the type had no
            // auto-discoverable connector and wasn't yet connected) — give
            // it its first try now, exactly as start() would for a fresh run.
            $this->attempt($run, $company, $channel);
        }

        return $this->refreshStage($run);
    }

    private function retryAttempt(DiscoveryConnectorAttempt $attempt): void
    {
        $integration = Integration::withoutGlobalScopes()->find($attempt->integration_id);

        if ($integration === null) {
            return;
        }

        // Same resilience guarantee as attempt(): one connector failing
        // again on retry must never block any other asset's retry.
        try {
            SyncIntegration::dispatch($integration);
        } catch (Throwable) {
            // Swallowed deliberately — see comment above.
        }
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

        // Reached Recommend: Atlas understands the business but hasn't
        // produced a Recommendation yet. If every attempt has finished and
        // no Opportunity resulted either, this is a legitimate, final
        // "learned your business, nothing to act on yet" outcome — not an
        // indefinite spinner. The 90 s grace period against the last
        // processed Observation mirrors the pre-Phase-2 no_opportunities
        // heuristic exactly, avoiding a race with any still-in-flight async
        // downstream step.
        if ($allTerminal) {
            $opportunityExists = Opportunity::withoutGlobalScopes()
                ->where('company_id', $run->company_id)
                ->where('created_at', '>=', $run->started_at)
                ->exists();

            if (! $opportunityExists) {
                $lastProcessedAt = Observation::withoutGlobalScopes()
                    ->whereIn('integration_id', $integrationIds)
                    ->where('status', 'processed')
                    ->max('processed_at');

                if ($lastProcessedAt !== null && Carbon::parse($lastProcessedAt)->lt(now()->subSeconds(90))) {
                    return DiscoveryStage::CompletedNoOpportunities;
                }
            }
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
     *     retry_available: bool,
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

        // Retry is offered whenever there's plausible unfinished business:
        // a connector that actually failed, or a terminal state reached
        // without every declared asset having been given a fair shot yet
        // (e.g. one was just corrected in Settings/Asset Details).
        $retryAvailable = $attempts->contains(fn (DiscoveryConnectorAttempt $a): bool => $a->status === DiscoveryAttemptStatus::Failed)
            || in_array($run->stage, [DiscoveryStage::CompletedWithErrors, DiscoveryStage::CompletedNoOpportunities], true);

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
            'retry_available' => $retryAvailable,
        ];
    }
}
