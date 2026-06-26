<?php

namespace App\Services\Observatory\Connectors\Exceptions;

use RuntimeException;

class UnsupportedIntegrationException extends RuntimeException
{
    public function __construct(string $type)
    {
        parent::__construct("No connector registered for integration type: [{$type}]");
    }
}
