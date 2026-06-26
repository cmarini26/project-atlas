<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Publishing\ExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly Campaign $campaign) {}

    public function handle(ExecutionService $executionService): void
    {
        $campaign = Campaign::withoutGlobalScopes()->findOrFail($this->campaign->id);

        if ($campaign->status !== 'approved') {
            Log::warning('PublishCampaign: campaign is not in approved status, skipping.', [
                'campaign_id' => $campaign->id,
                'status' => $campaign->status,
            ]);

            return;
        }

        $executions = $executionService->queueForCampaign($campaign);

        if (empty($executions)) {
            Log::warning('PublishCampaign: no approved assets found for campaign.', [
                'campaign_id' => $campaign->id,
            ]);

            return;
        }

        $dispatched = 0;

        foreach ($executions as $execution) {
            // Only dispatch immediately for executions with no schedule.
            // Scheduled executions are handled by PublishScheduledContent.
            if ($execution->scheduled_at === null) {
                PublishContent::dispatch($execution)->onQueue('high');
                $dispatched++;
            }
        }

        Log::info('PublishCampaign: dispatched content publishing.', [
            'campaign_id' => $campaign->id,
            'total' => count($executions),
            'dispatched' => $dispatched,
            'scheduled' => count($executions) - $dispatched,
        ]);
    }

    public function queue(): string
    {
        return 'high';
    }
}
