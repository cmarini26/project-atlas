<?php

namespace App\AI\Images\Contracts;

use App\AI\Images\Exceptions\ImageGenerationException;
use App\AI\Images\GeneratedImage;
use App\AI\Images\ImageGenerationRequest;

/**
 * Swappable image generation capability. Per-image list prices move fast and
 * vary by an order of magnitude across vendors, so product code depends only
 * on this interface — the concrete provider is chosen in config and can be
 * replaced without touching call sites.
 */
interface ImageProvider
{
    /**
     * Generate one or more images for the request.
     *
     * @return list<GeneratedImage> exactly $request->count images, in order
     *
     * @throws ImageGenerationException on any failure; vendor-specific errors
     *                                  never propagate to callers
     */
    public function generate(ImageGenerationRequest $request): array;

    /** Stable short identifier for this provider, e.g. "openai" or "fake". */
    public function identifier(): string;
}
