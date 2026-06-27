<?php

namespace App\Listeners;

use App\Events\ExecutionCompleted;
use App\Jobs\RetrieveExecutionMetrics;
use App\Services\Analytics\AnalyticsProviderRegistry;
use App\Services\Publishing\ChannelCredentialsRepository;
use App\Services\Publishing\Exceptions\CredentialsNotFoundException;

class ScheduleMetricRetrieval
{
    public function __construct(
        private readonly ChannelCredentialsRepository $credentialsRepository,
        private readonly AnalyticsProviderRegistry $providerRegistry,
    ) {}

    public function handle(ExecutionCompleted $event): void
    {
        $execution = $event->execution;

        $platformId = $execution->result['platform_id'] ?? null;
        if ($platformId === null || $platformId === '') {
            return;
        }

        try {
            $credentials = $this->credentialsRepository->for(
                $execution->company_id,
                $execution->channel->type ?? '',
            );
        } catch (CredentialsNotFoundException) {
            return;
        }

        $provider = $this->providerRegistry->for($credentials->provider_type);
        $delay = $provider->pollingDelayHours();

        $job = RetrieveExecutionMetrics::dispatch($execution->id)
            ->onQueue('observations');

        if ($delay > 0) {
            $job->delay(now()->addHours($delay));
        }
    }
}
