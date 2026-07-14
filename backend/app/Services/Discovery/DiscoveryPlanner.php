<?php

namespace App\Services\Discovery;

use App\Models\Integration;
use App\Models\MarketingChannel;
use App\Services\Observatory\Connectors\ConnectorRegistry;

/**
 * Decides how (if at all) a single declared MarketingChannel can be
 * observed right now. Knows nothing about Website, Instagram, or any other
 * specific asset type — it only asks two source-agnostic questions:
 * "is this already connected to a real Integration?" and, if not, "does any
 * registered connector declare it can auto-discover this asset type?" See
 * docs/specs/Business-Discovery-Onboarding.md §4.1–§4.2.
 */
class DiscoveryPlanner
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

    public function planFor(MarketingChannel $channel): ?DiscoveryAssetPlan
    {
        if ($channel->is_connected && $channel->integration_id !== null) {
            $integration = Integration::withoutGlobalScopes()->find($channel->integration_id);

            if ($integration !== null) {
                return DiscoveryAssetPlan::reuse($integration);
            }
        }

        $connector = $this->registry->autoDiscoverableFor($channel->type);

        if ($connector === null) {
            return null;
        }

        return DiscoveryAssetPlan::create(
            $connector->connectorType(),
            $connector->buildIntegrationConfig($channel),
        );
    }
}
