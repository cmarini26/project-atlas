<?php

namespace Tests\Feature\AI;

use App\AI\Images\Contracts\ImageProvider;
use App\AI\Images\Providers\OpenAiImageProvider;
use App\AI\Images\Testing\FakeImageProvider;
use InvalidArgumentException;
use Tests\TestCase;

class ImageProviderBindingTest extends TestCase
{
    private function resolve(): ImageProvider
    {
        $this->app->forgetInstance(ImageProvider::class);

        return $this->app->make(ImageProvider::class);
    }

    public function test_fake_provider_resolves_in_the_testing_environment(): void
    {
        config()->set('ai.image.provider', 'fake');

        $this->assertInstanceOf(FakeImageProvider::class, $this->resolve());
    }

    public function test_openai_provider_resolves_when_configured(): void
    {
        config()->set('ai.image.provider', 'openai');
        config()->set('services.openai.api_key', 'test-key');

        $this->assertInstanceOf(OpenAiImageProvider::class, $this->resolve());
    }

    public function test_unset_provider_throws(): void
    {
        config()->set('ai.image.provider', null);

        $this->expectException(InvalidArgumentException::class);

        $this->resolve();
    }

    public function test_unknown_provider_throws(): void
    {
        config()->set('ai.image.provider', 'midjourney');

        $this->expectException(InvalidArgumentException::class);

        $this->resolve();
    }
}
