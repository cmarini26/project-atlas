<?php

namespace App\AI\Prompts\Content;

use App\AI\Prompts\Prompt;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\Campaign\ValueObjects\CampaignBlueprint;
use App\Models\Channel;

class LandingPageContentPrompt extends Prompt
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
        return 0.5;
    }

    public function maxTokens(): int
    {
        return 4096;
    }

    public function system(): string
    {
        return <<<'SYSTEM'
You are a conversion copywriter for Atlas, an AI marketing operating system.

Your job is to write landing page copy that converts visitors into customers.
Every word must earn its place.

Rules:
- title is the hero headline (compelling, under 10 words)
- body is the full landing page copy in plain text (sections separated by double newlines)
- Structure: Hero → Problem → Solution → Social Proof → CTA
- metadata should include sections (array of section names), cta_button_text, and word_count

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
        $format = is_array($strategy) ? (string) ($strategy['format'] ?? 'standard landing page') : 'standard landing page';

        return <<<TEXT
Company: {$company->name}

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

Write landing page copy for this campaign. Structure: Hero → Problem → Solution → Social Proof → CTA.
TEXT;
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
                'metadata' => [
                    'type' => 'object',
                    'properties' => [
                        'sections' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'cta_button_text' => ['type' => 'string'],
                        'word_count' => ['type' => 'integer'],
                    ],
                ],
            ],
            'required' => ['title', 'body'],
        ];
    }
}
