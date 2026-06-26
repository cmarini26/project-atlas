<?php

namespace App\Services\Analyst;

use App\AI\Contracts\AiProvider;
use App\AI\Prompts\FactExtractionPrompt;
use App\AI\StructuredResponseParser;
use App\Models\Observation;
use App\Services\Analyst\Contracts\Analyst;
use App\Services\Brain\Data\FactData;
use Illuminate\Support\Collection;

class WebsiteAnalyst implements Analyst
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly StructuredResponseParser $parser,
    ) {}

    /**
     * Extract structured facts from a website crawl Observation.
     *
     * @return Collection<int, FactData>
     */
    public function analyze(Observation $observation): Collection
    {
        $payload = json_decode((string) $observation->raw_payload, true);

        if (! is_array($payload) || empty($payload['bodyText'])) {
            return collect();
        }

        $prompt = new FactExtractionPrompt(
            pageUrl: (string) ($payload['url'] ?? $observation->source_identifier),
            pageTitle: (string) ($payload['title'] ?? ''),
            bodyText: (string) $payload['bodyText'],
        );

        $response = $this->ai->complete($prompt);
        $data = $this->parser->parse($response);

        /** @var array<int, array{key: string, value: string, data_type: string, confidence: int}> $rawFacts */
        $rawFacts = $data['facts'] ?? [];

        return collect($rawFacts)->map(fn (array $fact): FactData => new FactData(
            key: $fact['key'],
            value: $fact['value'],
            dataType: $fact['data_type'],
            confidence: (int) $fact['confidence'],
        ));
    }
}
