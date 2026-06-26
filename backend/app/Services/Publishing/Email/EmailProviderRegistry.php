<?php

namespace App\Services\Publishing\Email;

use App\Services\Publishing\Email\Contracts\EmailProvider;
use App\Services\Publishing\Email\Exceptions\UnknownEmailProviderException;

class EmailProviderRegistry
{
    /** @var list<EmailProvider> */
    private array $providers = [];

    public function register(EmailProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * @throws UnknownEmailProviderException
     */
    public function for(string $providerType): EmailProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($providerType)) {
                return $provider;
            }
        }

        throw new UnknownEmailProviderException($providerType);
    }

    /** @return list<EmailProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}
