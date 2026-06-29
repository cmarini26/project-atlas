<?php

namespace App\Services\Analyst;

use App\AI\Contracts\AiProvider;
use App\AI\Prompts\FactExtractionPrompt;
use App\AI\StructuredResponseParser;
use App\Models\Observation;
use App\Services\Analyst\Contracts\Analyst;
use App\Services\Brain\Data\FactData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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

        if (! is_array($payload) || empty($payload['body_text'])) {
            Log::warning('WebsiteAnalyst: observation payload missing body_text, skipping fact extraction.', [
                'observation_id' => $observation->id,
                'keys' => is_array($payload) ? array_keys($payload) : [],
            ]);

            return collect();
        }

        Log::info('WebsiteAnalyst: starting fact extraction.', ['observation_id' => $observation->id]);

        $prompt = new FactExtractionPrompt(
            pageUrl: (string) ($payload['url'] ?? $observation->source_identifier),
            pageTitle: (string) ($payload['title'] ?? ''),
            bodyText: (string) $payload['body_text'],
        );

        $response = $this->ai->complete($prompt);
        $data = $this->parser->parse($response);

        /** @var array<int, array{key: string, value: string, data_type: string, confidence: int}> $rawFacts */
        $rawFacts = $data['facts'] ?? [];

        Log::info('WebsiteAnalyst: fact extraction complete.', [
            'observation_id' => $observation->id,
            'fact_count' => count($rawFacts),
        ]);

        return collect($rawFacts)->map(fn (array $fact): FactData => new FactData(
            key: $fact['key'],
            value: $fact['value'],
            dataType: $fact['data_type'],
            confidence: (int) $fact['confidence'],
        ));
    }
}
