<?php

namespace Tests\Unit\AI;

use App\AI\Prompts\Prompt;
use PHPUnit\Framework\TestCase;

class PromptTest extends TestCase
{
    private function makePrompt(?string $version = null): Prompt
    {
        return new class($version) extends Prompt
        {
            public function __construct(private readonly ?string $v) {}

            public function system(): string
            {
                return 'System prompt.';
            }

            public function user(): string
            {
                return 'User prompt.';
            }

            public function version(): string
            {
                return $this->v ?? parent::version();
            }
        };
    }

    public function test_default_values_are_correct(): void
    {
        $prompt = $this->makePrompt();

        $this->assertEquals(0.2, $prompt->temperature());
        $this->assertEquals(2048, $prompt->maxTokens());
        $this->assertEquals('1.0', $prompt->version());
        $this->assertNull($prompt->schema());
    }

    public function test_name_returns_class_basename(): void
    {
        $prompt = $this->makePrompt();

        // Anonymous class — name should be non-empty
        $this->assertNotEmpty($prompt->name());
    }

    public function test_version_can_be_overridden(): void
    {
        $prompt = $this->makePrompt('2.3');

        $this->assertEquals('2.3', $prompt->version());
    }

    public function test_system_and_user_return_strings(): void
    {
        $prompt = $this->makePrompt();

        $this->assertIsString($prompt->system());
        $this->assertIsString($prompt->user());
        $this->assertNotEmpty($prompt->system());
        $this->assertNotEmpty($prompt->user());
    }
}
