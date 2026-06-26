<?php

namespace App\Services\Observatory\Connectors;

use App\Models\Integration;
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

    /** @return Connector[] */
    public function all(): array
    {
        return $this->connectors;
    }
}
