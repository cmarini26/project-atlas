<?php

namespace App\Services\Observatory\Connectors\Shopify;

use App\Services\Observatory\Connectors\Shopify\Exceptions\ShopifyApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ShopifyApiClient
{
    private Client $http;

    public function __construct(
        private readonly string $apiVersion = '2025-04',
        int $requestTimeout = 10,
        int $connectTimeout = 5,
        ?Client $client = null,
    ) {
        $this->http = $client ?? new Client([
            'timeout' => $requestTimeout,
            'connect_timeout' => $connectTimeout,
        ]);
    }

    /** @return array<string, mixed> */
    public function fetchShop(string $shopDomain, string $adminApiToken): array
    {
        $data = $this->request($shopDomain, $adminApiToken, 'shop.json');

        if (! isset($data['shop']) || ! is_array($data['shop'])) {
            throw new ShopifyApiException('Shopify returned a response missing the required shop payload.');
        }

        return $data['shop'];
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchProducts(string $shopDomain, string $adminApiToken, int $limit = 25): array
    {
        $data = $this->request($shopDomain, $adminApiToken, 'products.json', [
            'limit' => $limit,
            'status' => 'active',
            'fields' => 'id,title,handle,product_type,vendor,tags,variants,updated_at',
        ]);

        if (! isset($data['products']) || ! is_array($data['products'])) {
            throw new ShopifyApiException('Shopify returned a response missing the required products payload.');
        }

        return $data['products'];
    }

    /** @return array<string, mixed> */
    private function request(string $shopDomain, string $adminApiToken, string $path, array $query = []): array
    {
        $baseUrl = sprintf('https://%s/admin/api/%s/%s', $this->normalizeShopDomain($shopDomain), $this->apiVersion, ltrim($path, '/'));

        try {
            $response = $this->http->get($baseUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Shopify-Access-Token' => $adminApiToken,
                ],
                'query' => $query,
            ]);
        } catch (GuzzleException $e) {
            throw new ShopifyApiException("Shopify API request failed: {$e->getMessage()}", previous: $e);
        }

        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data)) {
            throw new ShopifyApiException('Shopify returned invalid JSON.');
        }

        return $data;
    }

    private function normalizeShopDomain(string $shopDomain): string
    {
        $domain = trim($shopDomain);
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;

        return rtrim($domain, '/');
    }
}
