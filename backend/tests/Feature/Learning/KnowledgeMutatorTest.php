<?php

namespace Tests\Feature\Learning;

use App\Models\Knowledge;
use App\Services\Learning\KnowledgeMutator;

class KnowledgeMutatorTest extends LearningTestCase
{
    private KnowledgeMutator $mutator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mutator = new KnowledgeMutator();
    }

    public function test_channel_outperformed_creates_preferred_knowledge(): void
    {
        $learning = $this->makeLearning('channel_outperformed', ['channel' => 'email']);

        $effects = $this->mutator->mutate($learning);

        $this->assertCount(1, $effects);
        $this->assertSame('knowledge_mutation', $effects[0]['type']);
        $this->assertSame('channel.email.preferred', $effects[0]['subject']);

        $knowledge = Knowledge::withoutGlobalScopes()->where('subject', 'channel.email.preferred')->first();
        $this->assertNotNull($knowledge);
        $this->assertSame('learning', $knowledge->type);
        $this->assertTrue((bool) $knowledge->is_active);
        $this->assertNotNull($knowledge->expires_at);
    }

    public function test_channel_underperformed_creates_weak_knowledge(): void
    {
        $learning = $this->makeLearning('channel_underperformed', ['channel' => 'social']);

        $effects = $this->mutator->mutate($learning);

        $this->assertSame('channel.social.weak', $effects[0]['subject']);
    }

    public function test_superseding_deactivates_previous_knowledge(): void
    {
        $existing = Knowledge::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'learning',
            'subject' => 'channel.email.preferred',
            'body' => 'Old knowledge',
            'confidence' => 70,
            'is_active' => true,
            'generated_at' => now(),
            'expires_at' => now()->addDays(90),
        ]);

        $learning = $this->makeLearning('channel_outperformed', ['channel' => 'email']);

        $effects = $this->mutator->mutate($learning);

        $this->assertSame($existing->id, $effects[0]['previous_knowledge_id']);
        $existing->refresh();
        $this->assertFalse((bool) $existing->is_active);
    }

    public function test_email_deliverability_creates_health_knowledge(): void
    {
        $learning = $this->makeLearning('email_deliverability_issue', [
            'hard_bounces' => 5, 'spam_complaint_rate' => 0.002,
        ]);

        $effects = $this->mutator->mutate($learning);

        $this->assertSame('channel.email.health', $effects[0]['subject']);
    }

    public function test_campaign_type_succeeded_creates_effectiveness_knowledge(): void
    {
        $learning = $this->makeLearning('campaign_type_succeeded', ['campaign_type' => 'featured_item']);

        $effects = $this->mutator->mutate($learning);

        $this->assertSame('campaign.featured_item.effectiveness', $effects[0]['subject']);
    }

    public function test_content_angle_engaged_creates_angle_knowledge(): void
    {
        $learning = $this->makeLearning('content_angle_engaged', [
            'campaign_type' => 'featured_item', 'angles' => ['urgency', 'exclusivity'],
        ]);

        $effects = $this->mutator->mutate($learning);

        $this->assertSame('content.angle.featured_item', $effects[0]['subject']);
    }

    public function test_optimal_timing_creates_timing_knowledge(): void
    {
        $learning = $this->makeLearning('optimal_timing_signal', [
            'channel_type' => 'email', 'published_hour' => 10, 'open_rate' => 0.35,
        ]);

        $effects = $this->mutator->mutate($learning);

        $this->assertSame('timing.email.optimal_hour', $effects[0]['subject']);
    }

    public function test_knowledge_expires_in_90_days(): void
    {
        $learning = $this->makeLearning('channel_outperformed', ['channel' => 'email']);

        $this->mutator->mutate($learning);

        $knowledge = Knowledge::withoutGlobalScopes()->where('subject', 'channel.email.preferred')->first();
        $this->assertNotNull($knowledge?->expires_at);
        $this->assertTrue($knowledge->expires_at->isFuture());
        $this->assertEqualsWithDelta(90, now()->diffInDays($knowledge->expires_at), 1.0);
    }

    public function test_missing_channel_returns_empty(): void
    {
        $learning = $this->makeLearning('channel_outperformed', ['rate' => 0.05]);

        $effects = $this->mutator->mutate($learning);

        $this->assertEmpty($effects);
    }
}
