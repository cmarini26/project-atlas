<?php

namespace App\Domain\MarketingHealth\ValueObjects;

/**
 * A single piece of evidence backing a dimension score — see
 * docs/specs/Marketing-Health.md §5.2. Every evidence entry traces back to
 * a real, stored record, never an opaque or synthesized claim.
 */
readonly class MarketingHealthEvidence
{
    public function __construct(
        public string $label,
        public string $sourceType,
        public ?string $sourceId,
        public mixed $value,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'value' => $this->value,
        ];
    }
}
