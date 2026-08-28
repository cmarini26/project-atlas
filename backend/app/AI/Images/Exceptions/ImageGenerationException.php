<?php

namespace App\AI\Images\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Provider-agnostic image generation failure. Every concrete ImageProvider
 * wraps its vendor errors in this type so callers can degrade gracefully
 * without ever seeing a vendor-specific payload.
 */
class ImageGenerationException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $provider,
        public readonly bool $retryable,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function configuration(string $provider, string $message): self
    {
        return new self("Image provider [{$provider}] is misconfigured: {$message}", $provider, retryable: false);
    }

    public static function transient(string $provider, string $message, ?Throwable $previous = null): self
    {
        return new self(
            "Image provider [{$provider}] is temporarily unavailable: {$message}",
            $provider,
            retryable: true,
            previous: $previous,
        );
    }

    public static function failed(string $provider, string $message, ?Throwable $previous = null): self
    {
        return new self(
            "Image provider [{$provider}] could not generate the image: {$message}",
            $provider,
            retryable: false,
            previous: $previous,
        );
    }
}
