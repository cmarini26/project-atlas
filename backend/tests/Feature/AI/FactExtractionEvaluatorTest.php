<?php

namespace Tests\Feature\AI;

use App\AI\Eval\EvalCase;
use App\AI\Eval\FactExtractionEvaluator;
use App\AI\Exceptions\LocalAiModelMissingException;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\OllamaAiProvider;
use App\AI\Testing\FakeAiProvider;
use Tests\TestCase;

class FactExtractionEvaluatorTest extends TestCase
{
    private function evaluator(): FactExtractionEvaluator
    {
        return $this->app->make(FactExtractionEvaluator::class);
    }

    private function bindOllama(FakeAiProvider $fake): void
    {
        $this->app->instance(OllamaAiProvider::class, $fake);
    }

    private function factsJson(array $facts): string
    {
        return json_encode([
            'facts' => array_map(fn (array $f): array => [
                'key' => $f[0],
                'value' => $f[1],
                'data_type' => 'string',
                'confidence' => 90,
            ], $facts),
        ], JSON_THROW_ON_ERROR);
    }

    private function case(array $expectedKeys, array $expectedValues = []): EvalCase
    {
        return new EvalCase(
            name: 'case-1',
            url: 'https://example.test',
            title: 'Example',
            bodyText: 'Some analyzable body text about the business.',
            expectedKeys: $expectedKeys,
            expectedValues: $expectedValues,
        );
    }

    public function test_scores_a_provider_with_perfect_extraction(): void
    {
        $fake = new FakeAiProvider();
        $fake->queueResponse($this->factsJson([
            ['business.name', 'Fable City Comics'],
            ['contact.email', 'hello@example.test'],
        ]));
        $this->bindOllama($fake);

        $report = $this->evaluator()->run(['ollama'], [
            $this->case(['business.name', 'contact.email']),
        ]);

        $p = $report['providers'][0];
        $this->assertSame('ollama', $p['provider']);
        $this->assertSame(1.0, $p['schema_valid_rate']);
        $this->assertSame(1.0, $p['recall']);
        $this->assertSame(1.0, $p['precision']);
        $this->assertSame(1.0, $p['f1']);
        $this->assertSame(0, $p['unsupported_claims_total']);
        $this->assertSame([], $p['failures']);
    }

    public function test_captures_a_provider_failure_with_its_category(): void
    {
        $fake = new FakeAiProvider();
        $fake->queueException(new LocalAiModelMissingException('qwen3:14b', 'not found'));
        $this->bindOllama($fake);

        $report = $this->evaluator()->run(['ollama'], [$this->case(['business.name'])]);

        $p = $report['providers'][0];
        $this->assertSame(0.0, $p['schema_valid_rate']);
        $this->assertCount(1, $p['failures']);
        $this->assertSame('model_missing', $p['failures'][0]['category']);
        $this->assertSame('case-1', $p['failures'][0]['case']);
    }

    public function test_reports_partial_recall_and_unsupported_claims(): void
    {
        $fake = new FakeAiProvider();
        $fake->queueResponse($this->factsJson([
            ['business.name', 'X'],
            ['contact.email', 'a@b.test'],
            ['made.up.key', 'noise'],
        ]));
        $this->bindOllama($fake);

        $report = $this->evaluator()->run(['ollama'], [
            $this->case(['business.name', 'contact.email', 'business.location']),
        ]);

        $p = $report['providers'][0];
        $this->assertEqualsWithDelta(0.667, $p['recall'], 0.01);
        $this->assertEqualsWithDelta(0.667, $p['precision'], 0.01);
        $this->assertSame(1, $p['unsupported_claims_total']);
    }

    public function test_value_accuracy_checks_expected_substrings(): void
    {
        $fake = new FakeAiProvider();
        $fake->queueResponse($this->factsJson([
            ['business.name', 'Fable City Comics'],
        ]));
        $this->bindOllama($fake);

        $report = $this->evaluator()->run(['ollama'], [
            $this->case(['business.name'], ['business.name' => 'Fable']),
        ]);

        $this->assertSame(1.0, $report['providers'][0]['value_accuracy']);
    }

    public function test_runs_multiple_providers_and_records_each_model(): void
    {
        $anthropic = new FakeAiProvider();
        $anthropic->queueResponse($this->factsJson([['business.name', 'X']]));
        $ollama = new FakeAiProvider();
        $ollama->queueResponse($this->factsJson([['business.name', 'X']]));

        $this->app->instance(AnthropicProvider::class, $anthropic);
        $this->app->instance(OllamaAiProvider::class, $ollama);

        $report = $this->evaluator()->run(['anthropic', 'ollama'], [$this->case(['business.name'])]);

        $this->assertCount(2, $report['providers']);
        $this->assertSame('anthropic', $report['providers'][0]['provider']);
        $this->assertSame('ollama', $report['providers'][1]['provider']);
        $this->assertSame('fake-model', $report['providers'][0]['model']);
        $this->assertArrayHasKey('git_sha', $report);
    }
}
