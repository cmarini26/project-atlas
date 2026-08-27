<?php

namespace Tests\Feature\Learning;

use App\Models\Decision;
use App\Models\Learning;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Campaign\CampaignChannelSelectionService;
use App\Services\Learning\ContentPreferenceGuide;
use App\Services\Learning\EditPatternDetector;
use App\Services\Recommendation\ApprovalService;

class ApprovalServiceLearningTest extends LearningTestCase
{
    private ApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ApprovalService(new CampaignChannelSelectionService(), new EditPatternDetector());
    }

    private function makeRecommendation(): Recommendation
    {
        return Recommendation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'decision_id' => $this->decision->id,
            'campaign_id' => $this->campaign->id,
            'campaign_type' => 'featured_item',
            'title' => 'Test Recommendation',
            'summary' => 'Test',
            'confidence_score' => 70,
            'status' => 'pending',
        ]);
    }

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    public function test_approve_creates_recommendation_approved_learning(): void
    {
        $rec = $this->makeRecommendation();
        $user = $this->makeUser();

        $this->service->approve($rec, $user);

        $learning = Learning::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('signal', 'recommendation_approved')
            ->first();

        $this->assertNotNull($learning);
        $this->assertSame('featured_item', $learning->value['campaign_type'] ?? null);
        $this->assertNull($learning->applied_at);
    }

    public function test_reject_creates_recommendation_rejected_learning(): void
    {
        $rec = $this->makeRecommendation();
        $user = $this->makeUser();

        $this->service->reject($rec, $user, 'Not relevant');

        $learning = Learning::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('signal', 'recommendation_rejected')
            ->first();

        $this->assertNotNull($learning);
        $this->assertSame('featured_item', $learning->value['campaign_type'] ?? null);
    }

    public function test_edit_and_approve_creates_edited_approved_learning(): void
    {
        $rec = $this->makeRecommendation();
        $user = $this->makeUser();

        $this->service->editAndApprove($rec, $user, [
            'original' => ['body' => str_repeat('word ', 50)],
            'edited' => ['body' => 'Short.'],
        ]);

        $learning = Learning::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('signal', 'recommendation_edited_and_approved')
            ->first();

        $this->assertNotNull($learning);
        $this->assertSame('featured_item', $learning->value['campaign_type'] ?? null);
    }

    public function test_edited_approval_learning_records_channel_type_not_channel_id(): void
    {
        $rec = $this->makeRecommendation();
        $user = $this->makeUser();

        $this->service->editAndApprove($rec, $user, [
            'original' => ['body' => str_repeat('word ', 50)],
            'edited' => ['body' => 'Short.'],
        ]);

        $learning = Learning::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('signal', 'recommendation_edited_and_approved')
            ->first();

        $this->assertNotNull($learning);
        // The Decision stores a channel *id* in channel_ids; the learning must
        // persist the channel *type* so ContentPreferenceGuide can match on it.
        $this->assertSame('email', $learning->value['channel'] ?? null);
        $this->assertNotSame($this->channel->id, $learning->value['channel'] ?? null);
    }

    public function test_consistent_edited_approvals_close_the_content_preference_loop(): void
    {
        $user = $this->makeUser();

        foreach (range(1, 2) as $ignored) {
            $opportunity = Opportunity::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'subject_type' => 'company',
                'type' => 'featured_item',
                'title' => 'Test',
                'description' => 'Desc',
                'relevance_score' => 80,
                'timing_score' => 80,
                'confidence_score' => 80,
                'urgency_score' => 80,
                'composite_score' => 80,
                'status' => 'selected',
                'detected_at' => now(),
            ]);

            $decision = Decision::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'opportunity_id' => $opportunity->id,
                'campaign_type' => 'featured_item',
                'channel_ids' => [$this->channel->id],
                'rationale' => ['why_now' => 'Now'],
                'expected_impact' => ['target_engagement_rate' => 0.05],
                'confidence_score' => 70,
                'status' => 'recommended',
                'decided_at' => now(),
            ]);

            $rec = Recommendation::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'decision_id' => $decision->id,
                'campaign_id' => $this->campaign->id,
                'campaign_type' => 'featured_item',
                'title' => 'Test Recommendation',
                'summary' => 'Test',
                'confidence_score' => 70,
                'status' => 'pending',
            ]);

            $this->service->editAndApprove($rec, $user, [
                'original' => ['body' => str_repeat('word ', 60)],
                'edited' => ['body' => 'Tight copy.'],
            ]);
        }

        $guidance = (new ContentPreferenceGuide())
            ->guidanceFor($this->company, 'email');

        $this->assertNotNull($guidance);
        $this->assertStringContainsString('tighter and more concise', $guidance);
    }

    public function test_approve_is_idempotent_for_learning_signals(): void
    {
        $rec = $this->makeRecommendation();
        $user = $this->makeUser();

        // Approve once — should create 1 Learning
        $approval = $this->service->approve($rec, $user);

        // Simulate idempotency guard (same source_id + signal)
        $count = Learning::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('source_id', $approval->id)
            ->where('signal', 'recommendation_approved')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_approve_sets_recommendation_status_to_approved(): void
    {
        $rec = $this->makeRecommendation();
        $user = $this->makeUser();

        $this->service->approve($rec, $user);

        $rec->refresh();
        $this->assertSame('approved', $rec->status);
    }

    public function test_reject_sets_recommendation_status_to_rejected(): void
    {
        $rec = $this->makeRecommendation();
        $user = $this->makeUser();

        $this->service->reject($rec, $user);

        $rec->refresh();
        $this->assertSame('rejected', $rec->status);
    }

    public function test_edit_and_approve_status_is_approved(): void
    {
        $rec = $this->makeRecommendation();
        $user = $this->makeUser();

        $this->service->editAndApprove($rec, $user, []);

        $rec->refresh();
        $this->assertSame('approved', $rec->status);
    }
}
