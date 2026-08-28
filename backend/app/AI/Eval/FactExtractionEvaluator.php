<?php

namespace App\AI\Eval;

use App\AI\AiProviderFactory;
use App\AI\AiResponse;
use App\AI\Exceptions\LocalAiException;
use App\AI\Prompts\FactExtractionPrompt;
use App\AI\StructuredResponseParser;
use InvalidArgumentException;
use Throwable;

/**
 * Runs the real FactExtractionPrompt through one or more providers over a set
 * of synthetic cases and scores the output. Prompt semantics are never
 * altered — the same Prompt object each provider sees in production is used.
 */
class FactExtractionEvaluator
{
    public function __construct(
        private readonly AiProviderFactory $factory,
        private readonly StructuredResponseParser $parser,
    ) {}

    /**
     * @param  list<string>  $providerNames
     * @param  list<EvalCase>  $cases
     * @return array<string, mixed> machine-readable report
     */
    public function run(array $providerNames, array $cases): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'git_sha' => $this->gitSha(),
            'case_count' => count($cases),
            'cases' => array_map(fn (EvalCase $c): string => $c->name, $cases),
            'providers' => array_map(
                fn (string $name): array => $this->evaluateProvider($name, $cases),
                $providerNames,
            ),
        ];
    }

    /**
     * @param  list<EvalCase>  $cases
     * @return array<string, mixed>
     */
    private function evaluateProvider(string $name, array $cases): array
    {
        $provider = $this->factory->make($name);

        $model = null;
        $perCase = [];
        $failures = [];
        $latencies = [];
        $inputTokens = [];
        $outputTokens = [];

        foreach ($cases as $case) {
            $prompt = new FactExtractionPrompt(
                pageUrl: $case->url !== '' ? $case->url : 'https://example.test',
                pageTitle: $case->title,
                bodyText: $case->bodyText,
            );

            $startedAt = microtime(true);

            try {
                $response = $provider->complete($prompt);
                $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

                $model ??= $response->model;
                $latencies[] = $latencyMs;
                $inputTokens[] = $response->inputTokens;
                $outputTokens[] = $response->outputTokens;

                $keys = $this->extractFactKeys($response->content);
                $values = $this->extractFactValues($response->content);

                $perCase[] = $this->scoreCase($case, $keys, $values, $latencyMs);
            } catch (Throwable $e) {
                $failures[] = [
                    'case' => $case->name,
                    'category' => $this->categorise($e),
                    'message' => $this->shorten($e->getMessage()),
                ];
            }
        }

        return [
            'provider' => $name,
            'model' => $model ?? 'unknown',
            'settings' => $this->settingsFor($name),
            'cases_run' => count($cases),
            'schema_valid' => count($perCase),
            'schema_valid_rate' => $this->rate(count($perCase), count($cases)),
            'precision' => $this->mean(array_column($perCase, 'precision')),
            'recall' => $this->mean(array_column($perCase, 'recall')),
            'f1' => $this->mean(array_column($perCase, 'f1')),
            'value_accuracy' => $this->mean(array_filter(
                array_column($perCase, 'value_accuracy'),
                fn ($v): bool => $v !== null,
            )),
            'unsupported_claims_total' => array_sum(array_column($perCase, 'unsupported_claims')),
            'unsupported_claims_per_case' => $this->mean(array_column($perCase, 'unsupported_claims')),
            'latency_ms' => [
                'avg' => $this->mean($latencies),
                'max' => $latencies === [] ? 0 : max($latencies),
            ],
            'tokens' => [
                'avg_input' => $this->mean($inputTokens),
                'avg_output' => $this->mean($outputTokens),
            ],
            'failures' => $failures,
            'per_case' => $perCase,
        ];
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    private function scoreCase(EvalCase $case, array $keys, array $values, int $latencyMs): array
    {
        $expected = array_values(array_unique($case->expectedKeys));
        $got = array_values(array_unique($keys));

        $truePositives = count(array_intersect($got, $expected));
        $precision = $got === [] ? 0.0 : $truePositives / count($got);
        $recall = $expected === [] ? 0.0 : $truePositives / count($expected);
        $f1 = ($precision + $recall) === 0.0 ? 0.0 : 2 * $precision * $recall / ($precision + $recall);

        $valueAccuracy = null;
        if ($case->expectedValues !== []) {
            $hits = 0;
            foreach ($case->expectedValues as $key => $substr) {
                $actual = $values[$key] ?? null;
                if ($actual !== null && stripos($actual, $substr) !== false) {
                    $hits++;
                }
            }
            $valueAccuracy = $hits / count($case->expectedValues);
        }

        return [
            'case' => $case->name,
            'expected_keys' => count($expected),
            'extracted_keys' => count($got),
            'matched_keys' => $truePositives,
            'precision' => round($precision, 3),
            'recall' => round($recall, 3),
            'f1' => round($f1, 3),
            // Extracted keys with no expected counterpart — a proxy for
            // unsupported / hallucinated claims, not a precise measure.
            'unsupported_claims' => count(array_diff($got, $expected)),
            'value_accuracy' => $valueAccuracy === null ? null : round($valueAccuracy, 3),
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * @return list<string>
     */
    private function extractFactKeys(string $content): array
    {
        $keys = [];

        foreach ($this->factEntries($content) as $fact) {
            if (is_array($fact) && is_string($fact['key'] ?? null) && is_scalar($fact['value'] ?? null)) {
                $keys[] = $fact['key'];
            }
        }

        return $keys;
    }

    /**
     * @return array<string, string>
     */
    private function extractFactValues(string $content): array
    {
        $values = [];

        foreach ($this->factEntries($content) as $fact) {
            if (is_array($fact) && is_string($fact['key'] ?? null) && is_scalar($fact['value'] ?? null)) {
                $values[$fact['key']] = (string) $fact['value'];
            }
        }

        return $values;
    }

    /**
     * @return list<mixed>
     */
    private function factEntries(string $content): array
    {
        $data = $this->parser->parse(new AiResponse($content, 'eval', 0, 0));

        if (! isset($data['facts']) || ! is_array($data['facts'])) {
            throw new InvalidArgumentException("Response is missing a 'facts' array.");
        }

        return array_values($data['facts']);
    }

    private function categorise(Throwable $e): string
    {
        if ($e instanceof LocalAiException) {
            return $e->category;
        }

        if ($e instanceof InvalidArgumentException) {
            return 'invalid_response';
        }

        return 'error';
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsFor(string $provider): array
    {
        return match ($provider) {
            'ollama' => [
                'base_url' => config('services.ollama.base_url'),
                'model' => config('services.ollama.model'),
                'context_length' => (int) config('services.ollama.context_length'),
                'think' => (bool) config('services.ollama.think'),
                // Quantization is a property of the pulled model tag, not the
                // API — record it from OLLAMA_MODEL / `ollama show` when reporting.
                'quantization' => 'see model tag',
            ],
            'anthropic' => [
                'model' => config('services.anthropic.model'),
            ],
            default => [],
        };
    }

    private function gitSha(): string
    {
        $head = @file_get_contents(base_path('.git/HEAD'));

        if (! is_string($head)) {
            return 'unknown';
        }

        if (str_starts_with(trim($head), 'ref:')) {
            $ref = trim(substr(trim($head), 4));
            $sha = @file_get_contents(base_path('.git/'.$ref));

            return is_string($sha) ? substr(trim($sha), 0, 12) : 'unknown';
        }

        return substr(trim($head), 0, 12);
    }

    /**
     * @param  array<int, int|float>  $values
     */
    private function mean(array $values): float
    {
        $values = array_values($values);

        return $values === [] ? 0.0 : round(array_sum($values) / count($values), 3);
    }

    private function rate(int $n, int $total): float
    {
        return $total === 0 ? 0.0 : round($n / $total, 3);
    }

    private function shorten(string $message): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return mb_strlen($message) > 300 ? mb_substr($message, 0, 300).'…' : $message;
    }
}
