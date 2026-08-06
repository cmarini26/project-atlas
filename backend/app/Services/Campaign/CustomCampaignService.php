<?php

namespace App\Services\Campaign;

use App\Models\CampaignBrief;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\Opportunity;
use App\Models\SourceAsset;
use App\Services\Brain\BusinessBrainService;
use App\Services\Decision\DecisionContext;
use App\Services\Decision\DecisionService;
use App\Services\Publishing\ChannelPublishingCapabilityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomCampaignService
{
    public function __construct(
        private readonly BusinessBrainService $brainService,
        private readonly DecisionService $decisionService,
        private readonly ChannelPublishingCapabilityResolver $publishingCapabilities,
    ) {}

    /** @param array<string, mixed> $data */
    public function compose(Company $company, array $data): Decision
    {
        $assetIds = array_values(array_unique(array_map('strval', $data['source_asset_ids'])));
        $channelIds = array_values(array_unique(array_map('strval', $data['channel_ids'])));

        $assets = SourceAsset::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->where('status', 'ready')
            ->whereIn('id', $assetIds)
            ->get();

        if ($assets->count() !== count($assetIds)) {
            throw ValidationException::withMessages([
                'source_asset_ids' => 'Choose ready assets from your company’s Asset Library.',
            ]);
        }

        $channels = Channel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->whereIn('id', $channelIds)
            ->get();

        if ($channels->count() !== count($channelIds)) {
            throw ValidationException::withMessages([
                'channel_ids' => 'Choose active channels connected to your company.',
            ]);
        }

        $campaignType = match ((string) $data['goal']) {
            're_engagement' => 're_engagement',
            default => 'featured_item',
        };

        $publishingTargets = $channels
            ->map(function (Channel $channel) use ($company): ?string {
                $target = $this->publishingCapabilities->publishingTarget($company, $channel);

                return $target !== null ? "{$channel->name}: {$target}" : null;
            })
            ->filter()
            ->implode(', ');

        [, $opportunity] = DB::transaction(function () use ($company, $data, $assetIds, $channelIds, $campaignType, $publishingTargets): array {
            $brief = CampaignBrief::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'title' => $data['title'],
                'goal' => $data['goal'],
                'objective' => $data['objective'],
                'audience' => $data['audience'] ?? null,
                'guidance' => $data['guidance'] ?? null,
                'campaign_type' => $campaignType,
                'channel_ids' => $channelIds,
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
            ]);
            $brief->sourceAssets()->attach($assetIds);

            $opportunity = Opportunity::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'subject_type' => 'campaign_brief',
                'subject_id' => $brief->id,
                'type' => $campaignType === 're_engagement' ? 're_engagement' : 'featured_item',
                'title' => $brief->title,
                'description' => $this->opportunityDescription($brief->load('sourceAssets'), $publishingTargets),
                'relevance_score' => 100,
                'timing_score' => $brief->ends_at !== null ? 95 : 85,
                'confidence_score' => 100,
                'urgency_score' => $brief->ends_at !== null ? 90 : 60,
                'composite_score' => 100,
                'ai_detected' => false,
                'status' => 'open',
                'expires_at' => $brief->ends_at,
                'detected_at' => now(),
            ]);

            return [$brief, $opportunity];
        });

        try {
            return $this->decisionService->commit(new DecisionContext(
                opportunity: $opportunity,
                brain: $this->brainService->for($company),
                campaignType: $campaignType,
                channelIds: $channelIds,
            ));
        } catch (Throwable $exception) {
            $opportunity->dismiss();
            throw $exception;
        }
    }

    private function opportunityDescription(CampaignBrief $brief, string $publishingTargets): string
    {
        $assets = $brief->sourceAssets
            ->map(fn (SourceAsset $asset): string => "{$asset->title} ({$asset->type})")
            ->implode(', ');

        return implode("\n", array_filter([
            "Customer objective: {$brief->objective}",
            $brief->audience ? "Audience: {$brief->audience}" : null,
            $brief->guidance ? "Additional guidance: {$brief->guidance}" : null,
            $publishingTargets !== '' ? "Verified publishing targets: {$publishingTargets}" : null,
            "Selected Asset Library sources: {$assets}",
        ]));
    }
}
