<?php

namespace App\Services\Brain;

use App\Events\FactExtracted;
use App\Models\Fact;
use App\Models\Observation;
use App\Services\Brain\Data\FactData;
use Illuminate\Support\Collection;

class FactService
{
    public function __construct(private readonly FactRepository $repository) {}

    /**
     * Persist extracted facts for an Observation.
     * When a fact with the same key already exists for the company, it is
     * superseded (is_current = false) rather than deleted.
     *
     * @param  Collection<int, FactData>  $factData
     * @return Collection<int, Fact>
     */
    public function storeExtracted(Observation $observation, Collection $factData): Collection
    {
        return $factData->map(fn (FactData $data): Fact => $this->storeFact($observation, $data));
    }

    private function storeFact(Observation $observation, FactData $data): Fact
    {
        $existing = $this->repository->findCurrent($observation->company_id, $data->key);

        $fact = Fact::create([
            'company_id' => $observation->company_id,
            'observation_id' => $observation->id,
            'key' => $data->key,
            'value' => $data->value,
            'data_type' => $data->dataType,
            'confidence' => $data->confidence,
            'is_current' => true,
            'valid_from' => now(),
        ]);

        if ($existing) {
            $existing->update([
                'is_current' => false,
                'superseded_by_id' => $fact->id,
                'valid_until' => now(),
            ]);
        }

        FactExtracted::dispatch($fact);

        return $fact;
    }
}
