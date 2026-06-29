<?php

namespace App\Jobs;

use App\Events\ObservationProcessed;
use App\Models\Company;
use App\Models\Observation;
use App\Services\Analyst\WebsiteAnalyst;
use App\Services\Brain\FactService;
use App\Services\Brain\KnowledgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessObservation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly Observation $observation)
    {
        $this->onQueue('ai');
    }

    public function handle(
        WebsiteAnalyst $analyst,
        FactService $factService,
        KnowledgeService $knowledgeService,
    ): void {
        $observation = $this->observation;
        $observation->markProcessing();

        Log::info('ProcessObservation: starting fact extraction.', [
            'observation_id' => $observation->id,
            'source_type' => $observation->source_type,
        ]);

        try {
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
            }

            $observation->markProcessed();
            ObservationProcessed::dispatch($observation);

            Log::info('ProcessObservation: observation processed successfully.', [
                'observation_id' => $observation->id,
            ]);
        } catch (Throwable $e) {
            Log::error('ProcessObservation: failed.', [
                'observation_id' => $observation->id,
                'error' => $e->getMessage(),
            ]);

            $observation->markFailed();
            throw $e;
        }
    }
}
