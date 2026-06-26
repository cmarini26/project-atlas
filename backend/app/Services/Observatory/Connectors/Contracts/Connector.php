<?php

namespace App\Services\Observatory\Connectors\Contracts;

use App\Models\Integration;
use App\Services\Observatory\Connectors\ConnectorResult;
use Illuminate\Support\Collection;

interface Connector
{
    public function supports(Integration $integration): bool;

    /**
     * Sync the integration and return one ConnectorResult per source record
     * (page, feed item, API record). The ObservationService persists each
     * result as its own Observation.
     *
     * @return Collection<int, ConnectorResult>
     */
    public function sync(Integration $integration): Collection;
}
