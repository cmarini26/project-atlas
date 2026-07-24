<?php

namespace Tests\Feature\Discovery;

use App\Models\Company;
use App\Models\Integration;
use App\Services\Observatory\Connectors\ConnectorResult;
use App\Services\Observatory\Connectors\Shopify\ShopifyApiClient;
use App\Services\Observatory\Connectors\Shopify\ShopifyConnector;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ShopifyConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_maps_store_and_products_to_connector_results(): void
    {
        $client = Mockery::mock(ShopifyApiClient::class);
        $client->expects('fetchShop')->once()->with('acme.myshopify.com', 'token-123')->andReturn([
            'id' => 1,
            'name' => 'Acme Store',
            'email' => 'owner@example.com',
            'domain' => 'acme.myshopify.com',
            'currency' => 'USD',
            'primary_locale' => 'en',
            'iana_timezone' => 'America/New_York',
            'plan_name' => 'basic',
        ]);
        $client->expects('fetchProducts')->once()->with('acme.myshopify.com', 'token-123', 10)->andReturn([
            ['id' => 10, 'title' => 'Vintage Lamp', 'handle' => 'vintage-lamp'],
            ['id' => 11, 'title' => 'Collector Bowl', 'handle' => 'collector-bowl'],
        ]);

        $connector = new ShopifyConnector($client, productLimit: 10);
        $company = Company::factory()->create();
        $integration = Integration::withoutGlobalScopes()->make([
            'company_id' => $company->id,
            'type' => 'shopify',
            'name' => 'Shopify',
            'config' => ['shop_domain' => 'acme.myshopify.com', 'admin_api_token' => 'token-123'],
            'status' => 'active',
        ]);

        $results = $connector->sync($integration);

        $this->assertCount(2, $results);
        $this->assertInstanceOf(ConnectorResult::class, $results->first());
        $this->assertSame('ecommerce_store', $results->first()->sourceType);
        $this->assertSame('catalog', $results->last()->sourceType);

        $shopPayload = json_decode($results->first()->payload, true);
        $catalogPayload = json_decode($results->last()->payload, true);

        $this->assertSame('Acme Store', $shopPayload['shop']['name']);
        $this->assertCount(2, $catalogPayload['products']);
    }

    public function test_supports_only_shopify_integrations(): void
    {
        $connector = new ShopifyConnector(Mockery::mock(ShopifyApiClient::class));
        $company = Company::factory()->create();

        $shopify = Integration::withoutGlobalScopes()->make([
            'company_id' => $company->id,
            'type' => 'shopify',
            'name' => 'Shopify',
            'config' => ['shop_domain' => 'acme.myshopify.com'],
            'status' => 'active',
        ]);

        $website = Integration::withoutGlobalScopes()->make([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'config' => ['url' => 'https://example.com'],
            'status' => 'active',
        ]);

        $this->assertTrue($connector->supports($shopify));
        $this->assertFalse($connector->supports($website));
    }
}
