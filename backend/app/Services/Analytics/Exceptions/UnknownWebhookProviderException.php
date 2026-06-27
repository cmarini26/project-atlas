<?php

namespace App\Services\Analytics\Exceptions;

use RuntimeException;

class UnknownWebhookProviderException extends RuntimeException
{
    public function __construct(string $providerType)
    {
        parent::__construct("No webhook handler registered for provider '{$providerType}'.");
    }
}
