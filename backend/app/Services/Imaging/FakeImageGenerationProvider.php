<?php

namespace App\Services\Imaging;

use App\Domain\Imaging\ValueObjects\GeneratedImage;
use App\Services\Imaging\Contracts\ImageGenerationProvider;
use App\Services\Imaging\Exceptions\ImageGenerationException;

/**
 * Deterministic no-network provider used in tests and local development, and
 * the default until a real hosted image API is selected for SCRUM-71. Returns
 * a valid 1x1 PNG so the storage + preview path can be exercised end to end
 * without a vendor account.
 */
class FakeImageGenerationProvider implements ImageGenerationProvider
{
    /** Base64 of a 1x1 transparent PNG. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+P+/HgAFhAJ/wlseKgAAAABJRU5ErkJggg==';

    public function generate(string $prompt, string $size = '1024x1024'): GeneratedImage
    {
        if (trim($prompt) === '') {
            throw new ImageGenerationException('Image prompt was empty.');
        }

        return new GeneratedImage(
            contents: (string) base64_decode(self::PNG_BASE64, true),
            mimeType: 'image/png',
            providerType: 'fake',
            model: 'fake-1x1',
        );
    }

    public function supports(string $providerType): bool
    {
        return $providerType === 'fake';
    }
}
