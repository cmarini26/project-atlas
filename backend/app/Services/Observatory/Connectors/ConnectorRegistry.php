<?php

namespace App\Services\Observatory\Connectors;

use App\Enums\MarketingChannelType;
use App\Models\Integration;
use App\Services\Observatory\Connectors\Contracts\AutoDiscoverableConnector;
use App\Services\Observatory\Connectors\Contracts\Connector;
use App\Services\Observatory\Connectors\Exceptions\UnsupportedIntegrationException;

class ConnectorRegistry
{
    /** @param Connector[] $connectors */
    public function __construct(private readonly array $connectors) {}

    public function resolve(Integration $integration): Connector
    {
        foreach ($this->connectors as $connector) {
            if ($connector->supports($integration)) {
                return $connector;
            }
        }

        throw new UnsupportedIntegrationException($integration->type);
    }

    /**
     * Answers "what can currently be observed?" for a declared asset type,
     * with zero knowledge of the type itself living in the caller — see
     * App\Services\Observatory\Connectors\Contracts\AutoDiscoverableConnector.
     */
    public function autoDiscoverableFor(MarketingChannelType $type): ?AutoDiscoverableConnector
    {
        foreach ($this->connectors as $connector) {
            if ($connector instanceof AutoDiscoverableConnector && $connector->marketingChannelType() === $type) {
                return $connector;
            }
        }

        return null;
    }

    /** @return Connector[] */
    public function all(): array
    {
        return $this->connectors;
    }
}
