<?php

namespace App\Services\Observatory\Connectors\Website;

use App\Models\Integration;
use App\Services\Observatory\Connectors\ConnectorResult;
use App\Services\Observatory\Connectors\Contracts\Connector;
use Illuminate\Support\Collection;

class WebsiteConnector implements Connector
{
    public function __construct(private readonly WebPageCrawler $crawler) {}

    public function supports(Integration $integration): bool
    {
        return $integration->type === 'website_crawl';
    }

    /**
     * @return Collection<int, ConnectorResult>
     */
    public function sync(Integration $integration): Collection
    {
        $url = $integration->config['url'] ?? '';

        return $this->crawler
            ->crawl($url)
            ->map(fn (WebPageData $page) => new ConnectorResult(
                sourceType: 'crawl',
                sourceIdentifier: $page->url,
                payload: json_encode($page->toArray(), JSON_THROW_ON_ERROR),
                observedAt: $page->crawledAt,
            ));
    }
}
