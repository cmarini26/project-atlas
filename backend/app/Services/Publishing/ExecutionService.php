<?php

namespace App\Services\Publishing;

use App\Domain\Publishing\ValueObjects\ExecutionResult;
use App\Events\CampaignPublished;
use App\Events\ExecutionCompleted;
use App\Events\ExecutionFailed;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Services\Publishing\Email\EmailAudienceService;
use Illuminate\Support\Str;

class ExecutionService
{
    public function __construct(private readonly EmailAudienceService $emailAudiences) {}

    /**
     * Create Execution records for all approved ContentAssets in a campaign.
     * Transitions ContentAssets from approved → scheduled. Called only from
     * PublishCampaign, itself gated on Campaign.status === 'approved' — i.e.
     * only ever reached after human approval (RecommendationController::
     * approve() → ApprovalService::approve()).
     *
     * @return list<Execution>
     */
    public function queueForCampaign(Campaign $campaign): array
    {
        $assets = ContentAsset::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'approved')
            ->get();

        $executions = [];

        foreach ($assets as $asset) {
            $execution = Execution::create([
                'company_id' => $campaign->company_id,
                'campaign_id' => $campaign->id,
                'content_asset_id' => $asset->id,
                'channel_id' => $asset->channel_id,
                'status' => 'queued',
                'scheduled_at' => $asset->scheduled_at,
                'idempotency_key' => Str::ulid()->toString(),
            ]);

            $asset->update(['status' => 'scheduled']);

            // A no-op for every non-email channel and for a campaign with
            // no audience selected — see EmailAudienceService::
            // snapshotIfApplicable()'s own docblock. Snapshotting here,
            // once, at Execution-creation time, is what "do not re-read
            // live audience membership after snapshot creation" means in
            // practice: EmailPublisher (queued, possibly minutes later)
            // only ever reads the rows created on this line.
            $this->emailAudiences->snapshotIfApplicable($execution, $campaign);

            $executions[] = $execution;
        }

        return $executions;
    }

    /**
     * Mark an Execution as completed and record the platform result.
     * Transitions the ContentAsset from scheduled → published.
     */
    public function markCompleted(Execution $execution, ExecutionResult $result): void
    {
        $execution->update([
            'status' => 'completed',
            'completed_at' => now(),
            'result' => $result->toArray(),
        ]);

        ContentAsset::withoutGlobalScopes()
            ->where('id', $execution->content_asset_id)
            ->update(['status' => 'published', 'published_at' => now()]);

        ExecutionCompleted::dispatch($execution);

        $this->checkCampaignCompletion($execution->campaign_id);
    }

    /**
     * Mark an Execution as permanently failed.
     * Reverts the ContentAsset from scheduled → approved.
     */
    public function markFailed(Execution $execution, string $error): void
    {
        if ($execution->status === 'failed') {
            return;
        }

        $execution->update([
            'status' => 'failed',
            'last_error' => $error,
            'completed_at' => now(),
        ]);

        ContentAsset::withoutGlobalScopes()
            ->where('id', $execution->content_asset_id)
            ->where('status', 'scheduled')
            ->update(['status' => 'approved']);

        ExecutionFailed::dispatch($execution);

        $this->checkCampaignCompletion($execution->campaign_id);
    }

    /**
     * Log a single publish attempt. Increments the attempts counter.
     *
     * @param  array<string, mixed>|null  $response
     */
    public function logAttempt(
        Execution $execution,
        string $status,
        ?string $error = null,
        ?array $response = null,
    ): void {
        ExecutionAttempt::create([
            'execution_id' => $execution->id,
            'attempt_number' => $execution->attempts + 1,
            'attempted_at' => now(),
            'status' => $status,
            'error' => $error,
            'response' => $response,
            'created_at' => now(),
        ]);

        $execution->increment('attempts');
    }

    /**
     * Check whether all Executions for a campaign have settled.
     * Transitions the Campaign to published or cancelled accordingly.
     */
    public function checkCampaignCompletion(string $campaignId): void
    {
        $executions = Execution::withoutGlobalScopes()
            ->where('campaign_id', $campaignId)
            ->get();

        if ($executions->isEmpty()) {
            return;
        }

        $allSettled = $executions->every(fn (Execution $e) => $e->isSettled());

        if (! $allSettled) {
            return;
        }

        $campaign = Campaign::withoutGlobalScopes()->find($campaignId);

        if ($campaign === null) {
            return;
        }

        $anyCompleted = $executions->contains(fn (Execution $e) => $e->status === 'completed');

        if ($anyCompleted) {
            $campaign->update(['status' => 'published', 'completed_at' => now()]);
            CampaignPublished::dispatch($campaign);
        } else {
            $campaign->update(['status' => 'cancelled']);
        }
    }
}
