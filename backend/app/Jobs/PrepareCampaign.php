<?php

namespace App\Jobs;

use App\Domain\Campaign\Exceptions\BlueprintGenerationFailedException;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Services\Brain\BusinessBrainService;
use App\Services\Campaign\CampaignPreparationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PrepareCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly Decision $decision)
    {
        $this->onQueue('ai');
    }

    public function handle(
        CampaignPreparationService $preparationService,
        BusinessBrainService $brainService,
    ): void {
        $decision = Decision::withoutGlobalScopes()->findOrFail($this->decision->id);
        $company = Company::withoutGlobalScopes()->findOrFail($decision->company_id);
        $brain = $brainService->for($company);

        try {
            $campaign = $preparationService->prepare($decision, $brain);
        } catch (BlueprintGenerationFailedException $e) {
            Log::error('PrepareCampaign: blueprint generation failed.', [
                'decision_id' => $decision->id,
                'reason' => $e->getMessage(),
            ]);

            $this->fail($e);

            return;
        }

        $channelIds = $decision->channel_ids ?? [];

        foreach ($channelIds as $channelId) {
            $channel = Channel::withoutGlobalScopes()->where('id', (string) $channelId)->first();

            if ($channel === null) {
                Log::warning('PrepareCampaign: channel not found, skipping.', [
                    'channel_id' => $channelId,
                    'campaign_id' => $campaign->id,
                ]);

                continue;
            }

            GenerateContent::dispatch($campaign, $channel);
        }

        Log::info('PrepareCampaign: dispatched content generation.', [
            'campaign_id' => $campaign->id,
            'channel_count' => count($channelIds),
        ]);
    }
}
