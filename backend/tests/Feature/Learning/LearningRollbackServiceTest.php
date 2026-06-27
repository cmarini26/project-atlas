<?php

namespace Tests\Feature\Learning;

use App\Models\CompanyScoringWeights;
use App\Models\Fact;
use App\Models\Knowledge;
use App\Models\LearningApplication;
use App\Services\Learning\LearningRollbackService;
use RuntimeException;

class LearningRollbackServiceTest extends LearningTestCase
{
    private LearningRollbackService $rollback;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rollback = new LearningRollbackService();
    }

    public function test_rolling_back_fact_mutation_restores_previous_fact(): void
    {
        $oldFact = Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'key' => 'channel_performance.email.affinity',
            'value' => 'weak', 'data_type' => 'string', 'confidence' => 70,
            'is_current' => false, 'superseded_by_id' => null, 'valid_from' => now()->subDays(10),
        ]);

        $newFact = Fact::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'key' => 'channel_performance.email.affinity',
            'value' => 'strong', 'data_type' => 'string', 'confidence' => 70,
            'is_current' => true, 'superseded_by_id' => null, 'valid_from' => now(),
        ]);

        $learning = $this->makeLearning('channel_outperformed', ['channel' => 'email'], now()->toDateTimeString());

        $application = LearningApplication::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'learning_id' => $learning->id,
            'effects' => [[
                'type' => 'fact_mutation',
                'fact_id' => $newFact->id,
                'previous_fact_id' => $oldFact->id,
                'key' => 'channel_performance.email.affinity',
                'description' => 'Test',
            ]],
        ]);

        $this->rollback->rollback($application, 'test rollback');

        $newFact->refresh();
        $this->assertFalse((bool) $newFact->is_current);

        $oldFact->refresh();
        $this->assertTrue((bool) $oldFact->is_current);
        $this->assertNull($oldFact->superseded_by_id);

        $application->refresh();
        $this->assertNotNull($application->rolled_back_at);
        $this->assertSame('test rollback', $application->rollback_reason);

        $learning->refresh();
        $this->assertNull($learning->applied_at);
    }

    public function test_rolling_back_knowledge_mutation_restores_previous_entry(): void
    {
        $oldKnowledge = Knowledge::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'learning',
            'subject' => 'channel.email.preferred', 'body' => 'Old',
            'confidence' => 70, 'is_active' => false, 'generated_at' => now()->subDays(5),
        ]);

        $newKnowledge = Knowledge::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'learning',
            'subject' => 'channel.email.preferred', 'body' => 'New',
            'confidence' => 70, 'is_active' => true, 'generated_at' => now(),
            'expires_at' => now()->addDays(90),
        ]);

        $learning = $this->makeLearning('channel_outperformed', ['channel' => 'email'], now()->toDateTimeString());

        $application = LearningApplication::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'learning_id' => $learning->id,
            'effects' => [[
                'type' => 'knowledge_mutation',
                'knowledge_id' => $newKnowledge->id,
                'previous_knowledge_id' => $oldKnowledge->id,
                'subject' => 'channel.email.preferred',
                'description' => 'Test',
            ]],
        ]);

        $this->rollback->rollback($application, 'undo');

        $newKnowledge->refresh();
        $this->assertFalse((bool) $newKnowledge->is_active);

        $oldKnowledge->refresh();
        $this->assertTrue((bool) $oldKnowledge->is_active);
    }

    public function test_rolling_back_weight_calibration_restores_previous_weights(): void
    {
        $oldWeights = CompanyScoringWeights::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'weights' => ['type_modifiers' => ['featured_item' => 1.0]],
            'version' => 1, 'is_current' => false,
        ]);

        $newWeights = CompanyScoringWeights::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'weights' => ['type_modifiers' => ['featured_item' => 1.05]],
            'version' => 2, 'is_current' => true,
        ]);

        $learning = $this->makeLearning('campaign_type_succeeded', ['campaign_type' => 'featured_item'], now()->toDateTimeString());

        $application = LearningApplication::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'learning_id' => $learning->id,
            'effects' => [[
                'type' => 'weight_calibration',
                'new_weights_id' => $newWeights->id,
                'previous_weights_id' => $oldWeights->id,
                'description' => 'Test',
            ]],
        ]);

        $this->rollback->rollback($application, 'revert weights');

        $newWeights->refresh();
        $this->assertFalse((bool) $newWeights->is_current);

        $oldWeights->refresh();
        $this->assertTrue((bool) $oldWeights->is_current);
    }

    public function test_rollback_throws_if_already_rolled_back(): void
    {
        $learning = $this->makeLearning('channel_outperformed', ['channel' => 'email'], now()->toDateTimeString());

        $application = LearningApplication::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'learning_id' => $learning->id,
            'effects' => [],
            'rolled_back_at' => now(),
            'rollback_reason' => 'already done',
        ]);

        $this->expectException(RuntimeException::class);
        $this->rollback->rollback($application, 'again');
    }

    public function test_rollback_re_enters_learning_into_queue(): void
    {
        $learning = $this->makeLearning('channel_outperformed', ['channel' => 'email'], now()->toDateTimeString());

        $application = LearningApplication::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'learning_id' => $learning->id,
            'effects' => [],
        ]);

        $this->rollback->rollback($application, 'requeue');

        $learning->refresh();
        $this->assertNull($learning->applied_at);
    }
}
