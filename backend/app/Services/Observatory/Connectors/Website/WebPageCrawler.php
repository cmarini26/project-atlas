<?php

namespace App\Services\Observatory\Connectors\Website;

use App\Services\Observatory\Connectors\Website\Exceptions\SsrfBlockedException;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Collection;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

class WebPageCrawler
{
    private Client $http;

    private SsrfValidator $ssrfValidator;

    public function __construct(
        private readonly int $maxPages = 20,
        private readonly int $maxDepth = 3,
        private readonly int $requestTimeout = 15,
        private readonly int $connectTimeout = 5,
        ?SsrfValidator $ssrfValidator = null,
    ) {
        $this->http = new Client([
            'timeout' => $this->requestTimeout,
            'connect_timeout' => $this->connectTimeout,
            'headers' => [
                'User-Agent' => 'AtlasBot/1.0 (+https://atlas.app)',
                'Accept' => 'text/html,application/xhtml+xml',
            ],
            'allow_redirects' => [
                'max' => 5,
                'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                    // Validate each redirect destination to prevent SSRF via redirect.
                    $this->ssrfValidator->validate((string) $uri);
                },
            ],
            'verify' => true,
        ]);

        $this->ssrfValidator = $ssrfValidator ?? new SsrfValidator();
    }

    /**
     * Crawl the site starting at $startUrl.
     *
     * @return Collection<int, WebPageData>
     *
     * @throws SsrfBlockedException if the start URL resolves to a blocked address
     */
    public function crawl(string $startUrl): Collection
    {
        $startUrl = rtrim($startUrl, '/');

        // Validate before making any outbound connection.
        $this->ssrfValidator->validate($startUrl);

        $baseDomain = $this->extractDomain($startUrl);

        /** @var array<string, true> $visited */
        $visited = [];
        /** @var array<int, array{url: string, depth: int}> $queue */
        $queue = [['url' => $startUrl, 'depth' => 0]];
        $results = collect();

        while (! empty($queue) && $results->count() < $this->maxPages) {
            $item = array_shift($queue);
            $url = $item['url'];
            $depth = $item['depth'];

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;

            [$page, $links] = $this->fetchAndParse($url, $baseDomain);

            if ($page === null) {
                continue;
            }

            $results->push($page);

            if ($depth < $this->maxDepth) {
                foreach ($links as $link) {
                    if (! isset($visited[$link])) {
                        $queue[] = ['url' => $link, 'depth' => $depth + 1];
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Fetch a page once and return both the parsed WebPageData and internal links.
     *
     * @return array{0: ?WebPageData, 1: string[]}
     */
    private function fetchAndParse(string $url, string $baseDomain): array
    {
        try {
            $response = $this->http->get($url);
        } catch (RequestException) {
            return [null, []];
        }

        $statusCode = $response->getStatusCode();
        $contentType = $response->getHeaderLine('Content-Type');

        if (! str_contains($contentType, 'text/html')) {
            return [null, []];
        }

        $html = (string) $response->getBody();

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $links = $this->extractInternalLinks($xpath, $url, $baseDomain);
        $page = $this->parseDom($url, $statusCode, $xpath);

        return [$page, $links];
    }

    private function parseDom(string $url, int $statusCode, DOMXPath $xpath): WebPageData
    {
        return new WebPageData(
            url: $url,
            statusCode: $statusCode,
            title: $this->extractTitle($xpath),
            metaDescription: $this->extractMetaDescription($xpath),
            headings: $this->extractHeadings($xpath),
            bodyText: $this->extractBodyText($xpath),
            crawledAt: new DateTimeImmutable(),
        );
    }

    private function extractTitle(DOMXPath $xpath): string
    {
        $nodes = $xpath->query('//title');

        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        $node = $nodes->item(0);

        return $node instanceof DOMNode ? trim($node->textContent) : '';
    }

    private function extractMetaDescription(DOMXPath $xpath): string
    {
        $nodes = $xpath->query('//meta[@name="description"]/@content');

        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        $node = $nodes->item(0);

        return $node instanceof DOMNode ? trim($node->textContent) : '';
    }

    /**
     * @return array<string, string[]>
     */
    private function extractHeadings(DOMXPath $xpath): array
    {
        $headings = ['h1' => [], 'h2' => [], 'h3' => []];

        foreach (array_keys($headings) as $tag) {
            $nodes = $xpath->query("//{$tag}");

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! $node instanceof DOMNode) {
                    continue;
                }

                $text = trim($node->textContent);

                if ($text !== '') {
                    $headings[$tag][] = $text;
                }
            }
        }

        return $headings;
    }

    private function extractBodyText(DOMXPath $xpath): string
    {
        foreach (['script', 'style', 'nav', 'footer', 'header'] as $tag) {
            $nodes = $xpath->query("//{$tag}");

            if ($nodes !== false) {
                foreach (iterator_to_array($nodes) as $node) {
                    if ($node instanceof DOMNode) {
                        $node->parentNode?->removeChild($node);
                    }
                }
            }
        }

        $body = $xpath->query('//body');

        if ($body === false || $body->length === 0) {
            return '';
        }

        $node = $body->item(0);

        if (! $node instanceof DOMNode) {
            return '';
        }

        $text = $node->textContent;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);

        return mb_substr($text, 0, 5000);
    }

    /**
     * @return string[]
     */
    private function extractInternalLinks(DOMXPath $xpath, string $pageUrl, string $baseDomain): array
    {
        $anchors = $xpath->query('//a[@href]');

        if ($anchors === false) {
            return [];
        }

        $base = parse_url($pageUrl);

        if ($base === false) {
            return [];
        }

        $links = [];

        foreach ($anchors as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $href = trim($anchor->getAttribute('href'));

            if (str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }

            $absolute = $this->toAbsoluteUrl($href, $base);

            if ($this->extractDomain($absolute) === $baseDomain) {
                $stripped = strtok($absolute, '?#');

                if ($stripped !== false) {
                    $links[] = rtrim($stripped, '/');
                }
            }
        }

        return array_unique(array_filter($links));
    }

    /**
     * @param  array<string, int|string>  $base
     */
    private function toAbsoluteUrl(string $href, array $base): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            return ($base['scheme'] ?? 'https').':'.$href;
        }

        if (str_starts_with($href, '/')) {
            return ($base['scheme'] ?? 'https').'://'.($base['host'] ?? '').$href;
        }

        $path = rtrim(dirname((string) ($base['path'] ?? '/')), '/').'/'.$href;

        return ($base['scheme'] ?? 'https').'://'.($base['host'] ?? '').$path;
    }

    private function extractDomain(string $url): string
    {
        $parsed = parse_url($url);

        return strtolower((string) ($parsed['host'] ?? ''));
    }
}
