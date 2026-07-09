<?php

namespace App\Enums\Concerns;

trait EnumValues
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
