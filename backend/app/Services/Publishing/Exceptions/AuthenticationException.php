<?php

namespace App\Services\Publishing\Exceptions;

class AuthenticationException extends PublishingException
{
    public function __construct(string $message = 'Authentication failed for channel.')
    {
        parent::__construct($message, retryable: false);
    }

    public function userMessage(): string
    {
        return 'Your channel connection has expired or been revoked. Reconnect your account to continue publishing.';
    }
}
