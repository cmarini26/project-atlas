<?php

namespace App\Services\Opportunity\Detectors;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\Fact;
use App\Services\Opportunity\Detectors\Contracts\OpportunityDetector;
use App\Services\Opportunity\OpportunityCandidate;
use Illuminate\Support\Collection;

class ReEngagementDetector implements OpportunityDetector
{
    private const int GAP_THRESHOLD_DAYS = 14;

    /** @return string[] */
    public function appliesTo(): array
    {
        return ['re_engagement'];
    }

    /**
     * @return Collection<int, OpportunityCandidate>
     */
    public function detect(Company $company, BusinessBrain $brain): Collection
    {
        /** @var Collection<int, OpportunityCandidate> $candidates */
        $candidates = collect();

        if ($brain->featuredItems->isEmpty()) {
            return $candidates;
        }

        $daysSince = $this->daysSinceLastCampaign($brain);

        if ($daysSince === null || $daysSince < self::GAP_THRESHOLD_DAYS) {
            return $candidates;
        }

        $candidates->push(new OpportunityCandidate(
            type: 're_engagement',
            subjectType: 'company',
            subjectId: $company->id,
            title: "No campaigns in {$daysSince} days — re-engage your audience",
            description: $this->buildDescription($company, $daysSince),
            expiresAt: now()->addDays(7)->toIso8601String(),
            relevanceScore: 70,
            timingScore: $this->timingScore($daysSince),
            confidenceScore: 60,
            urgencyScore: $this->urgencyScore($daysSince),
        ));

        return $candidates;
    }

    private function daysSinceLastCampaign(BusinessBrain $brain): ?int
    {
        /** @var Fact|null $fact */
        $fact = $brain->activeFacts->first(
            fn (Fact $f): bool => $f->key === 'marketing.days_since_last_campaign'
        );

        if ($fact !== null) {
            return (int) ($fact->value ?? 0);
        }

        /** @var Collection<int, Campaign> $campaigns */
        $campaigns = $brain->recentCampaigns;

        if ($campaigns->isEmpty()) {
            return 999;
        }

        $latest = $campaigns->sortByDesc('created_at')->first();

        if ($latest === null || $latest->created_at === null) {
            return null;
        }

        return (int) $latest->created_at->diffInDays(now());
    }

    private function timingScore(int $daysSince): int
    {
        if ($daysSince >= 30) {
            return 90;
        }

        if ($daysSince >= 21) {
            return 82;
        }

        return 70;
    }

    private function urgencyScore(int $daysSince): int
    {
        if ($daysSince >= 30) {
            return 65;
        }

        if ($daysSince >= 21) {
            return 57;
        }

        return 48;
    }

    private function buildDescription(Company $company, int $daysSince): string
    {
        return "{$company->name} has not published a campaign in {$daysSince} days. "
            .'Audience engagement is at risk. A re-engagement campaign will re-establish '
            .'visibility and remind followers of active inventory.';
    }
}
