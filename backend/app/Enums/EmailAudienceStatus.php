<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

enum EmailAudienceStatus: string
{
    use EnumValues;

    case Active = 'active';
    case Archived = 'archived';
}
