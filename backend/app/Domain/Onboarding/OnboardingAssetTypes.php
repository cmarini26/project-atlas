<?php

namespace App\Domain\Onboarding;

use App\Enums\MarketingChannelType;

/**
 * The 10 Marketing Assets cards the onboarding wizard offers, plus — per type —
 * what setup still remains before that channel can actually be used.
 *
 * `requiresDetails` (existing, Website only) means "collect a URL inline now".
 * `integrationRequirement` is the orthogonal concept added for CM-91: the work
 * left *after* onboarding, surfaced so a user never leaves the wizard thinking
 * a channel is connected when it is only declared. The kinds:
 *
 *   - none    nothing further needed (Website — handled inline)
 *   - handle  the user needs to enter a username / profile URL in Settings
 *   - oauth   the user needs to connect an account in Settings
 *   - manual  an offline channel — tracked, never integrated
 *
 * Mirrored field-for-field in resources/js/lib/onboardingAssets.ts; a parity
 * test guards against drift.
 */
final class OnboardingAssetTypes
{
    public const REQUIREMENT_NONE = 'none';

    public const REQUIREMENT_HANDLE = 'handle';

    public const REQUIREMENT_OAUTH = 'oauth';

    public const REQUIREMENT_MANUAL = 'manual';

    public const REQUIREMENT_KINDS = [
        self::REQUIREMENT_NONE,
        self::REQUIREMENT_HANDLE,
        self::REQUIREMENT_OAUTH,
        self::REQUIREMENT_MANUAL,
    ];

    /**
     * Type => [requirement kind, one-line summary of what is still needed].
     * Order is the wizard's card order.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const DEFINITIONS = [
        'website' => [self::REQUIREMENT_NONE, 'Set up during onboarding — nothing more to do.'],
        'google_business_profile' => [self::REQUIREMENT_HANDLE, 'Add your Google Business Profile URL in Marketing Presence.'],
        'instagram' => [self::REQUIREMENT_OAUTH, 'Connect your Instagram account in Marketing Presence.'],
        'facebook' => [self::REQUIREMENT_OAUTH, 'Connect your Facebook Page in Marketing Presence.'],
        'linkedin' => [self::REQUIREMENT_HANDLE, 'Add your LinkedIn page URL in Marketing Presence.'],
        'x' => [self::REQUIREMENT_HANDLE, 'Add your X handle in Marketing Presence.'],
        'youtube' => [self::REQUIREMENT_HANDLE, 'Add your YouTube channel URL in Marketing Presence.'],
        'email' => [self::REQUIREMENT_OAUTH, 'Connect your email sending provider in Marketing Presence.'],
        'events' => [self::REQUIREMENT_MANUAL, 'Tracked as an offline channel — no connection needed.'],
        'print' => [self::REQUIREMENT_MANUAL, 'Tracked as an offline channel — no connection needed.'],
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function requirementFor(string $type): string
    {
        return self::DEFINITIONS[$type][0] ?? self::REQUIREMENT_MANUAL;
    }

    public static function summaryFor(string $type): string
    {
        return self::DEFINITIONS[$type][1] ?? '';
    }

    /**
     * Whether this type still needs the user to do something in Settings
     * (as opposed to `none`/`manual`, which need no connection).
     */
    public static function needsConnection(string $type): bool
    {
        return in_array(
            self::requirementFor($type),
            [self::REQUIREMENT_HANDLE, self::REQUIREMENT_OAUTH],
            true,
        );
    }

    /**
     * Full metadata for every type, for serialising to the client or tests.
     *
     * @return list<array{type: string, label: string, requires_details: bool, integration_requirement: string, integration_requirement_summary: string}>
     */
    public static function all(): array
    {
        return array_map(static function (string $type): array {
            [$requirement, $summary] = self::DEFINITIONS[$type];

            return [
                'type' => $type,
                'label' => MarketingChannelType::from($type)->label(),
                'requires_details' => $type === 'website',
                'integration_requirement' => $requirement,
                'integration_requirement_summary' => $summary,
            ];
        }, self::types());
    }
}
