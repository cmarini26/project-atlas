<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * How often a business posts to a declared channel. A constrained vocabulary
 * rather than free text so the Opportunity Engine and prompts can reason
 * about cadence structurally. `Unknown` is the default when not specified —
 * never actually null. See specs/core/marketing-presence.md §4.4.
 */
enum PostingFrequency: string
{
    use EnumValues;

    case Daily = 'daily';
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Rarely = 'rarely';
    case Unknown = 'unknown';
}
