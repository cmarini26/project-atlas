<?php

namespace App\AI\Eval;

use InvalidArgumentException;

/**
 * One fact-extraction evaluation case: a synthetic page and the facts a
 * competent extractor should produce from it. Cases are plain JSON in
 * `eval/fact-extraction/cases/` — synthetic only, never customer content.
 */
readonly class EvalCase
{
    /**
     * @param  list<string>  $expectedKeys  dot-notation fact keys expected
     * @param  array<string, string>  $expectedValues  key => substring the value should contain
     */
    public function __construct(
        public string $name,
        public string $url,
        public string $title,
        public string $bodyText,
        public array $expectedKeys,
        public array $expectedValues = [],
        public string $notes = '',
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('Eval case is missing a name.');
        }

        if (trim($this->bodyText) === '') {
            throw new InvalidArgumentException("Eval case [{$this->name}] has empty body_text.");
        }

        if ($this->expectedKeys === []) {
            throw new InvalidArgumentException("Eval case [{$this->name}] lists no expected_keys.");
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            bodyText: (string) ($data['body_text'] ?? ''),
            expectedKeys: array_values(array_map('strval', (array) ($data['expected_keys'] ?? []))),
            expectedValues: array_map('strval', (array) ($data['expected_values'] ?? [])),
            notes: (string) ($data['notes'] ?? ''),
        );
    }
}
