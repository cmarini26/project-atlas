<?php

namespace App\Console\Commands;

use App\AI\Health\LocalAiHealthService;
use Illuminate\Console\Command;

class CheckLocalAiHealth extends Command
{
    protected $signature = 'ai:local:health {--json : Emit the raw report as JSON}';

    protected $description = 'Probe the local Ollama service for reachability and required-model availability';

    public function handle(LocalAiHealthService $health): int
    {
        $report = $health->check();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['Status', $report['status']],
            ['Base URL', $report['base_url']],
            ['Configured model', $report['model']],
            ['Available models', $report['available_models'] === [] ? '—' : implode(', ', $report['available_models'])],
            ['Latency (ms)', $report['latency_ms'] ?? '—'],
            ['Error', $report['error'] ?? '—'],
        ]);

        return match ($report['status']) {
            'ok' => tap(self::SUCCESS, fn () => $this->info('Local AI is healthy.')),
            'model_missing' => tap(self::FAILURE, fn () => $this->error("Model [{$report['model']}] is not pulled. Run: ollama pull {$report['model']}")),
            default => tap(self::FAILURE, fn () => $this->error('Ollama is unreachable at '.$report['base_url'].'. Is the service running?')),
        };
    }
}
