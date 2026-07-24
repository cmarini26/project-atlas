<?php

namespace Tests\Feature\Discovery;

use App\Models\Company;
use App\Models\Integration;
use App\Services\Observatory\Connectors\ConnectorRegistry;
use App\Services\Observatory\Connectors\Exceptions\UnsupportedIntegrationException;
use App\Services\Observatory\Connectors\Instagram\InstagramConnector;
use App\Services\Observatory\Connectors\Mailchimp\MailchimpConnector;
use App\Services\Observatory\Connectors\Shopify\ShopifyConnector;
use App\Services\Observatory\Connectors\Website\WebsiteConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectorRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_website_connector_for_website_crawl_type(): void
    {
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co',
            'slug' => 'test-co',
        ]);

        $integration = Integration::withoutGlobalScopes()->make([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Site',
            'config' => ['url' => 'https://example.com'],
            'status' => 'active',
        ]);

        $registry = $this->app->make(ConnectorRegistry::class);
        $connector = $registry->resolve($integration);

        $this->assertInstanceOf(WebsiteConnector::class, $connector);
    }

    public function test_resolves_instagram_connector_for_instagram_type(): void
    {
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co',
            'slug' => 'test-co',
        ]);

        $integration = Integration::withoutGlobalScopes()->make([
            'company_id' => $company->id,
            'type' => 'instagram',
            'name' => 'Instagram',
            'config' => ['access_token' => 'token-123'],
            'status' => 'active',
        ]);

        $registry = $this->app->make(ConnectorRegistry::class);
        $connector = $registry->resolve($integration);

        $this->assertInstanceOf(InstagramConnector::class, $connector);
    }

    public function test_resolves_shopify_connector_for_shopify_type(): void
    {
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co',
            'slug' => 'test-co',
        ]);

        $integration = Integration::withoutGlobalScopes()->make([
            'company_id' => $company->id,
            'type' => 'shopify',
            'name' => 'Shopify',
            'config' => ['shop_domain' => 'acme.myshopify.com', 'admin_api_token' => 'token'],
            'status' => 'active',
        ]);

        $registry = $this->app->make(ConnectorRegistry::class);
        $connector = $registry->resolve($integration);

        $this->assertInstanceOf(ShopifyConnector::class, $connector);
    }

    public function test_resolves_mailchimp_connector_for_mailchimp_type(): void
    {
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co',
            'slug' => 'test-co',
        ]);

        $integration = Integration::withoutGlobalScopes()->make([
            'company_id' => $company->id,
            'type' => 'mailchimp',
            'name' => 'Mailchimp',
            'config' => ['server_prefix' => 'us14', 'api_key' => 'key', 'audience_id' => 'abc'],
            'status' => 'active',
        ]);

        $registry = $this->app->make(ConnectorRegistry::class);
        $connector = $registry->resolve($integration);

        $this->assertInstanceOf(MailchimpConnector::class, $connector);
    }

    public function test_throws_for_unsupported_integration_type(): void
    {
        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Test Co',
            'slug' => 'test-co',
        ]);

        $integration = Integration::withoutGlobalScopes()->make([
            'company_id' => $company->id,
            'type' => 'rss_feed',
            'name' => 'Feed',
            'config' => ['url' => 'https://example.com/feed'],
            'status' => 'active',
        ]);

        $registry = $this->app->make(ConnectorRegistry::class);

        $this->expectException(UnsupportedIntegrationException::class);
        $registry->resolve($integration);
    }

    public function test_registry_contains_all_registered_connectors(): void
    {
        $registry = $this->app->make(ConnectorRegistry::class);

        $this->assertNotEmpty($registry->all());
    }
}
