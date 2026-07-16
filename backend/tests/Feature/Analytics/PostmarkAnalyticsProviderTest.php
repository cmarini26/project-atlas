<?php

namespace Tests\Feature\Analytics;

use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\Execution;
use App\Services\Analytics\PostmarkAnalyticsProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostmarkAnalyticsProviderTest extends TestCase
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
            'channel_type' => 'email',
            'provider_type' => 'postmark',
            'credentials' => 'test-server-token',
            'status' => 'active',
        ]);
    }

    /** @param  list<Response>  $responses */
    private function makeProvider(array $responses): PostmarkAnalyticsProvider
    {
        $stack = HandlerStack::create(new MockHandler($responses));

        return new PostmarkAnalyticsProvider(new Client(['handler' => $stack]));
    }

    public function test_supports_only_postmark(): void
    {
        $provider = new PostmarkAnalyticsProvider();

        $this->assertTrue($provider->supports('postmark'));
        $this->assertFalse($provider->supports('meta'));
        $this->assertFalse($provider->supports('log'));
    }

    public function test_normalize_counts_delivered_and_opened_events(): void
    {
        $provider = new PostmarkAnalyticsProvider();

        $raw = ['MessageEvents' => [
            ['Type' => 'Delivered'],
            ['Type' => 'Opened'],
            ['Type' => 'Click'],
            ['Type' => 'Click'],
        ]];

        $normalized = $provider->normalize($raw);

        $this->assertSame(1, $normalized['delivered']);
        $this->assertSame(1.0, $normalized['open_rate']);
        $this->assertSame(2, $normalized['normalised_clicks']);
        $this->assertSame(0, $normalized['bounces_hard']);
    }

    public function test_normalize_emits_the_canonical_cross_channel_keys(): void
    {
        // Regression: CampaignKpiService::aggregate() reads
        // normalised_reach/normalised_engagement/normalised_clicks from
        // every provider's normalize() output. Postmark previously omitted
        // normalised_reach and normalised_engagement entirely, so a real
        // Postmark send silently aggregated as zero reach/engagement.
        $provider = new PostmarkAnalyticsProvider();

        $raw = ['MessageEvents' => [
            ['Type' => 'Delivered'],
            ['Type' => 'Opened'],
            ['Type' => 'Click'],
            ['Type' => 'Click'],
        ]];

        $normalized = $provider->normalize($raw);

        $this->assertSame(1, $normalized['normalised_reach'], 'normalised_reach should mirror delivered');
        $this->assertSame(3, $normalized['normalised_engagement'], 'normalised_engagement should sum opens + clicks (1 open + 2 clicks)');
        $this->assertSame(2, $normalized['normalised_clicks']);
    }

    public function test_normalize_canonical_keys_are_zero_without_delivery_or_engagement(): void
    {
        $provider = new PostmarkAnalyticsProvider();

        $normalized = $provider->normalize(['MessageEvents' => [
            ['Type' => 'Bounced', 'Details' => ['Type' => 'HardBounce']],
        ]]);

        $this->assertSame(0, $normalized['normalised_reach']);
        $this->assertSame(0, $normalized['normalised_engagement']);
    }

    public function test_normalize_counts_hard_bounces_and_spam_complaints(): void
    {
        $provider = new PostmarkAnalyticsProvider();

        $raw = ['MessageEvents' => [
            ['Type' => 'Delivered'],
            ['Type' => 'Bounced', 'Details' => ['Type' => 'HardBounce']],
            ['Type' => 'Bounced', 'Details' => ['Type' => 'SoftBounce']],
            ['Type' => 'SpamComplaint'],
        ]];

        $normalized = $provider->normalize($raw);

        $this->assertSame(1, $normalized['bounces_hard']);
        $this->assertSame(1, $normalized['spam_complaints']);
    }

    public function test_normalize_counts_unsubscribes_from_suppressed_subscription_changes(): void
    {
        $provider = new PostmarkAnalyticsProvider();

        $raw = ['MessageEvents' => [
            ['Type' => 'SubscriptionChange', 'Details' => ['SuppressSending' => true]],
            ['Type' => 'SubscriptionChange', 'Details' => ['SuppressSending' => false]],
        ]];

        $normalized = $provider->normalize($raw);

        $this->assertSame(1, $normalized['unsubscribes']);
    }

    public function test_normalize_open_rate_is_zero_without_an_open_event(): void
    {
        $provider = new PostmarkAnalyticsProvider();

        $normalized = $provider->normalize(['MessageEvents' => [['Type' => 'Delivered']]]);

        $this->assertSame(0.0, $normalized['open_rate']);
    }

    public function test_normalize_returns_empty_array_with_no_events(): void
    {
        $provider = new PostmarkAnalyticsProvider();

        $this->assertSame([], $provider->normalize([]));
    }

    public function test_pull_returns_the_decoded_response(): void
    {
        $provider = $this->makeProvider([
            new Response(200, [], json_encode(['MessageEvents' => [['Type' => 'Delivered']]])),
        ]);

        $result = $provider->pull('msg-123', $this->makeCredentials());

        $this->assertSame('Delivered', $result['MessageEvents'][0]['Type']);
    }

    public function test_window_is_closed_7_days_after_completion(): void
    {
        $provider = new PostmarkAnalyticsProvider();

        $stillOpen = new Execution(['completed_at' => now()->subDays(6)]);
        $closed = new Execution(['completed_at' => now()->subDays(8)]);

        $this->assertFalse($provider->isWindowClosed($stillOpen));
        $this->assertTrue($provider->isWindowClosed($closed));
    }
}
