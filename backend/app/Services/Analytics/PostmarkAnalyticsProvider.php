<?php

namespace App\Services\Analytics;

use App\Models\ChannelCredentials;
use App\Models\Execution;
use App\Services\Analytics\Contracts\AnalyticsProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Pulls delivery/open/click/bounce events for a single sent email by
 * Postmark message ID. Email engagement is effectively final within days,
 * not weeks (unlike Meta's 28-day social insights window), so this uses a
 * short fixed 7-day window rather than a long progressive backoff.
 */
class PostmarkAnalyticsProvider implements AnalyticsProvider
{
    private const BASE_URL = 'https://api.postmarkapp.com';

    private const WINDOW_DAYS = 7;

    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['base_uri' => self::BASE_URL, 'timeout' => 30]);
    }

    /** @return array<string, mixed> */
    public function pull(string $platformId, ChannelCredentials $credentials): array
    {
        try {
            $response = $this->http->get("/messages/outbound/{$platformId}/details", [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Postmark-Server-Token' => (string) $credentials->credentials,
                ],
            ]);

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $response->getBody(), true) ?? [];

            return $decoded;
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? (string) $e->getResponse()?->getBody() : $e->getMessage();

            return ['error' => $body];
        }
    }

    /**
     * Postmark's message-details response carries a `MessageEvents` list
     * (Delivered, Opened, Click, Bounced, SpamComplaint,
     * SubscriptionChange). Counting event types into two overlapping key
     * sets, both required:
     *
     * 1. Email-specific keys (`delivered`, `bounces_hard`, `spam_complaints`,
     *    `unsubscribes`, `open_rate`) that LearningService already reads
     *    (checkEmailDeliverability(), checkHighUnsubscribeRate(),
     *    checkOptimalTiming()) — these must never be renamed or removed.
     * 2. The canonical cross-channel `normalised_*` keys
     *    (AnalyticsProvider::normalize()'s contract, followed today by
     *    MetaAnalyticsProvider) that CampaignKpiService::aggregate() reads
     *    to sum reach/engagement/clicks across every channel a campaign
     *    used. Omitting these was a real bug: a real Postmark send produced
     *    a real ExecutionMetric row, but CampaignKpiService silently
     *    computed zero reach and zero engagement for it, because
     *    `$m['normalised_reach'] ?? 0` and `$m['normalised_engagement'] ?? 0`
     *    fall back to zero for any metrics array missing those keys.
     *
     * `normalised_reach` maps from `delivered` — a delivered message is the
     * email-channel equivalent of "reached" one recipient.
     * `normalised_engagement` maps from opens + clicks — the email-channel
     * equivalent of an interaction, summed the same way Meta's `engagement`
     * metric is an additive count, not a binary per-post flag.
     * `normalised_clicks` was already present under this exact name.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalize(array $raw): array
    {
        /** @var list<array<string, mixed>> $events */
        $events = $raw['MessageEvents'] ?? [];

        if ($events === []) {
            return [];
        }

        $delivered = 0;
        $hardBounces = 0;
        $spamComplaints = 0;
        $unsubscribes = 0;
        $opens = 0;
        $clicks = 0;

        foreach ($events as $event) {
            $type = $event['Type'] ?? null;

            match ($type) {
                'Delivered' => $delivered++,
                'Bounced' => ($event['Details']['Type'] ?? null) === 'HardBounce' ? $hardBounces++ : null,
                'SpamComplaint' => $spamComplaints++,
                'SubscriptionChange' => ($event['Details']['SuppressSending'] ?? false) === true ? $unsubscribes++ : null,
                'Opened' => $opens++,
                'Click' => $clicks++,
                default => null,
            };
        }

        return [
            'delivered' => $delivered,
            'bounces_hard' => $hardBounces,
            'spam_complaints' => $spamComplaints,
            'unsubscribes' => $unsubscribes,
            // A single message's open rate is binary — it was opened or it
            // wasn't; campaign-level open rate is an average of these
            // across every ExecutionMetric row for the campaign.
            'open_rate' => $opens > 0 ? 1.0 : 0.0,
            'normalised_clicks' => $clicks,
            // Canonical cross-channel keys — see the docblock above.
            'normalised_reach' => $delivered,
            'normalised_engagement' => $opens + $clicks,
        ];
    }

    public function isWindowClosed(Execution $execution): bool
    {
        return $execution->completed_at?->addDays(self::WINDOW_DAYS)->isPast() ?? false;
    }

    public function pollingDelayHours(): int
    {
        return 1;
    }

    public function repollingIntervalHours(Execution $execution): int
    {
        return 24;
    }

    public function supports(string $providerType): bool
    {
        return $providerType === 'postmark';
    }
}
