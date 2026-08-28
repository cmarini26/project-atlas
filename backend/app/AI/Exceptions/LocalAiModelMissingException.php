<?php

namespace App\AI\Exceptions;

use Throwable;

/**
 * The configured local model is not available on the inference host.
 * Not retryable — pulling the model is an operator action.
 */
final class LocalAiModelMissingException extends LocalAiException
{
    public function __construct(string $model, string $detail, ?Throwable $previous = null)
    {
        parent::__construct(
            message: "Ollama model [{$model}] is not available on the inference host: {$detail}",
            category: 'model_missing',
            retryable: false,
            guidance: "Run `ollama pull {$model}` on the inference host, then verify with `ollama list`.",
            previous: $previous,
        );
    }
}
