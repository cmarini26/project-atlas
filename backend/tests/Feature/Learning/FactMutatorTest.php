<?php

namespace Tests\Feature\Learning;

use App\Models\Fact;
use App\Services\Learning\FactMutator;

class FactMutatorTest extends LearningTestCase
{
    private FactMutator $mutator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mutator = new FactMutator();
    }

    public function test_channel_outperformed_creates_strong_affinity_fact(): void
    {
        $learning = $this->makeLearning('channel_outperformed', ['channel' => 'email', 'rate' => 0.05]);

        $effects = $this->mutator->mutate($learning);

        $this->assertCount(1, $effects);
        $this->assertSame('fact_mutation', $effects[0]['type']);
        $this->assertSame('channel_performance.email.affinity', $effects[0]['key']);
        $this->assertSame('strong', $effects[0]['value']);
        $this->assertNull($effects[0]['previous_fact_id']);

        $fact = Fact::withoutGlobalScopes()->where('key', 'channel_performance.email.affinity')->first();
        $this->assertNotNull($fact);
        $this->assertSame('strong', $fact->value);
        $this->assertTrue((bool) $fact->is_current);
    }

    public function test_channel_underperformed_creates_weak_affinity_fact(): void
    {
        $learning = $this->makeLearning('channel_underperformed', ['channel' => 'email', 'rate' => 0.01]);

        $effects = $this->mutator->mutate($learning);

        $this->assertCount(1, $effects);
        $this->assertSame('weak', $effects[0]['value']);
    }

    public function test_superseding_deactivates_previous_fact(): void
    {
        $existing = Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'key' => 'channel_performance.email.affinity',
            'value' => 'weak',
            'data_type' => 'string',
            'confidence' => 70,
            'is_current' => true,
            'valid_from' => now()->subDays(5),
        ]);

        $learning = $this->makeLearning('channel_outperformed', ['channel' => 'email']);

        $effects = $this->mutator->mutate($learning);

        $this->assertSame($existing->id, $effects[0]['previous_fact_id']);

        $existing->refresh();
        $this->assertFalse((bool) $existing->is_current);
        $this->assertNotNull($existing->superseded_by_id);
    }

    public function test_email_deliverability_issue_creates_compromised_fact(): void
    {
        $learning = $this->makeLearning('email_deliverability_issue', [
            'hard_bounces' => 10, 'spam_complaints' => 2, 'delivered' => 1000,
        ]);

        $effects = $this->mutator->mutate($learning);

        $this->assertSame('channel_health.email.status', $effects[0]['key']);
        $this->assertSame('compromised', $effects[0]['value']);
    }

    public function test_optimal_timing_creates_hour_fact(): void
    {
        $learning = $this->makeLearning('optimal_timing_signal', [
            'channel_type' => 'email', 'published_hour' => 10, 'open_rate' => 0.35,
        ]);

        $effects = $this->mutator->mutate($learning);

        $this->assertSame('channel_timing.email.optimal_hour', $effects[0]['key']);
        $this->assertSame(10, $effects[0]['value']);
    }

    public function test_unknown_signal_returns_empty_effects(): void
    {
        $learning = $this->makeLearning('content_angle_engaged', ['campaign_type' => 'featured_item', 'angles' => ['urgency']]);

        $effects = $this->mutator->mutate($learning);

        $this->assertEmpty($effects);
    }

    public function test_missing_channel_key_returns_empty(): void
    {
        $learning = $this->makeLearning('channel_outperformed', ['rate' => 0.05]);

        $effects = $this->mutator->mutate($learning);

        $this->assertEmpty($effects);
    }
}
