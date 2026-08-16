<?php

namespace Tests\Feature\AI;

use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\LocalAiProvider;
use App\AI\Testing\FakeAiProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AiProviderConfigurationTest extends TestCase
{
    public function test_explicit_anthropic_provider_resolves_without_environment_inference(): void
    {
        $process = $this->resolveProvider([
            'APP_ENV' => 'staging',
            'AI_PROVIDER' => 'anthropic',
            'ANTHROPIC_API_KEY' => '',
        ]);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame(AnthropicProvider::class, trim($process->getOutput()));
    }

    public function test_explicit_local_provider_does_not_depend_on_anthropic_key_presence(): void
    {
        $process = $this->resolveProvider([
            'APP_ENV' => 'local',
            'AI_PROVIDER' => 'local',
            'ANTHROPIC_API_KEY' => 'present-but-should-not-control-selection',
        ]);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame(LocalAiProvider::class, trim($process->getOutput()));
    }

    public function test_explicit_fake_provider_resolves_in_testing(): void
    {
        $process = $this->resolveProvider([
            'APP_ENV' => 'testing',
            'AI_PROVIDER' => 'fake',
        ]);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame(FakeAiProvider::class, trim($process->getOutput()));
    }

    public function test_local_stub_is_rejected_outside_local_environment(): void
    {
        $process = $this->resolveProvider([
            'APP_ENV' => 'staging',
            'AI_PROVIDER' => 'local',
        ]);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'AI_PROVIDER=local is only supported in the local environment.',
            $process->getErrorOutput(),
        );
    }

    public function test_fake_provider_is_rejected_outside_testing_environment(): void
    {
        $process = $this->resolveProvider([
            'APP_ENV' => 'staging',
            'AI_PROVIDER' => 'fake',
        ]);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'AI_PROVIDER=fake is only supported in the testing environment.',
            $process->getErrorOutput(),
        );
    }

    public function test_unknown_provider_is_rejected_instead_of_falling_back(): void
    {
        $process = $this->resolveProvider([
            'APP_ENV' => 'staging',
            'AI_PROVIDER' => 'anthorpic',
        ]);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'Unsupported AI_PROVIDER value [anthorpic]. Supported values: anthropic, local, fake, ollama.',
            $process->getErrorOutput(),
        );
    }

    public function test_ollama_provider_reports_its_pending_implementation(): void
    {
        $process = $this->resolveProvider([
            'APP_ENV' => 'staging',
            'AI_PROVIDER' => 'ollama',
        ]);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'AI_PROVIDER=ollama requires OllamaAiProvider from SCRUM-82.',
            $process->getErrorOutput(),
        );
    }

    #[DataProvider('blankProviderValues')]
    public function test_blank_provider_configuration_is_rejected(string|false $provider): void
    {
        $process = $this->resolveProvider([
            'APP_ENV' => 'staging',
            'AI_PROVIDER' => $provider,
        ]);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'AI_PROVIDER must be configured. Supported values: anthropic, local, fake, ollama.',
            $process->getErrorOutput(),
        );
    }

    /**
     * @return array<string, array{string|false}>
     */
    public static function blankProviderValues(): array
    {
        return [
            'unset' => [false],
            'empty' => [''],
        ];
    }

    /**
     * @param  array<string, string|false>  $environment
     */
    private function resolveProvider(array $environment): Process
    {
        $environmentPath = null;

        if (($environment['AI_PROVIDER'] ?? null) === false) {
            $environmentPath = sys_get_temp_dir().'/atlas-ai-provider-'.bin2hex(random_bytes(8));
            mkdir($environmentPath, 0700);
            $environment['ATLAS_TEST_ENV_PATH'] = $environmentPath;
        }

        $script = <<<'PHP'
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';

if ($environmentPath = getenv('ATLAS_TEST_ENV_PATH')) {
    $app->useEnvironmentPath($environmentPath);
}

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo $app->make(App\AI\Contracts\AiProvider::class)::class;
PHP;

        try {
            $process = new Process([PHP_BINARY, '-r', $script], base_path(), $environment);
            $process->run();
        } finally {
            if ($environmentPath !== null) {
                rmdir($environmentPath);
            }
        }

        return $process;
    }
}
