<?php

namespace App\AI\Images;

/**
 * A GeneratedImage after it has been persisted to the application storage
 * layer. `path` is the disk-relative path; `url` is the public URL callers
 * should hand to the UI.
 */
readonly class StoredImage
{
    public function __construct(
        public string $path,
        public string $url,
        public string $mimeType,
        public int $width,
        public int $height,
        public string $provider,
        public string $model,
        public float $costUsd,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'url' => $this->url,
            'mime_type' => $this->mimeType,
            'width' => $this->width,
            'height' => $this->height,
            'provider' => $this->provider,
            'model' => $this->model,
            'cost_usd' => $this->costUsd,
        ];
    }
}
