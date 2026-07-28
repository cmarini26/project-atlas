<?php

namespace App\Services\Analyst;

use App\Models\Observation;
use App\Services\Analyst\Contracts\ObservationAnalyst;
use App\Services\Brain\Data\FactData;
use Illuminate\Support\Collection;

class SourceAssetAnalyst implements ObservationAnalyst
{
    public function supports(Observation $observation): bool
    {
        return $observation->source_type === 'manual'
            && str_starts_with($observation->source_identifier, 'source_asset:');
    }

    /** @return Collection<int, FactData> */
    public function analyze(Observation $observation): Collection
    {
        $payload = json_decode((string) $observation->raw_payload, true, flags: JSON_THROW_ON_ERROR);
        $prefix = 'source_asset.'.(string) $payload['source_asset_id'];

        return collect([
            new FactData("{$prefix}.title", (string) $payload['title'], 'string', 100),
            new FactData("{$prefix}.type", (string) $payload['type'], 'string', 100),
            new FactData("{$prefix}.details", $payload, 'json', 100),
        ]);
    }
}
