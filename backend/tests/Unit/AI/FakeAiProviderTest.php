<?php

namespace Tests\Unit\AI;

use App\AI\AiResponse;
use App\AI\Prompts\Prompt;
use App\AI\Testing\FakeAiProvider;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FakeAiProviderTest extends TestCase
{
    private function makePrompt(): Prompt
    {
        return new class() extends Prompt
        {
            public function system(): string
            {
                return 'You are a test assistant.';
            }

            public function user(): string
            {
                return 'Return test data.';
            }
        };
    }

    public function test_queued_response_is_returned_on_complete(): void
    {
        $provider = new FakeAiProvider();
        $provider->queueResponse('{"key": "value"}');

        $response = $provider->complete($this->makePrompt());

        $this->assertInstanceOf(AiResponse::class, $response);
        $this->assertEquals('{"key": "value"}', $response->content);
        $this->assertEquals('fake-model', $response->model);
    }

    public function test_multiple_responses_are_returned_in_order(): void
    {
        $provider = new FakeAiProvider();
        $provider->queueResponse('{"n": 1}');
        $provider->queueResponse('{"n": 2}');

        $prompt = $this->makePrompt();

        $first = $provider->complete($prompt);
        $second = $provider->complete($prompt);

        $this->assertEquals('{"n": 1}', $first->content);
        $this->assertEquals('{"n": 2}', $second->content);
    }

    public function test_throws_when_queue_is_empty(): void
    {
        $provider = new FakeAiProvider();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FakeAiProvider queue is empty');

        $provider->complete($this->makePrompt());
    }

    public function test_assert_prompt_sent_passes_when_prompt_was_sent(): void
    {
        $provider = new FakeAiProvider();
        $provider->queueResponse('{}');

        $prompt = $this->makePrompt();
        $provider->complete($prompt);

        $provider->assertPromptSent($prompt::class);
    }

    public function test_assert_prompt_sent_fails_when_prompt_was_not_sent(): void
    {
        $provider = new FakeAiProvider();

        $this->expectException(AssertionFailedError::class);

        $provider->assertPromptSent('App\AI\Prompts\NonExistentPrompt');
    }

    public function test_assert_nothing_sent_passes_when_no_prompts_were_sent(): void
    {
        $provider = new FakeAiProvider();

        $provider->assertNothingSent();
    }

    public function test_sent_count_reflects_number_of_completions(): void
    {
        $provider = new FakeAiProvider();
        $provider->queueResponse('{}');
        $provider->queueResponse('{}');

        $prompt = $this->makePrompt();
        $provider->complete($prompt);
        $provider->complete($prompt);

        $this->assertEquals(2, $provider->sentCount());
    }

    public function test_queue_response_returns_static_for_chaining(): void
    {
        $provider = new FakeAiProvider();

        $result = $provider->queueResponse('{}');

        $this->assertSame($provider, $result);
    }
}
