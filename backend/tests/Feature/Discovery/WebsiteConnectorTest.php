<?php

namespace Tests\Feature\Discovery;

use App\Models\Company;
use App\Models\Integration;
use App\Services\Observatory\Connectors\ConnectorResult;
use App\Services\Observatory\Connectors\Website\WebPageCrawler;
use App\Services\Observatory\Connectors\Website\WebPageData;
use App\Services\Observatory\Connectors\Website\WebsiteConnector;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WebsiteConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_maps_crawled_pages_to_connector_results(): void
    {
        $crawledAt = new DateTimeImmutable('2026-06-25T12:00:00Z');

        $pages = collect([
            new WebPageData(
                url: 'https://example.com',
                statusCode: 200,
                title: 'Home',
                metaDescription: 'Welcome',
                headings: ['h1' => ['Home'], 'h2' => [], 'h3' => []],
                bodyText: 'Welcome to our site.',
                crawledAt: $crawledAt,
            ),
            new WebPageData(
                url: 'https://example.com/about',
                statusCode: 200,
                title: 'About',
                metaDescription: '',
                headings: ['h1' => ['About Us'], 'h2' => [], 'h3' => []],
                bodyText: 'Learn about us.',
                crawledAt: $crawledAt,
            ),
        ]);

        $crawler = Mockery::mock(WebPageCrawler::class);
        $crawler->expects('crawl')
            ->once()
            // First-ever sync (last_successful_run_at null) uses the shallow
            // onboarding budget from crawler.max_pages.
            ->with('https://example.com', (int) config('crawler.max_pages', 1))
            ->andReturn($pages);

        $connector = new WebsiteConnector($crawler);

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co',
            'slug' => 'test-co',
        ]);

        $integration = Integration::withoutGlobalScopes()->make([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Site',
            'config' => ['url' => 'https://example.com'],
            'status' => 'active',
        ]);

        $results = $connector->sync($integration);

        $this->assertCount(2, $results);
        $this->assertInstanceOf(ConnectorResult::class, $results->first());
        $this->assertEquals('crawl', $results->first()->sourceType);
        $this->assertEquals('https://example.com', $results->first()->sourceIdentifier);

        $payload = json_decode($results->first()->payload, true);
        $this->assertEquals('Home', $payload['title']);
    }

    public function test_recurring_sync_uses_deeper_crawl_budget(): void
    {
        $crawler = Mockery::mock(WebPageCrawler::class);
        $crawler->expects('crawl')
            ->once()
            // Any sync after the first crawls deeper so the Business Brain
            // keeps learning beyond the home page.
            ->with('https://example.com', (int) config('crawler.recurring_max_pages', 10))
            ->andReturn(collect());

        $connector = new WebsiteConnector($crawler);

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co',
            'slug' => 'test-co',
        ]);

        $integration = Integration::withoutGlobalScopes()->make([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Site',
            'config' => ['url' => 'https://example.com'],
            'status' => 'active',
            'last_successful_run_at' => now()->subDay(),
        ]);

        $this->assertCount(0, $connector->sync($integration));
    }

    public function test_supports_only_website_crawl_integrations(): void
    {
        $connector = new WebsiteConnector(new WebPageCrawler());

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co',
            'slug' => 'test-co',
        ]);

        $websiteCrawl = Integration::withoutGlobalScopes()->make([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Site',
            'config' => ['url' => 'https://example.com'],
            'status' => 'active',
        ]);

        $rssFeed = Integration::withoutGlobalScopes()->make([
            'company_id' => $company->id,
            'type' => 'rss_feed',
            'name' => 'Feed',
            'config' => ['url' => 'https://example.com/feed'],
            'status' => 'active',
        ]);

        $this->assertTrue($connector->supports($websiteCrawl));
        $this->assertFalse($connector->supports($rssFeed));
    }
}
