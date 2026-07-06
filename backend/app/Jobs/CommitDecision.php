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
use Illuminate\Support\Facades\Log;

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
        Log::info('CommitDecision: evaluating decision.', [
            'company_id' => $this->company->id,
        ]);

        $brain = $brainService->for($this->company);
        $decision = $engine->evaluate($this->company, $brain);

        if ($decision === null) {
            Log::info('CommitDecision: no decision committed (engine guards not satisfied).', [
                'company_id' => $this->company->id,
            ]);

            return;
        }

        Log::info('CommitDecision: decision committed.', [
            'company_id' => $this->company->id,
            'decision_id' => $decision->id,
        ]);
    }

    public function uniqueId(): string
    {
        return $this->company->id;
    }
}
