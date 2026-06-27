<?php

namespace App\Providers;

use App\Services\Analytics\AnalyticsProviderRegistry;
use App\Services\Analytics\LogAnalyticsProvider;
use App\Services\Analytics\WebhookHandlerRegistry;
use App\Services\Analytics\Webhooks\PostmarkWebhookHandler;
use Illuminate\Support\ServiceProvider;

class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AnalyticsProviderRegistry::class, fn () => new AnalyticsProviderRegistry());
        $this->app->singleton(WebhookHandlerRegistry::class, fn () => new WebhookHandlerRegistry());
    }

    public function boot(): void
    {
        $providerRegistry = $this->app->make(AnalyticsProviderRegistry::class);
        $providerRegistry->register($this->app->make(LogAnalyticsProvider::class));

        $webhookRegistry = $this->app->make(WebhookHandlerRegistry::class);
        $webhookRegistry->register($this->app->make(PostmarkWebhookHandler::class));
    }
}
