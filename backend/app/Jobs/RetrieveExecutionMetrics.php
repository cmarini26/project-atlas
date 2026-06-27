<?php

namespace App\Jobs;

use App\Models\Execution;
use App\Models\ExecutionMetric;
use App\Models\MetricRetrievalLog;
use App\Services\Analytics\AnalyticsProviderRegistry;
use App\Services\Analytics\CampaignKpiService;
use App\Services\Publishing\ChannelCredentialsRepository;
use App\Services\Publishing\Exceptions\CredentialsExpiredException;
use App\Services\Publishing\Exceptions\CredentialsNotFoundException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RetrieveExecutionMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $executionId)
    {
        $this->onQueue('observations');
    }

    public function handle(
        ChannelCredentialsRepository $credentialsRepository,
        AnalyticsProviderRegistry $providerRegistry,
        CampaignKpiService $kpiService,
    ): void {
        $execution = Execution::withoutGlobalScopes()
            ->with('channel')
            ->findOrFail($this->executionId);

        if ($execution->status !== 'completed') {
            return;
        }

        $platformId = $execution->result['platform_id'] ?? null;
        if ($platformId === null || $platformId === '') {
            return;
        }

        $channel = $execution->channel;
        if ($channel === null) {
            return;
        }

        try {
            $credentials = $credentialsRepository->for($execution->company_id, $channel->type);
        } catch (CredentialsNotFoundException|CredentialsExpiredException) {
            MetricRetrievalLog::create([
                'execution_id' => $execution->id,
                'provider_type' => 'unknown',
                'attempted_at' => now(),
                'status' => 'skipped',
                'error' => 'Credentials unavailable for channel type: '.$channel->type,
            ]);

            return;
        }

        $provider = $providerRegistry->for($credentials->provider_type);

        try {
            $raw = $provider->pull((string) $platformId, $credentials);
            $normalized = $provider->normalize($raw);
            $windowClosed = $provider->isWindowClosed($execution);

            ExecutionMetric::withoutGlobalScopes()->updateOrCreate(
                ['execution_id' => $execution->id],
                [
                    'company_id' => $execution->company_id,
                    'campaign_id' => $execution->campaign_id,
                    'channel_type' => $channel->type,
                    'provider_type' => $credentials->provider_type,
                    'platform_id' => $platformId,
                    'retrieved_at' => now(),
                    'raw' => empty($raw) ? null : $raw,
                    'metrics' => $normalized,
                    'is_final' => $windowClosed,
                ],
            );

            MetricRetrievalLog::create([
                'execution_id' => $execution->id,
                'provider_type' => $credentials->provider_type,
                'attempted_at' => now(),
                'status' => 'success',
            ]);

            if ($windowClosed) {
                $kpiService->snapshotIfReady($execution->campaign_id);
            } else {
                $delay = $provider->repollingIntervalHours();
                $pending = self::dispatch($this->executionId)->onQueue('observations');
                if ($delay > 0) {
                    $pending->delay(now()->addHours($delay));
                }
            }
        } catch (\Throwable $e) {
            MetricRetrievalLog::create([
                'execution_id' => $execution->id,
                'provider_type' => $credentials->provider_type,
                'attempted_at' => now(),
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
