<?php

namespace App\Services\Opportunity\Detectors;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Models\Fact;
use App\Services\Opportunity\Detectors\Contracts\OpportunityDetector;
use App\Services\Opportunity\OpportunityCandidate;
use Illuminate\Support\Collection;

class UrgencyDetector implements OpportunityDetector
{
    private const int URGENCY_HOURS = 48;

    /** @return string[] */
    public function appliesTo(): array
    {
        return ['urgency'];
    }

    /**
     * @return Collection<int, OpportunityCandidate>
     */
    public function detect(Company $company, BusinessBrain $brain): Collection
    {
        /** @var Collection<int, OpportunityCandidate> $candidates */
        $candidates = collect();

        $endingCount = $this->endingWithin48hCount($brain);
        $expiringItems = $this->itemsExpiringWithin48h($brain);

        if ($expiringItems->isNotEmpty()) {
            foreach ($expiringItems as $item) {
                $hoursLeft = (int) now()->diffInHours($item->expires_at);
                $expiry = $item->expires_at;

                $candidates->push(new OpportunityCandidate(
                    type: 'urgency',
                    subjectType: 'catalog_item',
                    subjectId: $item->id,
                    title: "{$item->title} — ending in {$hoursLeft} hours",
                    description: $this->buildItemDescription($item, $hoursLeft),
                    expiresAt: $expiry !== null
                        ? $expiry->addHours(2)->toIso8601String()
                        : now()->addHours(self::URGENCY_HOURS + 2)->toIso8601String(),
                    relevanceScore: 85,
                    timingScore: $hoursLeft <= 24 ? 95 : 85,
                    confidenceScore: 80,
                    urgencyScore: $hoursLeft <= 24 ? 98 : 90,
                ));
            }

            return $candidates;
        }

        if ($endingCount > 0) {
            $candidates->push(new OpportunityCandidate(
                type: 'urgency',
                subjectType: 'catalog',
                subjectId: $brain->catalog?->id,
                title: "{$endingCount} listings ending within 48 hours",
                description: "You have {$endingCount} active listings closing within the next 48 hours. This is a high-urgency promotion window.",
                expiresAt: now()->addHours(self::URGENCY_HOURS + 2)->toIso8601String(),
                relevanceScore: 85,
                timingScore: 95,
                confidenceScore: 80,
                urgencyScore: 98,
            ));
        }

        return $candidates;
    }

    private function endingWithin48hCount(BusinessBrain $brain): int
    {
        /** @var Fact|null $fact */
        $fact = $brain->activeFacts->first(
            fn (Fact $f): bool => $f->key === 'catalog.ending_within_48h_count'
        );

        if ($fact === null) {
            return 0;
        }

        return (int) ($fact->value ?? 0);
    }

    /**
     * @return Collection<int, CatalogItem>
     */
    private function itemsExpiringWithin48h(BusinessBrain $brain): Collection
    {
        $threshold = now()->addHours(self::URGENCY_HOURS);

        /** @var Collection<int, CatalogItem> $items */
        $items = $brain->featuredItems;

        return $items->filter(
            fn (CatalogItem $item): bool => $item->expires_at !== null && $item->expires_at->isBefore($threshold)
        );
    }

    private function buildItemDescription(CatalogItem $item, int $hoursLeft): string
    {
        $parts = ["{$item->title} closes in {$hoursLeft} hours."];

        if ($item->price !== null) {
            $parts[] = 'Listed at $'.number_format((float) $item->price, 0).'.';
        }

        $parts[] = 'A time-sensitive promotion will drive last-minute bids and interest.';

        return implode(' ', $parts);
    }
}
