<?php

namespace App\Services\Learning;

use App\Models\Learning;
use Illuminate\Support\Collection;

class ConflictResolver
{
    public const string APPLY = 'apply';

    public const string CONSUME = 'consume';

    public const string SKIP = 'skip';

    /** @var array<string, string> Opposing signal pairs */
    private const array OPPOSING = [
        'channel_outperformed' => 'channel_underperformed',
        'channel_underperformed' => 'channel_outperformed',
        'campaign_type_succeeded' => 'campaign_type_underperformed',
        'campaign_type_underperformed' => 'campaign_type_succeeded',
    ];

    public function __construct(
        private readonly SignalTier $signalTier,
        private readonly EvidenceEvaluator $evidenceEvaluator,
    ) {}

    /**
     * Resolve conflicts across all unapplied signals in a batch.
     *
     * @param  Collection<int, Learning>  $unapplied
     * @return array<string, string> Map of learning.id => 'apply'|'consume'|'skip'
     */
    public function resolveAll(Collection $unapplied): array
    {
        /** @var array<string, string> $resolutions */
        $resolutions = [];

        foreach ($unapplied as $learning) {
            if (isset($resolutions[$learning->id])) {
                continue;
            }

            $opposingSignal = self::OPPOSING[$learning->signal] ?? null;

            if ($opposingSignal === null) {
                $resolutions[$learning->id] = self::APPLY;
                continue;
            }

            $discriminator = $this->evidenceEvaluator->discriminatorFor($learning);

            // All same-direction signals with this discriminator in the batch
            $sameGroup = $unapplied->filter(
                fn (Learning $l) => $l->signal === $learning->signal
                    && $this->evidenceEvaluator->discriminatorFor($l) === $discriminator
            );

            // All opposing signals with this discriminator in the batch
            $opposingGroup = $unapplied->filter(
                fn (Learning $l) => $l->signal === $opposingSignal
                    && $this->evidenceEvaluator->discriminatorFor($l) === $discriminator
            );

            if ($opposingGroup->isEmpty()) {
                foreach ($sameGroup as $l) {
                    $resolutions[$l->id] = self::APPLY;
                }
                continue;
            }

            $winnerSignal = $this->resolveConflict(
                $learning->signal,
                $opposingSignal,
                $sameGroup,
                $opposingGroup,
            );

            foreach ($sameGroup as $l) {
                $resolutions[$l->id] = match (true) {
                    $winnerSignal === null => self::SKIP,
                    $winnerSignal === $learning->signal => self::APPLY,
                    default => self::CONSUME,
                };
            }

            foreach ($opposingGroup as $l) {
                $resolutions[$l->id] = match (true) {
                    $winnerSignal === null => self::SKIP,
                    $winnerSignal === $opposingSignal => self::APPLY,
                    default => self::CONSUME,
                };
            }
        }

        return $resolutions;
    }

    /**
     * @param  Collection<int, Learning>  $sameGroup
     * @param  Collection<int, Learning>  $opposingGroup
     */
    private function resolveConflict(
        string $signal,
        string $opposingSignal,
        Collection $sameGroup,
        Collection $opposingGroup,
    ): ?string {
        // Rule 1 — Safety override: any Tier 1 signal wins against non-Tier-1
        $sameTier = $this->signalTier->tierFor($signal);
        $opposingTier = $this->signalTier->tierFor($opposingSignal);

        if ($sameTier === SignalTier::SAFETY && $opposingTier !== SignalTier::SAFETY) {
            return $signal;
        }

        if ($opposingTier === SignalTier::SAFETY && $sameTier !== SignalTier::SAFETY) {
            return $opposingSignal;
        }

        $sameCount = $sameGroup->count();
        $opposingCount = $opposingGroup->count();

        // Rule 3 — Majority: diff of 2 or more
        if ($sameCount >= $opposingCount + 2) {
            return $signal;
        }

        if ($opposingCount >= $sameCount + 2) {
            return $opposingSignal;
        }

        // Rule 2 — Recency: most recent wins when one side is ≥ 30 days newer
        $latestSame = $sameGroup->sortByDesc('created_at')->first();
        $latestOpposing = $opposingGroup->sortByDesc('created_at')->first();

        if ($latestSame !== null && $latestOpposing !== null) {
            $sameDate = $latestSame->created_at;
            $opposingDate = $latestOpposing->created_at;

            if ($sameDate !== null && $opposingDate !== null) {
                if ($sameDate->gt($opposingDate->copy()->addDays(30))) {
                    return $signal;
                }

                if ($opposingDate->gt($sameDate->copy()->addDays(30))) {
                    return $opposingSignal;
                }
            }
        }

        // Rule 4 — Tie: leave both unapplied
        return null;
    }
}
