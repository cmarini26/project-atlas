<?php

namespace App\Services\Opportunity\Detectors;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Services\Opportunity\Detectors\Contracts\OpportunityDetector;
use App\Services\Opportunity\OpportunityCandidate;
use Illuminate\Support\Collection;

class FeaturedItemDetector implements OpportunityDetector
{
    private const int DEFAULT_COOLDOWN_DAYS = 14;

    private const int HIGH_VALUE_COOLDOWN_DAYS = 45;

    private const float HIGH_VALUE_THRESHOLD = 10000.0;

    /** @return string[] */
    public function appliesTo(): array
    {
        return ['featured_item'];
    }

    /**
     * @return Collection<int, OpportunityCandidate>
     */
    public function detect(Company $company, BusinessBrain $brain): Collection
    {
        /** @var Collection<int, OpportunityCandidate> $candidates */
        $candidates = collect();

        /** @var Collection<int, CatalogItem> $items */
        $items = $brain->featuredItems;

        if ($items->isEmpty()) {
            return $candidates;
        }

        foreach ($items as $item) {
            $cooldownDays = $this->cooldownDays($item);
            $threshold = now()->subDays($cooldownDays);

            $lastPromoted = $item->promoted_at;

            if ($lastPromoted !== null && $lastPromoted->isAfter($threshold)) {
                continue;
            }

            $daysSince = $lastPromoted !== null
                ? (int) $lastPromoted->diffInDays(now())
                : 999;

            $candidates->push(new OpportunityCandidate(
                type: 'featured_item',
                subjectType: 'catalog_item',
                subjectId: $item->id,
                title: "{$item->title} — no campaign in {$daysSince} days",
                description: $this->buildDescription($item, $daysSince),
                expiresAt: now()->addDays(14)->toIso8601String(),
                relevanceScore: $this->relevanceScore($item),
                timingScore: $this->timingScore($daysSince, $cooldownDays),
                confidenceScore: 70,
                urgencyScore: 40,
            ));
        }

        return $candidates;
    }

    private function cooldownDays(CatalogItem $item): int
    {
        $price = (float) ($item->price ?? 0);

        return $price >= self::HIGH_VALUE_THRESHOLD
            ? self::HIGH_VALUE_COOLDOWN_DAYS
            : self::DEFAULT_COOLDOWN_DAYS;
    }

    private function relevanceScore(CatalogItem $item): int
    {
        $price = (float) ($item->price ?? 0);

        if ($price >= 100000) {
            return 92;
        }

        if ($price >= 10000) {
            return 85;
        }

        return 75;
    }

    private function timingScore(int $daysSince, int $cooldownDays): int
    {
        if ($daysSince >= $cooldownDays * 3) {
            return 90;
        }

        if ($daysSince >= $cooldownDays * 2) {
            return 80;
        }

        return 65;
    }

    private function buildDescription(CatalogItem $item, int $daysSince): string
    {
        $parts = ["{$item->title} has been in the catalog for {$daysSince} days with no promotion."];

        if ($item->price !== null) {
            $parts[] = 'Listed at $'.number_format((float) $item->price, 0).'.';
        }

        $parts[] = 'Featuring this item will increase its visibility.';

        return implode(' ', $parts);
    }
}
