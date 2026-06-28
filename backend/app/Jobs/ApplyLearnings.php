<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Learning\LearningEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ApplyLearnings implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 86400; // 24 hours — one run per day

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(LearningEngine $engine): void
    {
        Company::withoutGlobalScopes()
            ->each(function (Company $company) use ($engine): void {
                try {
                    $engine->apply($company->id);
                } catch (\Throwable $e) {
                    Log::error('ApplyLearnings: failed for company.', [
                        'company_id' => $company->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
