<?php

namespace App\Services\Publishing\Exceptions;

use RuntimeException;

class PublishingException extends RuntimeException
{
    public function __construct(
        string $message = '',
        private readonly bool $retryable = true,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function userMessage(): string
    {
        return 'Publishing failed. Please try again or contact support.';
    }
}
