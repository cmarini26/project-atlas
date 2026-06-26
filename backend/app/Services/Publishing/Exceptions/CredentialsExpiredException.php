<?php

namespace App\Services\Publishing\Exceptions;

class CredentialsExpiredException extends PublishingException
{
    public function __construct(string $channelType)
    {
        parent::__construct("Credentials for channel '{$channelType}' have expired.", retryable: false);
    }

    public function userMessage(): string
    {
        return 'Your channel credentials have expired. Reconnect your account to continue publishing.';
    }
}
