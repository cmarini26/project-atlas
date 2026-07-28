<?php

namespace App\Services\Observatory;

use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\Company;
use App\Models\Integration;
use App\Models\MarketingChannel;
use App\Services\MarketingPresence\MarketingPresenceService;
use App\Services\Observatory\Connectors\Shopify\ShopifyApiClient;

class ShopifyConnectionService
{
    public function __construct(
        private readonly ShopifyApiClient $client,
        private readonly MarketingPresenceService $marketingPresence,
    ) {}

    public function connect(Company $company, string $shopDomain, string $adminApiToken): PingResult
    {
        try {
            $shop = $this->client->fetchShop($shopDomain, $adminApiToken);
            $reachable = true;
            $error = null;
        } catch (\Throwable $e) {
            $shop = null;
            $reachable = false;
            $error = $e->getMessage();
        }

        $config = [
            'shop_domain' => $shopDomain,
            'admin_api_token' => $adminApiToken,
            'shop_name' => $shop['name'] ?? null,
        ];

        $integration = Integration::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'type' => 'shopify'],
            [
                'name' => (string) ($shop['name'] ?? $shopDomain),
                'config' => $config,
                'status' => $reachable ? 'active' : 'error',
                'last_error' => $error,
            ],
        );

        $this->linkWebsiteChannelIfPresent($company, $integration);

        return new PingResult(reachable: $reachable, error: $error);
    }

    public function disconnect(Company $company): void
    {
        Integration::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', 'shopify')
            ->update([
                'status' => 'disconnected',
                'last_error' => null,
            ]);
    }

    private function linkWebsiteChannelIfPresent(Company $company, Integration $integration): void
    {
        $websiteChannel = MarketingChannel::where('company_id', $company->id)
            ->where('type', 'website')
            ->first();

        if ($websiteChannel !== null) {
            $this->marketingPresence->linkIntegration($websiteChannel, $integration);
        }
    }
}
