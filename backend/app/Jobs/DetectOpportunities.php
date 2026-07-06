<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Brain\BusinessBrainService;
use App\Services\Opportunity\OpportunityEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DetectOpportunities implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly Company $company)
    {
        $this->onQueue('default');
    }

    /**
     * One queued scan per company at a time — a multi-page crawl processes
     * many observations in a burst and each one dispatches a scan; duplicates
     * are dropped while one is already waiting.
     */
    public function uniqueId(): string
    {
        return $this->company->id;
    }

    public function handle(BusinessBrainService $brainService, OpportunityEngine $engine): void
    {
        Log::info('DetectOpportunities: starting opportunity scan.', [
            'company_id' => $this->company->id,
        ]);

        $brain = $brainService->for($this->company);
        $engine->scan($this->company, $brain);
    }
}
