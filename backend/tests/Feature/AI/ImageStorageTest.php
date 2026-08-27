<?php

namespace Tests\Feature\AI;

use App\AI\Images\GeneratedImage;
use App\AI\Images\ImageStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageStorageTest extends TestCase
{
    public function test_it_stores_a_generated_image_under_a_company_scoped_public_path(): void
    {
        Storage::fake('public');

        $stored = (new ImageStorage)->store('company-123', new GeneratedImage(
            binary: 'fake-bytes',
            mimeType: 'image/png',
            width: 1024,
            height: 1024,
            provider: 'openai',
            model: 'gpt-image-1',
            costUsd: 0.011,
        ));

        $this->assertStringStartsWith('campaign-images/company-123/', $stored->path);
        $this->assertStringEndsWith('.png', $stored->path);
        Storage::disk('public')->assertExists($stored->path);
        $this->assertSame('fake-bytes', Storage::disk('public')->get($stored->path));
        $this->assertStringContainsString('storage/'.$stored->path, $stored->url);
        $this->assertSame(0.011, $stored->costUsd);
        $this->assertSame('openai', $stored->provider);
    }

    public function test_delete_removes_the_stored_file_and_tolerates_null(): void
    {
        Storage::fake('public');
        $storage = new ImageStorage;

        $stored = $storage->store('c1', new GeneratedImage('b', 'image/png', 1, 1, 'fake', 'm', 0.0));
        Storage::disk('public')->assertExists($stored->path);

        $storage->delete($stored->path);
        $storage->delete(null);

        Storage::disk('public')->assertMissing($stored->path);
    }
}
