<?php

namespace App\Domain\Publishing\ValueObjects;

readonly class EmailTestSendResult
{
    public function __construct(
        public bool $success,
        public string $message,
    ) {}
}
