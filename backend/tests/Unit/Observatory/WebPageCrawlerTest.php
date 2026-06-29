<?php

namespace Tests\Unit\Observatory;

use App\Services\Observatory\Connectors\Website\Exceptions\SsrfBlockedException;
use App\Services\Observatory\Connectors\Website\WebPageCrawler;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * Uses IP-based URLs (e.g. https://1.2.3.4) to satisfy SsrfValidator's IP-range
 * checks without triggering DNS resolution. The Guzzle HTTP client is replaced via
 * reflection so no real network connections are made.
 */
class WebPageCrawlerTest extends TestCase
{
    private const TEST_URL = 'https://1.2.3.4';

    public function test_crawl_returns_page_data_for_successful_response(): void
    {
        $html = <<<'HTML'
            <!DOCTYPE html>
            <html><head><title>Acme Co</title></head>
            <body><h1>Welcome</h1><p>Body text here.</p></body>
            </html>
            HTML;

        $crawler = $this->makeCrawler(new Response(200, ['Content-Type' => 'text/html'], $html));

        $results = $crawler->crawl(self::TEST_URL);

        $this->assertCount(1, $results);
        $this->assertSame('Acme Co', $results->first()->title);
    }

    public function test_crawl_skips_non_html_responses(): void
    {
        $crawler = $this->makeCrawler(new Response(200, ['Content-Type' => 'application/json'], '{}'));

        $results = $crawler->crawl(self::TEST_URL);

        $this->assertCount(0, $results);
    }

    public function test_crawl_silently_skips_http_error_responses(): void
    {
        // 4xx/5xx are RequestException subclasses — silently skipped so the crawl
        // can continue with other pages rather than failing the whole sync.
        $crawler = $this->makeCrawler(new Response(404, ['Content-Type' => 'text/html'], 'Not Found'));

        $results = $crawler->crawl(self::TEST_URL);

        $this->assertCount(0, $results);
    }

    public function test_crawl_propagates_connect_exception(): void
    {
        // ConnectException (TCP timeout / refused) is NOT a RequestException —
        // it propagates so the controller can mark the integration as error.
        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', self::TEST_URL)),
        ]);
        $crawler = $this->makeCrawlerWithMock($mock);

        $this->expectException(ConnectException::class);
        $crawler->crawl(self::TEST_URL);
    }

    public function test_crawl_respects_max_pages_limit(): void
    {
        $html = <<<'HTML'
            <!DOCTYPE html>
            <html><head><title>Page</title></head>
            <body>
              <a href="/page2">link</a>
              <a href="/page3">link</a>
            </body></html>
            HTML;

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'text/html'], $html),
        ]);

        $crawler = new WebPageCrawler(maxPages: 1, connectTimeout: 5);
        $this->injectHttpClient($crawler, $mock);

        $results = $crawler->crawl(self::TEST_URL);

        $this->assertCount(1, $results);
    }

    public function test_crawl_blocks_ssrf_private_ip(): void
    {
        $this->expectException(SsrfBlockedException::class);

        $crawler = new WebPageCrawler(maxPages: 1, connectTimeout: 1);
        $crawler->crawl('http://169.254.169.254/latest/meta-data');
    }

    public function test_crawl_blocks_loopback(): void
    {
        $this->expectException(SsrfBlockedException::class);

        $crawler = new WebPageCrawler(maxPages: 1, connectTimeout: 1);
        $crawler->crawl('http://127.0.0.1/');
    }

    public function test_connect_timeout_param_is_accepted(): void
    {
        $crawler = new WebPageCrawler(maxPages: 1, connectTimeout: 1);

        $this->assertInstanceOf(WebPageCrawler::class, $crawler);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeCrawler(Response $response): WebPageCrawler
    {
        return $this->makeCrawlerWithMock(new MockHandler([$response]));
    }

    private function makeCrawlerWithMock(MockHandler $mock): WebPageCrawler
    {
        $crawler = new WebPageCrawler(maxPages: 1, connectTimeout: 5);
        $this->injectHttpClient($crawler, $mock);

        return $crawler;
    }

    private function injectHttpClient(WebPageCrawler $crawler, MockHandler $mock): void
    {
        $stack = HandlerStack::create($mock);
        $client = new Client(['handler' => $stack]);

        $ref = new \ReflectionProperty(WebPageCrawler::class, 'http');
        $ref->setAccessible(true);
        $ref->setValue($crawler, $client);
    }
}
