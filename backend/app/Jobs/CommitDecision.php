<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Brain\BusinessBrainService;
use App\Services\Decision\DecisionEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CommitDecision implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly Company $company)
    {
        $this->onQueue('ai');
    }

    public function handle(BusinessBrainService $brainService, DecisionEngine $engine): void
    {
        $brain = $brainService->for($this->company);
        $engine->evaluate($this->company, $brain);
    }

    public function uniqueId(): string
    {
        return $this->company->id;
    }
}
