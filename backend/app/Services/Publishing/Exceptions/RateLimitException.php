<?php

namespace App\Services\Publishing\Exceptions;

class RateLimitException extends PublishingException
{
    public function __construct(string $message = 'Platform rate limit exceeded.')
    {
        parent::__construct($message, retryable: true);
    }

    public function userMessage(): string
    {
        return 'The platform is temporarily limiting new posts. Retrying automatically — no action needed.';
    }
}
