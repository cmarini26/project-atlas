<?php

namespace App\Domain\Onboarding;

use App\Models\Company;
use App\Models\MarketingChannel;

/**
 * The channels a company declared during onboarding that still need the user
 * to do something before they can be used — a `handle` type with no URL/handle
 * entered, or an `oauth` type not yet connected. `none` and `manual` types
 * never appear. Recomputed live, so connecting a channel later removes it.
 */
final class PendingChannelSetup
{
    /**
     * @return list<array{type: string, label: string, requirement: string, summary: string}>
     */
    public static function forCompany(Company $company): array
    {
        $channels = MarketingChannel::query()
            ->where('company_id', $company->id)
            ->get();

        $pending = [];

        foreach ($channels as $channel) {
            $type = $channel->type->value;

            if (! OnboardingAssetTypes::needsConnection($type) || self::isSatisfied($channel)) {
                continue;
            }

            $pending[] = [
                'type' => $type,
                'label' => $channel->type->label(),
                'requirement' => OnboardingAssetTypes::requirementFor($type),
                'summary' => OnboardingAssetTypes::summaryFor($type),
            ];
        }

        return $pending;
    }

    public static function hasPending(Company $company): bool
    {
        return self::forCompany($company) !== [];
    }

    private static function isSatisfied(MarketingChannel $channel): bool
    {
        return match (OnboardingAssetTypes::requirementFor($channel->type->value)) {
            // A handle/URL type is satisfied once the user has entered one.
            OnboardingAssetTypes::REQUIREMENT_HANDLE => filled($channel->handle_or_url),
            // An OAuth type is satisfied once the account is actually connected.
            OnboardingAssetTypes::REQUIREMENT_OAUTH => (bool) $channel->is_connected,
            default => true,
        };
    }
}
