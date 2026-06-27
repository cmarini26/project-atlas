<?php

namespace App\Services\Learning;

use App\Models\Learning;
use App\Models\LearningApplication;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LearningEngine
{
    public function __construct(
        private readonly SignalTier $signalTier,
        private readonly EvidenceEvaluator $evidenceEvaluator,
        private readonly ConflictResolver $conflictResolver,
        private readonly FactMutator $factMutator,
        private readonly KnowledgeMutator $knowledgeMutator,
        private readonly WeightCalibrator $weightCalibrator,
    ) {}

    /**
     * Apply all pending Learning records for a company in priority order.
     * Idempotent: running twice on the same day processes nothing on the second run.
     */
    public function apply(string $companyId): void
    {
        /** @var Collection<int, Learning> $unapplied */
        $unapplied = Learning::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->unapplied()
            ->orderBy('created_at')
            ->get();

        if ($unapplied->isEmpty()) {
            return;
        }

        $prioritised = $this->signalTier->prioritise($unapplied);
        $resolutions = $this->conflictResolver->resolveAll($unapplied);

        foreach ($prioritised as $learning) {
            // Refresh — another iteration may have already set applied_at
            $current = Learning::withoutGlobalScopes()->find($learning->id);

            if ($current === null || $current->applied_at !== null) {
                continue;
            }

            $action = $resolutions[$learning->id] ?? ConflictResolver::APPLY;

            match ($action) {
                ConflictResolver::SKIP => null,
                ConflictResolver::CONSUME => $this->markConsumed($current),
                ConflictResolver::APPLY => $this->applyOne($current, $companyId),
                default => null,
            };
        }
    }

    private function applyOne(Learning $learning, string $companyId): void
    {
        $tier = $this->signalTier->tierFor($learning->signal);
        $threshold = $this->signalTier->thresholdFor($tier);

        if (! $this->evidenceEvaluator->meetsThreshold($learning, $threshold, $companyId)) {
            return;
        }

        $effects = [
            ...$this->factMutator->mutate($learning),
            ...$this->knowledgeMutator->mutate($learning),
            ...$this->weightCalibrator->calibrate($learning, $companyId),
        ];

        DB::transaction(function () use ($learning, $companyId, $effects): void {
            if (! empty($effects)) {
                LearningApplication::create([
                    'company_id' => $companyId,
                    'learning_id' => $learning->id,
                    'effects' => $effects,
                ]);
            }

            Learning::withoutGlobalScopes()
                ->where('id', $learning->id)
                ->update(['applied_at' => now()]);
        });
    }

    private function markConsumed(Learning $learning): void
    {
        Learning::withoutGlobalScopes()
            ->where('id', $learning->id)
            ->update(['applied_at' => now()]);
    }
}
