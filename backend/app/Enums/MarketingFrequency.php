<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * How often the business currently markets itself — collected in
 * onboarding's Marketing Preferences step (Milestone 15 Phase 1).
 */
enum MarketingFrequency: string
{
    use EnumValues;

    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case PromotionsOnly = 'promotions_only';
    case Rarely = 'rarely';
}
