<?php

namespace App\Domain\Publishing\ValueObjects;

readonly class ExecutionResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $platformId,
        public ?string $url,
        public \DateTimeImmutable $publishedAt,
        public array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'platform_id' => $this->platformId,
            'url' => $this->url,
            'published_at' => $this->publishedAt->format('Y-m-d H:i:s'),
            'metadata' => $this->metadata,
        ];
    }
}
