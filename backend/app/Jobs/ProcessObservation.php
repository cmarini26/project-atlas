<?php

namespace App\Jobs;

use App\Models\Observation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessObservation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly Observation $observation)
    {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        // Stub — Milestone 3 will add fact extraction and AI summarization.
        $this->observation->markProcessing();
        $this->observation->markProcessed();
    }
}
