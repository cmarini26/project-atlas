<?php

namespace App\Console\Commands;

use App\AI\Eval\EvalCaseRepository;
use App\AI\Eval\FactExtractionEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Throwable;

class RunFactExtractionEval extends Command
{
    protected $signature = 'ai:eval:fact-extraction
        {--providers= : Comma-separated providers to run (default: config ai.eval.providers)}
        {--gate= : Provider whose metrics gate the exit code (default: config ai.eval.gate_provider)}
        {--cases= : Directory of case JSON files (default: eval/fact-extraction/cases)}
        {--out= : Path to write the JSON report (default: storage/app/eval/…)}';

    protected $description = 'Score the fact-extraction prompt across providers over synthetic cases';

    public function handle(EvalCaseRepository $repository, FactExtractionEvaluator $evaluator): int
    {
        $providers = $this->providerList();
        $gate = (string) ($this->option('gate') ?: config('ai.eval.gate_provider'));

        try {
            $cases = $repository->load($this->option('cases') ?: null);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Running %d case(s) through: %s', count($cases), implode(', ', $providers)));

        $report = $evaluator->run($providers, $cases);
        $report['thresholds'] = config('ai.eval.thresholds');
        $report['gate_provider'] = $gate;

        [$verdict, $reasons] = $this->evaluateGate($report, $gate);
        $report['verdict'] = $verdict;
        $report['gate_reasons'] = $reasons;

        $path = $this->writeReport($report);
        $this->renderTable($report);
        $this->line('');
        $this->line("Report written to: {$path}");

        if ($verdict === 'pass') {
            $this->info("PASS — {$gate} cleared every threshold.");

            return self::SUCCESS;
        }

        if ($verdict === 'not_gated') {
            $this->warn("Gate provider [{$gate}] was not run — thresholds not evaluated.");

            return self::SUCCESS;
        }

        $this->error("FAIL — {$gate} did not clear: ".implode('; ', $reasons));

        return self::FAILURE;
    }

    /** @return list<string> */
    private function providerList(): array
    {
        $raw = $this->option('providers');
        $list = $raw !== null
            ? array_map('trim', explode(',', (string) $raw))
            : (array) config('ai.eval.providers', ['anthropic', 'ollama']);

        return array_values(array_filter($list, fn (string $p): bool => $p !== ''));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: string, 1: list<string>}
     */
    private function evaluateGate(array $report, string $gate): array
    {
        $metrics = collect($report['providers'])->firstWhere('provider', $gate);

        if ($metrics === null) {
            return ['not_gated', []];
        }

        $t = $report['thresholds'];
        $reasons = [];

        if ($metrics['schema_valid_rate'] < $t['min_schema_valid_rate']) {
            $reasons[] = sprintf('schema_valid_rate %.2f < %.2f', $metrics['schema_valid_rate'], $t['min_schema_valid_rate']);
        }
        if ($metrics['recall'] < $t['min_recall']) {
            $reasons[] = sprintf('recall %.2f < %.2f', $metrics['recall'], $t['min_recall']);
        }
        if ($metrics['f1'] < $t['min_f1']) {
            $reasons[] = sprintf('f1 %.2f < %.2f', $metrics['f1'], $t['min_f1']);
        }
        if ($metrics['unsupported_claims_per_case'] > $t['max_unsupported_claims_per_case']) {
            $reasons[] = sprintf('unsupported/case %.2f > %.2f', $metrics['unsupported_claims_per_case'], $t['max_unsupported_claims_per_case']);
        }

        return [$reasons === [] ? 'pass' : 'fail', $reasons];
    }

    /** @param array<string, mixed> $report */
    private function writeReport(array $report): string
    {
        $path = (string) ($this->option('out')
            ?: storage_path('app/eval/fact-extraction-'.now()->format('Ymd-His').'.json'));

        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /** @param array<string, mixed> $report */
    private function renderTable(array $report): void
    {
        $rows = array_map(fn (array $p): array => [
            $p['provider'],
            $p['model'],
            sprintf('%d/%d', $p['schema_valid'], $p['cases_run']),
            number_format($p['recall'], 2),
            number_format($p['f1'], 2),
            number_format($p['unsupported_claims_per_case'], 2),
            (int) $p['latency_ms']['avg'].' ms',
            count($p['failures']),
        ], $report['providers']);

        $this->table(
            ['provider', 'model', 'schema ok', 'recall', 'f1', 'unsup/case', 'avg latency', 'failures'],
            $rows,
        );

        foreach ($report['providers'] as $p) {
            foreach (Arr::get($p, 'failures', []) as $f) {
                $this->warn("  {$p['provider']} · {$f['case']} · {$f['category']}: {$f['message']}");
            }
        }
    }
}
