<?php

namespace Tests\Feature\Learning;

use App\Models\Learning;
use App\Services\Learning\SignalTier;
use Illuminate\Support\Collection;

class SignalTierTest extends LearningTestCase
{
    private SignalTier $tier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tier = new SignalTier();
    }

    public function test_safety_signals_are_tier_one(): void
    {
        $this->assertSame(SignalTier::SAFETY, $this->tier->tierFor('email_deliverability_issue'));
        $this->assertSame(SignalTier::SAFETY, $this->tier->tierFor('high_unsubscribe_rate'));
    }

    public function test_performance_signals_are_tier_two(): void
    {
        $this->assertSame(SignalTier::PERFORMANCE, $this->tier->tierFor('channel_outperformed'));
        $this->assertSame(SignalTier::PERFORMANCE, $this->tier->tierFor('channel_underperformed'));
        $this->assertSame(SignalTier::PERFORMANCE, $this->tier->tierFor('campaign_type_succeeded'));
        $this->assertSame(SignalTier::PERFORMANCE, $this->tier->tierFor('campaign_type_underperformed'));
        $this->assertSame(SignalTier::PERFORMANCE, $this->tier->tierFor('optimal_timing_signal'));
    }

    public function test_preference_signals_are_tier_three(): void
    {
        $this->assertSame(SignalTier::PREFERENCE, $this->tier->tierFor('content_angle_engaged'));
        $this->assertSame(SignalTier::PREFERENCE, $this->tier->tierFor('recommendation_approved'));
        $this->assertSame(SignalTier::PREFERENCE, $this->tier->tierFor('recommendation_rejected'));
        $this->assertSame(SignalTier::PREFERENCE, $this->tier->tierFor('recommendation_edited_and_approved'));
    }

    public function test_unknown_signal_defaults_to_preference(): void
    {
        $this->assertSame(SignalTier::PREFERENCE, $this->tier->tierFor('unknown_signal'));
    }

    public function test_thresholds_match_spec(): void
    {
        $this->assertSame(1, $this->tier->thresholdFor(SignalTier::SAFETY));
        $this->assertSame(2, $this->tier->thresholdFor(SignalTier::PERFORMANCE));
        $this->assertSame(3, $this->tier->thresholdFor(SignalTier::PREFERENCE));
    }

    public function test_prioritise_sorts_safety_first(): void
    {
        $safety = $this->makeLearning('email_deliverability_issue', ['hard_bounces' => 5]);
        $preference = $this->makeLearning('recommendation_approved', ['campaign_type' => 'featured_item']);
        $performance = $this->makeLearning('channel_outperformed', ['channel' => 'email']);

        /** @var Collection<int, Learning> $collection */
        $collection = collect([$preference, $performance, $safety]);

        $sorted = $this->tier->prioritise($collection);

        $this->assertSame('email_deliverability_issue', $sorted->first()?->signal);
    }
}
