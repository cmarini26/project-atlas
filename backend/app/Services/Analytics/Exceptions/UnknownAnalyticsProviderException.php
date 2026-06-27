<?php

namespace App\Services\Analytics\Exceptions;

use RuntimeException;

class UnknownAnalyticsProviderException extends RuntimeException
{
    public function __construct(string $providerType)
    {
        parent::__construct("No analytics provider registered for type '{$providerType}'.");
    }
}
