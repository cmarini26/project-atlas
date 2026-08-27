<?php

namespace App\AI\Images;

use InvalidArgumentException;

/**
 * A provider-agnostic request for generated imagery. Callers describe *what*
 * they want (a grounded prompt, a shape, a count); the resolved ImageProvider
 * decides *how* to produce it.
 */
readonly class ImageGenerationRequest
{
    public function __construct(
        public string $prompt,
        public ImageAspectRatio $aspectRatio = ImageAspectRatio::Square,
        public int $count = 1,
    ) {
        $trimmed = trim($this->prompt);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Image generation prompt must not be empty.');
        }

        if (mb_strlen($trimmed) > 4000) {
            throw new InvalidArgumentException('Image generation prompt must be 4000 characters or fewer.');
        }

        if ($this->count < 1 || $this->count > 4) {
            throw new InvalidArgumentException('Image generation count must be between 1 and 4.');
        }
    }
}
