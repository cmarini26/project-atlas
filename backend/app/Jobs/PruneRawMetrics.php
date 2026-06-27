<?php

namespace App\Jobs;

use App\Models\ExecutionMetric;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PruneRawMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        $count = ExecutionMetric::withoutGlobalScopes()
            ->where('retrieved_at', '<', now()->subYear())
            ->whereNotNull('raw')
            ->update(['raw' => null]);

        Log::info("PruneRawMetrics: nulled raw on {$count} record(s).");
    }
}
