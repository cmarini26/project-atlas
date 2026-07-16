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
     * There is exactly one canonical cross-channel schema, read by
     * CampaignKpiService::aggregate() to sum/compare metrics across every
     * channel a campaign used — every implementation MUST emit these three
     * keys whenever the underlying data is available:
     *
     * - `normalised_reach` (int): how many recipients/viewers this execution
     *   reached (Meta: post reach; email: messages delivered).
     * - `normalised_engagement` (int): how many interactions occurred
     *   (Meta: reactions+comments+shares; email: opens+clicks).
     * - `normalised_clicks` (int): link clicks, tracked separately from
     *   engagement since not every channel's "engagement" implies a click.
     *
     * A provider MAY also return additional, provider-specific keys beyond
     * this canonical set (e.g. PostmarkAnalyticsProvider's `bounces_hard`,
     * `spam_complaints`, `unsubscribes`, `open_rate`, consumed directly by
     * LearningService) — those are additive, not a competing schema.
     * CampaignKpiService must never be taught to read a provider-specific
     * key; if a metric needs to be comparable across channels, it belongs
     * in the canonical set above, mapped by the provider that produces it.
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
     * Hours to wait before the next retrieval attempt while the window is
     * open. Takes the Execution (matching isWindowClosed()'s signature)
     * since a progressive backoff schedule is naturally derived from time
     * elapsed since publication, not a fixed interval.
     */
    public function repollingIntervalHours(Execution $execution): int;

    /**
     * Returns true if this provider handles the given provider type string.
     */
    public function supports(string $providerType): bool;
}
