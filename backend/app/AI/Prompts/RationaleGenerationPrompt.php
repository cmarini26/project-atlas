<?php

namespace App\AI\Prompts;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\CatalogItem;
use App\Models\Fact;
use App\Models\Knowledge;
use App\Models\Opportunity;

class RationaleGenerationPrompt extends Prompt
{
    /**
     * @param  array{campaign_type: string, channel_ids: string[]}  $partialDecision
     */
    public function __construct(
        private readonly Opportunity $opportunity,
        private readonly array $partialDecision,
        private readonly BusinessBrain $brain,
    ) {}

    public function version(): string
    {
        return '1.0';
    }

    public function temperature(): float
    {
        return 0.4;
    }

    public function system(): string
    {
        return <<<'SYSTEM'
You are a marketing strategist for Atlas, an AI marketing operating system.

Your job is to write a clear, specific, and credible rationale for a marketing decision.
The rationale is user-facing — the business owner will read it before approving the campaign.

Rules:
- Write in the company's brand voice and tone
- Ground every claim in the facts and knowledge provided — do not invent statistics
- Be specific, not generic; reference actual data points from the context
- The rationale must be credible and trustworthy — no exaggerations
- All five rationale fields are required; none may be empty
- The expected_impact section has four required sub-fields; all must be populated

Respond with valid JSON only. No markdown. No explanation outside the JSON.
SYSTEM;
    }

    public function user(): string
    {
        $company = $this->brain->company;
        $opp = $this->opportunity;

        $rawBrand = $company->brand;
        $brandArray = is_string($rawBrand) ? json_decode($rawBrand, true) : $rawBrand;
        $brandVoice = is_array($brandArray) && isset($brandArray['voice'])
            ? (string) $brandArray['voice']
            : 'professional';
        $brandTone = is_array($brandArray) && isset($brandArray['tone'])
            ? (string) $brandArray['tone']
            : 'confident';

        $factsJson = $this->brain->activeFacts
            ->map(fn (Fact $f): array => ['key' => $f->key, 'value' => $f->value])
            ->values()
            ->toJson();

        $knowledgeJson = $this->brain->activeKnowledge
            ->map(fn (Knowledge $k): array => ['subject' => $k->subject, 'body' => $k->body])
            ->values()
            ->toJson();

        $subject = $this->resolveSubject();
        $industry = $company->industry ?? 'not specified';
        $campaignType = $this->partialDecision['campaign_type'];

        $channelTypes = $this->brain->activeFacts
            ->filter(fn (Fact $f): bool => str_starts_with($f->key, 'channel.'))
            ->map(fn (Fact $f): string => $f->key)
            ->values()
            ->toJson();

        return <<<TEXT
Company: {$company->name}
Industry: {$industry}
Brand voice: {$brandVoice}
Brand tone: {$brandTone}

Opportunity:
- Type: {$opp->type}
- Title: {$opp->title}
- Description: {$opp->description}
- Relevance score: {$opp->relevance_score}/100
- Timing score: {$opp->timing_score}/100
- Confidence score: {$opp->confidence_score}/100
- Urgency score: {$opp->urgency_score}/100
- Composite score: {$opp->composite_score}/100

Campaign decision:
- Campaign type: {$campaignType}
- Selected channels: {$channelTypes}

{$subject}

Facts:
{$factsJson}

Knowledge:
{$knowledgeJson}

Write a rationale for this marketing decision. Be specific, grounded, and use the company's brand voice.
TEXT;
    }

    private function resolveSubject(): string
    {
        if ($this->opportunity->subject_type !== 'catalog_item' || $this->opportunity->subject_id === null) {
            return '';
        }

        $item = CatalogItem::withoutGlobalScopes()->find($this->opportunity->subject_id);

        if ($item === null) {
            return '';
        }

        $price = $item->price !== null ? '$'.number_format((float) $item->price, 0) : 'price not listed';

        return <<<TEXT

Subject item:
- Title: {$item->title}
- Status: {$item->status}
- Price: {$price}
TEXT;
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'why_now' => ['type' => 'string'],
                'why_this' => ['type' => 'string'],
                'why_channel' => ['type' => 'string'],
                'why_works' => ['type' => 'string'],
                'expected_impact' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string'],
                        'reach_estimate' => ['type' => 'string'],
                        'engagement_signal' => ['type' => 'string'],
                        'confidence_basis' => ['type' => 'string'],
                    ],
                    'required' => ['summary', 'reach_estimate', 'engagement_signal', 'confidence_basis'],
                ],
            ],
            'required' => ['why_now', 'why_this', 'why_channel', 'why_works', 'expected_impact'],
        ];
    }
}
