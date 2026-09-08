<?php

namespace App\Services\Analyst\Content;

use App\AI\Contracts\AiProvider;
use App\AI\Prompts\Content\BlogContentPrompt;
use App\AI\Prompts\Content\EmailContentPrompt;
use App\AI\Prompts\Content\LandingPageContentPrompt;
use App\AI\Prompts\Content\SmsContentPrompt;
use App\AI\Prompts\Content\SocialContentPrompt;
use App\AI\StructuredResponseParser;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\Campaign\ValueObjects\CampaignBlueprint;
use App\Domain\Content\ValueObjects\ContentAssetData;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Observation;
use App\Services\Analyst\Contracts\Analyst;
use App\Services\Imaging\GeneratedImageService;
use App\Services\Learning\ContentPreferenceGuide;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ContentGenerationAnalyst implements Analyst
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly StructuredResponseParser $parser,
        private readonly ContentPreferenceGuide $contentPreferenceGuide,
        private readonly GeneratedImageService $generatedImages,
    ) {}

    public function analyze(Campaign $campaign, Channel $channel, BusinessBrain $brain): ContentAssetData
    {
        $blueprint = $this->resolveBlueprint($campaign);
        $preferenceGuidance = $this->contentPreferenceGuide->guidanceFor($brain->company, $channel->type);

        $prompt = match ($channel->type) {
            'instagram', 'facebook', 'linkedin', 'x' => new SocialContentPrompt($channel, $blueprint, $brain, $preferenceGuidance),
            'email' => new EmailContentPrompt($channel, $blueprint, $brain, $preferenceGuidance),
            'sms' => new SmsContentPrompt($blueprint, $brain, $preferenceGuidance),
            'blog' => new BlogContentPrompt($channel, $blueprint, $brain, $preferenceGuidance),
            'landing_page' => new LandingPageContentPrompt($channel, $blueprint, $brain, $preferenceGuidance),
        };

        $response = $this->ai->complete($prompt);
        $data = $this->parser->parse($response);

        $type = match ($channel->type) {
            'instagram', 'facebook', 'linkedin', 'x' => 'social_post',
            'email' => 'email',
            'sms' => 'sms',
            'blog' => 'blog_post',
            'landing_page' => 'landing_page',
        };

        $media = $this->resolveMedia($campaign, $channel, $brain);

        return new ContentAssetData(
            type: $type,
            body: (string) ($data['body'] ?? ''),
            title: isset($data['title']) ? (string) $data['title'] : null,
            media: $media,
            metadata: $this->resolveMetadata($data, $media),
            promptName: $prompt->name(),
            promptVersion: $prompt->version(),
        );
    }

    /**
     * Merge the model's own metadata with an explicit marker when Atlas
     * attached an AI-generated image, so the approval UI can label the image
     * as generated rather than sourced from real inventory (SCRUM-71).
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>|null  $media
     * @return array<string, mixed>|null
     */
    private function resolveMetadata(array $data, ?array $media): ?array
    {
        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];

        if ($media !== null && ($media[0]['source'] ?? null) === 'ai_generated') {
            $metadata['generated_image'] = true;
            $metadata['image_prompt_version'] = (string) ($media[0]['prompt_version'] ?? GeneratedImageService::PROMPT_VERSION);
        }

        return $metadata === [] ? null : $metadata;
    }

    /**
     * Prefer an AI-generated image proposal for eligible visual channels
     * (SCRUM-71), then fall back to a real crawled/source image. The generated
     * path is off by default and returns null unless explicitly enabled, so
     * behaviour is unchanged until a real image provider is configured.
     *
     * @return list<array<string, mixed>>|null
     */
    private function resolveMedia(Campaign $campaign, Channel $channel, BusinessBrain $brain): ?array
    {
        $generated = $this->generatedImages->proposeFor($campaign, $channel, $brain);

        if ($generated !== null) {
            return $generated;
        }

        return $this->resolveMediaFallback($campaign, $brain);
    }

    /**
     * Best-effort media: no per-product photo matching exists yet (there's no
     * catalog-item ingestion pipeline), so this just surfaces the first image
     * found on the company's most recently crawled page, if any. Returns null
     * when nothing has ever been crawled — visual channels will correctly
     * fail to render until a real image is available.
     *
     * @return list<array{url: string}>|null
     */
    private function resolveMediaFallback(Campaign $campaign, BusinessBrain $brain): ?array
    {
        if ($campaign->brief !== null) {
            $source = $campaign->brief->sourceAssets()
                ->whereNotNull('media_path')
                ->where('media_mime_type', 'like', 'image/%')
                ->first();

            if ($source !== null && $source->media_path !== null) {
                return [['url' => asset("storage/{$source->media_path}")]];
            }
        }

        /** @var Collection<int, Observation> $crawls */
        $crawls = $brain->recentObservations->where('source_type', 'crawl');

        foreach ($crawls as $observation) {
            $payload = json_decode((string) $observation->raw_payload, true);

            if (! is_array($payload) || empty($payload['images']) || ! is_array($payload['images'])) {
                continue;
            }

            $url = (string) ($payload['images'][0] ?? '');

            if ($url !== '') {
                return [['url' => $url]];
            }
        }

        return null;
    }

    private function resolveBlueprint(Campaign $campaign): CampaignBlueprint
    {
        $blueprintData = $campaign->blueprint;

        if (! is_array($blueprintData)) {
            throw new InvalidArgumentException("Campaign [{$campaign->id}] has no blueprint.");
        }

        return CampaignBlueprint::fromArray($blueprintData);
    }
}
