<?php

namespace App\Services\Analyst;

use App\AI\Contracts\AiProvider;
use App\AI\Prompts\FactExtractionPrompt;
use App\AI\StructuredResponseParser;
use App\Models\Observation;
use App\Services\Analyst\Contracts\Analyst;
use App\Services\Analyst\Exceptions\FactExtractionFailedException;
use App\Services\Brain\Data\FactData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

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

        // Raw response logging for local/debug troubleshooting only.
        if (config('app.debug')) {
            Log::debug('WebsiteAnalyst: raw AI response content.', [
                'observation_id' => $observation->id,
                'model' => $response->model,
                'stop_reason' => $response->stopReason,
                'content' => $response->content,
            ]);
        }

        try {
            $data = $this->parser->parse($response);
        } catch (InvalidArgumentException $e) {
            throw new FactExtractionFailedException(
                "AI returned unparseable output for observation {$observation->id}: {$e->getMessage()}",
                previous: $e,
            );
        }

        if (! isset($data['facts']) || ! is_array($data['facts'])) {
            throw new FactExtractionFailedException(
                "AI response for observation {$observation->id} is missing the required 'facts' array. "
                .'Got keys: ['.implode(', ', array_keys($data)).']',
            );
        }

        $facts = collect($data['facts'])
            ->filter(function (mixed $fact) use ($observation): bool {
                $valid = is_array($fact)
                    && isset($fact['key'], $fact['value'], $fact['data_type'], $fact['confidence'])
                    && is_string($fact['key'])
                    && is_scalar($fact['value']);

                if (! $valid) {
                    Log::warning('WebsiteAnalyst: skipping malformed fact entry.', [
                        'observation_id' => $observation->id,
                        'entry' => $fact,
                    ]);
                }

                return $valid;
            })
            ->values()
            ->map(fn (array $fact): FactData => new FactData(
                key: $fact['key'],
                value: (string) $fact['value'],
                dataType: (string) $fact['data_type'],
                confidence: (int) $fact['confidence'],
            ));

        // The page had analyzable content, so zero facts means the analysis failed —
        // marking the observation processed here would leave onboarding spinning
        // with nothing to show. Fail so the UI can surface a clear AI error.
        if ($facts->isEmpty()) {
            throw new FactExtractionFailedException(
                "AI analysis produced zero usable facts for observation {$observation->id} "
                .'despite the page having body text.',
            );
        }

        Log::info('WebsiteAnalyst: fact extraction complete.', [
            'observation_id' => $observation->id,
            'fact_count' => $facts->count(),
        ]);

        return $facts;
    }
}
