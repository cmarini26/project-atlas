<?php

namespace App\Services\Brain\Data;

readonly class FactData
{
    public function __construct(
        public string $key,
        public mixed $value,
        public string $dataType,
        public int $confidence,
    ) {}
}
