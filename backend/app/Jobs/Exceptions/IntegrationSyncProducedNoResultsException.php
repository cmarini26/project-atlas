<?php

namespace App\Jobs\Exceptions;

use RuntimeException;

/**
 * Individual page fetch failures (4xx/5xx, unreachable) are swallowed
 * silently by WebPageCrawler so a single broken link doesn't fail an
 * entire multi-page crawl (see WebPageCrawlerTest). But when a connector
 * sync yields zero results altogether — e.g. the site's own start URL was
 * rate-limited (429) or unreachable — SyncIntegration must not mark itself
 * successful; a "successful" sync that recorded nothing produces no Facts,
 * no Opportunities, and no Recommendation, forever, with no visible error.
 */
class IntegrationSyncProducedNoResultsException extends RuntimeException
{
    public static function create(): self
    {
        // User-facing: this message is surfaced as-is on the Settings page
        // (Integration.last_error), so it must read as an explanation to a
        // business owner, not a log line — no internal IDs.
        return new self('Atlas could not read any content from this source during the last sync. The site may be temporarily unreachable or rate-limiting automated requests — try again later.');
    }
}
