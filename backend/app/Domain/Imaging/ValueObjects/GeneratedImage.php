<?php

namespace App\Domain\Imaging\ValueObjects;

/**
 * The raw result of one image-generation call, before Atlas stores it.
 * Providers return binary contents plus enough metadata for the storing
 * layer to name the file and record where it came from.
 */
readonly class GeneratedImage
{
    public function __construct(
        public string $contents,
        public string $mimeType,
        public string $providerType,
        public ?string $model = null,
    ) {}

    public function extension(): string
    {
        return match ($this->mimeType) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }
}
