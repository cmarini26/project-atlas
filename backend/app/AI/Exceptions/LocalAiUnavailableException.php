<?php

namespace App\AI\Exceptions;

use Throwable;

/**
 * The local inference service is unreachable, timed out, or returned a
 * transient server error. Retryable — bounded retries with backoff at the
 * provider, then "retrying" (not "failed") at the job level.
 */
final class LocalAiUnavailableException extends LocalAiException implements RetryableAiException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct(
            message: $message,
            category: 'unavailable',
            retryable: true,
            guidance: 'Confirm the Ollama service is running and bound to its configured loopback address. Run `php artisan ai:local:health` for details.',
            previous: $previous,
        );
    }
}
