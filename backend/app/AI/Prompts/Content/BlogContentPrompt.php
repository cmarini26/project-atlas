<?php

namespace App\AI\Prompts\Content;

use App\AI\Prompts\Prompt;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\Campaign\ValueObjects\CampaignBlueprint;
use App\Models\Channel;

class BlogContentPrompt extends Prompt
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
        return 0.6;
    }

    public function maxTokens(): int
    {
        return 4096;
    }

    public function system(): string
    {
        return <<<'SYSTEM'
You are a content writer for Atlas, an AI marketing operating system.

Your job is to write a complete blog post for the company's blog.
The post should be educational, engaging, and SEO-friendly.

Rules:
- title is the blog post headline (under 70 chars, ideally 50-60)
- body is the full blog post in plain text (500-800 words)
- Use the supporting points as the post's key sections
- End with a natural call to action
- metadata should include word_count, seo_keywords (array), and meta_description (under 160 chars)

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
        $angle = is_array($strategy) ? (string) ($strategy['angle'] ?? '') : '';

        return <<<TEXT
Company: {$company->name}

Blueprint:
- Goal: {$bp->goal}
- Audience: {$bp->audience}
- Core message: {$bp->coreMessage}
- Supporting points:
  - {$supportingPoints}
- Call to action: {$bp->callToAction}
- Voice: {$voice} / {$modifier}
- Avoid: {$avoidList}
- Angle: {$angle}

Write a complete blog post for this campaign. Use the supporting points as sections.
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
                        'word_count' => ['type' => 'integer'],
                        'seo_keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'meta_description' => ['type' => 'string'],
                    ],
                ],
            ],
            'required' => ['title', 'body'],
        ];
    }
}
