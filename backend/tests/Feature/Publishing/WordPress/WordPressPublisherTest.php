<?php

namespace Tests\Feature\Publishing\WordPress;

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Execution;
use App\Models\Opportunity;
use App\Services\Publishing\ChannelCredentialsRepository;
use App\Services\Publishing\ChannelRendererRegistry;
use App\Services\Publishing\Exceptions\PublishingException;
use App\Services\Publishing\WordPressMediaUploader;
use App\Services\Publishing\WordPressPublisher;
use App\Services\Publishing\WordPressRenderer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WordPressPublisherTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::withoutGlobalScopes()->create(['name' => 'CBB Auctions', 'slug' => 'cbb-auctions']);

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);

        $this->channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'blog',
            'name' => 'CBB Blog',
            'config' => ['site_url' => 'https://blog.cbb-auctions.example'],
            'is_active' => true,
        ]);

        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'channel_type' => 'blog',
            'provider_type' => 'wordpress',
            'credentials' => json_encode(['username' => 'atlas', 'app_password' => 'xxxx xxxx xxxx']),
            'status' => 'active',
        ]);
    }

    /**
     * @param  list<Response>  $postResponses
     * @param  list<Response>  $uploadResponses
     */
    private function makePublisher(array $postResponses, array $uploadResponses = []): WordPressPublisher
    {
        $postStack = HandlerStack::create(new MockHandler($postResponses));
        $uploadStack = HandlerStack::create(new MockHandler($uploadResponses));

        $renderers = new ChannelRendererRegistry();
        $renderers->register(new WordPressRenderer());

        $uploader = new WordPressMediaUploader(new Client(['handler' => $uploadStack]));

        return new WordPressPublisher(
            $renderers,
            new ChannelCredentialsRepository(),
            $uploader,
            new Client(['handler' => $postStack]),
        );
    }

    private function makeExecution(?array $media = [['url' => 'https://cdn.example.com/photo.jpg']]): Execution
    {
        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'subject_type' => 'catalog_item',
            'type' => 'featured_item',
            'title' => 'Silver Age',
            'description' => 'Desc',
            'relevance_score' => 80,
            'timing_score' => 75,
            'confidence_score' => 70,
            'urgency_score' => 65,
            'composite_score' => 73,
            'status' => 'selected',
            'detected_at' => now(),
        ]);

        $decision = Decision::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'opportunity_id' => $opportunity->id,
            'campaign_type' => 'featured_item',
            'channel_ids' => [$this->channel->id],
            'rationale' => ['why_now' => 'Now.'],
            'expected_impact' => ['summary' => 'Lift'],
            'confidence_score' => 70,
            'status' => 'recommended',
            'decided_at' => now(),
        ]);

        $campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'decision_id' => $decision->id,
            'campaign_type' => 'featured_item',
            'title' => 'Test Campaign',
            'blueprint' => ['goal' => 'conversion'],
            'blueprint_version' => '1.0',
            'prompt_version' => '1.0',
            'expected_asset_count' => 1,
            'generated_asset_count' => 1,
            'status' => 'approved',
        ]);

        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $campaign->id,
            'channel_id' => $this->channel->id,
            'type' => 'blog_post',
            'title' => 'Why Silver Age Comics Are Booming',
            'body' => 'A great post.',
            'media' => $media,
            'status' => 'approved',
        ]);

        return Execution::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $this->channel->id,
            'status' => 'executing',
            'idempotency_key' => (string) Str::ulid(),
        ]);
    }

    public function test_supports_blog_only(): void
    {
        $publisher = $this->makePublisher([]);

        $this->assertTrue($publisher->supports('blog'));
        $this->assertFalse($publisher->supports('email'));
    }

    public function test_publish_uploads_featured_image_and_creates_the_post(): void
    {
        $execution = $this->makeExecution();

        $publisher = $this->makePublisher(
            postResponses: [new Response(201, [], json_encode(['id' => 501, 'link' => 'https://blog.cbb-auctions.example/?p=501']))],
            uploadResponses: [
                new Response(200, ['Content-Type' => 'image/jpeg'], 'fake-bytes'),
                new Response(201, [], json_encode(['id' => 99])),
            ],
        );

        $result = $publisher->publish($execution);

        $this->assertSame('501', $result->platformId);
        $this->assertSame('https://blog.cbb-auctions.example/?p=501', $result->url);
        $this->assertSame('wordpress', $result->metadata['publisher']);
    }

    public function test_publish_succeeds_without_a_featured_image(): void
    {
        $execution = $this->makeExecution(media: null);

        $publisher = $this->makePublisher(
            postResponses: [new Response(201, [], json_encode(['id' => 502]))],
        );

        $result = $publisher->publish($execution);

        $this->assertSame('502', $result->platformId);
    }

    public function test_publish_succeeds_when_the_featured_image_upload_fails(): void
    {
        $execution = $this->makeExecution();

        $publisher = $this->makePublisher(
            postResponses: [new Response(201, [], json_encode(['id' => 503]))],
            uploadResponses: [new Response(500, [], 'error')],
        );

        $result = $publisher->publish($execution);

        $this->assertSame('503', $result->platformId);
    }

    public function test_publish_throws_when_the_post_request_fails(): void
    {
        $execution = $this->makeExecution(media: null);

        $publisher = $this->makePublisher(
            postResponses: [new Response(500, [], 'Internal error')],
        );

        $this->expectException(PublishingException::class);

        $publisher->publish($execution);
    }

    public function test_ping_succeeds_when_credentials_are_valid(): void
    {
        $publisher = $this->makePublisher([
            new Response(200, [], json_encode(['id' => 1, 'name' => 'atlas'])),
        ]);

        $credentials = ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('channel_type', 'blog')
            ->firstOrFail();

        $result = $publisher->ping($credentials);

        $this->assertTrue($result->reachable);
    }

    public function test_ping_fails_when_credentials_are_invalid(): void
    {
        $publisher = $this->makePublisher([
            new Response(401, [], json_encode(['message' => 'Invalid application password'])),
        ]);

        $credentials = ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('channel_type', 'blog')
            ->firstOrFail();

        $result = $publisher->ping($credentials);

        $this->assertFalse($result->reachable);
        $this->assertNotNull($result->error);
    }
}
