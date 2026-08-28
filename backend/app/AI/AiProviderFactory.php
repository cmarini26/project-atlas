<?php

namespace App\AI;

use App\AI\Contracts\AiProvider;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\LocalAiProvider;
use App\AI\Providers\OllamaAiProvider;
use App\AI\Testing\FakeAiProvider;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

/**
 * Resolves a concrete {@see AiProvider} by name. Selection is explicit and
 * never inferred from credential presence; environment safety restrictions are
 * enforced per provider (local stub only in `local`, fake only in `testing`).
 *
 * Used both for the global default ({@see self::default()}, driven by
 * `config('ai.provider')`) and for per-task routing overrides
 * (`config('ai.task_providers.*')`).
 */
class AiProviderFactory
{
    public const SUPPORTED = ['anthropic', 'local', 'fake', 'ollama'];

    public function __construct(private readonly Application $app) {}

    public function default(): AiProvider
    {
        $provider = config('ai.provider');

        if (! is_string($provider) || trim($provider) === '') {
            throw new InvalidArgumentException(
                'AI_PROVIDER must be configured. Supported values: anthropic, local, fake, ollama.'
            );
        }

        return $this->make(trim($provider));
    }

    public function make(string $provider): AiProvider
    {
        return match ($provider) {
            'anthropic' => $this->app->make(AnthropicProvider::class),
            'local' => $this->app->environment('local')
                ? $this->app->make(LocalAiProvider::class)
                : throw new InvalidArgumentException('AI_PROVIDER=local is only supported in the local environment.'),
            'fake' => $this->app->environment('testing')
                ? $this->app->make(FakeAiProvider::class)
                : throw new InvalidArgumentException('AI_PROVIDER=fake is only supported in the testing environment.'),
            'ollama' => $this->app->make(OllamaAiProvider::class),
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported AI_PROVIDER value [%s]. Supported values: anthropic, local, fake, ollama.',
                $provider,
            )),
        };
    }
}
