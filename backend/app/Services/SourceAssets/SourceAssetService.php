<?php

namespace App\Services\SourceAssets;

use App\Events\ObservationRecorded;
use App\Models\Company;
use App\Models\Observation;
use App\Models\SourceAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SourceAssetService
{
    /** @param array<string, mixed> $data */
    public function create(Company $company, array $data): SourceAsset
    {
        $fingerprint = $this->fingerprint($data);
        $existing = SourceAsset::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('content_fingerprint', $fingerprint)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        [$asset, $observation] = DB::transaction(function () use ($company, $data, $fingerprint): array {
            $mediaPath = isset($data['media']) && $data['media'] instanceof UploadedFile
                ? $data['media']->store("source-assets/{$company->id}", 'public')
                : null;

            $asset = SourceAsset::withoutGlobalScopes()->create([
                ...Arr::except($data, ['media']),
                'company_id' => $company->id,
                'media_path' => $mediaPath,
                'content_fingerprint' => $fingerprint,
                'status' => 'processing',
            ]);
            $observation = $this->observationFor($asset);
            $asset->update(['observation_id' => $observation->id]);

            return [$asset, $observation];
        });

        ObservationRecorded::dispatch($observation);

        return $asset->refresh();
    }

    /** @param array<string, mixed> $data */
    public function update(SourceAsset $asset, array $data): SourceAsset
    {
        $oldPath = $asset->media_path;
        $media = $data['media'] ?? null;
        $attributes = Arr::except($data, ['media']);

        if ($media instanceof UploadedFile) {
            $attributes['media_path'] = $media->store("source-assets/{$asset->company_id}", 'public');
        }

        $merged = [...$asset->only(['type', 'title', 'description', 'source_url', 'metadata', 'starts_at', 'ends_at']), ...$attributes];
        $attributes['content_fingerprint'] = $this->fingerprint($merged);
        $attributes['status'] = 'processing';
        $attributes['processing_error'] = null;

        $observation = DB::transaction(function () use ($asset, $attributes): Observation {
            $asset->update($attributes);
            $observation = $this->observationFor($asset->refresh());
            $asset->update(['observation_id' => $observation->id]);

            return $observation;
        });

        if ($media instanceof UploadedFile && $oldPath !== null) {
            Storage::disk('public')->delete($oldPath);
        }

        ObservationRecorded::dispatch($observation);

        return $asset->refresh();
    }

    public function retry(SourceAsset $asset): SourceAsset
    {
        $asset->update(['status' => 'processing', 'processing_error' => null]);
        $observation = $this->observationFor($asset);
        $asset->update(['observation_id' => $observation->id]);
        ObservationRecorded::dispatch($observation);

        return $asset->refresh();
    }

    public function archive(SourceAsset $asset): void
    {
        $asset->delete();
    }

    private function observationFor(SourceAsset $asset): Observation
    {
        return Observation::withoutGlobalScopes()->create([
            'company_id' => $asset->company_id,
            'source_type' => 'manual',
            'source_identifier' => "source_asset:{$asset->id}",
            'raw_payload' => json_encode([
                'source_asset_id' => $asset->id,
                'type' => $asset->type,
                'title' => $asset->title,
                'description' => $asset->description,
                'source_url' => $asset->source_url,
                'media_path' => $asset->media_path,
                'metadata' => $asset->metadata,
                'starts_at' => $asset->starts_at?->toIso8601String(),
                'ends_at' => $asset->ends_at?->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'observed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function fingerprint(array $data): string
    {
        return hash('sha256', json_encode(Arr::only($data, [
            'type', 'title', 'description', 'source_url', 'metadata', 'starts_at', 'ends_at',
        ]), JSON_THROW_ON_ERROR));
    }
}
