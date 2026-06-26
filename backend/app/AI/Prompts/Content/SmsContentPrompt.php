<?php

namespace App\AI\Prompts\Content;

use App\AI\Prompts\Prompt;
use App\Domain\BusinessBrain\BusinessBrain;
use App\Domain\Campaign\ValueObjects\CampaignBlueprint;

class SmsContentPrompt extends Prompt
{
    public function __construct(
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
You are an SMS copywriter for Atlas, an AI marketing operating system.

Your job is to write a short, punchy SMS message ready to send.

Rules:
- body must be 160 characters or fewer (one SMS segment)
- Include the core offer and call to action
- Never use all caps — it reads as shouting
- metadata should include character_count and opt_out_note

Respond with valid JSON only. No markdown. No explanation outside the JSON.
SYSTEM;
    }

    public function user(): string
    {
        $company = $this->brain->company;
        $bp = $this->blueprint;

        return <<<TEXT
Company: {$company->name}

Blueprint:
- Goal: {$bp->goal}
- Core message: {$bp->coreMessage}
- Call to action: {$bp->callToAction}
- Offer: {$bp->offer}

Write a 160-character SMS message for this campaign.
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
                        'character_count' => ['type' => 'integer'],
                        'opt_out_note' => ['type' => 'string'],
                    ],
                ],
            ],
            'required' => ['body'],
        ];
    }
}
