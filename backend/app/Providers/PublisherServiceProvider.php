<?php

namespace App\Providers;

use App\Services\Publishing\ChannelPublisherRegistry;
use App\Services\Publishing\ChannelRendererRegistry;
use App\Services\Publishing\Email\EmailProviderRegistry;
use App\Services\Publishing\Email\LogEmailProvider;
use App\Services\Publishing\Email\PostmarkEmailProvider;
use App\Services\Publishing\EmailPublisher;
use App\Services\Publishing\EmailRenderer;
use App\Services\Publishing\GenericRenderer;
use App\Services\Publishing\LogChannelPublisher;
use App\Services\Publishing\MetaChannelPublisher;
use App\Services\Publishing\MetaRenderer;
use Illuminate\Support\ServiceProvider;

class PublisherServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelRendererRegistry::class, fn () => new ChannelRendererRegistry());
        $this->app->singleton(ChannelPublisherRegistry::class, fn () => new ChannelPublisherRegistry());
        $this->app->singleton(EmailProviderRegistry::class, fn () => new EmailProviderRegistry());
    }

    public function boot(): void
    {
        $rendererRegistry = $this->app->make(ChannelRendererRegistry::class);
        // EmailRenderer and MetaRenderer are registered first — take priority
        // over GenericRenderer for their respective channel types.
        $rendererRegistry->register($this->app->make(EmailRenderer::class));
        $rendererRegistry->register($this->app->make(MetaRenderer::class));
        $rendererRegistry->register($this->app->make(GenericRenderer::class));

        $emailProviderRegistry = $this->app->make(EmailProviderRegistry::class);
        // PostmarkEmailProvider is registered first — not load-bearing today
        // since the two support() checks are mutually exclusive ('postmark'
        // vs 'log'), but matches this file's established priority-order
        // convention for registries where the first match wins.
        $emailProviderRegistry->register($this->app->make(PostmarkEmailProvider::class));
        $emailProviderRegistry->register($this->app->make(LogEmailProvider::class));

        $publisherRegistry = $this->app->make(ChannelPublisherRegistry::class);
        // EmailPublisher and MetaChannelPublisher are registered first — take
        // priority over LogChannelPublisher for their respective channels.
        $publisherRegistry->register($this->app->make(EmailPublisher::class));
        $publisherRegistry->register($this->app->make(MetaChannelPublisher::class));
        $publisherRegistry->register($this->app->make(LogChannelPublisher::class));
    }
}
