<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Every kind of marketing channel a business can declare — broader than
 * App\Models\Channel's publishing-oriented type enum, and including types
 * with no publishing equivalent at all (Events, Print). See
 * specs/core/marketing-presence.md §3.
 */
enum MarketingChannelType: string
{
    use EnumValues;

    case Website = 'website';
    case Email = 'email';
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case LinkedIn = 'linkedin';
    case X = 'x';
    case YouTube = 'youtube';
    case TikTok = 'tiktok';
    case GoogleBusinessProfile = 'google_business_profile';
    case Events = 'events';
    case Print = 'print';
    case Other = 'other';

    /**
     * Whether this type has a corresponding App\Models\Channel type today —
     * i.e., whether channel_id could ever be populated for a MarketingChannel
     * of this type. A stable fact about the type itself, independent of any
     * specific company's data. See specs/core/marketing-presence.md §3, §6.
     */
    public function hasChannelEquivalent(): bool
    {
        return match ($this) {
            self::Email, self::Instagram, self::Facebook, self::LinkedIn, self::X => true,
            default => false,
        };
    }

    /**
     * Human-readable display label — the single source of truth both the
     * onboarding wizard and the Settings Marketing Presence page use, so
     * neither maintains its own copy of this list.
     */
    public function label(): string
    {
        return match ($this) {
            self::Website => 'Website',
            self::Email => 'Email Newsletter',
            self::Instagram => 'Instagram',
            self::Facebook => 'Facebook',
            self::LinkedIn => 'LinkedIn',
            self::X => 'X',
            self::YouTube => 'YouTube',
            self::TikTok => 'TikTok',
            self::GoogleBusinessProfile => 'Google Business Profile',
            self::Events => 'Events',
            self::Print => 'Print',
            self::Other => 'Other',
        };
    }
}
