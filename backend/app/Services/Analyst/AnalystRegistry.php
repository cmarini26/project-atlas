<?php

namespace App\Services\Analyst;

use App\Models\Observation;
use App\Services\Analyst\Contracts\ObservationAnalyst;
use App\Services\Analyst\Exceptions\UnsupportedObservationException;

/**
 * Resolves the right Analyst for an Observation by source_type — mirrors
 * App\Services\Observatory\Connectors\ConnectorRegistry exactly, so adding a
 * new observation source (this milestone: Instagram) never requires
 * touching ProcessObservation or any other Analyst.
 */
class AnalystRegistry
{
    /** @param ObservationAnalyst[] $analysts */
    public function __construct(private readonly array $analysts) {}

    public function resolve(Observation $observation): ObservationAnalyst
    {
        foreach ($this->analysts as $analyst) {
            if ($analyst->supports($observation)) {
                return $analyst;
            }
        }

        throw new UnsupportedObservationException($observation->source_type);
    }

    /** @return ObservationAnalyst[] */
    public function all(): array
    {
        return $this->analysts;
    }
}
