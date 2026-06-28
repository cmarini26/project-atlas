<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Brain\BusinessBrainService;
use App\Services\Opportunity\OpportunityEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DetectOpportunities implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly Company $company)
    {
        $this->onQueue('default');
    }

    public function handle(BusinessBrainService $brainService, OpportunityEngine $engine): void
    {
        $brain = $brainService->for($this->company);
        $engine->scan($this->company, $brain);
    }
}
