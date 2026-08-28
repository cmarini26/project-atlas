<?php

namespace App\AI\Images;

use InvalidArgumentException;

/**
 * The normalised result of a single generated image, identical in shape across
 * every provider. Holds the raw bytes (never a vendor URL that might expire),
 * the dimensions actually produced, and enough provenance — provider, model,
 * reported cost — for cost tracking and later analysis.
 */
readonly class GeneratedImage
{
    public function __construct(
        public string $binary,
        public string $mimeType,
        public int $width,
        public int $height,
        public string $provider,
        public string $model,
        public float $costUsd,
    ) {
        if ($this->binary === '') {
            throw new InvalidArgumentException('GeneratedImage binary must not be empty.');
        }

        if ($this->costUsd < 0) {
            throw new InvalidArgumentException('GeneratedImage costUsd must not be negative.');
        }
    }

    public function extension(): string
    {
        return match ($this->mimeType) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }
}
