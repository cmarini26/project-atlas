<?php

namespace App\AI;

use InvalidArgumentException;
use JsonException;

class StructuredResponseParser
{
    /**
     * Parse a structured JSON response from an AiResponse.
     * Strips markdown code fences if present.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the response is not valid JSON or does not decode to an array
     */
    public function parse(AiResponse $response): array
    {
        $content = trim($response->content);

        // Strip markdown code fences (```json ... ``` or ``` ... ```)
        if (str_starts_with($content, '```')) {
            $content = (string) preg_replace('/^```[a-z]*\n?/m', '', $content);
            $content = str_replace('```', '', $content);
            $content = trim($content);
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                'AI response is not valid JSON: '.$e->getMessage(),
                previous: $e,
            );
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('AI response did not decode to an array.');
        }

        return $data;
    }
}
