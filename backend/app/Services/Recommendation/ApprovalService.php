<?php

namespace App\Services\Recommendation;

use App\Events\RecommendationApproved;
use App\Events\RecommendationRejected;
use App\Models\Approval;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\Recommendation;
use App\Models\User;
use InvalidArgumentException;

class ApprovalService
{
    public function approve(Recommendation $recommendation, User $user, ?string $notes = null): Approval
    {
        if ($recommendation->status !== 'pending') {
            throw new InvalidArgumentException(
                "Cannot approve recommendation with status: {$recommendation->status}"
            );
        }

        $approval = Approval::create([
            'company_id' => $recommendation->company_id,
            'approvable_type' => Recommendation::class,
            'approvable_id' => $recommendation->id,
            'user_id' => $user->id,
            'action' => 'approved',
            'notes' => $notes,
            'acted_at' => now(),
        ]);

        $recommendation->update([
            'status' => 'approved',
            'responded_at' => now(),
        ]);

        $this->approveLinkedCampaign($recommendation);

        RecommendationApproved::dispatch($recommendation, $approval);

        return $approval;
    }

    public function reject(Recommendation $recommendation, User $user, ?string $notes = null): Approval
    {
        if ($recommendation->status !== 'pending') {
            throw new InvalidArgumentException(
                "Cannot reject recommendation with status: {$recommendation->status}"
            );
        }

        $approval = Approval::create([
            'company_id' => $recommendation->company_id,
            'approvable_type' => Recommendation::class,
            'approvable_id' => $recommendation->id,
            'user_id' => $user->id,
            'action' => 'rejected',
            'notes' => $notes,
            'acted_at' => now(),
        ]);

        $recommendation->update([
            'status' => 'rejected',
            'responded_at' => now(),
        ]);

        $this->rejectLinkedCampaign($recommendation);

        RecommendationRejected::dispatch($recommendation, $approval);

        return $approval;
    }

    private function approveLinkedCampaign(Recommendation $recommendation): void
    {
        if ($recommendation->campaign_id === null) {
            return;
        }

        $campaign = Campaign::withoutGlobalScopes()->find($recommendation->campaign_id);

        if ($campaign === null) {
            return;
        }

        $campaign->update(['status' => 'approved']);

        ContentAsset::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'draft')
            ->update(['status' => 'approved']);
    }

    private function rejectLinkedCampaign(Recommendation $recommendation): void
    {
        if ($recommendation->campaign_id === null) {
            return;
        }

        $campaign = Campaign::withoutGlobalScopes()->find($recommendation->campaign_id);

        if ($campaign === null) {
            return;
        }

        $campaign->update(['status' => 'cancelled']);

        ContentAsset::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'draft')
            ->update(['status' => 'archived']);
    }
}
