<?php

namespace App\Jobs;

use App\AI\Images\CampaignImagePrompt;
use App\AI\Images\Contracts\ImageProvider;
use App\AI\Images\Exceptions\ImageGenerationException;
use App\AI\Images\ImageGenerationCap;
use App\AI\Images\ImageGenerationRequest;
use App\AI\Images\ImageStorage;
use App\Models\CampaignBrief;
use App\Models\CampaignImageGeneration;
use App\Services\Brain\BusinessBrainService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates the campaign image for a brief off the request path. Composition
 * never blocks on this: a failure here leaves the campaign fully usable with
 * copy, and the pending ledger row is marked failed so the review UI can say
 * so without gating approval.
 */
class GenerateCampaignImagery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly string $generationId)
    {
        $this->onQueue('ai');
    }

    public function handle(
        ImageProvider $images,
        ImageStorage $storage,
        ImageGenerationCap $cap,
        BusinessBrainService $brains,
    ): void {
        $generation = CampaignImageGeneration::withoutGlobalScopes()->find($this->generationId);

        if ($generation === null || ! $generation->isPending()) {
            return;
        }

        $brief = CampaignBrief::withoutGlobalScopes()
            ->with('company')
            ->find($generation->campaign_brief_id);

        if ($brief === null || $brief->company === null) {
            $this->markFailed($generation, 'The campaign is no longer available.');

            return;
        }

        if ($cap->wouldExceed($generation->company_id, excludeId: $generation->id)) {
            Log::warning('GenerateCampaignImagery: per-company cap reached.', [
                'company_id' => $generation->company_id,
                'brief_id' => $brief->id,
                'limit' => $cap->limit(),
                'window_hours' => $cap->windowHours(),
            ]);
            $this->markFailed($generation, $cap->message());

            return;
        }

        $prompt = CampaignImagePrompt::forBrief($brief, $brains->for($brief->company));
        $generation->update(['prompt' => $prompt]);

        try {
            $generated = $images->generate(new ImageGenerationRequest($prompt))[0]
                ?? throw ImageGenerationException::failed($images->identifier(), 'no image returned.');

            $stored = $storage->store($generation->company_id, $generated);

            $generation->update([
                'status' => CampaignImageGeneration::STATUS_READY,
                'provider' => $stored->provider,
                'model' => $stored->model,
                'media_path' => $stored->path,
                'width' => $stored->width,
                'height' => $stored->height,
                'cost_usd' => $stored->costUsd,
                'error' => null,
            ]);

            Log::info('GenerateCampaignImagery: image ready.', [
                'company_id' => $generation->company_id,
                'brief_id' => $brief->id,
                'provider' => $stored->provider,
                'model' => $stored->model,
                'cost_usd' => $stored->costUsd,
            ]);
        } catch (ImageGenerationException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 60);

                return;
            }

            $this->markFailed($generation, 'Atlas could not generate imagery for this campaign right now.');
            Log::warning('GenerateCampaignImagery: generation failed.', [
                'company_id' => $generation->company_id,
                'brief_id' => $brief->id,
                'provider' => $e->provider,
                'retryable' => $e->retryable,
            ]);
        } catch (Throwable $e) {
            $this->markFailed($generation, 'Atlas could not generate imagery for this campaign right now.');
            Log::error('GenerateCampaignImagery: unexpected failure.', [
                'company_id' => $generation->company_id,
                'brief_id' => $brief->id,
                'exception' => $e::class,
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $generation = CampaignImageGeneration::withoutGlobalScopes()->find($this->generationId);

        if ($generation !== null && $generation->isPending()) {
            $this->markFailed($generation, 'Atlas could not generate imagery for this campaign right now.');
        }
    }

    private function markFailed(CampaignImageGeneration $generation, string $reason): void
    {
        $generation->update([
            'status' => CampaignImageGeneration::STATUS_FAILED,
            'error' => $reason,
        ]);
    }
}
