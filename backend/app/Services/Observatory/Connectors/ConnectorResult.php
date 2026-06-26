<?php

namespace App\Services\Observatory\Connectors;

use DateTimeImmutable;

/**
 * Raw data produced by a Connector before it is persisted as an Observation.
 * A single connector sync may produce multiple ConnectorResults — one per
 * source page, feed item, or API record discovered.
 */
readonly class ConnectorResult
{
    public function __construct(
        /** The source type that produced this result, e.g. 'crawl', 'feed'. */
        public string $sourceType,
        /** The canonical identifier for this specific result, e.g. a page URL. */
        public string $sourceIdentifier,
        /** The raw payload to be stored on the Observation. */
        public string $payload,
        public DateTimeImmutable $observedAt,
    ) {}
}
