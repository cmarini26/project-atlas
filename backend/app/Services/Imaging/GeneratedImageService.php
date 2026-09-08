<?php

namespace App\Services\Imaging;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\Campaign\ValueObjects\CampaignBlueprint;
use App\Domain\Imaging\ValueObjects\GeneratedImage;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ContentAsset;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Decides *when* Atlas should propose an AI-generated image for a draft and
 * turns a provider result into the `ContentAsset.media` shape the recommendation
 * preview already renders. Everything here degrades to `null` (no generated
 * media) rather than throwing, so content generation never fails because image
 * generation did.
 */
class GeneratedImageService
{
    public const PROMPT_VERSION = '1.0';

    public function __construct(
        private readonly ImageGenerationProviderRegistry $registry,
    ) {}

    /**
     * @return list<array{url: string, type: string, source: string, prompt_version: string}>|null
     */
    public function proposeFor(Campaign $campaign, Channel $channel, BusinessBrain $brain): ?array
    {
        if (! (bool) config('imaging.enabled')) {
            return null;
        }

        /** @var list<string> $eligible */
        $eligible = (array) config('imaging.channels', []);

        if (! in_array($channel->type, $eligible, true)) {
            return null;
        }

        $limit = (int) config('imaging.per_company_daily_limit', 0);

        if ($limit > 0 && $this->generatedTodayForCompany($campaign->company_id) >= $limit) {
            Log::warning('GeneratedImageService: per-company daily image limit reached, skipping generation.', [
                'company_id' => $campaign->company_id,
                'campaign_id' => $campaign->id,
                'limit' => $limit,
            ]);

            return null;
        }

        try {
            $provider = $this->registry->for((string) config('imaging.provider'));
            $prompt = $this->buildPrompt($campaign, $channel, $brain);
            $image = $provider->generate($prompt);
            $path = $this->store($campaign, $channel, $image);

            return [[
                'url' => $this->disk()->url($path),
                'type' => 'image',
                'source' => 'ai_generated',
                'prompt_version' => self::PROMPT_VERSION,
            ]];
        } catch (\Throwable $e) {
            Log::warning('GeneratedImageService: image generation failed, degrading to no generated media.', [
                'campaign_id' => $campaign->id,
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildPrompt(Campaign $campaign, Channel $channel, BusinessBrain $brain): string
    {
        $company = $brain->company;
        $blueprint = is_array($campaign->blueprint)
            ? CampaignBlueprint::fromArray($campaign->blueprint)
            : null;

        $lines = [
            "Marketing image for {$company->name}".($company->industry ? " ({$company->industry})" : '').'.',
            'Channel: '.$channel->type.'.',
        ];

        if ($blueprint !== null) {
            $lines[] = 'Campaign message: '.$blueprint->coreMessage;

            if ($blueprint->offer !== null && $blueprint->offer !== '') {
                $lines[] = 'Offer: '.$blueprint->offer;
            }

            $tone = array_filter(
                $blueprint->tone,
                static fn ($value): bool => is_scalar($value) && (string) $value !== '',
            );

            if ($tone !== []) {
                $lines[] = 'Tone: '.implode(', ', array_map('strval', $tone)).'.';
            }
        }

        $lines[] = 'No text, logos, or watermarks in the image.';

        return implode("\n", $lines);
    }

    private function store(Campaign $campaign, Channel $channel, GeneratedImage $image): string
    {
        $path = sprintf(
            'generated-content/%s/%s/%s/%s.%s',
            $campaign->company_id,
            $campaign->id,
            $channel->id,
            (string) Str::uuid(),
            $image->extension(),
        );

        $this->disk()->put($path, $image->contents);

        return $path;
    }

    private function generatedTodayForCompany(string $companyId): int
    {
        return ContentAsset::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('created_at', today())
            ->get(['media'])
            ->filter(function (ContentAsset $asset): bool {
                foreach ((array) ($asset->media ?? []) as $item) {
                    if (is_array($item) && ($item['source'] ?? null) === 'ai_generated') {
                        return true;
                    }
                }

                return false;
            })
            ->count();
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('imaging.disk', 'public'));
    }
}
