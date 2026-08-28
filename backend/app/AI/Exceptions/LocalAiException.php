<?php

namespace App\AI\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for failures from the local (Ollama) inference path. Every
 * instance carries a machine-readable {@see self::$category} and an
 * operator-actionable {@see self::$guidance} string so failures are loud and
 * diagnosable in logs and health output.
 *
 * Categories:
 *   - unavailable        transient: service down/unreachable/timed out (retryable)
 *   - model_missing      the configured model is not pulled on the host
 *   - out_of_memory      the host cannot fit the model in memory
 *   - invalid_response   malformed, truncated, or schema-invalid output
 */
abstract class LocalAiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $category,
        public readonly bool $retryable,
        public readonly string $guidance,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
