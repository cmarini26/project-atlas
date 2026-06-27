<?php

namespace Tests\Feature\Learning;

use App\Models\Company;
use App\Models\Fact;
use App\Models\Knowledge;
use App\Models\Learning;
use App\Models\LearningApplication;
use App\Services\Learning\ConflictResolver;
use App\Services\Learning\EvidenceEvaluator;
use App\Services\Learning\FactMutator;
use App\Services\Learning\KnowledgeMutator;
use App\Services\Learning\LearningEngine;
use App\Services\Learning\SignalTier;
use App\Services\Learning\WeightCalibrator;

class LearningEngineTest extends LearningTestCase
{
    private LearningEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $signalTier = new SignalTier();
        $evidenceEvaluator = new EvidenceEvaluator();

        $this->engine = new LearningEngine(
            $signalTier,
            $evidenceEvaluator,
            new ConflictResolver($signalTier, $evidenceEvaluator),
            new FactMutator(),
            new KnowledgeMutator(),
            new WeightCalibrator(),
        );
    }

    public function test_apply_is_idempotent(): void
    {
        // Create 2 channel_outperformed signals
        $this->makeLearning('channel_outperformed', ['channel' => 'email']);
        $this->makeLearning('channel_outperformed', ['channel' => 'email']);

        $this->engine->apply($this->company->id);
        $this->engine->apply($this->company->id); // second run

        $applications = LearningApplication::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->count();

        // Each signal is applied exactly once
        $this->assertSame(2, $applications);
    }

    public function test_tier_1_signal_applied_with_single_evidence(): void
    {
        $this->makeLearning('email_deliverability_issue', ['hard_bounces' => 5]);

        $this->engine->apply($this->company->id);

        $applied = Learning::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereNotNull('applied_at')
            ->count();

        $this->assertSame(1, $applied);
    }

    public function test_tier_2_signal_skipped_without_sufficient_evidence(): void
    {
        // Only 1 signal — needs 2 for performance tier
        $this->makeLearning('channel_outperformed', ['channel' => 'email']);

        $this->engine->apply($this->company->id);

        $applied = Learning::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereNotNull('applied_at')
            ->count();

        $this->assertSame(0, $applied);
    }

    public function test_tier_2_signal_applied_with_sufficient_evidence(): void
    {
        $this->makeLearning('channel_outperformed', ['channel' => 'email']);
        $this->makeLearning('channel_outperformed', ['channel' => 'email']);

        $this->engine->apply($this->company->id);

        $applied = Learning::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereNotNull('applied_at')
            ->count();

        $this->assertSame(2, $applied);

        $fact = Fact::withoutGlobalScopes()
            ->where('key', 'channel_performance.email.affinity')
            ->where('is_current', true)
            ->first();

        $this->assertNotNull($fact);
        $this->assertSame('strong', $fact->value);
    }

    public function test_tier_3_signal_requires_3_evidence(): void
    {
        $this->makeLearning('recommendation_approved', ['campaign_type' => 'featured_item']);
        $this->makeLearning('recommendation_approved', ['campaign_type' => 'featured_item']);
        // Only 2 — not enough

        $this->engine->apply($this->company->id);

        $applied = Learning::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereNotNull('applied_at')
            ->count();

        $this->assertSame(0, $applied);
    }

    public function test_conflict_losers_are_consumed(): void
    {
        // 3 outperformed vs 1 underperformed for email → outperformed wins, underperformed consumed
        $this->makeLearning('channel_outperformed', ['channel' => 'email']);
        $this->makeLearning('channel_outperformed', ['channel' => 'email']);
        $this->makeLearning('channel_outperformed', ['channel' => 'email']);
        $under = $this->makeLearning('channel_underperformed', ['channel' => 'email']);

        $this->engine->apply($this->company->id);

        $under->refresh();
        $this->assertNotNull($under->applied_at);

        // Underperformed loser should NOT have a LearningApplication
        $apps = LearningApplication::withoutGlobalScopes()
            ->where('learning_id', $under->id)
            ->count();
        $this->assertSame(0, $apps);
    }

    public function test_tie_leaves_both_unapplied(): void
    {
        // 1 vs 1 → tie
        $this->makeLearning('channel_outperformed', ['channel' => 'email']);
        $this->makeLearning('channel_underperformed', ['channel' => 'email']);

        $this->engine->apply($this->company->id);

        $unapplied = Learning::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereNull('applied_at')
            ->count();

        $this->assertSame(2, $unapplied);
    }

    public function test_learning_application_contains_effects(): void
    {
        $this->makeLearning('channel_outperformed', ['channel' => 'email']);
        $this->makeLearning('channel_outperformed', ['channel' => 'email']);

        $this->engine->apply($this->company->id);

        $app = LearningApplication::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->first();

        $this->assertNotNull($app);
        $this->assertNotEmpty($app->effects);
    }

    public function test_apply_empty_queue_does_nothing(): void
    {
        $this->engine->apply($this->company->id);

        $this->assertDatabaseCount('learning_applications', 0);
    }

    public function test_learnings_are_company_scoped(): void
    {
        $other = Company::withoutGlobalScopes()->create([
            'name' => 'Other', 'slug' => 'other', 'industry' => 'test',
        ]);

        // Add signals for OTHER company — should not affect our company
        Learning::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'source_type' => 'execution_result',
            'source_id' => $this->campaign->id,
            'subject_type' => 'campaign',
            'subject_id' => $this->campaign->id,
            'signal' => 'channel_outperformed',
            'value' => ['channel' => 'email'],
            'applied_at' => null,
        ]);

        $this->makeLearning('channel_outperformed', ['channel' => 'email']);
        $this->makeLearning('channel_outperformed', ['channel' => 'email']);

        // Run for our company only
        $this->engine->apply($this->company->id);

        $knowledgeForOther = Knowledge::withoutGlobalScopes()
            ->where('company_id', $other->id)
            ->count();

        $this->assertSame(0, $knowledgeForOther);
    }
}
