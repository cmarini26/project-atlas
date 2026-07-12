<?php

namespace App\Services\Observatory\Connectors\Website;

use DateTimeImmutable;

/**
 * Structured data extracted from a single crawled web page.
 * This is an intermediate value object used by WebPageCrawler before
 * the data is serialised into a ConnectorResult payload.
 */
readonly class WebPageData
{
    /**
     * @param  array<string, string[]>  $headings  Keys: 'h1', 'h2', 'h3'
     * @param  string[]  $images  Absolute image URLs found on the page, og:image first
     */
    public function __construct(
        public string $url,
        public int $statusCode,
        public string $title,
        public string $metaDescription,
        public array $headings,
        public string $bodyText,
        public array $images,
        public DateTimeImmutable $crawledAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'status_code' => $this->statusCode,
            'title' => $this->title,
            'meta_description' => $this->metaDescription,
            'headings' => $this->headings,
            'body_text' => $this->bodyText,
            'images' => $this->images,
            'crawled_at' => $this->crawledAt->format('c'),
        ];
    }
}
