<?php

namespace App\Services\SourceAssets;

use App\Events\ObservationRecorded;
use App\Models\Company;
use App\Models\Observation;
use App\Models\SourceAsset;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SourceAssetService
{
    /** @param array<string, mixed> $data */
    public function create(Company $company, array $data): SourceAsset
    {
        $media = $data['media'] ?? null;
        $mediaFingerprint = $media instanceof UploadedFile ? $this->mediaFingerprint($media) : null;
        $fingerprint = $this->fingerprint($data, $mediaFingerprint);
        $existing = SourceAsset::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->where('content_fingerprint', $fingerprint)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $mediaPath = $media instanceof UploadedFile
            ? $this->storeMedia($media, $company->id)
            : null;

        try {
            [$asset, $observation] = DB::transaction(function () use ($company, $data, $fingerprint, $mediaFingerprint, $media, $mediaPath): array {
                $asset = SourceAsset::withoutGlobalScopes()->create([
                    ...Arr::except($data, ['media']),
                    'company_id' => $company->id,
                    'media_path' => $mediaPath,
                    'media_mime_type' => $media instanceof UploadedFile ? $media->getMimeType() : null,
                    'media_fingerprint' => $mediaFingerprint,
                    'content_fingerprint' => $fingerprint,
                    'status' => 'processing',
                ]);
                $observation = $this->observationFor($asset);
                $asset->update(['observation_id' => $observation->id]);

                return [$asset, $observation];
            });
        } catch (Throwable $exception) {
            $this->deleteMedia($mediaPath);

            if ($exception instanceof QueryException && $this->isUniqueConstraintViolation($exception)) {
                $existing = SourceAsset::withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->whereNull('deleted_at')
                    ->where('content_fingerprint', $fingerprint)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $exception;
        }

        ObservationRecorded::dispatch($observation);

        return $asset->refresh();
    }

    /** @param array<string, mixed> $data */
    public function update(SourceAsset $asset, array $data): SourceAsset
    {
        $oldPath = $asset->media_path;
        $media = $data['media'] ?? null;
        $attributes = Arr::except($data, ['media']);
        $mediaFingerprint = $media instanceof UploadedFile
            ? $this->mediaFingerprint($media)
            : $asset->media_fingerprint;

        $merged = [...$asset->only(['type', 'title', 'description', 'source_url', 'metadata', 'starts_at', 'ends_at']), ...$attributes];
        $fingerprint = $this->fingerprint($merged, $mediaFingerprint);

        $duplicateExists = SourceAsset::withoutGlobalScopes()
            ->where('company_id', $asset->company_id)
            ->whereNull('deleted_at')
            ->where('content_fingerprint', $fingerprint)
            ->whereKeyNot($asset->id)
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'title' => 'An identical asset already exists in your library.',
            ]);
        }

        $newPath = null;
        if ($media instanceof UploadedFile) {
            $newPath = $this->storeMedia($media, $asset->company_id);
            $attributes['media_path'] = $newPath;
            $attributes['media_mime_type'] = $media->getMimeType();
        }

        $attributes['media_fingerprint'] = $mediaFingerprint;
        $attributes['content_fingerprint'] = $fingerprint;
        $attributes['status'] = 'processing';
        $attributes['processing_error'] = null;

        try {
            $observation = DB::transaction(function () use ($asset, $attributes): Observation {
                $asset->update($attributes);
                $observation = $this->observationFor($asset->refresh());
                $asset->update(['observation_id' => $observation->id]);

                return $observation;
            });
        } catch (Throwable $exception) {
            $this->deleteMedia($newPath);
            $asset->refresh();

            throw $exception;
        }

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
        $asset->update([
            'content_fingerprint' => hash('sha256', "archived:{$asset->id}:".now()->getTimestampMs()),
        ]);
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
                'media_mime_type' => $asset->media_mime_type,
                'metadata' => $asset->metadata,
                'starts_at' => $asset->starts_at?->toIso8601String(),
                'ends_at' => $asset->ends_at?->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'observed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function fingerprint(array $data, ?string $mediaFingerprint): string
    {
        $identity = [];

        foreach (['type', 'title', 'description', 'source_url', 'metadata', 'starts_at', 'ends_at'] as $key) {
            $value = $data[$key] ?? null;
            $identity[$key] = in_array($key, ['starts_at', 'ends_at'], true)
                ? $this->canonicalDate($value)
                : $this->canonicalize($value);
        }

        return hash('sha256', json_encode([
            ...$identity,
            'media_fingerprint' => $mediaFingerprint,
        ], JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc()->toIso8601String();
        }

        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        return $value;
    }

    private function canonicalDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc()->toIso8601String();
        }

        return Carbon::parse((string) $value)->utc()->toIso8601String();
    }

    private function mediaFingerprint(UploadedFile $file): string
    {
        $fingerprint = hash_file('sha256', $file->path());

        if ($fingerprint === false) {
            throw new RuntimeException('Could not fingerprint the uploaded source asset.');
        }

        return $fingerprint;
    }

    private function storeMedia(UploadedFile $file, string $companyId): string
    {
        $path = $file->store("source-assets/{$companyId}", 'public');

        if (! is_string($path)) {
            throw new RuntimeException('Could not store the uploaded source asset.');
        }

        return $path;
    }

    private function deleteMedia(?string $path): void
    {
        if ($path !== null) {
            Storage::disk('public')->delete($path);
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true);
    }
}
