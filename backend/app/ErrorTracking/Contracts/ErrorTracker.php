<?php

namespace App\ErrorTracking\Contracts;

use Throwable;

interface ErrorTracker
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function report(Throwable $exception, array $context = []): void;
}
