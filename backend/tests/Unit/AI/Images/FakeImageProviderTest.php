<?php

namespace Tests\Unit\AI\Images;

use App\AI\Images\Contracts\ImageProvider;
use App\AI\Images\Exceptions\ImageGenerationException;
use App\AI\Images\ImageAspectRatio;
use App\AI\Images\ImageGenerationRequest;
use App\AI\Images\Testing\FakeImageProvider;
use PHPUnit\Framework\TestCase;

class FakeImageProviderTest extends TestCase
{
    public function test_it_returns_the_requested_number_of_images_against_the_interface(): void
    {
        $provider = new FakeImageProvider();

        $images = $provider->generate(new ImageGenerationRequest('a warm product photo', count: 2));

        $this->assertInstanceOf(ImageProvider::class, $provider);
        $this->assertCount(2, $images);
        $this->assertSame('image/png', $images[0]->mimeType);
        $this->assertSame('fake', $images[0]->provider);
        $this->assertSame(0.0, $images[0]->costUsd);
    }

    public function test_placeholder_bytes_are_a_valid_png_sized_to_the_aspect_ratio(): void
    {
        $provider = new FakeImageProvider();

        [$image] = $provider->generate(new ImageGenerationRequest('x', ImageAspectRatio::Landscape));

        $this->assertSame("\x89PNG\r\n\x1a\n", substr($image->binary, 0, 8));

        if (function_exists('imagecreatetruecolor')) {
            $info = getimagesizefromstring($image->binary);
            $this->assertSame(1536, $info[0]);
            $this->assertSame(1024, $info[1]);
        }
    }

    public function test_queued_exception_is_thrown_instead_of_generating(): void
    {
        $provider = new FakeImageProvider();
        $provider->queueException(ImageGenerationException::transient('fake', 'boom'));

        $this->expectException(ImageGenerationException::class);

        $provider->generate(new ImageGenerationRequest('x'));
    }
}
