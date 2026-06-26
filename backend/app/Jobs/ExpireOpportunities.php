<?php

namespace App\Jobs;

use App\Models\Opportunity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExpireOpportunities implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $expired = Opportunity::withoutGlobalScopes()
            ->where('status', 'open')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        $count = $expired->count();

        foreach ($expired as $opportunity) {
            $opportunity->update(['status' => 'expired']);
        }

        if ($count > 0) {
            Log::info("ExpireOpportunities: expired {$count} opportunities.");
        }
    }

    public function queue(): string
    {
        return 'maintenance';
    }
}
