<?php

namespace App\AI\Exceptions;

use Throwable;

/**
 * The local model returned a response Atlas cannot use: non-JSON, structurally
 * malformed, truncated, or failing JSON Schema validation. Not retryable at the
 * job level — a re-run of the same prompt is unlikely to help and would only
 * load the local model further.
 */
final class LocalAiInvalidResponseException extends LocalAiException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct(
            message: $message,
            category: 'invalid_response',
            retryable: false,
            guidance: 'Inspect the prompt/schema and the model output. Consider a stronger model or tighter schema; retrying as-is will not help.',
            previous: $previous,
        );
    }
}
