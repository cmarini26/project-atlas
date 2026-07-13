<?php

namespace App\Domain\Onboarding;

use App\Enums\MarketingChannelType;

/**
 * Which MarketingChannelTypes need identifying details before the
 * onboarding wizard's Asset Details step (Milestone 15 Phase 1) considers
 * them complete, and what those details are. Deliberately a plain,
 * declarative map rather than a class hierarchy — this is intentionally
 * the minimal version of the richer AssetFieldSchema registry
 * docs/specs/Business-Discovery-Onboarding.md §5.4 designs for Phase 3
 * (Discovery orchestration); that phase is not built here, so this phase
 * only needs "what does this step require," not "what can auto-discover."
 */
final class AssetDetailRequirements
{
    /**
     * Types where the Asset Details step must collect something before
     * the wizard considers the asset "detailed." Every other declarable
     * type (Email, Events, Print, TikTok, Other) has only optional fields.
     *
     * @var list<string>
     */
    public const REQUIRES_DETAILS = [
        'website', 'instagram', 'facebook', 'linkedin', 'google_business_profile', 'youtube', 'x',
    ];

    /**
     * Whether a declared asset's currently-stored handle_or_url/metadata
     * satisfy this type's minimum identifying-info requirement.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function isSatisfied(string $type, ?string $handleOrUrl, array $metadata): bool
    {
        if (! in_array($type, self::REQUIRES_DETAILS, true)) {
            return true;
        }

        if ($type === MarketingChannelType::Website->value) {
            return filled($handleOrUrl) && filled($metadata['platform'] ?? null);
        }

        return filled($handleOrUrl);
    }
}
