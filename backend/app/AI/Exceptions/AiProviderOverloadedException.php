<?php

namespace App\AI\Exceptions;

use RuntimeException;

/**
 * The AI provider is temporarily unable to serve requests (e.g. Anthropic's
 * overloaded_error / HTTP 529). This is transient and retryable — callers
 * must not treat it as a permanent failure: observations go to 'retrying'
 * instead of 'failed', and integrations are not marked 'error'.
 */
class AiProviderOverloadedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }
}
