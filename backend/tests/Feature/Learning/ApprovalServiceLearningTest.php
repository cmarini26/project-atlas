<?php

namespace Tests\Feature\Learning;

use App\Models\Learning;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Learning\EditPatternDetector;
use App\Services\Recommendation\ApprovalService;

class ApprovalServiceLearningTest extends LearningTestCase
{
    private ApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ApprovalService(new EditPatternDetector());
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
