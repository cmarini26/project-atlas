<?php

namespace App\Services\Campaign;

use App\AI\Images\ImageGenerationCap;
use App\Jobs\GenerateCampaignImagery;
use App\Models\CampaignBrief;
use App\Models\CampaignImageGeneration;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Opportunity;
use App\Models\SourceAsset;
use App\Services\Brain\BusinessBrainService;
use App\Services\Decision\DecisionContext;
use App\Services\Decision\DecisionService;
use App\Services\Publishing\ChannelPublishingCapabilityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomCampaignService
{
    public function __construct(
        private readonly BusinessBrainService $brainService,
        private readonly DecisionService $decisionService,
        private readonly ChannelPublishingCapabilityResolver $publishingCapabilities,
        private readonly ImageGenerationCap $imageCap,
    ) {}

    /** @param array<string, mixed> $data */
    public function compose(Company $company, array $data): Decision
    {
        $assetIds = array_values(array_unique(array_map('strval', $data['source_asset_ids'] ?? [])));
        $channelIds = array_values(array_unique(array_map('strval', $data['channel_ids'])));

        if ($assetIds !== []) {
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
        } elseif (! $this->hasBrainContext($company)) {
            // Prompt-only path: without a single asset AND without an established
            // Business Brain, Atlas has nothing to ground the campaign in.
            throw ValidationException::withMessages([
                'objective' => 'Atlas needs either a source asset or an analyzed Business Brain before it can compose this campaign. Add an asset from your library, or finish onboarding so Atlas can learn about your business.',
            ]);
        }

        $title = $this->resolveTitle($data);

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

        [$brief, $opportunity] = DB::transaction(function () use ($company, $data, $title, $assetIds, $channelIds, $campaignType, $publishingTargets): array {
            $brief = CampaignBrief::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'title' => $title,
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
            $decision = $this->decisionService->commit(new DecisionContext(
                opportunity: $opportunity,
                brain: $this->brainService->for($company),
                campaignType: $campaignType,
                channelIds: $channelIds,
            ));
        } catch (Throwable $exception) {
            $opportunity->dismiss();
            throw $exception;
        }

        $this->maybeGenerateImagery(
            $company,
            $brief,
            hasUserAssets: $assetIds !== [],
            optedIn: (bool) ($data['generate_imagery'] ?? false),
        );

        return $decision;
    }

    /**
     * Queue campaign imagery. Generation is requested automatically when the
     * user supplied no assets, or on explicit opt-in when they did. It never
     * blocks composition: a pending ledger row is created now and the review
     * UI reflects its status.
     */
    private function maybeGenerateImagery(Company $company, CampaignBrief $brief, bool $hasUserAssets, bool $optedIn): void
    {
        if ($hasUserAssets && ! $optedIn) {
            return;
        }

        // Cap is authoritatively re-checked in the job before the provider is
        // called; this pre-check just avoids queuing a doomed generation and
        // surfaces the reason immediately in the review UI.
        if ($this->imageCap->wouldExceed($company->id)) {
            CampaignImageGeneration::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'campaign_brief_id' => $brief->id,
                'status' => CampaignImageGeneration::STATUS_FAILED,
                'error' => $this->imageCap->message(),
            ]);

            return;
        }

        $generation = CampaignImageGeneration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_brief_id' => $brief->id,
            'status' => CampaignImageGeneration::STATUS_PENDING,
        ]);

        GenerateCampaignImagery::dispatch($generation->id);
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
            $assets !== ''
                ? "Selected Asset Library sources: {$assets}"
                : 'No source assets supplied — Atlas will ground this campaign in the Business Brain.',
        ]));
    }

    /**
     * Use the supplied title when present, otherwise derive a short title from
     * the objective prompt so a prompt-only brief still reads sensibly.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveTitle(array $data): string
    {
        $title = trim((string) ($data['title'] ?? ''));

        if ($title !== '') {
            return $title;
        }

        return $this->titleFromObjective((string) $data['objective']);
    }

    private function titleFromObjective(string $objective): string
    {
        $objective = trim((string) preg_replace('/\s+/', ' ', $objective));

        // Take the first sentence, then trim to a headline-friendly length.
        $firstSentence = preg_split('/(?<=[.!?])\s+/', $objective)[0] ?? $objective;
        $candidate = rtrim($firstSentence, " .!?\u{2026}");

        if (mb_strlen($candidate) > 80) {
            $candidate = rtrim(mb_substr($candidate, 0, 80), ' ').'…';
        }

        return Str::ucfirst($candidate);
    }

    /**
     * Whether the company has an established Business Brain to ground a
     * prompt-only campaign in. The Digital Twin is the anchor the brain is
     * assembled from, so its presence is the minimum bar.
     */
    private function hasBrainContext(Company $company): bool
    {
        return DigitalTwin::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->exists();
    }
}
