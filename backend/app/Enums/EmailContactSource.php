<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * How a contact entered Atlas. Only 'manual' is reachable through any UI in
 * this slice — 'import'/'api' exist so a later bulk-import or public-API
 * intake slice can record real provenance without a schema change.
 */
enum EmailContactSource: string
{
    use EnumValues;

    case Manual = 'manual';
    case Import = 'import';
    case Api = 'api';
}
