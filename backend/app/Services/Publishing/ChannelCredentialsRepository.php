<?php

namespace App\Services\Publishing;

use App\Models\ChannelCredentials;
use App\Services\Publishing\Exceptions\CredentialsNotFoundException;

class ChannelCredentialsRepository
{
    public function for(string $companyId, string $channelType): ChannelCredentials
    {
        $credentials = ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('channel_type', $channelType)
            ->where('status', '!=', 'revoked')
            ->first();

        if ($credentials === null) {
            throw new CredentialsNotFoundException($channelType);
        }

        return $credentials;
    }

    public function update(ChannelCredentials $credentials): void
    {
        $credentials->save();
    }
}
