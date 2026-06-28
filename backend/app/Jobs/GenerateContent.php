<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Company;
use App\Services\Analyst\Content\ContentGenerationAnalyst;
use App\Services\Brain\BusinessBrainService;
use App\Services\Content\ContentGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly Campaign $campaign,
        public readonly Channel $channel,
    ) {
        $this->onQueue('ai');
    }

    public function handle(
        ContentGenerationAnalyst $analyst,
        ContentGenerationService $contentService,
        BusinessBrainService $brainService,
    ): void {
        $campaign = Campaign::withoutGlobalScopes()->findOrFail($this->campaign->id);
        $channel = Channel::withoutGlobalScopes()->findOrFail($this->channel->id);
        $company = Company::withoutGlobalScopes()->findOrFail($campaign->company_id);
        $brain = $brainService->for($company);

        $assetData = $analyst->analyze($campaign, $channel, $brain);
        $contentService->createAsset($campaign, $channel, $assetData);

        Log::info('GenerateContent: asset created.', [
            'campaign_id' => $campaign->id,
            'channel_id' => $channel->id,
            'type' => $assetData->type,
        ]);
    }
}
