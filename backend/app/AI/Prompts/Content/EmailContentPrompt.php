<?php

namespace App\AI\Prompts\Content;

use App\AI\Prompts\Prompt;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\Campaign\ValueObjects\CampaignBlueprint;
use App\Models\Channel;

class EmailContentPrompt extends Prompt
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

    public function system(): string
    {
        return <<<'SYSTEM'
You are an email copywriter for Atlas, an AI marketing operating system.

Your job is to write a complete marketing email ready to send.
The email must be grounded in the campaign blueprint.

Rules:
- title contains the subject line (compelling, under 60 chars)
- body contains the full email body in plain text (no HTML)
- Include a clear opening hook, supporting evidence, and a call to action
- Respect the brand voice and tone throughout
- metadata should include preview_text (under 100 chars) and word_count

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
        $format = is_array($strategy) ? (string) ($strategy['format'] ?? 'standard email') : 'standard email';

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
- Landing page: {$bp->landingPage}
- Voice: {$voice} / {$modifier}
- Avoid: {$avoidList}
- Format: {$format}

Write a complete marketing email for this campaign. Include subject line, body, and call to action.
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
                        'preview_text' => ['type' => 'string'],
                        'word_count' => ['type' => 'integer'],
                    ],
                ],
            ],
            'required' => ['title', 'body'],
        ];
    }
}
