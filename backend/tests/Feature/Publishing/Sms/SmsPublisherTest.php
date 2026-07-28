<?php

namespace Tests\Feature\Publishing\Sms;

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Models\Execution;
use App\Services\Publishing\Exceptions\PublishingException;
use App\Services\Publishing\Sms\Contracts\SmsProvider;
use App\Services\Publishing\Sms\SmsProviderRegistry;
use App\Services\Publishing\SmsPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SmsPublisherTest extends TestCase
{
    use RefreshDatabase;

    private SmsPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher = $this->app->make(SmsPublisher::class);
    }

    public function test_publish_sends_to_the_configured_destination_number(): void
    {
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co-'.uniqid()]);
        $campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'title' => 'SMS Campaign',
            'campaign_type' => 'featured_item',
            'status' => 'approved',
        ]);
        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'sms',
            'name' => 'SMS',
            'config' => ['from_number' => '+15550001111', 'to_number' => '+15550002222'],
            'is_active' => true,
        ]);
        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $campaign->id,
            'channel_id' => $channel->id,
            'type' => 'sms',
            'body' => 'Flash sale ends tonight.',
            'status' => 'approved',
        ]);
        $execution = Execution::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $channel->id,
            'status' => 'queued',
            'idempotency_key' => 'sms-publisher-1',
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'channel_type' => 'sms',
            'provider_type' => 'twilio',
            'credentials' => json_encode(['account_sid' => 'AC123', 'auth_token' => 'auth-token']),
            'status' => 'active',
        ]);

        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldReceive('supports')->with('twilio')->andReturn(true);
        $provider->shouldReceive('send')
            ->once()
            ->with('+15550001111', '+15550002222', 'Flash sale ends tonight.', Mockery::type(ChannelCredentials::class))
            ->andReturn('SM123');

        $registry = new SmsProviderRegistry();
        $registry->register($provider);
        $this->app->instance(SmsProviderRegistry::class, $registry);
        $this->publisher = $this->app->make(SmsPublisher::class);

        $result = $this->publisher->publish($execution);

        $this->assertSame('SM123', $result->platformId);
        $this->assertSame('twilio', $result->metadata['provider']);
        $this->assertSame('+15550002222', $result->metadata['to_number']);
    }

    public function test_publish_fails_honestly_when_no_destination_number_is_configured(): void
    {
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co-'.uniqid()]);
        $campaign = Campaign::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'title' => 'SMS Campaign',
            'campaign_type' => 'featured_item',
            'status' => 'approved',
        ]);
        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'sms',
            'name' => 'SMS',
            'config' => ['from_number' => '+15550001111'],
            'is_active' => true,
        ]);
        $asset = ContentAsset::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $campaign->id,
            'channel_id' => $channel->id,
            'type' => 'sms',
            'body' => 'Flash sale ends tonight.',
            'status' => 'approved',
        ]);
        $execution = Execution::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'campaign_id' => $campaign->id,
            'content_asset_id' => $asset->id,
            'channel_id' => $channel->id,
            'status' => 'queued',
            'idempotency_key' => 'sms-publisher-2',
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'channel_type' => 'sms',
            'provider_type' => 'twilio',
            'credentials' => json_encode(['account_sid' => 'AC123', 'auth_token' => 'auth-token']),
            'status' => 'active',
        ]);

        try {
            $this->publisher->publish($execution);
            $this->fail('Expected a PublishingException when no destination number is configured.');
        } catch (PublishingException $e) {
            $this->assertFalse($e->isRetryable());
            $this->assertStringContainsString('destination number', $e->getMessage());
        }
    }
}
