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

        try {
            $factData = $analyst->analyze($observation);
            $factService->storeExtracted($observation, $factData);

            $company = Company::withoutGlobalScopes()->find($observation->company_id);

            if ($company) {
                $knowledgeService->synthesizeForCompany($company);
            }

            $observation->markProcessed();
            ObservationProcessed::dispatch($observation);
        } catch (Throwable $e) {
            $observation->markFailed();
            throw $e;
        }
    }
}
