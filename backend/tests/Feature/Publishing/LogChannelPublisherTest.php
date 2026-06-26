<?php

namespace Tests\Feature\Publishing;

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Decision;
use App\Models\DigitalTwin;
use App\Models\Execution;
use App\Models\Opportunity;
use App\Services\Publishing\LogChannelPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class LogChannelPublisherTest extends TestCase
{
    use RefreshDatabase;

    private LogChannelPublisher $publisher;

    private Company $company;

    private Channel $channel;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publisher = $this->app->make(LogChannelPublisher::class);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions', 'slug' => 'cbb', 'industry' => 'auction',
        ]);

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);

        $this->channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'email', 'name' => 'Email', 'is_active' => true,
        ]);

        $opportunity = Opportunity::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'subject_type' => 'catalog_item',
            'type' => 'featured_item',
            'title' => 'Test',
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

        $this->campaign = Campaign::withoutGlobalScopes()->create([
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

    private function makeExecution(): Execution
    {
        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'channel_id' => $this->channel->id,
            'type' => 'email',
            'title' => 'Test email subject line',
            'body' => 'This is the email body content for testing.',
            'status' => 'scheduled',
        ]);

        return Execution::create([
            'company_id' => $this->company->id,
            'campaign_id' => $this->campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $this->channel->id,
            'status' => 'executing',
            'idempotency_key' => Str::ulid()->toString(),
        ]);
    }

    public function test_publish_writes_to_publishing_log_channel(): void
    {
        $channelLogger = \Mockery::mock(LoggerInterface::class);
        $channelLogger->shouldReceive('info')
            ->once()
            ->with('LogChannelPublisher: simulating publish', \Mockery::type('array'));

        Log::shouldReceive('channel')
            ->with('publishing')
            ->once()
            ->andReturn($channelLogger);

        $execution = $this->makeExecution();
        $this->publisher->publish($execution);
    }

    public function test_publish_returns_execution_result_with_log_prefix(): void
    {
        $channelLogger = \Mockery::mock(LoggerInterface::class);
        $channelLogger->shouldReceive('info')->once();
        Log::shouldReceive('channel')->with('publishing')->andReturn($channelLogger);

        $execution = $this->makeExecution();
        $result = $this->publisher->publish($execution);

        $this->assertStringStartsWith('log-', $result->platformId);
        $this->assertEquals('log', $result->metadata['publisher']);
    }

    public function test_publish_result_has_published_at(): void
    {
        $channelLogger = \Mockery::mock(LoggerInterface::class);
        $channelLogger->shouldReceive('info')->once();
        Log::shouldReceive('channel')->with('publishing')->andReturn($channelLogger);

        $execution = $this->makeExecution();
        $result = $this->publisher->publish($execution);

        $this->assertInstanceOf(\DateTimeImmutable::class, $result->publishedAt);
    }

    public function test_supports_all_eight_channel_types(): void
    {
        $channels = ['facebook', 'instagram', 'linkedin', 'x', 'email', 'sms', 'blog', 'landing_page'];

        foreach ($channels as $type) {
            $this->assertTrue(
                $this->publisher->supports($type),
                "Expected LogChannelPublisher to support channel type: {$type}"
            );
        }
    }

    public function test_does_not_support_unknown_channel_type(): void
    {
        $this->assertFalse($this->publisher->supports('carrier_pigeon'));
    }

    public function test_ping_always_returns_reachable(): void
    {
        $credentials = ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'channel_type' => 'email',
            'credentials' => json_encode(['api_key' => 'test']),
            'status' => 'active',
        ]);

        $result = $this->publisher->ping($credentials);

        $this->assertTrue($result->reachable);
        $this->assertNull($result->error);
    }
}
