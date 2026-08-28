<?php

namespace App\AI\Images;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Persists generated imagery through the same public disk and company-scoped
 * path convention the Asset Library uses (`source-assets/{companyId}/…`), so
 * generated and uploaded media are served and cleaned up identically.
 */
class ImageStorage
{
    private const DISK = 'public';

    private const DIRECTORY = 'campaign-images';

    public function store(string $companyId, GeneratedImage $image): StoredImage
    {
        $path = sprintf('%s/%s/%s.%s', self::DIRECTORY, $companyId, (string) Str::ulid(), $image->extension());

        if (! Storage::disk(self::DISK)->put($path, $image->binary)) {
            throw new RuntimeException('Could not store the generated campaign image.');
        }

        return new StoredImage(
            path: $path,
            url: asset('storage/'.$path),
            mimeType: $image->mimeType,
            width: $image->width,
            height: $image->height,
            provider: $image->provider,
            model: $image->model,
            costUsd: $image->costUsd,
        );
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
