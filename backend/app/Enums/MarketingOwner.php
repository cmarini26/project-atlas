<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Who currently handles marketing for the business — collected in
 * onboarding's Marketing Preferences step (Milestone 15 Phase 1).
 */
enum MarketingOwner: string
{
    use EnumValues;

    case Me = 'me';
    case Team = 'team';
    case Agency = 'agency';
    case Freelancer = 'freelancer';
    case Nobody = 'nobody';
}
