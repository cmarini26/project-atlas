<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Recommendation\RecommendationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateRecommendation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly Campaign $campaign)
    {
        $this->onQueue('default');
    }

    public function handle(RecommendationService $recommendationService): void
    {
        $campaign = Campaign::withoutGlobalScopes()->findOrFail($this->campaign->id);

        $recommendation = $recommendationService->create($campaign);

        Log::info('CreateRecommendation: recommendation created.', [
            'campaign_id' => $campaign->id,
            'recommendation_id' => $recommendation->id,
        ]);
    }
}
