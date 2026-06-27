<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Contracts\AnalyticsProvider;
use App\Services\Analytics\Exceptions\UnknownAnalyticsProviderException;

class AnalyticsProviderRegistry
{
    /** @var list<AnalyticsProvider> */
    private array $providers = [];

    public function register(AnalyticsProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    public function for(string $providerType): AnalyticsProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($providerType)) {
                return $provider;
            }
        }

        throw new UnknownAnalyticsProviderException($providerType);
    }

    /** @return list<AnalyticsProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}
