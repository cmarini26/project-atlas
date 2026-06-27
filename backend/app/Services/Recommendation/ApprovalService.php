<?php

namespace App\Services\Recommendation;

use App\Events\RecommendationApproved;
use App\Events\RecommendationRejected;
use App\Models\Approval;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\Learning;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Learning\EditPatternDetector;
use InvalidArgumentException;

class ApprovalService
{
    public function __construct(
        private readonly EditPatternDetector $editPatternDetector,
    ) {}

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
        $this->recordApprovalLearning($recommendation, $approval, 'recommendation_approved');

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
        $this->recordApprovalLearning($recommendation, $approval, 'recommendation_rejected');

        RecommendationRejected::dispatch($recommendation, $approval);

        return $approval;
    }

    /**
     * @param  array<string, mixed>  $edits
     */
    public function editAndApprove(
        Recommendation $recommendation,
        User $user,
        array $edits,
        ?string $notes = null,
    ): Approval {
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
            'action' => 'edited_and_approved',
            'notes' => $notes,
            'edits' => $edits,
            'acted_at' => now(),
        ]);

        $recommendation->update([
            'status' => 'approved',
            'responded_at' => now(),
        ]);

        $this->approveLinkedCampaign($recommendation);
        $this->recordEditedApprovalLearning($recommendation, $approval);

        RecommendationApproved::dispatch($recommendation, $approval);

        return $approval;
    }

    private function recordApprovalLearning(
        Recommendation $recommendation,
        Approval $approval,
        string $signal,
    ): void {
        $campaignType = $recommendation->campaign_type ?? '';
        $channel = $this->primaryChannel($recommendation);

        if ($campaignType === '') {
            return;
        }

        $existing = Learning::withoutGlobalScopes()
            ->where('company_id', $recommendation->company_id)
            ->where('source_id', $approval->id)
            ->where('signal', $signal)
            ->exists();

        if ($existing) {
            return;
        }

        Learning::create([
            'company_id' => $recommendation->company_id,
            'source_type' => 'approval',
            'source_id' => $approval->id,
            'subject_type' => 'recommendation',
            'subject_id' => $recommendation->id,
            'signal' => $signal,
            'value' => array_filter([
                'campaign_type' => $campaignType,
                'channel' => $channel,
            ]),
            'applied_at' => null,
        ]);
    }

    private function recordEditedApprovalLearning(
        Recommendation $recommendation,
        Approval $approval,
    ): void {
        $campaignType = $recommendation->campaign_type ?? '';

        if ($campaignType === '') {
            return;
        }

        /** @var array<string, mixed> $edits */
        $edits = $approval->edits ?? [];

        $patterns = [];
        if (! empty($edits)) {
            $original = (array) ($edits['original'] ?? []);
            $edited = (array) ($edits['edited'] ?? []);

            if (! empty($original) && ! empty($edited)) {
                $patterns = $this->editPatternDetector->detect($original, $edited);
            }
        }

        $existing = Learning::withoutGlobalScopes()
            ->where('company_id', $recommendation->company_id)
            ->where('source_id', $approval->id)
            ->where('signal', 'recommendation_edited_and_approved')
            ->exists();

        if ($existing) {
            return;
        }

        Learning::create([
            'company_id' => $recommendation->company_id,
            'source_type' => 'approval',
            'source_id' => $approval->id,
            'subject_type' => 'recommendation',
            'subject_id' => $recommendation->id,
            'signal' => 'recommendation_edited_and_approved',
            'value' => array_filter([
                'campaign_type' => $campaignType,
                'channel' => $this->primaryChannel($recommendation),
                'edit_patterns' => ! empty($patterns) ? $patterns : null,
            ]),
            'applied_at' => null,
        ]);
    }

    private function primaryChannel(Recommendation $recommendation): ?string
    {
        if ($recommendation->decision_id === null) {
            return null;
        }

        $decision = Decision::withoutGlobalScopes()->find($recommendation->decision_id);

        if ($decision === null) {
            return null;
        }

        /** @var list<string> $channelIds */
        $channelIds = $decision->channel_ids ?? [];

        return $channelIds[0] ?? null;
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
