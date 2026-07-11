<?php

namespace App\Services\Analyst\Contracts;

use App\Models\Observation;
use App\Services\Brain\Data\FactData;
use Illuminate\Support\Collection;

/**
 * Dispatch contract for turning an Observation into Facts — mirrors
 * App\Services\Observatory\Connectors\Contracts\Connector's supports()/
 * resolve() pattern so a new observation source can add its own Analyst
 * without any other Analyst or ProcessObservation itself needing to change.
 *
 * Unlike the AI-specific Analyst marker interface, implementing this
 * contract does not imply the analyst calls an AiProvider — WebsiteAnalyst
 * does; InstagramAnalyst deliberately does not (see its class docblock).
 */
interface ObservationAnalyst
{
    public function supports(Observation $observation): bool;

    /** @return Collection<int, FactData> */
    public function analyze(Observation $observation): Collection;
}
