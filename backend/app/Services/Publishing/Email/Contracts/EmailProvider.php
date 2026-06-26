<?php

namespace App\Services\Publishing\Email\Contracts;

use App\Domain\Publishing\ValueObjects\EmailPayload;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\ChannelCredentials;
use App\Services\Publishing\Exceptions\PublishingException;

interface EmailProvider
{
    /**
     * Send an email and return the provider-assigned message ID.
     *
     * @throws PublishingException
     */
    public function send(EmailPayload $payload, ChannelCredentials $credentials): string;

    /**
     * Verify that the provider is reachable with the given credentials.
     * Does not send anything.
     */
    public function ping(ChannelCredentials $credentials): PingResult;

    /**
     * Returns true if this provider handles the given provider type string.
     * Examples: 'log', 'postmark', 'mailgun', 'ses'
     */
    public function supports(string $providerType): bool;
}
