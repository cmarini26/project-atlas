<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Minimal send-eligibility metadata for a contact. 'unknown' is the default
 * for a manually-added contact — Atlas has no independent way to confirm
 * consent for a business-supplied address in this slice. Suppression
 * enforcement based on this field is explicitly out of scope here; this
 * only records the status for a later slice to act on.
 */
enum EmailConsentStatus: string
{
    use EnumValues;

    case Unknown = 'unknown';
    case Confirmed = 'confirmed';
    case Declined = 'declined';
}
