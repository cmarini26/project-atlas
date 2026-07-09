<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * The domain-level lifecycle stage a declared MarketingChannel has reached —
 * see specs/core/marketing-presence.md §5. Derived by
 * MarketingChannelCapabilityResolver from MarketingChannel's own flags plus
 * its linked Channel (if any); never computed ad hoc elsewhere.
 *
 * This is deliberately the lifecycle vocabulary (Declared/Connected/
 * PublishingEnabled/AnalyticsEnabled), not the four UI-facing capability
 * labels from spec §11 (Connected/Draft only/Coming later/Not configured) —
 * translating this domain result into those UI labels is Phase 7
 * (Recommendation/Campaign UI), not this resolver's job.
 */
enum MarketingChannelCapability: string
{
    use EnumValues;

    case Declared = 'declared';
    case Connected = 'connected';
    case PublishingEnabled = 'publishing_enabled';
    case AnalyticsEnabled = 'analytics_enabled';
}
