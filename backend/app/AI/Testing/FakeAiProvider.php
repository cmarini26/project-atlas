<?php

namespace App\AI\Testing;

use App\AI\AiResponse;
use App\AI\Contracts\AiProvider;
use App\AI\Prompts\Prompt;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Throwable;

class FakeAiProvider implements AiProvider
{
    /** @var array<int, AiResponse|Throwable> */
    private array $queue = [];

    /** @var array<int, array{prompt: Prompt, response: AiResponse}> */
    private array $recorded = [];

    /**
     * Queue a raw JSON string as the next AI response.
     */
    public function queueResponse(string $jsonContent): static
    {
        $this->queue[] = new AiResponse(
            content: $jsonContent,
            model: 'fake-model',
            inputTokens: 0,
            outputTokens: 0,
        );

        return $this;
    }

    /**
     * Queue the contents of a JSON fixture file.
     * Fixtures live in tests/Fixtures/AI/{name}.json.
     */
    public function queueFixture(string $name): static
    {
        $path = base_path("tests/Fixtures/AI/{$name}.json");

        if (! file_exists($path)) {
            throw new RuntimeException("AI fixture not found: {$path}");
        }

        return $this->queueResponse((string) file_get_contents($path));
    }

    /**
     * Queue an exception to be thrown on the next complete() call — used to
     * simulate provider failures like AiProviderOverloadedException.
     */
    public function queueException(Throwable $exception): static
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function complete(Prompt $prompt): AiResponse
    {
        if (empty($this->queue)) {
            throw new RuntimeException(
                'FakeAiProvider queue is empty. Call queueResponse() or queueFixture() before calling complete().'
            );
        }

        $response = array_shift($this->queue);

        if ($response instanceof Throwable) {
            throw $response;
        }

        $this->recorded[] = ['prompt' => $prompt, 'response' => $response];

        return $response;
    }

    public function assertPromptSent(string $promptClass): void
    {
        $sent = array_map(
            fn (array $entry) => $entry['prompt']::class,
            $this->recorded,
        );

        Assert::assertContains(
            $promptClass,
            $sent,
            "Expected prompt [{$promptClass}] to have been sent, but it was not."
        );
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty(
            $this->recorded,
            'Expected no prompts to be sent, but '.count($this->recorded).' were sent.'
        );
    }

    /** @return array<int, array{prompt: Prompt, response: AiResponse}> */
    public function recorded(): array
    {
        return $this->recorded;
    }

    public function sentCount(): int
    {
        return count($this->recorded);
    }
}
