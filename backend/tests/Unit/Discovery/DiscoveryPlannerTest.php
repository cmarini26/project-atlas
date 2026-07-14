<?php

namespace Tests\Unit\Discovery;

use App\Enums\MarketingChannelType;
use App\Models\Company;
use App\Models\Integration;
use App\Models\MarketingChannel;
use App\Services\Discovery\DiscoveryPlanner;
use App\Services\Observatory\Connectors\ConnectorRegistry;
use App\Services\Observatory\Connectors\ConnectorResult;
use App\Services\Observatory\Connectors\Contracts\AutoDiscoverableConnector;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class DiscoveryPlannerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    public function test_reuses_an_existing_connected_integration_over_creating_a_new_one(): void
    {
        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'type' => 'instagram',
            'name' => 'Instagram',
            'config' => ['access_token' => 'token'],
            'status' => 'active',
        ]);

        $channel = MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::Instagram,
            'integration_id' => $integration->id,
            'is_connected' => true,
        ]);

        // No AutoDiscoverableConnector registered for Instagram at all — the
        // planner must still resolve a plan, purely from the existing linkage.
        $planner = new DiscoveryPlanner(new ConnectorRegistry([]));

        $plan = $planner->planFor($channel);

        $this->assertNotNull($plan);
        $this->assertTrue($plan->isReuse());
        $this->assertSame($integration->id, $plan->existingIntegration?->id);
    }

    public function test_creates_a_new_integration_when_a_connector_can_auto_discover_the_type(): void
    {
        $channel = MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::Website,
            'handle_or_url' => 'https://acme.example.com',
            'integration_id' => null,
            'is_connected' => false,
        ]);

        $registry = new ConnectorRegistry([$this->fakeWebsiteConnector()]);
        $planner = new DiscoveryPlanner($registry);

        $plan = $planner->planFor($channel);

        $this->assertNotNull($plan);
        $this->assertFalse($plan->isReuse());
        $this->assertSame('website_crawl', $plan->connectorType);
        $this->assertSame('https://acme.example.com', $plan->config['url']);
    }

    public function test_returns_null_when_neither_connected_nor_auto_discoverable(): void
    {
        $channel = MarketingChannel::factory()->for($this->company)->create([
            'type' => MarketingChannelType::Facebook,
            'integration_id' => null,
            'is_connected' => false,
        ]);

        $planner = new DiscoveryPlanner(new ConnectorRegistry([$this->fakeWebsiteConnector()]));

        $this->assertNull($planner->planFor($channel));
    }

    private function fakeWebsiteConnector(): AutoDiscoverableConnector
    {
        return new class() implements AutoDiscoverableConnector
        {
            public function supports(Integration $integration): bool
            {
                return $integration->type === 'website_crawl';
            }

            public function marketingChannelType(): MarketingChannelType
            {
                return MarketingChannelType::Website;
            }

            public function connectorType(): string
            {
                return 'website_crawl';
            }

            /** @return array<string, mixed> */
            public function buildIntegrationConfig(MarketingChannel $channel): array
            {
                return ['url' => $channel->handle_or_url];
            }

            /** @return Collection<int, ConnectorResult> */
            public function sync(Integration $integration): Collection
            {
                return collect([
                    new ConnectorResult(
                        sourceType: 'crawl',
                        sourceIdentifier: (string) ($integration->config['url'] ?? ''),
                        payload: '{}',
                        observedAt: new DateTimeImmutable(),
                    ),
                ]);
            }
        };
    }
}
