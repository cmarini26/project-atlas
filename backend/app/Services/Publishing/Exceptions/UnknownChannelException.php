<?php

namespace App\Services\Publishing\Exceptions;

class UnknownChannelException extends PublishingException
{
    public function __construct(string $channelType)
    {
        parent::__construct("No publisher registered for channel type: {$channelType}", retryable: false);
    }

    public function userMessage(): string
    {
        return 'This channel type is not supported for publishing yet.';
    }
}
