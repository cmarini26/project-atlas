<?php

namespace App\Services\Publishing\Sms;

use App\Services\Publishing\Exceptions\PublishingException;
use App\Services\Publishing\Sms\Contracts\SmsProvider;

class SmsProviderRegistry
{
    /** @var list<SmsProvider> */
    private array $providers = [];

    public function register(SmsProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    /** @throws PublishingException */
    public function for(string $providerType): SmsProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($providerType)) {
                return $provider;
            }
        }

        throw new PublishingException("Unknown SMS provider [{$providerType}].", retryable: false);
    }
}
