<?php

namespace App\Jobs;

use App\Models\Decision;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Milestone 4 stub — no-op. Campaign preparation is implemented in Milestone 5.
 */
class PrepareCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Decision $decision) {}

    public function handle(): void
    {
        Log::info('PrepareCampaign: stub — campaign preparation not yet implemented (Milestone 5).', [
            'decision_id' => $this->decision->id,
            'company_id' => $this->decision->company_id,
        ]);
    }

    public function queue(): string
    {
        return 'ai';
    }
}
