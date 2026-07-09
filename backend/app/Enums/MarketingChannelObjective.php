<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * What a declared channel is for. MarketingChannel::objective stores an array
 * of 1+ of these — a real channel usually serves more than one purpose. See
 * specs/core/marketing-presence.md §4.3.
 */
enum MarketingChannelObjective: string
{
    use EnumValues;

    case Awareness = 'awareness';
    case Leads = 'leads';
    case Sales = 'sales';
    case Retention = 'retention';
    case Trust = 'trust';
    case Seo = 'seo';
    case Community = 'community';
}
