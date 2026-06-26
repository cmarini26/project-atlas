<?php

namespace App\AI\Prompts;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Decision;
use App\Models\Fact;
use App\Models\Knowledge;

class CampaignPreparationPrompt extends Prompt
{
    public function __construct(
        private readonly Decision $decision,
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
You are a campaign strategist for Atlas, an AI marketing operating system.

Your job is to create a Campaign Blueprint — the strategic foundation for a marketing campaign.
The blueprint must be specific, grounded in the business context, and immediately actionable.

Rules:
- Every field is required unless marked nullable
- The goal must be one of: awareness, conversion, re_engagement
- Audience must describe real, specific people — not a vague demographic
- Core message must be a single compelling claim, not a slogan
- Supporting points must be 1-5 distinct, concrete reasons to believe the core message
- Call to action must be specific (e.g. "Book a test drive this weekend") — never generic filler
- Tone must define voice, modifier, and words/phrases to avoid
- Channel strategy must contain one entry per channel type provided in the decision
- Success metrics must be specific and measurable

Respond with valid JSON only. No markdown. No explanation outside the JSON.
SYSTEM;
    }

    public function user(): string
    {
        $company = $this->brain->company;
        $decision = $this->decision;

        $industry = $company->industry ?? 'not specified';

        $rawBrand = $company->brand;
        $brandArray = is_string($rawBrand) ? json_decode($rawBrand, true) : $rawBrand;
        $brandVoice = is_array($brandArray) && isset($brandArray['voice'])
            ? (string) $brandArray['voice']
            : 'professional';

        $channelIds = implode(', ', $decision->channel_ids ?? []);

        $rationaleJson = json_encode($decision->rationale ?? [], JSON_PRETTY_PRINT);

        $factsJson = $this->brain->activeFacts
            ->map(fn (Fact $f): array => ['key' => $f->key, 'value' => $f->value])
            ->values()
            ->toJson();

        $knowledgeJson = $this->brain->activeKnowledge
            ->map(fn (Knowledge $k): array => ['subject' => $k->subject, 'body' => $k->body])
            ->values()
            ->toJson();

        $catalogJson = $this->brain->featuredItems
            ->take(10)
            ->map(fn ($item): array => [
                'title' => $item->title,
                'status' => $item->status,
                'price' => $item->price,
            ])
            ->values()
            ->toJson();

        return <<<TEXT
Company: {$company->name}
Industry: {$industry}
Brand voice: {$brandVoice}

Decision:
- Campaign type: {$decision->campaign_type}
- Channel IDs: {$channelIds}

Rationale:
{$rationaleJson}

Facts:
{$factsJson}

Knowledge:
{$knowledgeJson}

Catalog (sample):
{$catalogJson}

Create a Campaign Blueprint for this decision. Be specific and ground every claim in the business context above.
TEXT;
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'version' => ['type' => 'string'],
                'goal' => ['type' => 'string', 'enum' => ['awareness', 'conversion', 're_engagement']],
                'audience' => ['type' => 'string'],
                'core_message' => ['type' => 'string'],
                'supporting_points' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 1,
                    'maxItems' => 5,
                ],
                'call_to_action' => ['type' => 'string'],
                'offer' => ['type' => ['string', 'null']],
                'tone' => [
                    'type' => 'object',
                    'properties' => [
                        'voice' => ['type' => 'string'],
                        'modifier' => ['type' => 'string'],
                        'avoid' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['voice', 'modifier', 'avoid'],
                ],
                'landing_page' => ['type' => ['string', 'null']],
                'success_metrics' => [
                    'type' => 'object',
                    'properties' => [
                        'primary_metric' => ['type' => 'string'],
                        'secondary_metrics' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'baseline' => ['type' => 'string'],
                        'timeframe' => ['type' => 'string'],
                    ],
                    'required' => ['primary_metric', 'secondary_metrics', 'baseline', 'timeframe'],
                ],
                'channel_strategy' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'format' => ['type' => 'string'],
                            'angle' => ['type' => 'string'],
                            'constraints' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'priority' => ['type' => 'integer'],
                        ],
                        'required' => ['format', 'angle', 'constraints', 'priority'],
                    ],
                ],
            ],
            'required' => [
                'version', 'goal', 'audience', 'core_message', 'supporting_points',
                'call_to_action', 'tone', 'success_metrics', 'channel_strategy',
            ],
        ];
    }
}
