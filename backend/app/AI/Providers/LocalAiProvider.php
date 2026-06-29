<?php

namespace App\AI\Providers;

use App\AI\AiResponse;
use App\AI\Contracts\AiProvider;
use App\AI\Prompts\CampaignPreparationPrompt;
use App\AI\Prompts\Content\BlogContentPrompt;
use App\AI\Prompts\Content\EmailContentPrompt;
use App\AI\Prompts\Content\LandingPageContentPrompt;
use App\AI\Prompts\Content\SmsContentPrompt;
use App\AI\Prompts\Content\SocialContentPrompt;
use App\AI\Prompts\FactExtractionPrompt;
use App\AI\Prompts\OpportunityDetectionPrompt;
use App\AI\Prompts\Prompt;
use App\AI\Prompts\RationaleGenerationPrompt;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic AI provider for local development.
 *
 * Returns hardcoded, structurally valid JSON stubs so the full pipeline
 * (observation → facts → knowledge → opportunities → decision → campaign → recommendation)
 * can run end-to-end on a local machine without an Anthropic API key or
 * a queue worker (requires QUEUE_CONNECTION=sync in .env).
 *
 * NOT safe for production — never bind this outside the local environment.
 */
class LocalAiProvider implements AiProvider
{
    public function complete(Prompt $prompt): AiResponse
    {
        $content = match (true) {
            $prompt instanceof FactExtractionPrompt => $this->factExtractionStub(),
            $prompt instanceof OpportunityDetectionPrompt => $this->opportunityDetectionStub(),
            $prompt instanceof RationaleGenerationPrompt => $this->rationaleStub(),
            $prompt instanceof CampaignPreparationPrompt => $this->campaignBlueprintStub(),
            $prompt instanceof EmailContentPrompt,
            $prompt instanceof SocialContentPrompt,
            $prompt instanceof SmsContentPrompt,
            $prompt instanceof BlogContentPrompt,
            $prompt instanceof LandingPageContentPrompt => $this->contentStub(),
            default => '{}',
        };

        Log::debug('LocalAiProvider: returning stub response.', [
            'prompt' => $prompt::class,
        ]);

        return new AiResponse(
            content: $content,
            model: 'local-stub',
            inputTokens: 0,
            outputTokens: 0,
        );
    }

    private function factExtractionStub(): string
    {
        return json_encode([
            'facts' => [
                [
                    'key' => 'business.name',
                    'value' => 'Your Business',
                    'data_type' => 'string',
                    'confidence' => 75,
                ],
                [
                    'key' => 'business.description',
                    'value' => 'A local business serving the community with quality products and services',
                    'data_type' => 'string',
                    'confidence' => 65,
                ],
                [
                    'key' => 'business.industry',
                    'value' => 'general',
                    'data_type' => 'string',
                    'confidence' => 60,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function opportunityDetectionStub(): string
    {
        return json_encode([
            'opportunities' => [
                [
                    'type' => 're_engagement',
                    'subject_type' => null,
                    'subject_id' => null,
                    'title' => 'Re-engage your audience with fresh content',
                    'description' => 'You have not published a campaign recently. A re-engagement piece will re-establish audience connection and remind followers of your business.',
                    'expires_at' => null,
                    'relevance_score' => 72,
                    'timing_score' => 70,
                    'confidence_score' => 65,
                    'urgency_score' => 50,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function rationaleStub(): string
    {
        return json_encode([
            'why_now' => 'Your audience has not heard from you recently, making this the right moment to re-establish your presence and rebuild engagement before competitors fill the gap.',
            'why_this' => 'A re-engagement campaign directly addresses the communication gap and positions your business top of mind, converting dormant awareness into active interest.',
            'why_channel' => 'The selected channel reaches your most engaged audience segment with minimal friction and can be published immediately without complex setup.',
            'why_works' => 'Consistent communication outperforms sporadic campaigns. Re-engagement campaigns reliably recover dormant audience interest across industries and business sizes.',
            'expected_impact' => [
                'summary' => 'Expected to re-engage dormant audience segments and increase brand recall within two weeks of publication.',
                'reach_estimate' => 'Estimated to reach 150–400 people based on typical audience size at this business stage.',
                'engagement_signal' => 'An engagement rate above 5% would confirm successful re-engagement with the target audience.',
                'confidence_basis' => 'Based on industry benchmarks for re-engagement campaigns in early-stage businesses without prior campaign history.',
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function campaignBlueprintStub(): string
    {
        return json_encode([
            'version' => '1.0',
            'goal' => 're_engagement',
            'audience' => 'Past customers and interested prospects who engaged with the business previously but have not returned in the last 30 days',
            'core_message' => 'We have fresh updates and new offerings worth your attention — discover what is new this week',
            'supporting_points' => [
                'New content and updates are available now on our website',
                'Consistent communication keeps you informed about our latest offerings',
            ],
            'call_to_action' => 'Visit our website to discover what is new this week',
            'offer' => null,
            'tone' => [
                'voice' => 'professional',
                'modifier' => 'warm',
                'avoid' => ['pushy', 'salesy', 'aggressive'],
            ],
            'landing_page' => null,
            'success_metrics' => [
                'primary_metric' => 'Website traffic from campaign',
                'secondary_metrics' => ['Time on site', 'Return visit rate'],
                'baseline' => 'Average weekly website visits before campaign launch',
                'timeframe' => '7 days after publication',
            ],
            'channel_strategy' => [
                'blog' => [
                    'format' => 'informational post highlighting recent updates and offerings',
                    'angle' => 'Educational and community-focused storytelling',
                    'constraints' => ['800–1200 words', 'SEO-optimized title'],
                    'priority' => 1,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function contentStub(): string
    {
        return json_encode([
            'title' => 'We Have Updates Worth Your Attention',
            'body' => "Hello and welcome back.\n\nWe have been working hard to bring you fresh content and new offerings. Whether you are a returning customer or discovering us for the first time, we are glad you are here.\n\nVisit our website to discover what is new this week — we think you will find something that interests you.",
            'metadata' => [
                'word_count' => 52,
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
