<?php

namespace App\Providers;

use App\Services\Imaging\FakeImageGenerationProvider;
use App\Services\Imaging\ImageGenerationProviderRegistry;
use Illuminate\Support\ServiceProvider;

class ImagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImageGenerationProviderRegistry::class, fn () => new ImageGenerationProviderRegistry());
    }

    public function boot(): void
    {
        $registry = $this->app->make(ImageGenerationProviderRegistry::class);

        // Only the fake provider is registered today. A real hosted provider
        // is added here once the SCRUM-71 vendor decision is confirmed.
        $registry->register($this->app->make(FakeImageGenerationProvider::class));
    }
}
