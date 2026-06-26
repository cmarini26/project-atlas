<?php

namespace App\Services\Decision;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Campaign;
use App\Models\CatalogItem;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Services\Opportunity\OpportunityRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DecisionEngine
{
    private const array COOLDOWN_DAYS = [
        'urgency_promotion' => 3,
        'featured_item' => 14,
        're_engagement' => 14,
        'seasonal' => 365,
    ];

    public function __construct(
        private readonly OpportunityRepository $opportunityRepository,
        private readonly DecisionService $decisionService,
    ) {}

    /**
     * Evaluate all open Opportunities for a company and commit a Decision
     * for the highest-scoring candidate that passes all five guard conditions.
     *
     * Returns the committed Decision, or null if no candidate passes.
     */
    public function evaluate(Company $company, BusinessBrain $brain): ?Decision
    {
        // Guard 5 — Channel availability (global; checked once before the loop)
        /** @var Collection<int, Channel> $activeChannels */
        $activeChannels = Channel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->get();

        if ($activeChannels->isEmpty()) {
            Log::info('DecisionEngine: no active channels; cannot commit Decision', [
                'company_id' => $company->id,
            ]);

            return null;
        }

        /** @var Collection<int, Opportunity> $candidates */
        $candidates = $this->opportunityRepository->openForCompany($company->id);

        foreach ($candidates as $opportunity) {
            // Guard 1 — Minimum score
            if ($opportunity->composite_score < 30) {
                continue;
            }

            $campaignType = match ($opportunity->type) {
                'urgency' => 'urgency_promotion',
                'new_arrival', 'featured_item', 'milestone' => 'featured_item',
                're_engagement' => 're_engagement',
                'seasonal' => 'seasonal',
            };

            // Guard 2 — Duplicate recommendation
            if ($this->hasDuplicateRecommendation($company->id, $campaignType)) {
                continue;
            }

            // Guard 3 — Campaign cooldown
            if ($this->isInCooldown($company->id, $campaignType)) {
                continue;
            }

            // Guard 4 — Catalog availability (only for CatalogItem-level opportunities)
            if ($opportunity->subject_type === 'catalog_item' && $opportunity->subject_id !== null) {
                $item = CatalogItem::withoutGlobalScopes()
                    ->where('id', $opportunity->subject_id)
                    ->where('company_id', $company->id)
                    ->first();

                if ($item === null || ! $item->isActive()) {
                    $opportunity->dismiss();
                    continue;
                }
            }

            // All guards passed — commit this Decision
            $channelIds = $this->resolveChannelIds($activeChannels, $campaignType);

            $context = new DecisionContext(
                opportunity: $opportunity,
                brain: $brain,
                campaignType: $campaignType,
                channelIds: $channelIds,
            );

            return $this->decisionService->commit($context);
        }

        Log::info('DecisionEngine: no candidates passed all guard conditions', [
            'company_id' => $company->id,
            'candidates_evaluated' => $candidates->count(),
        ]);

        return null;
    }

    private function hasDuplicateRecommendation(string $companyId, string $campaignType): bool
    {
        return Recommendation::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('campaign_type', $campaignType)
            ->whereIn('status', ['pending', 'viewed'])
            ->exists();
    }

    private function isInCooldown(string $companyId, string $campaignType): bool
    {
        $days = self::COOLDOWN_DAYS[$campaignType] ?? 14;

        return Campaign::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('campaign_type', $campaignType)
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subDays($days))
            ->exists();
    }

    /**
     * Select channel IDs with type-affinity preference for the campaign type.
     * Falls back to all active channels if no affinity match exists.
     *
     * @param  Collection<int, Channel>  $activeChannels
     * @return string[]
     */
    private function resolveChannelIds(Collection $activeChannels, string $campaignType): array
    {
        $affinity = match ($campaignType) {
            'urgency_promotion' => ['email', 'facebook', 'instagram'],
            'featured_item' => ['facebook', 'instagram', 'blog', 'landing_page'],
            're_engagement' => ['email'],
            default => [],
        };

        if (empty($affinity)) {
            return $activeChannels->pluck('id')->all();
        }

        $preferred = $activeChannels
            ->filter(fn (Channel $ch): bool => in_array($ch->type, $affinity, true))
            ->pluck('id')
            ->all();

        return empty($preferred)
            ? $activeChannels->pluck('id')->all()
            : $preferred;
    }
}
