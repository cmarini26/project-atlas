<?php

namespace App\Services\Learning;

use App\Models\CompanyScoringWeights;
use App\Models\Fact;
use App\Models\Knowledge;
use App\Models\Learning;
use App\Models\LearningApplication;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LearningRollbackService
{
    /**
     * Roll back a LearningApplication by creating compensating state.
     * Never deletes rows. The rolled-back Learning re-enters the queue.
     *
     * @throws RuntimeException if the application is already rolled back
     */
    public function rollback(LearningApplication $application, string $reason): void
    {
        if ($application->isRolledBack()) {
            throw new RuntimeException("LearningApplication {$application->id} is already rolled back.");
        }

        DB::transaction(function () use ($application, $reason): void {
            /** @var list<array<string, mixed>> $effects */
            $effects = $application->effects ?? [];

            foreach ($effects as $effect) {
                $type = (string) ($effect['type'] ?? '');

                match ($type) {
                    'fact_mutation' => $this->rollbackFact($effect),
                    'knowledge_mutation' => $this->rollbackKnowledge($effect),
                    'weight_calibration' => $this->rollbackWeights($effect),
                    default => null,
                };
            }

            // Mark the application as rolled back
            $application->update([
                'rolled_back_at' => now(),
                'rollback_reason' => $reason,
            ]);

            // Re-enter the Learning into the queue by clearing applied_at
            Learning::withoutGlobalScopes()
                ->where('id', $application->learning_id)
                ->update(['applied_at' => null]);
        });
    }

    /**
     * @param  array<string, mixed>  $effect
     */
    private function rollbackFact(array $effect): void
    {
        $factId = $effect['fact_id'] ?? null;
        $previousFactId = $effect['previous_fact_id'] ?? null;

        if ($factId === null) {
            return;
        }

        // Deactivate the applied fact
        Fact::withoutGlobalScopes()
            ->where('id', $factId)
            ->update([
                'is_current' => false,
                'valid_until' => now(),
            ]);

        // Restore the previous fact if one existed
        if ($previousFactId !== null) {
            Fact::withoutGlobalScopes()
                ->where('id', $previousFactId)
                ->update([
                    'is_current' => true,
                    'superseded_by_id' => null,
                    'valid_until' => null,
                ]);
        }
    }

    /**
     * @param  array<string, mixed>  $effect
     */
    private function rollbackKnowledge(array $effect): void
    {
        $knowledgeId = $effect['knowledge_id'] ?? null;
        $previousKnowledgeId = $effect['previous_knowledge_id'] ?? null;

        if ($knowledgeId === null) {
            return;
        }

        // Deactivate the applied knowledge entry
        Knowledge::withoutGlobalScopes()
            ->where('id', $knowledgeId)
            ->update(['is_active' => false]);

        // Restore the previous knowledge entry if one existed
        if ($previousKnowledgeId !== null) {
            Knowledge::withoutGlobalScopes()
                ->where('id', $previousKnowledgeId)
                ->update([
                    'is_active' => true,
                    'expires_at' => now()->addDays(90),
                ]);
        }
    }

    /**
     * @param  array<string, mixed>  $effect
     */
    private function rollbackWeights(array $effect): void
    {
        $newWeightsId = $effect['new_weights_id'] ?? null;
        $previousWeightsId = $effect['previous_weights_id'] ?? null;

        if ($newWeightsId === null) {
            return;
        }

        // Retire the applied weights row
        CompanyScoringWeights::withoutGlobalScopes()
            ->where('id', $newWeightsId)
            ->update(['is_current' => false]);

        // Restore the previous weights row if one existed
        if ($previousWeightsId !== null) {
            CompanyScoringWeights::withoutGlobalScopes()
                ->where('id', $previousWeightsId)
                ->update(['is_current' => true]);
        }
    }
}
