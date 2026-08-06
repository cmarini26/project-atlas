<?php

namespace App\Jobs;

use App\Models\Execution;
use App\Models\ExecutionMetric;
use App\Models\EmailRecipientSnapshot;
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
            $providerMessageIds = EmailRecipientSnapshot::withoutGlobalScopes()
                ->where('execution_id', $execution->id)
                ->whereNotNull('provider_message_id')
                ->pluck('provider_message_id')
                ->filter()
                ->values();

            if ($providerMessageIds->isEmpty()) {
                $raw = $provider->pull((string) $platformId, $credentials);
                $normalized = $provider->normalize($raw);
            } else {
                $rawMessages = [];
                $normalizedMessages = [];

                foreach ($providerMessageIds as $providerMessageId) {
                    $messageRaw = $provider->pull((string) $providerMessageId, $credentials);
                    $rawMessages[(string) $providerMessageId] = $messageRaw;
                    $normalizedMessages[] = $provider->normalize($messageRaw);
                }

                $raw = ['messages' => $rawMessages];
                $normalized = $this->aggregateRecipientMetrics($normalizedMessages);
            }
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
                $delay = $provider->repollingIntervalHours($execution);
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

    /**
     * Collapse per-recipient provider metrics into the execution-level shape
     * consumed by CampaignKpiService. Count metrics are additive; rate
     * metrics are averaged across recipient messages so a two-recipient
     * audience with one open reports 50%, not 100% or 200%.
     *
     * @param  list<array<string, mixed>>  $metricSets
     * @return array<string, mixed>
     */
    private function aggregateRecipientMetrics(array $metricSets): array
    {
        $aggregated = [];
        $rateCounts = [];

        foreach ($metricSets as $metrics) {
            foreach ($metrics as $key => $value) {
                if (! is_int($value) && ! is_float($value)) {
                    continue;
                }

                $aggregated[$key] = ($aggregated[$key] ?? 0) + $value;

                if (str_ends_with($key, '_rate')) {
                    $rateCounts[$key] = ($rateCounts[$key] ?? 0) + 1;
                }
            }
        }

        foreach ($rateCounts as $key => $count) {
            $aggregated[$key] /= $count;
        }

        return $aggregated;
    }
}
