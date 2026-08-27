<?php

namespace App\AI\Images\Testing;

use App\AI\Images\Contracts\ImageProvider;
use App\AI\Images\GeneratedImage;
use App\AI\Images\ImageGenerationRequest;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Deterministic in-memory image provider for tests and local development.
 * Produces a solid-colour placeholder PNG sized to the request and never
 * touches the network. Failures can be simulated with queueException().
 */
class FakeImageProvider implements ImageProvider
{
    /** 1x1 transparent PNG — fallback when the GD extension is unavailable. */
    private const PIXEL_PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+P+/HgAFhAJ/wlseKgAAAABJRU5ErkJggg==';

    /** @var list<Throwable> */
    private array $exceptionQueue = [];

    /** @var list<ImageGenerationRequest> */
    private array $recorded = [];

    public float $costPerImageUsd = 0.0;

    public function queueException(Throwable $exception): static
    {
        $this->exceptionQueue[] = $exception;

        return $this;
    }

    public function generate(ImageGenerationRequest $request): array
    {
        $this->recorded[] = $request;

        if ($this->exceptionQueue !== []) {
            throw array_shift($this->exceptionQueue);
        }

        $binary = $this->placeholderPng($request->aspectRatio->width(), $request->aspectRatio->height());

        return array_fill(0, $request->count, new GeneratedImage(
            binary: $binary,
            mimeType: 'image/png',
            width: $request->aspectRatio->width(),
            height: $request->aspectRatio->height(),
            provider: $this->identifier(),
            model: 'fake-image-model',
            costUsd: $this->costPerImageUsd,
        ));
    }

    public function identifier(): string
    {
        return 'fake';
    }

    /** @return list<ImageGenerationRequest> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    public function assertGenerated(): void
    {
        Assert::assertNotEmpty($this->recorded, 'Expected an image to be generated, but none was.');
    }

    public function assertNothingGenerated(): void
    {
        Assert::assertEmpty(
            $this->recorded,
            'Expected no image generation, but '.count($this->recorded).' request(s) were made.',
        );
    }

    private function placeholderPng(int $width, int $height): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return base64_decode(self::PIXEL_PNG_BASE64, true) ?: '';
        }

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 226, 232, 240));

        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return $binary;
    }
}
