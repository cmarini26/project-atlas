<?php

namespace App\Services\Discovery;

use App\Models\Integration;

/**
 * The result of asking "can this declared asset be observed right now?" —
 * either reuse an Integration that's already connected (e.g. Instagram,
 * connected for real from Settings), or create a new one via a connector
 * that can auto-discover with zero credentials (e.g. Website). Never both,
 * never neither — a null DiscoveryAssetPlan (not this class) represents
 * "not observable right now."
 */
final class DiscoveryAssetPlan
{
    /** @param array<string, mixed> $config */
    private function __construct(
        public readonly ?Integration $existingIntegration,
        public readonly ?string $connectorType,
        public readonly array $config,
    ) {}

    public static function reuse(Integration $integration): self
    {
        return new self($integration, null, []);
    }

    /** @param array<string, mixed> $config */
    public static function create(string $connectorType, array $config): self
    {
        return new self(null, $connectorType, $config);
    }

    public function isReuse(): bool
    {
        return $this->existingIntegration !== null;
    }
}
