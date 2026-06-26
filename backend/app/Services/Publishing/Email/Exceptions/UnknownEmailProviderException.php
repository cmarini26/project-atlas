<?php

namespace App\Services\Publishing\Email\Exceptions;

use App\Services\Publishing\Exceptions\PublishingException;

class UnknownEmailProviderException extends PublishingException
{
    public function __construct(string $providerType)
    {
        parent::__construct(
            "No email provider registered for type '{$providerType}'.",
            retryable: false,
        );
    }

    public function userMessage(): string
    {
        return 'The configured email provider is not supported. Contact support.';
    }
}
