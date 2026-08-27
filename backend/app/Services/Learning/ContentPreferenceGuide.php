<?php

namespace App\Services\Learning;

use App\Models\Company;
use App\Models\Learning;
use Illuminate\Support\Collection;

class ContentPreferenceGuide
{
    private const int MIN_OCCURRENCES = 2;

    private const float MIN_CONSISTENCY = 0.6;

    public function guidanceFor(Company $company, string $channelType): ?string
    {
        /** @var Collection<int, Learning> $learners */
        $learners = Learning::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('signal', 'recommendation_edited_and_approved')
            ->latest('created_at')
            ->get();

        $channelLearners = $learners->filter(function (Learning $learning) use ($channelType): bool {
            $value = $learning->value ?? [];

            return ($value['channel'] ?? null) === $channelType
                && is_array($value['edit_patterns'] ?? null)
                && ! empty($value['edit_patterns']);
        })->values();

        if ($channelLearners->isEmpty()) {
            return null;
        }

        $instructions = array_filter([
            $this->lengthInstruction($channelLearners),
            $this->hashtagInstruction($channelLearners),
            $this->priceInstruction($channelLearners),
        ]);

        if ($instructions === []) {
            return null;
        }

        return implode("\n", [
            'Learned content preferences from prior approved edits:',
            ...array_map(static fn (string $instruction): string => '- '.$instruction, $instructions),
            'Only apply these preferences when they fit the campaign naturally.',
        ]);
    }

    /**
     * @param  Collection<int, Learning>  $learners
     */
    private function lengthInstruction(Collection $learners): ?string
    {
        $winner = $this->dominantPattern($learners, 'length_preference');

        return match ($winner) {
            'shorter' => 'Keep the copy tighter and more concise than Atlas usually drafts.',
            'longer' => 'Expand the copy with a bit more detail than Atlas usually drafts.',
            default => null,
        };
    }

    /**
     * @param  Collection<int, Learning>  $learners
     */
    private function hashtagInstruction(Collection $learners): ?string
    {
        $winner = $this->dominantPattern($learners, 'hashtag_preference');

        return match ($winner) {
            'added', 'increased' => 'Include relevant hashtags in the final draft.',
            'removed', 'decreased' => 'Avoid hashtags unless they are essential.',
            default => null,
        };
    }

    /**
     * @param  Collection<int, Learning>  $learners
     */
    private function priceInstruction(Collection $learners): ?string
    {
        $winner = $this->dominantPattern($learners, 'price_inclusion');

        return match ($winner) {
            'added' => 'Include clear price or offer details when the campaign has a concrete offer.',
            'removed' => 'Avoid explicit price callouts unless the campaign absolutely depends on them.',
            default => null,
        };
    }

    /**
     * @param  Collection<int, Learning>  $learners
     */
    private function dominantPattern(Collection $learners, string $key): ?string
    {
        /** @var Collection<int, string> $patterns */
        $patterns = $learners
            ->map(function (Learning $learning) use ($key): ?string {
                $value = $learning->value ?? [];
                $editPatterns = $value['edit_patterns'] ?? null;

                if (! is_array($editPatterns)) {
                    return null;
                }

                $pattern = $editPatterns[$key] ?? null;

                return is_string($pattern) && $pattern !== '' ? $pattern : null;
            })
            ->filter()
            ->values();

        if ($patterns->count() < self::MIN_OCCURRENCES) {
            return null;
        }

        $counts = $patterns->countBy()->sortDesc();
        $winner = $counts->keys()->first();

        if (! is_string($winner) || $winner === '') {
            return null;
        }

        $winnerCount = (int) ($counts->first() ?? 0);
        $consistency = $winnerCount / $patterns->count();

        if ($winnerCount < self::MIN_OCCURRENCES || $consistency < self::MIN_CONSISTENCY) {
            return null;
        }

        return $winner;
    }
}
