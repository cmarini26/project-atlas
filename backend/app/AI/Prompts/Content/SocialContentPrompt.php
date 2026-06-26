<?php

namespace App\AI\Prompts\Content;

use App\AI\Prompts\Prompt;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\Campaign\ValueObjects\CampaignBlueprint;
use App\Models\Channel;

class SocialContentPrompt extends Prompt
{
    public function __construct(
        private readonly Channel $channel,
        private readonly CampaignBlueprint $blueprint,
        private readonly BusinessBrain $brain,
    ) {}

    public function version(): string
    {
        return '1.0';
    }

    public function temperature(): float
    {
        return 0.7;
    }

    public function system(): string
    {
        return <<<'SYSTEM'
You are a social media copywriter for Atlas, an AI marketing operating system.

Your job is to write a social media post that is ready to publish.
The post must be grounded in the campaign blueprint and feel authentic to the brand.

Rules:
- Write copy that stops the scroll — lead with the hook
- Match the brand tone exactly
- Include the call to action
- Stay within platform character limits (Instagram/Facebook: 2200 chars max, ideal under 300)
- body contains the post copy only — no captions, no labels
- metadata may include hashtags, suggested emoji, platform, and character_count

Respond with valid JSON only. No markdown. No explanation outside the JSON.
SYSTEM;
    }

    public function user(): string
    {
        $company = $this->brain->company;
        $bp = $this->blueprint;

        $channelType = $this->channel->type;
        $voice = (string) ($bp->tone['voice'] ?? 'professional');
        $modifier = (string) ($bp->tone['modifier'] ?? 'confident');
        $avoidRaw = $bp->tone['avoid'] ?? [];
        $avoidList = implode(', ', is_array($avoidRaw) ? $avoidRaw : []);
        $supportingPoints = implode("\n- ", $bp->supportingPoints);

        $strategy = $bp->channelStrategy[$channelType] ?? [];
        $format = is_array($strategy) ? (string) ($strategy['format'] ?? 'standard post') : 'standard post';
        $angle = is_array($strategy) ? (string) ($strategy['angle'] ?? '') : '';

        return <<<TEXT
Company: {$company->name}
Channel: {$channelType}

Blueprint:
- Goal: {$bp->goal}
- Audience: {$bp->audience}
- Core message: {$bp->coreMessage}
- Supporting points:
  - {$supportingPoints}
- Call to action: {$bp->callToAction}
- Offer: {$bp->offer}
- Voice: {$voice} / {$modifier}
- Avoid: {$avoidList}
- Format: {$format}
- Angle: {$angle}

Write a social media post for this campaign. Match the brand voice. Lead with the hook.
TEXT;
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => ['string', 'null']],
                'body' => ['type' => 'string'],
                'metadata' => [
                    'type' => 'object',
                    'properties' => [
                        'platform' => ['type' => 'string'],
                        'hashtags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'character_count' => ['type' => 'integer'],
                    ],
                ],
            ],
            'required' => ['body'],
        ];
    }
}
