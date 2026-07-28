<?php

namespace App\Services\Observatory\Connectors\Shopify;

use App\Models\Integration;
use App\Services\Observatory\Connectors\ConnectorResult;
use App\Services\Observatory\Connectors\Contracts\Connector;
use DateTimeImmutable;
use Illuminate\Support\Collection;

class ShopifyConnector implements Connector
{
    public function __construct(
        private readonly ShopifyApiClient $client,
        private readonly int $productLimit = 25,
    ) {}

    public function supports(Integration $integration): bool
    {
        return $integration->type === 'shopify';
    }

    /** @return Collection<int, ConnectorResult> */
    public function sync(Integration $integration): Collection
    {
        $shopDomain = (string) ($integration->config['shop_domain'] ?? '');
        $adminApiToken = (string) ($integration->config['admin_api_token'] ?? '');

        $shop = $this->client->fetchShop($shopDomain, $adminApiToken);
        $products = $this->client->fetchProducts($shopDomain, $adminApiToken, $this->productLimit);
        $observedAt = new DateTimeImmutable();

        return collect([
            new ConnectorResult(
                sourceType: 'ecommerce_store',
                sourceIdentifier: (string) ($shop['domain'] ?? $shopDomain),
                payload: json_encode([
                    'shop' => [
                        'id' => $shop['id'] ?? null,
                        'name' => $shop['name'] ?? null,
                        'email' => $shop['email'] ?? null,
                        'domain' => $shop['domain'] ?? $shopDomain,
                        'currency' => $shop['currency'] ?? null,
                        'primary_locale' => $shop['primary_locale'] ?? null,
                        'timezone' => $shop['iana_timezone'] ?? null,
                        'plan_name' => $shop['plan_name'] ?? null,
                    ],
                ], JSON_THROW_ON_ERROR),
                observedAt: $observedAt,
            ),
            new ConnectorResult(
                sourceType: 'catalog',
                sourceIdentifier: sprintf('%s-products', $shopDomain),
                payload: json_encode([
                    'shop_domain' => $shopDomain,
                    'products' => $products,
                    'product_limit' => $this->productLimit,
                ], JSON_THROW_ON_ERROR),
                observedAt: $observedAt,
            ),
        ]);
    }
}
