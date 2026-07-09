<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Strategic weight the business assigns to a declared channel. Business
 * declared, not Atlas computed. See specs/core/marketing-presence.md §4.2.
 */
enum MarketingChannelImportance: string
{
    use EnumValues;

    case Primary = 'primary';
    case Secondary = 'secondary';
    case Experimental = 'experimental';
}
