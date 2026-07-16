<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * A soft, reversible disable — mirrors MarketingChannelStatus's convention
 * of a status flip instead of a hard delete or Eloquent SoftDeletes. An
 * archived contact's row is never removed; its email stays reserved by the
 * (company_id, normalized_email) unique constraint so "recreating" it
 * reactivates the same row.
 */
enum EmailContactStatus: string
{
    use EnumValues;

    case Active = 'active';
    case Archived = 'archived';
}
