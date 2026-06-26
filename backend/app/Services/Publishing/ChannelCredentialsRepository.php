<?php

namespace App\Services\Publishing;

use App\Models\ChannelCredentials;
use App\Services\Publishing\Exceptions\AuthenticationException;
use App\Services\Publishing\Exceptions\CredentialsExpiredException;
use App\Services\Publishing\Exceptions\CredentialsNotFoundException;

class ChannelCredentialsRepository
{
    /**
     * Resolve active, non-expired credentials for a company and channel type.
     *
     * @throws CredentialsNotFoundException when no record exists or credentials are revoked
     * @throws CredentialsExpiredException when credentials exist but have expired
     * @throws AuthenticationException when credentials exist but their last health check failed
     */
    public function for(string $companyId, string $channelType): ChannelCredentials
    {
        $credentials = ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('channel_type', $channelType)
            ->first();

        if ($credentials === null || $credentials->status === 'revoked') {
            throw new CredentialsNotFoundException($channelType);
        }

        if ($credentials->isExpired() || $credentials->status === 'expired') {
            throw new CredentialsExpiredException($channelType);
        }

        if ($credentials->status === 'error') {
            throw new AuthenticationException(
                "Credentials for channel '{$channelType}' failed the last health check. Reconnect your account."
            );
        }

        return $credentials;
    }

    public function update(ChannelCredentials $credentials): void
    {
        $credentials->save();
    }
}
