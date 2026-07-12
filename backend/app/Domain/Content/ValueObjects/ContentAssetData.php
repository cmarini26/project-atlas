<?php

namespace App\Domain\Content\ValueObjects;

readonly class ContentAssetData
{
    /**
     * @param  list<array<string, mixed>>|null  $media
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly string $type,
        public readonly string $body,
        public readonly ?string $title = null,
        public readonly ?array $media = null,
        public readonly ?array $metadata = null,
        public readonly ?string $promptName = null,
        public readonly ?string $promptVersion = null,
    ) {}
}
