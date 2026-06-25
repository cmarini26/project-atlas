<?php

namespace App\AI;

readonly class AiResponse
{
    public function __construct(
        public string $content,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
    ) {}
}
