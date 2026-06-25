<?php

namespace App\Services\Observatory\Connectors\Contracts;

use App\Models\Integration;
use App\Models\Observation;

interface Connector
{
    public function supports(Integration $integration): bool;

    public function sync(Integration $integration): Observation;
}
