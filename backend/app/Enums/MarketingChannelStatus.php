<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * The business's actual current relationship to a declared channel — distinct
 * from Channel::is_active's blunt boolean. See
 * specs/core/marketing-presence.md §4.1.
 */
enum MarketingChannelStatus: string
{
    use EnumValues;

    case Active = 'active';
    case Occasional = 'occasional';
    case Planned = 'planned';
    case Inactive = 'inactive';
}
