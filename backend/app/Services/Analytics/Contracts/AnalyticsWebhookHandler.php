<?php

namespace App\Services\Analytics\Contracts;

use App\Domain\Analytics\ValueObjects\WebhookEvent;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

interface AnalyticsWebhookHandler
{
    /**
     * Verify the request signature or shared secret.
     * Throws if the signature is invalid — never swallows the failure.
     *
     * @throws HttpException
     */
    public function verify(Request $request): void;

    /**
     * Parse the raw request body into a list of normalised WebhookEvents.
     *
     * @return list<WebhookEvent>
     */
    public function parse(Request $request): array;

    /**
     * Returns true if this handler supports the given provider type string.
     */
    public function supports(string $providerType): bool;
}
