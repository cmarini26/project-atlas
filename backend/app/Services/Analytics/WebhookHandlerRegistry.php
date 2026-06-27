<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Contracts\AnalyticsWebhookHandler;
use App\Services\Analytics\Exceptions\UnknownWebhookProviderException;

class WebhookHandlerRegistry
{
    /** @var list<AnalyticsWebhookHandler> */
    private array $handlers = [];

    public function register(AnalyticsWebhookHandler $handler): void
    {
        $this->handlers[] = $handler;
    }

    public function for(string $providerType): AnalyticsWebhookHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($providerType)) {
                return $handler;
            }
        }

        throw new UnknownWebhookProviderException($providerType);
    }

    /** @return list<AnalyticsWebhookHandler> */
    public function all(): array
    {
        return $this->handlers;
    }
}
