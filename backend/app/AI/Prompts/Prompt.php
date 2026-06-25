<?php

namespace App\AI\Prompts;

abstract class Prompt
{
    abstract public function system(): string;

    abstract public function user(): string;

    /** @return array<string, mixed>|null */
    public function schema(): ?array
    {
        return null;
    }

    public function temperature(): float
    {
        return 0.2;
    }

    public function maxTokens(): int
    {
        return 2048;
    }

    public function version(): string
    {
        return '1.0';
    }

    public function name(): string
    {
        return class_basename(static::class);
    }
}
