<?php

namespace App\Services\Observatory\Connectors\Contracts;

use App\Enums\MarketingChannelType;
use App\Models\MarketingChannel;

/**
 * Optional capability a Connector may declare: it can start observing a
 * declared MarketingChannel immediately, using only the identifying details
 * onboarding collects — no credentials, no prior connection. Business
 * Discovery (docs/specs/Business-Discovery-Onboarding.md §4.2) asks the
 * ConnectorRegistry which connectors implement this, rather than knowing
 * about any specific source type itself. Adding a future no-auth-capable
 * connector (e.g. a public Google Business lookup) means implementing this
 * interface once — no change to the Discovery orchestration layer.
 */
interface AutoDiscoverableConnector extends Connector
{
    /** Which declared asset type this connector can auto-discover. */
    public function marketingChannelType(): MarketingChannelType;

    /** The Integration.type value this connector's sync() supports. */
    public function connectorType(): string;

    /**
     * Build the Integration config needed to sync this channel, from only
     * the details already declared on it (never a credential the wizard
     * never collected).
     *
     * @return array<string, mixed>
     */
    public function buildIntegrationConfig(MarketingChannel $channel): array;
}
