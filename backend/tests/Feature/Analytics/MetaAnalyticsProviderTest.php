<?php

namespace Tests\Feature\Analytics;

use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\Execution;
use App\Services\Analytics\MetaAnalyticsProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaAnalyticsProviderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'CBB Auctions', 'slug' => 'cbb-auctions']);
    }

    private function makeCredentials(): ChannelCredentials
    {
        return ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'channel_type' => 'instagram',
            'provider_type' => 'meta',
            'credentials' => 'test-page-token',
            'status' => 'active',
        ]);
    }

    /** @param  list<Response>  $responses */
    private function makeProvider(array $responses): MetaAnalyticsProvider
    {
        $stack = HandlerStack::create(new MockHandler($responses));

        return new MetaAnalyticsProvider(new Client(['handler' => $stack]));
    }

    public function test_supports_only_meta(): void
    {
        $provider = new MetaAnalyticsProvider();

        $this->assertTrue($provider->supports('meta'));
        $this->assertFalse($provider->supports('postmark'));
        $this->assertFalse($provider->supports('log'));
    }

    public function test_pull_returns_the_decoded_response(): void
    {
        $provider = $this->makeProvider([
            new Response(200, [], json_encode(['data' => [['name' => 'reach', 'values' => [['value' => 500]]]]])),
        ]);

        $result = $provider->pull('post-123', $this->makeCredentials());

        $this->assertSame(500, $result['data'][0]['values'][0]['value']);
    }

    public function test_normalize_maps_reach_engagement_and_clicks(): void
    {
        $provider = new MetaAnalyticsProvider();

        $raw = [
            'data' => [
                ['name' => 'reach', 'values' => [['value' => 1200]]],
                ['name' => 'engagement', 'values' => [['value' => 340]]],
                ['name' => 'clicks', 'values' => [['value' => 45]]],
                ['name' => 'impressions', 'values' => [['value' => 5000]]],
            ],
        ];

        $normalized = $provider->normalize($raw);

        $this->assertSame(1200, $normalized['normalised_reach']);
        $this->assertSame(340, $normalized['normalised_engagement']);
        $this->assertSame(45, $normalized['normalised_clicks']);
        $this->assertArrayNotHasKey('impressions', $normalized);
    }

    public function test_normalize_omits_metrics_meta_did_not_return(): void
    {
        $provider = new MetaAnalyticsProvider();

        $normalized = $provider->normalize(['data' => [['name' => 'reach', 'values' => [['value' => 100]]]]]);

        $this->assertArrayHasKey('normalised_reach', $normalized);
        $this->assertArrayNotHasKey('normalised_engagement', $normalized);
        $this->assertArrayNotHasKey('normalised_clicks', $normalized);
    }

    public function test_window_is_closed_28_days_after_completion(): void
    {
        $provider = new MetaAnalyticsProvider();

        $stillOpen = new Execution(['completed_at' => now()->subDays(27)]);
        $closed = new Execution(['completed_at' => now()->subDays(29)]);

        $this->assertFalse($provider->isWindowClosed($stillOpen));
        $this->assertTrue($provider->isWindowClosed($closed));
    }

    public function test_window_is_not_closed_when_never_completed(): void
    {
        $provider = new MetaAnalyticsProvider();

        $this->assertFalse($provider->isWindowClosed(new Execution()));
    }

    public function test_repolling_schedule_follows_elapsed_time(): void
    {
        $provider = new MetaAnalyticsProvider();

        $this->assertSame(12, $provider->repollingIntervalHours(new Execution(['completed_at' => now()->subHours(10)])));
        $this->assertSame(24, $provider->repollingIntervalHours(new Execution(['completed_at' => now()->subHours(20)])));
        $this->assertSame(48, $provider->repollingIntervalHours(new Execution(['completed_at' => now()->subHours(50)])));
        $this->assertSame(168, $provider->repollingIntervalHours(new Execution(['completed_at' => now()->subHours(100)])));
    }
}
