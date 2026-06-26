<?php

namespace App\Providers;

use App\Services\Publishing\ChannelPublisherRegistry;
use App\Services\Publishing\LogChannelPublisher;
use Illuminate\Support\ServiceProvider;

class PublisherServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelPublisherRegistry::class, function () {
            return new ChannelPublisherRegistry();
        });
    }

    public function boot(): void
    {
        $registry = $this->app->make(ChannelPublisherRegistry::class);

        // In M6, LogChannelPublisher handles all channel types.
        // Real publishers (EmailPublisher, InstagramPublisher, etc.) are added in subsequent milestones.
        $registry->register($this->app->make(LogChannelPublisher::class));
    }
}
