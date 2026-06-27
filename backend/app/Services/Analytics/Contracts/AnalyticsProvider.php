<?php

namespace App\Services\Analytics\Contracts;

use App\Models\ChannelCredentials;
use App\Models\Execution;

interface AnalyticsProvider
{
    /**
     * Pull raw metrics for a published item from the platform API.
     * Returns the full provider response. No normalisation.
     *
     * @return array<string, mixed>
     */
    public function pull(string $platformId, ChannelCredentials $credentials): array;

    /**
     * Normalise the raw provider response into the standard metric key map.
     * Omit keys that are not available — never set them to null or 0.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalize(array $raw): array;

    /**
     * Returns true if the metric collection window has closed for this execution.
     * After the window closes, no further polling is needed.
     */
    public function isWindowClosed(Execution $execution): bool;

    /**
     * Hours to wait after publication before the first retrieval attempt.
     */
    public function pollingDelayHours(): int;

    /**
     * Hours between subsequent retrieval attempts while the window is open.
     */
    public function repollingIntervalHours(): int;

    /**
     * Returns true if this provider handles the given provider type string.
     */
    public function supports(string $providerType): bool;
}
