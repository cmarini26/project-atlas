<?php

namespace App\Domain\Onboarding;

/**
 * Which MarketingChannelTypes need identifying details before the
 * onboarding wizard's Asset Details step considers them complete.
 *
 * Deliberately scoped to only what Discovery can currently act on
 * (`ConnectorRegistry::autoDiscoverableFor()` — Website today) rather than
 * every type with a plausible URL field. Collecting a URL for Instagram/
 * Facebook/LinkedIn/etc. at onboarding time was pure friction: Discovery
 * never used it (those types are declared-only until connected for real
 * from Settings), so asking for it up front only slowed the wizard down.
 * Deferred fields are filled in later from `/app/settings/marketing-presence`,
 * which already has an add/edit form for exactly this. See the UI-rethink
 * plan, Workstream C.1.
 */
final class AssetDetailRequirements
{
    /**
     * Types where the Asset Details step must collect something before
     * the wizard considers the asset "detailed." Every other declarable
     * type has only optional fields, filled in later from Settings.
     *
     * @var list<string>
     */
    public const REQUIRES_DETAILS = ['website'];

    /**
     * Whether a declared asset's currently-stored handle_or_url/metadata
     * satisfy this type's minimum identifying-info requirement. Website is
     * the only type in REQUIRES_DETAILS today, needing both a URL and a
     * platform; every other type is satisfied unconditionally.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function isSatisfied(string $type, ?string $handleOrUrl, array $metadata): bool
    {
        if (! in_array($type, self::REQUIRES_DETAILS, true)) {
            return true;
        }

        return filled($handleOrUrl) && filled($metadata['platform'] ?? null);
    }
}
