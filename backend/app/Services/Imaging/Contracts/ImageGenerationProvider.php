<?php

namespace App\Services\Imaging\Contracts;

use App\Domain\Imaging\ValueObjects\GeneratedImage;
use App\Services\Imaging\Exceptions\ImageGenerationException;

interface ImageGenerationProvider
{
    /**
     * Generate a single still image from a text prompt.
     *
     * @param  string  $size  requested pixel dimensions, e.g. "1024x1024"
     *
     * @throws ImageGenerationException
     */
    public function generate(string $prompt, string $size = '1024x1024'): GeneratedImage;

    public function supports(string $providerType): bool;
}
