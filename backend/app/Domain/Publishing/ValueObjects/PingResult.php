<?php

namespace App\Domain\Publishing\ValueObjects;

readonly class PingResult
{
    public function __construct(
        public bool $reachable,
        public ?string $error = null,
    ) {}
}
