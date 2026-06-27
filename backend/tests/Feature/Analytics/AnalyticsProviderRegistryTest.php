<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\AnalyticsProviderRegistry;
use App\Services\Analytics\Exceptions\UnknownAnalyticsProviderException;
use App\Services\Analytics\FakeAnalyticsProvider;
use App\Services\Analytics\LogAnalyticsProvider;
use Tests\TestCase;

class AnalyticsProviderRegistryTest extends TestCase
{
    public function test_resolves_registered_provider(): void
    {
        $registry = new AnalyticsProviderRegistry();
        $registry->register(new LogAnalyticsProvider());

        $provider = $registry->for('log');

        $this->assertInstanceOf(LogAnalyticsProvider::class, $provider);
    }

    public function test_throws_for_unknown_provider_type(): void
    {
        $registry = new AnalyticsProviderRegistry();

        $this->expectException(UnknownAnalyticsProviderException::class);
        $this->expectExceptionMessageMatches('/unknown-provider/');

        $registry->for('unknown-provider');
    }

    public function test_first_match_wins_when_multiple_providers_support_type(): void
    {
        $fake = new FakeAnalyticsProvider();
        $log = new LogAnalyticsProvider();

        $registry = new AnalyticsProviderRegistry();
        $registry->register($fake);
        $registry->register($log);

        $resolved = $registry->for('log');

        $this->assertInstanceOf(FakeAnalyticsProvider::class, $resolved);
    }

    public function test_all_returns_registered_providers(): void
    {
        $registry = new AnalyticsProviderRegistry();
        $registry->register(new LogAnalyticsProvider());
        $registry->register(new FakeAnalyticsProvider());

        $this->assertCount(2, $registry->all());
    }
}
