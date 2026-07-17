<?php

namespace App\Jobs\Testing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Test-only, side-effect-free job used by QueueWorkerConfigTest (SCRUM-41)
 * to prove exactly how a job dispatched via onQueue() alone (the pattern
 * every real job in app/Jobs uses) is actually routed and consumed — never
 * dispatched by application code itself.
 */
class QueueRoutingProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        Log::info('QueueRoutingProbeJob ran');
    }
}
