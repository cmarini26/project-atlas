<?php

namespace App\Services\Imaging;

use App\Services\Imaging\Contracts\ImageGenerationProvider;
use App\Services\Imaging\Exceptions\ImageGenerationException;

class ImageGenerationProviderRegistry
{
    /** @var list<ImageGenerationProvider> */
    private array $providers = [];

    public function register(ImageGenerationProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    /** @throws ImageGenerationException */
    public function for(string $providerType): ImageGenerationProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($providerType)) {
                return $provider;
            }
        }

        throw new ImageGenerationException("Unknown image generation provider [{$providerType}].");
    }
}
