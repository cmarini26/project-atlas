<?php

namespace Tests\Feature\Analytics;

use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\Execution;
use App\Services\Analytics\LogAnalyticsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogAnalyticsProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_pull_returns_empty_array(): void
    {
        $provider = new LogAnalyticsProvider();

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test', 'slug' => 'test', 'industry' => 'test',
        ]);

        $credentials = ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'log', 'provider_type' => 'log',
            'credentials' => json_encode(['placeholder' => true]), 'status' => 'active',
        ]);

        $result = $provider->pull('platform-id', $credentials);

        $this->assertEquals([], $result);
    }

    public function test_normalize_returns_empty_array(): void
    {
        $provider = new LogAnalyticsProvider();

        $this->assertEquals([], $provider->normalize(['some_key' => 'value']));
    }

    public function test_is_window_closed_always_true(): void
    {
        $provider = new LogAnalyticsProvider();

        $execution = new Execution();
        $this->assertTrue($provider->isWindowClosed($execution));
    }

    public function test_supports_log_provider_type(): void
    {
        $provider = new LogAnalyticsProvider();

        $this->assertTrue($provider->supports('log'));
        $this->assertFalse($provider->supports('postmark'));
        $this->assertFalse($provider->supports('email'));
    }

    public function test_polling_delay_is_zero(): void
    {
        $this->assertEquals(0, (new LogAnalyticsProvider())->pollingDelayHours());
    }

    public function test_repolling_interval_is_zero(): void
    {
        $this->assertEquals(0, (new LogAnalyticsProvider())->repollingIntervalHours(new Execution()));
    }
}
