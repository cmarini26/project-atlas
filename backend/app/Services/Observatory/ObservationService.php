<?php

namespace App\Services\Observatory;

use App\Events\ObservationRecorded;
use App\Models\Integration;
use App\Models\Observation;
use App\Services\Observatory\Connectors\ConnectorResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ObservationService
{
    /**
     * Persist a collection of ConnectorResults as Observations and fire an
     * ObservationRecorded event for each one.
     *
     * @param  Collection<int, ConnectorResult>  $results
     * @return Collection<int, Observation>
     */
    public function recordAll(Integration $integration, Collection $results): Collection
    {
        return $results->map(fn (ConnectorResult $result) => $this->record($integration, $result));
    }

    public function record(Integration $integration, ConnectorResult $result): Observation
    {
        Log::info('ObservationService: recording observation.', [
            'integration_id' => $integration->id,
            'source_type' => $result->sourceType,
            'source_identifier' => $result->sourceIdentifier,
        ]);

        $observation = Observation::create([
            'company_id' => $integration->company_id,
            'integration_id' => $integration->id,
            'source_type' => $result->sourceType,
            'source_identifier' => $result->sourceIdentifier,
            'raw_payload' => $result->payload,
            'status' => 'pending',
            'observed_at' => $result->observedAt,
        ]);

        ObservationRecorded::dispatch($observation);

        Log::info('ObservationService: ObservationRecorded dispatched.', [
            'observation_id' => $observation->id,
        ]);

        return $observation;
    }
}
