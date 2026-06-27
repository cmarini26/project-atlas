<?php

namespace App\Domain\Analytics\ValueObjects;

final readonly class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $providerType,
        public string $platformMessageId,
        public string $eventType,
        public \DateTimeImmutable $occurredAt,
        public array $metadata = [],
    ) {}
}
