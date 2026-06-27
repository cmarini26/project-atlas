<?php

namespace Tests\Feature\Learning;

use App\Services\Learning\ConflictResolver;
use App\Services\Learning\EvidenceEvaluator;
use App\Services\Learning\SignalTier;

class ConflictResolverTest extends LearningTestCase
{
    private ConflictResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ConflictResolver(new SignalTier(), new EvidenceEvaluator());
    }

    public function test_non_conflicting_signals_get_apply_action(): void
    {
        $l1 = $this->makeLearning('email_deliverability_issue', ['hard_bounces' => 5]);
        $l2 = $this->makeLearning('recommendation_approved', ['campaign_type' => 'featured_item']);

        $resolutions = $this->resolver->resolveAll(collect([$l1, $l2]));

        $this->assertSame(ConflictResolver::APPLY, $resolutions[$l1->id]);
        $this->assertSame(ConflictResolver::APPLY, $resolutions[$l2->id]);
    }

    public function test_majority_wins_when_diff_is_two_or_more(): void
    {
        // 3 outperformed vs 1 underperformed (diff=2) → outperformed wins
        $out1 = $this->makeLearning('channel_outperformed', ['channel' => 'email']);
        $out2 = $this->makeLearning('channel_outperformed', ['channel' => 'email']);
        $out3 = $this->makeLearning('channel_outperformed', ['channel' => 'email']);
        $under = $this->makeLearning('channel_underperformed', ['channel' => 'email']);

        $resolutions = $this->resolver->resolveAll(collect([$out1, $out2, $out3, $under]));

        $this->assertSame(ConflictResolver::APPLY, $resolutions[$out1->id]);
        $this->assertSame(ConflictResolver::APPLY, $resolutions[$out2->id]);
        $this->assertSame(ConflictResolver::APPLY, $resolutions[$out3->id]);
        $this->assertSame(ConflictResolver::CONSUME, $resolutions[$under->id]);
    }

    public function test_tie_leaves_both_unapplied(): void
    {
        // 1 outperformed vs 1 underperformed → tie
        $out = $this->makeLearning('channel_outperformed', ['channel' => 'email']);
        $under = $this->makeLearning('channel_underperformed', ['channel' => 'email']);

        $resolutions = $this->resolver->resolveAll(collect([$out, $under]));

        $this->assertSame(ConflictResolver::SKIP, $resolutions[$out->id]);
        $this->assertSame(ConflictResolver::SKIP, $resolutions[$under->id]);
    }

    public function test_conflict_is_discriminator_scoped(): void
    {
        // email outperformed vs social underperformed — different discriminators, no conflict
        $emailOut = $this->makeLearning('channel_outperformed', ['channel' => 'email']);
        $socialUnder = $this->makeLearning('channel_underperformed', ['channel' => 'social']);

        $resolutions = $this->resolver->resolveAll(collect([$emailOut, $socialUnder]));

        $this->assertSame(ConflictResolver::APPLY, $resolutions[$emailOut->id]);
        $this->assertSame(ConflictResolver::APPLY, $resolutions[$socialUnder->id]);
    }

    public function test_recency_wins_when_one_side_is_30_days_newer(): void
    {
        // Recent outperformed vs old underperformed → recency wins for outperformed
        $out = $this->makeLearning('channel_outperformed', ['channel' => 'email'], null, 0);
        $under = $this->makeLearning('channel_underperformed', ['channel' => 'email'], null, 31);

        $resolutions = $this->resolver->resolveAll(collect([$out, $under]));

        $this->assertSame(ConflictResolver::APPLY, $resolutions[$out->id]);
        $this->assertSame(ConflictResolver::CONSUME, $resolutions[$under->id]);
    }

    public function test_campaign_type_conflicts_resolved_correctly(): void
    {
        $suc1 = $this->makeLearning('campaign_type_succeeded', ['campaign_type' => 'featured_item']);
        $suc2 = $this->makeLearning('campaign_type_succeeded', ['campaign_type' => 'featured_item']);
        $suc3 = $this->makeLearning('campaign_type_succeeded', ['campaign_type' => 'featured_item']);
        $under = $this->makeLearning('campaign_type_underperformed', ['campaign_type' => 'featured_item']);

        $resolutions = $this->resolver->resolveAll(collect([$suc1, $suc2, $suc3, $under]));

        $this->assertSame(ConflictResolver::APPLY, $resolutions[$suc1->id]);
        $this->assertSame(ConflictResolver::CONSUME, $resolutions[$under->id]);
    }
}
