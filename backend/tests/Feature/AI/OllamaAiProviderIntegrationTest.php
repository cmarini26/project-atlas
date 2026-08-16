<?php

namespace Tests\Feature\AI;

use App\AI\Prompts\Prompt;
use App\AI\Providers\OllamaAiProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class OllamaAiProviderIntegrationTest extends TestCase
{
    #[Group('ollama-integration')]
    public function test_completes_a_schema_constrained_prompt_against_local_ollama(): void
    {
        if (! filter_var(getenv('OLLAMA_INTEGRATION_TEST'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Set OLLAMA_INTEGRATION_TEST=1 to run against private local Ollama.');
        }

        $result = $this->app->make(OllamaAiProvider::class)->complete($this->integrationPrompt());
        $decoded = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Atlas', $decoded['answer'] ?? null);
        $this->assertNotSame('', $result->model);
        $this->assertGreaterThan(0, $result->inputTokens);
        $this->assertGreaterThan(0, $result->outputTokens);
    }

    private function integrationPrompt(): Prompt
    {
        return new class() extends Prompt
        {
            public function system(): string
            {
                return 'Return only data matching the provided JSON schema.';
            }

            public function user(): string
            {
                return 'Set answer to exactly Atlas.';
            }

            /** @return array<string, mixed> */
            public function schema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [
                        'answer' => ['type' => 'string'],
                    ],
                    'required' => ['answer'],
                    'additionalProperties' => false,
                ];
            }

            public function temperature(): float
            {
                return 0.0;
            }

            public function maxTokens(): int
            {
                return 64;
            }
        };
    }
}
