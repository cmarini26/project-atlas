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
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ContentGenerationAnalyst implements Analyst
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly StructuredResponseParser $parser,
    ) {}

    public function analyze(Campaign $campaign, Channel $channel, BusinessBrain $brain): ContentAssetData
    {
        $blueprint = $this->resolveBlueprint($campaign);

        $prompt = match ($channel->type) {
            'instagram', 'facebook', 'linkedin', 'x' => new SocialContentPrompt($channel, $blueprint, $brain),
            'email' => new EmailContentPrompt($channel, $blueprint, $brain),
            'sms' => new SmsContentPrompt($blueprint, $brain),
            'blog' => new BlogContentPrompt($channel, $blueprint, $brain),
            'landing_page' => new LandingPageContentPrompt($channel, $blueprint, $brain),
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

        return new ContentAssetData(
            type: $type,
            body: (string) ($data['body'] ?? ''),
            title: isset($data['title']) ? (string) $data['title'] : null,
            media: $this->resolveMediaFallback($brain),
            metadata: isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
            promptName: $prompt->name(),
            promptVersion: $prompt->version(),
        );
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
    private function resolveMediaFallback(BusinessBrain $brain): ?array
    {
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
