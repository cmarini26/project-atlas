<?php

namespace App\Services\Opportunity\Detectors;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Services\Opportunity\Detectors\Contracts\OpportunityDetector;
use App\Services\Opportunity\OpportunityCandidate;
use Illuminate\Support\Collection;

class NewArrivalDetector implements OpportunityDetector
{
    private const int NEW_ARRIVAL_HOURS = 48;

    /** @return string[] */
    public function appliesTo(): array
    {
        return ['new_arrival'];
    }

    /**
     * @return Collection<int, OpportunityCandidate>
     */
    public function detect(Company $company, BusinessBrain $brain): Collection
    {
        /** @var Collection<int, OpportunityCandidate> $candidates */
        $candidates = collect();

        $threshold = now()->subHours(self::NEW_ARRIVAL_HOURS);

        /** @var Collection<int, CatalogItem> $items */
        $items = $brain->featuredItems;

        $newArrivals = $items->filter(
            fn (CatalogItem $item): bool => $item->created_at !== null && $item->created_at->isAfter($threshold)
        );

        foreach ($newArrivals as $item) {
            $createdAt = $item->created_at;

            if ($createdAt === null) {
                continue;
            }

            $hoursAgo = (int) $createdAt->diffInHours(now());

            $candidates->push(new OpportunityCandidate(
                type: 'new_arrival',
                subjectType: 'catalog_item',
                subjectId: $item->id,
                title: "New arrival — {$item->title}",
                description: $this->buildDescription($item, $hoursAgo),
                expiresAt: $createdAt->addHours(72)->toIso8601String(),
                relevanceScore: 85,
                timingScore: $this->timingScore($hoursAgo),
                confidenceScore: 80,
                urgencyScore: $hoursAgo <= 12 ? 75 : 55,
            ));
        }

        return $candidates;
    }

    private function timingScore(int $hoursAgo): int
    {
        if ($hoursAgo <= 6) {
            return 95;
        }

        if ($hoursAgo <= 24) {
            return 85;
        }

        return 70;
    }

    private function buildDescription(CatalogItem $item, int $hoursAgo): string
    {
        $parts = ["{$item->title} was added {$hoursAgo} hours ago."];

        if ($item->price !== null) {
            $parts[] = 'Listed at $'.number_format((float) $item->price, 0).'.';
        }

        $parts[] = 'New arrivals perform best when promoted immediately while novelty is high.';

        return implode(' ', $parts);
    }
}
