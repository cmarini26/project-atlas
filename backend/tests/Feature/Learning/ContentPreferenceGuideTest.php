<?php

namespace Tests\Feature\Learning;

use App\Models\Learning;
use App\Services\Learning\ContentPreferenceGuide;

class ContentPreferenceGuideTest extends LearningTestCase
{
    private ContentPreferenceGuide $guide;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guide = new ContentPreferenceGuide();
    }

    public function test_returns_null_when_there_is_not_enough_history(): void
    {
        $this->makeEditedApprovalLearning('email', [
            'length_preference' => 'shorter',
            'hashtag_preference' => 'removed',
        ]);

        $this->assertNull($this->guide->guidanceFor($this->company, 'email'));
    }

    public function test_returns_guidance_when_patterns_are_consistent_enough(): void
    {
        $this->makeEditedApprovalLearning('email', [
            'length_preference' => 'shorter',
            'hashtag_preference' => 'removed',
            'price_inclusion' => 'added',
        ]);

        $this->makeEditedApprovalLearning('email', [
            'length_preference' => 'shorter',
            'hashtag_preference' => 'removed',
            'price_inclusion' => 'added',
        ]);

        $this->makeEditedApprovalLearning('email', [
            'length_preference' => 'longer',
            'hashtag_preference' => 'removed',
            'price_inclusion' => 'added',
        ]);

        $guidance = $this->guide->guidanceFor($this->company, 'email');

        $this->assertNotNull($guidance);
        $this->assertStringContainsString('Keep the copy tighter and more concise', $guidance);
        $this->assertStringContainsString('Avoid hashtags unless they are essential.', $guidance);
        $this->assertStringContainsString('Include clear price or offer details', $guidance);
    }

    public function test_returns_null_for_patterns_without_a_clear_winner(): void
    {
        $this->makeEditedApprovalLearning('email', [
            'length_preference' => 'shorter',
        ]);

        $this->makeEditedApprovalLearning('email', [
            'length_preference' => 'longer',
        ]);

        $this->assertNull($this->guide->guidanceFor($this->company, 'email'));
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private function makeEditedApprovalLearning(string $channelType, array $patterns): Learning
    {
        return Learning::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'source_type' => 'approval',
            'source_id' => (string) str()->ulid(),
            'subject_type' => 'recommendation',
            'subject_id' => (string) str()->ulid(),
            'signal' => 'recommendation_edited_and_approved',
            'value' => [
                'campaign_type' => 'featured_item',
                'channel' => $channelType,
                'edit_patterns' => $patterns,
            ],
            'applied_at' => null,
        ]);
    }
}
