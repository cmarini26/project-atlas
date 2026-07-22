<?php

namespace App\Domain\Publishing\ValueObjects;

readonly class SmsTestSendResult
{
    public function __construct(
        public bool $success,
        public string $message,
    ) {}
}
