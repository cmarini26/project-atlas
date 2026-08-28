<?php

namespace App\Jobs;

use App\AI\Exceptions\LocalAiException;
use App\AI\Exceptions\RetryableAiException;
use App\Events\ObservationProcessed;
use App\Models\Company;
use App\Models\Observation;
use App\Models\SourceAsset;
use App\Services\Analyst\AnalystRegistry;
use App\Services\Brain\FactService;
use App\Services\Brain\KnowledgeService;
use App\Services\MarketingHealth\MarketingHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessObservation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Backoff between queued retries. Only applies when a worker processes
     * the job asynchronously; overloaded-provider retries in sync mode are
     * driven by the onboarding status endpoint re-dispatching instead.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly Observation $observation)
    {
        $this->onQueue('ai');
    }

    public function handle(
        AnalystRegistry $analysts,
        FactService $factService,
        KnowledgeService $knowledgeService,
        MarketingHealthService $marketingHealthService,
    ): void {
        $observation = $this->observation;
        $observation->markProcessing();

        Log::info('ProcessObservation: starting fact extraction.', [
            'observation_id' => $observation->id,
            'source_type' => $observation->source_type,
        ]);

        try {
            $analyst = $analysts->resolve($observation);
            $factData = $analyst->analyze($observation);

            Log::info('ProcessObservation: facts extracted.', [
                'observation_id' => $observation->id,
                'fact_count' => $factData->count(),
            ]);

            $factService->storeExtracted($observation, $factData);

            $company = Company::withoutGlobalScopes()->find($observation->company_id);

            if ($company) {
                Log::info('ProcessObservation: synthesizing knowledge.', [
                    'company_id' => $company->id,
                ]);

                $knowledgeService->synthesizeForCompany($company);

                Log::info('ProcessObservation: recomputing marketing health.', [
                    'company_id' => $company->id,
                ]);

                $marketingHealthService->recompute($company);
            }

            $observation->markProcessed();

            Log::info('ProcessObservation: observation processed successfully.', [
                'observation_id' => $observation->id,
            ]);
        } catch (RetryableAiException $e) {
            // Transient provider issue (hosted overload, or the local model
            // being briefly unreachable) — not a permanent failure. Queued
            // jobs retry via $tries/$backoff; in sync mode the onboarding
            // status endpoint re-dispatches stale 'retrying' observations.
            // Only the final queued attempt downgrades to 'failed'.
            $queuedFinalAttempt = $this->job !== null
                && ! ($this->job instanceof SyncJob)
                && $this->attempts() >= $this->tries;

            if ($queuedFinalAttempt) {
                Log::error('ProcessObservation: AI provider unavailable, retries exhausted.', [
                    'observation_id' => $observation->id,
                    'attempts' => $this->attempts(),
                ]);

                $observation->markFailed();
            } else {
                Log::warning('ProcessObservation: AI provider unavailable, marked for retry.', [
                    'observation_id' => $observation->id,
                    'attempt' => $this->attempts(),
                ]);

                $observation->markRetrying();
            }

            throw $e;
        } catch (LocalAiException $e) {
            // A permanent local-inference failure (model not pulled, out of
            // memory, unusable output). Retrying the same prompt would only
            // load the local model further, so fail fast — no more attempts.
            Log::error('ProcessObservation: local AI failure, not retrying.', [
                'observation_id' => $observation->id,
                'failure_category' => $e->category,
            ]);

            $observation->markFailed();
            $this->fail($e);

            return;
        } catch (Throwable $e) {
            Log::error('ProcessObservation: failed.', [
                'observation_id' => $observation->id,
                'error' => $e->getMessage(),
            ]);

            $observation->markFailed();
            throw $e;
        }

        // The downstream pipeline (opportunities → decision → campaign →
        // recommendation) is triggered by ObservationProcessed and runs inline
        // under the sync queue. The observation itself succeeded — a downstream
        // failure must not flip it back to 'failed' or abort the sync request,
        // so it is contained and reported here instead of propagating.
        try {
            ObservationProcessed::dispatch($observation);
        } catch (Throwable $e) {
            Log::error('ProcessObservation: downstream pipeline failed after observation was processed.', [
                'observation_id' => $observation->id,
                'error' => $e->getMessage(),
            ]);

            report($e);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if (! str_starts_with($this->observation->source_identifier, 'source_asset:')) {
            return;
        }

        SourceAsset::withoutGlobalScopes()
            ->whereKey(substr($this->observation->source_identifier, strlen('source_asset:')))
            ->update([
                'status' => 'failed',
                'processing_error' => $exception?->getMessage() ?? 'Atlas could not analyze this asset.',
            ]);
    }
}
