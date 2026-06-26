<?php

namespace App\Providers;

use App\Services\Publishing\ChannelPublisherRegistry;
use App\Services\Publishing\ChannelRendererRegistry;
use App\Services\Publishing\GenericRenderer;
use App\Services\Publishing\LogChannelPublisher;
use Illuminate\Support\ServiceProvider;

class PublisherServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelRendererRegistry::class, function () {
            return new ChannelRendererRegistry();
        });

        $this->app->singleton(ChannelPublisherRegistry::class, function () {
            return new ChannelPublisherRegistry();
        });
    }

    public function boot(): void
    {
        $rendererRegistry = $this->app->make(ChannelRendererRegistry::class);
        $rendererRegistry->register($this->app->make(GenericRenderer::class));

        $publisherRegistry = $this->app->make(ChannelPublisherRegistry::class);
        // In M6, LogChannelPublisher handles all channel types.
        // Real publishers (EmailPublisher, InstagramPublisher, etc.) are added in subsequent milestones.
        $publisherRegistry->register($this->app->make(LogChannelPublisher::class));
    }
}
