<?php

namespace App\Services\Publishing\Exceptions;

class CredentialsNotFoundException extends PublishingException
{
    public function __construct(string $channelType)
    {
        parent::__construct("No credentials found for channel type: {$channelType}", retryable: false);
    }

    public function userMessage(): string
    {
        return 'No credentials are configured for this channel. Connect your account before publishing.';
    }
}
