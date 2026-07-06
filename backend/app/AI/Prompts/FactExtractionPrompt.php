<?php

namespace App\AI\Prompts;

class FactExtractionPrompt extends Prompt
{
    public function __construct(
        private readonly string $pageUrl,
        private readonly string $pageTitle,
        private readonly string $bodyText,
    ) {}

    public function system(): string
    {
        return <<<'EOT'
        You are an AI analyst for Atlas, an autonomous marketing operating system.
        Your job is to extract structured business facts from website page content.

        Extract facts about:
        - Business identity: name, description, industry, location
        - Products and services offered
        - Contact information: email, phone, address
        - Brand voice and tone
        - Pricing indicators (ranges, not exact prices)
        - Unique selling points and differentiators

        Rules:
        - Use dot-notation keys (e.g., "business.name", "services.primary", "contact.email")
        - Confidence is 0-100. Use 90+ only for clearly stated facts. Use 60-80 for inferred facts.
        - data_type must be one of: string, integer, float, boolean, json
        - For lists, use data_type "json" and serialize the value as a JSON array string
        - Only extract facts that are clearly supported by the content
        - Do not hallucinate facts that are not present

        Return a JSON object with a "facts" array.
        EOT;
    }

    public function user(): string
    {
        return <<<EOT
        Extract business facts from this webpage.

        URL: {$this->pageUrl}
        Title: {$this->pageTitle}

        Content:
        {$this->bodyText}
        EOT;
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'facts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string', 'description' => 'Dot-notation fact key, e.g. business.name'],
                            'value' => ['type' => 'string', 'description' => 'The extracted value, always as a string'],
                            'data_type' => [
                                'type' => 'string',
                                'enum' => ['string', 'integer', 'float', 'boolean', 'json'],
                            ],
                            'confidence' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'maximum' => 100,
                            ],
                        ],
                        'required' => ['key', 'value', 'data_type', 'confidence'],
                    ],
                ],
            ],
            'required' => ['facts'],
        ];
    }

    public function version(): string
    {
        return '1.0';
    }

    public function maxTokens(): int
    {
        // A real page yields dozens of facts; the structured tool-use JSON for them
        // easily exceeds 1024 tokens, and truncation makes the API return an empty
        // tool input — which surfaced as "zero facts" during onboarding.
        return 4096;
    }

    public function temperature(): float
    {
        return 0.1;
    }
}
