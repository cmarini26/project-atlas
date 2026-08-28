<?php

namespace App\AI\Exceptions;

use Throwable;

/**
 * The inference host could not fit the model (plus its context) in memory.
 * Not retryable — the same request will fail again until resources change.
 */
final class LocalAiOutOfMemoryException extends LocalAiException
{
    public function __construct(string $detail, ?Throwable $previous = null)
    {
        parent::__construct(
            message: "Ollama ran out of memory serving the request: {$detail}",
            category: 'out_of_memory',
            retryable: false,
            guidance: 'Free memory on the inference host (`ollama ps` then `ollama stop <model>`), lower the context length, or use a smaller / more-quantized model.',
            previous: $previous,
        );
    }
}
