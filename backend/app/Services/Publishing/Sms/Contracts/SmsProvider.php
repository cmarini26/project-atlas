<?php

namespace App\Services\Publishing\Sms\Contracts;

use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\ChannelCredentials;
use App\Services\Publishing\Exceptions\PublishingException;

interface SmsProvider
{
    /**
     * Send an SMS and return the provider-assigned message ID.
     *
     * @throws PublishingException
     */
    public function send(string $fromNumber, string $toNumber, string $body, ChannelCredentials $credentials): string;

    public function ping(ChannelCredentials $credentials): PingResult;

    public function supports(string $providerType): bool;
}
