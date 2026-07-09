<?php

namespace Tests\Feature\MarketingPresence;

use App\Enums\MarketingChannelCapability;
use App\Models\Channel;
use App\Models\Company;
use App\Models\MarketingChannel;
use App\Services\MarketingPresence\MarketingChannelCapabilityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingChannelCapabilityResolverTest extends TestCase
{
    use RefreshDatabase;

    private MarketingChannelCapabilityResolver $resolver;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new MarketingChannelCapabilityResolver();
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
    }

    public function test_declared_when_not_connected(): void
    {
        $channel = $this->makeMarketingChannel();

        $this->assertSame(MarketingChannelCapability::Declared, $this->resolver->resolve($channel));
    }

    public function test_connected_when_is_connected_but_no_linked_channel(): void
    {
        $channel = $this->makeMarketingChannel(['is_connected' => true]);

        $this->assertSame(MarketingChannelCapability::Connected, $this->resolver->resolve($channel));
    }

    public function test_connected_when_supports_publishing_but_no_linked_channel_row(): void
    {
        // supports_publishing is set but channel_id is null — there's nothing
        // to actually publish through, so this cannot outrank Connected.
        $channel = $this->makeMarketingChannel(['is_connected' => true, 'supports_publishing' => true]);

        $this->assertSame(MarketingChannelCapability::Connected, $this->resolver->resolve($channel));
    }

    public function test_publishing_enabled_when_linked_channel_is_active_and_supports_publishing(): void
    {
        $realChannel = $this->makeChannel(['is_active' => true]);
        $channel = $this->makeMarketingChannel([
            'channel_id' => $realChannel->id,
            'is_connected' => true,
            'supports_publishing' => true,
        ]);

        $this->assertSame(MarketingChannelCapability::PublishingEnabled, $this->resolver->resolve($channel));
    }

    public function test_analytics_enabled_when_linked_channel_is_active_and_supports_analytics(): void
    {
        $realChannel = $this->makeChannel(['is_active' => true]);
        $channel = $this->makeMarketingChannel([
            'channel_id' => $realChannel->id,
            'is_connected' => true,
            'supports_publishing' => true,
            'supports_analytics' => true,
        ]);

        $this->assertSame(MarketingChannelCapability::AnalyticsEnabled, $this->resolver->resolve($channel));
    }

    public function test_analytics_enabled_outranks_publishing_enabled(): void
    {
        $realChannel = $this->makeChannel(['is_active' => true]);
        $channel = $this->makeMarketingChannel([
            'channel_id' => $realChannel->id,
            'is_connected' => true,
            'supports_publishing' => false,
            'supports_analytics' => true,
        ]);

        $this->assertSame(MarketingChannelCapability::AnalyticsEnabled, $this->resolver->resolve($channel));
    }

    public function test_inactive_linked_channel_never_yields_publishing_enabled(): void
    {
        $realChannel = $this->makeChannel(['is_active' => false]);
        $channel = $this->makeMarketingChannel([
            'channel_id' => $realChannel->id,
            'is_connected' => true,
            'supports_publishing' => true,
        ]);

        $this->assertSame(MarketingChannelCapability::Connected, $this->resolver->resolve($channel));
    }

    public function test_inactive_linked_channel_never_yields_analytics_enabled(): void
    {
        $realChannel = $this->makeChannel(['is_active' => false]);
        $channel = $this->makeMarketingChannel([
            'channel_id' => $realChannel->id,
            'is_connected' => true,
            'supports_publishing' => true,
            'supports_analytics' => true,
        ]);

        $this->assertSame(MarketingChannelCapability::Connected, $this->resolver->resolve($channel));
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeChannel(array $overrides = []): Channel
    {
        return Channel::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'type' => 'email',
            'name' => 'Email',
            'is_active' => true,
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeMarketingChannel(array $overrides = []): MarketingChannel
    {
        return MarketingChannel::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'type' => 'email',
            'display_name' => 'Newsletter',
            'objective' => ['retention'],
        ], $overrides));
    }
}
