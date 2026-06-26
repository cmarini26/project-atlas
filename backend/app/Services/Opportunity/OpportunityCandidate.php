<?php

namespace App\Services\Opportunity;

readonly class OpportunityCandidate
{
    public function __construct(
        public string $type,
        public ?string $subjectType,
        public ?string $subjectId,
        public string $title,
        public string $description,
        public ?string $expiresAt,
        public int $relevanceScore = 70,
        public int $timingScore = 70,
        public int $confidenceScore = 70,
        public int $urgencyScore = 40,
        public bool $aiDetected = false,
    ) {}
}
