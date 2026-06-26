<?php

namespace App\Services\Publishing\Exceptions;

class PlatformUnavailableException extends PublishingException
{
    public function __construct(string $message = 'Platform is temporarily unavailable.')
    {
        parent::__construct($message, retryable: true);
    }

    public function userMessage(): string
    {
        return 'The platform is temporarily unavailable. Retrying automatically.';
    }
}
