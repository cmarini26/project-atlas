<?php

namespace App\Services\Analyst\Exceptions;

use RuntimeException;

class UnsupportedObservationException extends RuntimeException
{
    public function __construct(string $sourceType)
    {
        parent::__construct("No analyst registered for observation source type: [{$sourceType}]");
    }
}
