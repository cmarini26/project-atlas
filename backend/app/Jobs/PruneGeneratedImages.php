<?php

namespace App\Jobs;

use App\Models\ContentAsset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes stored AI-generated draft images (SCRUM-71) that no live content
 * asset references any more — rejected drafts, assets superseded by
 * regeneration or channel re-selection, cancelled campaigns. Status-agnostic
 * on purpose: a file is kept only while some non-archived, non-deleted
 * ContentAsset still points at it, and only pruned once it is older than the
 * configured grace period so a freshly created asset is never raced.
 */
class PruneGeneratedImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const PREFIX = 'generated-content';

    public int $tries = 3;

    public int $backoff = 300;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        $disk = Storage::disk((string) config('imaging.disk', 'public'));

        if (! $disk->exists(self::PREFIX)) {
            return;
        }

        $cutoff = now()->subDays((int) config('imaging.retention_days', 30))->getTimestamp();
        $referenced = $this->referencedPaths();

        $deleted = 0;

        foreach ($disk->allFiles(self::PREFIX) as $path) {
            if (isset($referenced[$path])) {
                continue;
            }

            if ($disk->lastModified($path) >= $cutoff) {
                continue;
            }

            $disk->delete($path);
            $deleted++;
        }

        Log::info("PruneGeneratedImages: deleted {$deleted} orphaned generated image(s).");
    }

    /**
     * Storage paths still pointed at by a live (non-archived, non-deleted)
     * content asset, as a set keyed by path for O(1) lookup.
     *
     * @return array<string, true>
     */
    private function referencedPaths(): array
    {
        $paths = [];

        ContentAsset::withoutGlobalScopes()
            ->where('status', '!=', 'archived')
            ->whereNotNull('media')
            ->select(['id', 'media'])
            ->chunkById(500, function ($assets) use (&$paths): void {
                foreach ($assets as $asset) {
                    foreach ((array) ($asset->media ?? []) as $item) {
                        $url = is_array($item) ? (string) ($item['url'] ?? '') : '';
                        $pos = strpos($url, self::PREFIX.'/');

                        if ($pos !== false) {
                            $paths[substr($url, $pos)] = true;
                        }
                    }
                }
            });

        return $paths;
    }

    public function failed(Throwable $exception): void
    {
        Log::error('PruneGeneratedImages: job failed after exhausting retries.', [
            'error' => $exception->getMessage(),
        ]);
    }
}
