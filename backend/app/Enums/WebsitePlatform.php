<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * What the business's website is built on — collected in onboarding's
 * Asset Details step (Milestone 15 Phase 1) when Website is a declared
 * asset. Stored in MarketingChannel.metadata, not a dedicated column.
 */
enum WebsitePlatform: string
{
    use EnumValues;

    case WordPress = 'wordpress';
    case Squarespace = 'squarespace';
    case Shopify = 'shopify';
    case Wix = 'wix';
    case Webflow = 'webflow';
    case Custom = 'custom';
    case Other = 'other';
    case Unknown = 'unknown';
}
