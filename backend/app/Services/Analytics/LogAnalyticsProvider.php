<?php

namespace App\Services\Analytics;

use App\Models\ChannelCredentials;
use App\Models\Execution;
use App\Services\Analytics\Contracts\AnalyticsProvider;

class LogAnalyticsProvider implements AnalyticsProvider
{
    /** @return array<string, mixed> */
    public function pull(string $platformId, ChannelCredentials $credentials): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalize(array $raw): array
    {
        return [];
    }

    public function isWindowClosed(Execution $execution): bool
    {
        return true;
    }

    public function pollingDelayHours(): int
    {
        return 0;
    }

    public function repollingIntervalHours(Execution $execution): int
    {
        return 0;
    }

    public function supports(string $providerType): bool
    {
        return $providerType === 'log';
    }
}
