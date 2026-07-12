<?php

namespace Tests\Feature\Publishing\Meta;

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
use App\Services\Publishing\Exceptions\ContentPolicyViolationException;
use App\Services\Publishing\Exceptions\PublishingException;
use App\Services\Publishing\MetaChannelPublisher;
use App\Services\Publishing\MetaMediaUploader;
use App\Services\Publishing\MetaRenderer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MetaChannelPublisherTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::withoutGlobalScopes()->create(['name' => 'CBB Auctions', 'slug' => 'cbb-auctions']);

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
    }

    /** @param  list<Response>  $responses */
    private function makePublisher(array $responses, ?Client $pingHttp = null): MetaChannelPublisher
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $uploader = new MetaMediaUploader(new Client(['handler' => $stack]));

        $renderers = new ChannelRendererRegistry();
        $renderers->register(new MetaRenderer());

        return new MetaChannelPublisher($renderers, new ChannelCredentialsRepository(), $uploader, $pingHttp);
    }

    private function makeChannel(string $type = 'instagram'): Channel
    {
        return Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => $type, 'name' => 'CBB Auctions', 'is_active' => true,
        ]);
    }

    private function makeCredentials(Channel $channel, string $targetId = 'ig-account-1'): ChannelCredentials
    {
        return ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'channel_type' => $channel->type,
            'provider_type' => 'meta',
            'credentials' => json_encode(['access_token' => 'page-token', 'target_id' => $targetId]),
            'status' => 'active',
        ]);
    }

    private function makeCampaign(Channel $channel): Campaign
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
            'channel_ids' => [$channel->id],
            'rationale' => ['why_now' => 'Now.'],
            'expected_impact' => ['summary' => 'Lift'],
            'confidence_score' => 70,
            'status' => 'recommended',
            'decided_at' => now(),
        ]);

        return Campaign::withoutGlobalScopes()->create([
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
    }

    private function makeExecution(Channel $channel): Execution
    {
        $campaign = $this->makeCampaign($channel);

        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $campaign->id,
            'channel_id' => $channel->id,
            'type' => 'social_post',
            'body' => 'Ending soon: Amazing Fantasy #15.',
            'media' => [['url' => 'https://cdn.example.com/photo.jpg']],
            'status' => 'approved',
        ]);

        return Execution::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $channel->id,
            'status' => 'executing',
            'idempotency_key' => (string) Str::ulid(),
        ]);
    }

    public function test_supports_instagram_and_facebook_only(): void
    {
        $publisher = $this->makePublisher([]);

        $this->assertTrue($publisher->supports('instagram'));
        $this->assertTrue($publisher->supports('facebook'));
        $this->assertFalse($publisher->supports('email'));
    }

    public function test_publish_to_instagram_uses_the_two_step_container_flow(): void
    {
        $channel = $this->makeChannel('instagram');
        $this->makeCredentials($channel);
        $execution = $this->makeExecution($channel);

        $publisher = $this->makePublisher([
            new Response(200, [], json_encode(['id' => 'creation-container-1'])),
            new Response(200, [], json_encode(['id' => 'ig-post-1'])),
        ]);

        $result = $publisher->publish($execution);

        $this->assertSame('ig-post-1', $result->platformId);
        $this->assertSame('meta', $result->metadata['publisher']);
        $this->assertSame('instagram', $result->metadata['channel_type']);
    }

    public function test_publish_to_facebook_uses_the_single_step_photo_endpoint(): void
    {
        $channel = $this->makeChannel('facebook');
        $this->makeCredentials($channel, 'page-1');
        $execution = $this->makeExecution($channel);

        $publisher = $this->makePublisher([
            new Response(200, [], json_encode(['id' => 'fb-post-1'])),
        ]);

        $result = $publisher->publish($execution);

        $this->assertSame('fb-post-1', $result->platformId);
    }

    public function test_publish_throws_publishing_exception_when_upload_fails(): void
    {
        $channel = $this->makeChannel('instagram');
        $this->makeCredentials($channel);
        $execution = $this->makeExecution($channel);

        $publisher = $this->makePublisher([
            new Response(500, [], json_encode(['error' => ['message' => 'Internal error', 'code' => 1]])),
        ]);

        $this->expectException(PublishingException::class);

        $publisher->publish($execution);
    }

    public function test_publish_throws_content_policy_exception_on_a_policy_rejection(): void
    {
        $channel = $this->makeChannel('instagram');
        $this->makeCredentials($channel);
        $execution = $this->makeExecution($channel);

        $publisher = $this->makePublisher([
            new Response(400, [], json_encode(['error' => ['message' => 'Content violates policy', 'code' => 9004]])),
        ]);

        $this->expectException(ContentPolicyViolationException::class);

        $publisher->publish($execution);
    }

    public function test_ping_succeeds_when_the_token_is_valid(): void
    {
        $channel = $this->makeChannel('facebook');
        $credentials = $this->makeCredentials($channel, 'page-1');

        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['id' => 'page-1', 'name' => 'CBB Auctions'])),
        ]));
        $publisher = $this->makePublisher([], new Client(['handler' => $stack]));

        $result = $publisher->ping($credentials);

        $this->assertTrue($result->reachable);
    }

    public function test_ping_fails_when_the_token_is_invalid(): void
    {
        $channel = $this->makeChannel('facebook');
        $credentials = $this->makeCredentials($channel, 'page-1');

        $stack = HandlerStack::create(new MockHandler([
            new Response(401, [], json_encode(['error' => ['message' => 'Invalid token']])),
        ]));
        $publisher = $this->makePublisher([], new Client(['handler' => $stack]));

        $result = $publisher->ping($credentials);

        $this->assertFalse($result->reachable);
        $this->assertNotNull($result->error);
    }
}
