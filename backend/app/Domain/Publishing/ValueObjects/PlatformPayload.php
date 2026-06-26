<?php

namespace App\Domain\Publishing\ValueObjects;

readonly class PlatformPayload
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $channelType,
        public array $data,
    ) {}
}
