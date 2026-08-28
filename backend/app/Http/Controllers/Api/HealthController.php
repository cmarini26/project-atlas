<?php

namespace App\Http\Controllers\Api;

use App\AI\Health\LocalAiHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final class HealthController
{
    /**
     * Basic health check — confirms the application can serve HTTP.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'version' => config('app.version', '0.1.0'),
        ]);
    }

    /**
     * Readiness check — confirms the application is ready to serve traffic.
     * Verifies database, cache, and queue connectivity.
     */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
        ];

        // Only gate readiness on local inference when it is the active provider:
        // a hosted-provider deployment must not go degraded because Ollama is
        // absent, and vice versa.
        if (config('ai.provider') === 'ollama') {
            $checks['ollama'] = $this->checkOllama();
        }

        $allOk = collect($checks)->every(fn (array $check) => $check['status'] === 'ok');

        return response()->json(
            ['status' => $allOk ? 'ok' : 'degraded', 'checks' => $checks],
            $allOk ? 200 : 503,
        );
    }

    /**
     * Liveness check — confirms the process is alive and not deadlocked.
     * Intentionally lightweight: no external dependencies.
     */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /** @return array{status: string, error?: string} */
    private function checkDatabase(): array
    {
        try {
            DB::select('SELECT 1');

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /** @return array{status: string, error?: string} */
    private function checkCache(): array
    {
        try {
            $key = '_atlas_health_probe';
            Cache::put($key, 1, 5);
            Cache::forget($key);

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /** @return array{status: string, error?: string} */
    private function checkQueue(): array
    {
        try {
            $size = Queue::size();

            return ['status' => 'ok', 'size' => $size];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /** @return array{status: string, detail?: string, model?: string, error?: string} */
    private function checkOllama(): array
    {
        try {
            $report = app(LocalAiHealthService::class)->check();
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        return [
            'status' => $report['status'] === 'ok' ? 'ok' : 'error',
            'detail' => $report['status'],
            'model' => $report['model'],
            ...($report['error'] !== null ? ['error' => $report['error']] : []),
        ];
    }
}
