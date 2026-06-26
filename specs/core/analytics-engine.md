# Analytics Engine — Design Specification

**Version:** 1.0  
**Status:** Approved — authoritative specification for Phase 7 (Analytics)  
**Depends on:** `specs/core/domain-model.md`, `specs/core/publishing-engine.md`, `specs/core/campaign-blueprint.md`  
**See also:** `docs/technical/Architecture.md`, `docs/technical/AI.md`

When this document conflicts with others, this document wins for anything related to metric ingestion, provider webhooks, campaign KPIs, decision effectiveness, and the analytics-to-learning feedback loop. Update the others.

---

## Milestone Scope

The Analytics Engine is implemented in **Phase 7** of the roadmap. It activates after Phase 6 (Publishing) has produced `completed` Executions with real `platform_id` values.

Phase 7 does **not** implement:

- Learning record application (`ApplyLearnings` job — Phase 8)
- Scoring weight recalibration — Phase 8
- Cross-company aggregate analytics — Phase 8
- Paid media analytics — not on current roadmap
- Individual subscriber or contact tracking — never (privacy)
- Real-time streaming analytics — not on current roadmap

The output of Phase 7 is:

```
Execution (completed) + platform_id
→ RetrieveExecutionMetrics job (scheduled)
   → AnalyticsProvider::pull()
   → ExecutionMetric records stored
→ CampaignKpiService::aggregate(Campaign)
   → CampaignKpiSnapshot stored
→ LearningService::recordFromMetrics()
   → Learning records created (Phase 8 applies them)
```

Webhook-based enrichment runs alongside polling:

```
Provider webhook received (email open, link click)
→ AnalyticsWebhookController::handle()
   → AnalyticsWebhookHandler::process()
   → ExecutionMetric upserted
```

---

## 1. Analytics Domain Model

### 1.1 ExecutionMetric

Stores the platform-reported metrics for a single `Execution`. One row per execution per retrieval window (updated in place after initial creation; versioned snapshots if needed).

**Table:** `execution_metrics`

| Column              | Type       | Notes |
|---------------------|------------|-------|
| id                  | char(26)   | ULID PK |
| company_id          | char(26)   | FK companies.id |
| execution_id        | char(26)   | FK executions.id (unique — one metric record per execution) |
| campaign_id         | char(26)   | FK campaigns.id (denormalised for query performance) |
| channel_type        | varchar    | `email`, `instagram`, `facebook`, `linkedin`, `x`, `sms`, `blog`, `landing_page` |
| provider_type       | varchar    | `postmark`, `mailchimp`, `instagram_graph`, etc. |
| platform_id         | varchar    | mirrors `executions.result.platform_id` — the key used to query the platform API |
| retrieved_at        | timestamp  | when metrics were last fetched from the platform |
| window_closes_at    | timestamp  | nullable; when the metric collection window ends (e.g., 48h after publish for social) |
| is_final            | boolean    | false while the window is open; true when no further retrieval is expected |
| raw                 | json       | full provider API response, stored verbatim for auditability |
| metrics             | json       | normalised metric map (see §5) |
| created_at          | timestamp  | |
| updated_at          | timestamp  | |

**Indexes:** `(execution_id)` unique; `(campaign_id, is_final)`; `(company_id, channel_type, retrieved_at)`

**Eloquent model:** `App\Models\ExecutionMetric`  
Use `HasUlids`, `BelongsToCompany`. Cast `raw` and `metrics` to `array`.

---

### 1.2 CampaignKpiSnapshot

A point-in-time rollup of campaign-level KPIs, computed from all `ExecutionMetric` records for a given Campaign.

**Table:** `campaign_kpi_snapshots`

| Column              | Type       | Notes |
|---------------------|------------|-------|
| id                  | char(26)   | ULID PK |
| company_id          | char(26)   | FK companies.id |
| campaign_id         | char(26)   | FK campaigns.id |
| snapshot_type       | enum       | `interim` (window still open), `final` (all windows closed) |
| snapshotted_at      | timestamp  | when this snapshot was computed |
| channels_included   | json       | list of channel types included in this snapshot |
| expected_impact     | json       | copy of `Decision.rationale.expected_impact` at the time of Decision |
| actual_kpis         | json       | computed KPIs (see §6) |
| performance_rating  | enum       | `exceeded`, `met`, `below`, `insufficient_data` — compared to expected_impact |
| created_at          | timestamp  | |

**Indexes:** `(campaign_id, snapshot_type)`; `(company_id, snapshotted_at)`

**Eloquent model:** `App\Models\CampaignKpiSnapshot`  
Use `HasUlids`, `BelongsToCompany`. Cast `channels_included`, `expected_impact`, `actual_kpis` to `array`.

---

### 1.3 MetricRetrievalLog

Tracks every attempt to fetch metrics from a platform API — success or failure. Append-only audit record.

**Table:** `metric_retrieval_logs`

| Column        | Type       | Notes |
|---------------|------------|-------|
| id            | char(26)   | ULID PK |
| execution_id  | char(26)   | FK executions.id |
| provider_type | varchar    | |
| attempted_at  | timestamp  | |
| status        | enum       | `success`, `failed`, `skipped` |
| error         | text       | nullable |
| response_code | smallint   | nullable; HTTP status from provider |
| created_at    | timestamp  | |

No `updated_at` — append-only. No `BelongsToCompany` scope needed (queried via execution_id).

---

## 2. Event Ingestion Architecture

Atlas retrieves metrics using **two complementary mechanisms**: scheduled polling and webhook callbacks. Neither alone is sufficient — polling handles platforms without webhooks; webhooks handle real-time events (email opens) that polls would miss.

### 2.1 Polling (Pull)

A scheduled job polls each platform API for metrics after a configurable delay following publication.

**Job:** `App\Jobs\RetrieveExecutionMetrics`  
**Queue:** `observations` (low priority; not time-critical)  
**Dispatch trigger:** `ExecutionCompleted` event → listener schedules the first retrieval after `polling_delay_hours`

```
ExecutionCompleted event
→ ScheduleMetricRetrieval listener
→ RetrieveExecutionMetrics::dispatch($execution)->delay(hours: $provider->pollingDelayHours())
```

**Job flow:**

```php
class RetrieveExecutionMetrics implements ShouldQueue
{
    public function handle(AnalyticsProviderRegistry $registry): void
    {
        $execution = Execution::withoutGlobalScopes()->findOrFail($this->executionId);
        $credentials = ChannelCredentialsRepository::for($execution->company_id, $execution->channel->type);
        $provider = $registry->for($credentials->provider_type);

        $raw = $provider->pull($execution->result['platform_id'], $credentials);

        ExecutionMetric::updateOrCreate(
            ['execution_id' => $execution->id],
            [
                'raw'          => $raw,
                'metrics'      => $provider->normalize($raw),
                'retrieved_at' => now(),
                'is_final'     => $provider->isWindowClosed($execution),
            ],
        );

        MetricRetrievalLog::create([...]);

        if (! $provider->isWindowClosed($execution)) {
            RetrieveExecutionMetrics::dispatch($execution)->delay(hours: $provider->repollingIntervalHours());
        } else {
            CampaignKpiService::snapshotIfReady($execution->campaign);
        }
    }
}
```

**Polling schedule by channel type:**

| Channel type | Initial delay | Re-poll interval | Window closes |
|--------------|--------------|------------------|---------------|
| `email`      | 4 hours      | 12 hours         | 48 hours after send |
| `instagram`  | 2 hours      | 6 hours          | 48 hours after post |
| `facebook`   | 2 hours      | 6 hours          | 48 hours after post |
| `linkedin`   | 4 hours      | 12 hours         | 48 hours after post |
| `x`          | 1 hour       | 4 hours          | 24 hours after post |
| `sms`        | 1 hour       | — (one-shot)     | 24 hours after send |
| `blog`       | 24 hours     | 48 hours         | 7 days after publish |
| `landing_page` | 24 hours   | 48 hours         | 7 days after publish |

### 2.2 Webhooks (Push)

Providers that support real-time event callbacks (Postmark for email opens/clicks; future: Mailgun, SendGrid) push events to Atlas.

**Webhook endpoint:** `POST /api/analytics/webhooks/{provider}`

**Controller:** `App\Http\Controllers\Api\AnalyticsWebhookController`

```php
class AnalyticsWebhookController extends Controller
{
    public function handle(string $provider, Request $request): JsonResponse
    {
        $handler = $this->registry->for($provider);
        $handler->verify($request);   // HMAC / secret validation; throws on failure
        $events  = $handler->parse($request);

        foreach ($events as $event) {
            ProcessAnalyticsWebhookEvent::dispatch($event)->onQueue('observations');
        }

        return response()->json(['accepted' => count($events)]);
    }
}
```

**Job:** `App\Jobs\ProcessAnalyticsWebhookEvent`  
**Queue:** `observations`

The job resolves the `ExecutionMetric` by matching `platform_id` (from the webhook payload) to `execution_metrics.platform_id`, then merges the webhook event into the `metrics` JSON.

**Webhook event types tracked:**

| Provider | Event | Signal |
|----------|-------|--------|
| Postmark | `Delivery` | Email delivered |
| Postmark | `Open` | Email opened |
| Postmark | `Click` | Link clicked |
| Postmark | `Bounce` | Hard/soft bounce |
| Postmark | `SpamComplaint` | Complaint filed |
| Mailgun | `delivered` | Email delivered |
| Mailgun | `opened` | Email opened |
| Mailgun | `clicked` | Link clicked |

Webhook processing is idempotent: duplicate events for the same `platform_id` + `event_type` increment a counter on the existing `ExecutionMetric` rather than creating a new record.

---

## 3. Provider Webhook Interface

```php
interface AnalyticsWebhookHandler
{
    /**
     * Verify the request signature/secret. Throws if invalid.
     */
    public function verify(Request $request): void;

    /**
     * Parse the raw request into a list of normalised webhook events.
     *
     * @return list<WebhookEvent>
     */
    public function parse(Request $request): array;

    /**
     * Returns the provider type string this handler supports.
     */
    public function supports(string $providerType): bool;
}
```

**`WebhookEvent`** value object:

```php
readonly class WebhookEvent
{
    public function __construct(
        public string $providerType,      // 'postmark', 'mailgun', etc.
        public string $platformMessageId, // matches ExecutionMetric.platform_id
        public string $eventType,         // 'delivery', 'open', 'click', 'bounce', 'complaint'
        public \DateTimeImmutable $occurredAt,
        public array $metadata,           // provider-specific supplemental data
    ) {}
}
```

**`WebhookHandlerRegistry`** follows the same pattern as `ChannelPublisherRegistry`:

```php
class WebhookHandlerRegistry
{
    public function register(AnalyticsWebhookHandler $handler): void;
    public function for(string $providerType): AnalyticsWebhookHandler;
    // throws UnknownProviderException if none registered
}
```

**Security:** Every webhook endpoint validates the provider's HMAC signature or shared secret before processing. Requests with invalid signatures return `401`. All webhook payloads are logged (raw) to the `MetricRetrievalLog` before processing.

---

## 4. Attribution Model

Attribution determines which channel, content, or moment is credited with a marketing outcome.

### 4.1 MVP Attribution

The MVP uses **platform-reported attribution only** — no cross-channel attribution modelling. Each platform reports its own engagement metrics. Atlas aggregates at the campaign level without attempting to resolve which channel drove a conversion.

- Email: opens and clicks are attributed to the email channel, reported by the ESP
- Social: engagement (likes, comments, shares) attributed to the posting channel
- No first-touch, last-touch, or linear multi-touch model in Phase 7

### 4.2 Attribution Scope

Atlas does not place tracking pixels, use cross-site cookies, or track individual users across channels. All attribution is based on platform-reported metrics for the specific content Atlas published.

### 4.3 Campaign-Level Attribution

A campaign's total engagement is the sum of all `ExecutionMetric.metrics` values across all Executions in the campaign, grouped by metric key:

```
campaign.total_reach    = sum(execution_metrics.metrics.reach) for all executions
campaign.total_clicks   = sum(execution_metrics.metrics.clicks) for all executions
campaign.best_channel   = channel_type with highest engagement_rate
```

### 4.4 Expected vs. Actual Attribution

The Decision's `rationale.expected_impact` carries the anticipated outcome expressed qualitatively or quantitatively. When a `CampaignKpiSnapshot` is finalized, it compares `actual_kpis` against `expected_impact` to produce a `performance_rating`:

| Rating | Meaning |
|--------|---------|
| `exceeded` | Actual engagement ≥ 125% of expected baseline |
| `met` | Actual engagement 75%–125% of expected baseline |
| `below` | Actual engagement < 75% of expected baseline |
| `insufficient_data` | Not enough metrics returned to assess (e.g., 0 opens after full window) |

The comparison logic lives in `CampaignKpiService::ratePerformance()` and is deterministic: same inputs always produce the same rating.

---

## 5. Metrics Collected

All metrics are stored in `ExecutionMetric.metrics` as a flat JSON object with standardised keys. Providers that do not supply a given metric omit the key (never set it to `null` or `0`).

### 5.1 Email Metrics

| Key | Description |
|-----|-------------|
| `delivered` | Confirmed deliveries |
| `opens` | Total opens (includes multiple opens from same recipient) |
| `unique_opens` | Opens de-duplicated per recipient |
| `open_rate` | `unique_opens / delivered` as a decimal (0–1) |
| `clicks` | Total link clicks |
| `unique_clicks` | Clicks de-duplicated per recipient |
| `click_rate` | `unique_clicks / delivered` as a decimal |
| `click_to_open_rate` | `unique_clicks / unique_opens` as a decimal |
| `bounces_hard` | Permanent delivery failures |
| `bounces_soft` | Temporary delivery failures |
| `unsubscribes` | Recipients who unsubscribed |
| `spam_complaints` | Spam reports filed |
| `total_recipients` | Recipient count at send time |

### 5.2 Instagram / Facebook Metrics

| Key | Description |
|-----|-------------|
| `reach` | Unique accounts that saw the post |
| `impressions` | Total times the post was displayed |
| `likes` | Like/reaction count |
| `comments` | Comment count |
| `shares` | Share/repost count |
| `saves` | Save/bookmark count |
| `link_clicks` | Clicks on a link in the post or bio |
| `engagement_rate` | `(likes + comments + shares + saves) / reach` |
| `video_views` | Views (video posts only) |
| `video_play_rate` | `video_views / impressions` (video posts only) |

### 5.3 LinkedIn Metrics

| Key | Description |
|-----|-------------|
| `impressions` | Total impressions |
| `unique_impressions` | Unique viewer count |
| `clicks` | Total clicks on the post |
| `likes` | Reactions count |
| `comments` | Comment count |
| `shares` | Share count |
| `engagement_rate` | `(clicks + likes + comments + shares) / impressions` |
| `followers_gained` | New followers attributed to the post |

### 5.4 X (Twitter) Metrics

| Key | Description |
|-----|-------------|
| `impressions` | Tweet impressions |
| `likes` | Like count |
| `retweets` | Retweet count |
| `replies` | Reply count |
| `profile_clicks` | Profile link clicks |
| `link_clicks` | Clicks on embedded URLs |
| `engagement_rate` | `(likes + retweets + replies + link_clicks) / impressions` |

### 5.5 SMS Metrics

| Key | Description |
|-----|-------------|
| `delivered` | Delivered message count |
| `failed` | Failed delivery count |
| `clicked` | Link click count (short URLs with click tracking) |
| `opted_out` | Opt-out responses received |

### 5.6 Blog / Landing Page Metrics

| Key | Description |
|-----|-------------|
| `page_views` | Total page views during attribution window |
| `unique_visitors` | Unique visitor count |
| `avg_time_on_page_seconds` | Average time spent in seconds |
| `bounce_rate` | Percentage of single-page visits |
| `scroll_depth_pct` | Average scroll depth (0–100) |
| `cta_clicks` | CTA button clicks (if instrumented) |

Blog and landing page metrics are not available if the CMS/host does not provide an analytics API. When unavailable, `is_final = true` is set immediately after publication and `metrics = {}`.

### 5.7 Normalised Keys

All channels emit three common normalised keys used for cross-channel comparison:

| Key | Description |
|-----|-------------|
| `normalised_reach` | Best available reach proxy per channel (unique_opens, reach, unique_impressions, delivered, unique_visitors) |
| `normalised_engagement` | Best available engagement proxy (unique_clicks, engagement count, link_clicks, cta_clicks) |
| `normalised_engagement_rate` | `normalised_engagement / normalised_reach` |

These keys are computed by the `AnalyticsProvider::normalize()` method after raw metric retrieval.

---

## 6. Campaign KPIs

Campaign KPIs are computed by `CampaignKpiService::aggregate(Campaign)` and stored in `CampaignKpiSnapshot.actual_kpis`.

```json
{
    "total_reach":            4200,
    "total_engagement":       310,
    "total_engagement_rate":  0.074,
    "total_clicks":           87,
    "best_channel":           "email",
    "best_channel_reason":    "Highest engagement rate (12.4%) vs. Instagram (6.1%)",
    "channel_breakdown": {
        "email": {
            "reach":           3400,
            "engagement":      221,
            "engagement_rate": 0.124,
            "clicks":          71
        },
        "instagram": {
            "reach":           800,
            "engagement":      89,
            "engagement_rate": 0.061,
            "clicks":          16
        }
    },
    "execution_count":        2,
    "executions_with_data":   2,
    "window_closed":          true
}
```

**`CampaignKpiService`** responsibilities:

- `aggregate(Campaign): array` — collects all `ExecutionMetric` records for the campaign and computes the KPI map
- `snapshotIfReady(Campaign): ?CampaignKpiSnapshot` — creates an `interim` snapshot when any execution's window closes; upgrades to `final` when all windows are closed
- `ratePerformance(array $actualKpis, array $expectedImpact): string` — returns `exceeded|met|below|insufficient_data`
- `bestChannel(array $channelBreakdown): string` — returns the channel type with highest `engagement_rate`

---

## 7. Recommendation KPIs

Recommendation KPIs are computed at the company level, not per campaign. They answer: "Is Atlas getting better at recommending things this company approves?"

**Computed by:** `RecommendationKpiService` (no persistence — assembled on demand from existing records)

| KPI | Computation |
|-----|-------------|
| `approval_rate` | Approvals / total acted-on Recommendations |
| `rejection_rate` | Rejections / total acted-on Recommendations |
| `edit_rate` | `edited_and_approved` / total approvals |
| `median_time_to_decision_hours` | Median hours from Recommendation created to Approval acted_at |
| `approval_rate_by_opportunity_type` | `approval_rate` broken down by `Opportunity.type` |
| `approval_rate_by_channel` | `approval_rate` broken down by channel type in Decision.channel_ids |
| `approval_rate_trend_30d` | Approval rate for last 30 days vs. prior 30 days |

These KPIs are surfaced in the admin UI and fed into Learning records as signals (Phase 8).

---

## 8. Decision Effectiveness Metrics

Decision effectiveness answers: "When Atlas committed a Decision with a predicted outcome, how close was the actual result?"

**Table source:** `CampaignKpiSnapshot` (where `snapshot_type = 'final'`) joined to `Decision` via `Campaign.decision_id`

| Metric | Computation |
|--------|-------------|
| `decisions_total` | Count of committed Decisions with a completed Campaign and final KPI snapshot |
| `exceeded_pct` | % of Decisions where `performance_rating = 'exceeded'` |
| `met_pct` | % of Decisions where `performance_rating = 'met'` |
| `below_pct` | % of Decisions where `performance_rating = 'below'` |
| `accuracy_rate` | `(exceeded + met) / decisions_total` — how often the Decision outcome was at least "met" |
| `accuracy_by_detector` | `accuracy_rate` broken down by which `OpportunityDetector` produced the Opportunity |
| `accuracy_by_campaign_type` | `accuracy_rate` broken down by `Campaign.campaign_type` |
| `avg_composite_score_for_exceeded` | Mean `Opportunity.composite_score` for campaigns that exceeded expectations |
| `avg_composite_score_for_below` | Mean `Opportunity.composite_score` for campaigns that fell below expectations |

The gap between `avg_composite_score_for_exceeded` and `avg_composite_score_for_below` indicates whether the scoring formula is predictive. A large gap means scoring is calibrated; a small gap means the formula needs recalibration.

**Computed by:** `DecisionEffectivenessService` (no persistence — assembled from existing records)

---

## 9. BusinessBrain Feedback Loop

Analytics outcomes feed back into the `BusinessBrain` to improve future Decisions. This is the bridge between Phase 7 (Measure) and Phase 8 (Learn).

### 9.1 Feedback Flow

```
CampaignKpiSnapshot (final)
→ LearningService::recordFromMetrics(Campaign, CampaignKpiSnapshot)
→ Learning record created (source_type: execution_result)
→ [Phase 8] ApplyLearnings job reads unapplied Learnings
→ [Phase 8] Facts and Knowledge updated
→ [Phase 8] BusinessBrain updated on next assembly
```

Phase 7 is responsible only for creating the `Learning` record. Phase 8 is responsible for applying it.

### 9.2 Learning Records Created from Analytics

`LearningService::recordFromMetrics()` produces one or more `Learning` records per finalized `CampaignKpiSnapshot`:

| Signal | Created when | Subject |
|--------|-------------|---------|
| `channel_outperformed` | `best_channel` engagement_rate ≥ 1.5× second-best | `channel` |
| `channel_underperformed` | channel engagement_rate < 50% of campaign average | `channel` |
| `campaign_type_succeeded` | `performance_rating = 'exceeded'` | `campaign_type` (via opportunity_type) |
| `campaign_type_underperformed` | `performance_rating = 'below'` for 2+ consecutive campaigns of same type | `opportunity_type` |
| `email_deliverability_issue` | `bounce_rate > 5%` or `spam_complaint_rate > 0.1%` | `channel.email` |
| `high_unsubscribe_rate` | Email `unsubscribes / delivered > 1%` | `channel.email` |
| `content_angle_engaged` | Campaign with a specific `channel_strategy.angle` that exceeded expectations | `content_asset` |
| `optimal_timing_signal` | Campaign published at a time-of-day that correlated with above-average open rate | `channel.email` |

Each `Learning` record carries a `value` JSON payload that Phase 8 can use to update the relevant Fact or Knowledge:

```json
{
    "signal": "channel_outperformed",
    "channel_type": "email",
    "engagement_rate": 0.124,
    "campaign_id": "01HZ...",
    "campaign_type": "featured_item",
    "period": "2026-06-26"
}
```

---

## 10. Learning Inputs from Analytics

The following channels of information flow from Analytics into the Learning system:

| Input type | Source | Learning signal | Phase 8 action |
|------------|--------|-----------------|----------------|
| Email engagement | `ExecutionMetric.metrics.open_rate` | `high_email_engagement` | Strengthen email channel affinity Fact |
| Email deliverability | `bounces_hard + spam_complaints` | `deliverability_degraded` | Create Knowledge entry flagging list health |
| Social reach | `normalised_reach` per post | `social_reach_benchmark` | Update benchmark Fact for future predictions |
| Best-performing angle | Blueprint `channel_strategy.angle` + performance rating | `content_angle_engaged` | Strengthen angle preference in Knowledge |
| Time-of-send performance | `published_at` hour-of-day + open rate | `optimal_timing_signal` | Update send-time preference Knowledge |
| Decision accuracy | `performance_rating` per Decision | `decision_outcome` | Adjust scoring weights (Phase 8) |
| Repeated below | 2+ consecutive `below` campaigns of same type | `campaign_type_cooling` | Reduce opportunity score for that type |
| Approval after edit | `Approval.action = 'edited_and_approved'` with `edits` diff | `content_preference` | Update content generation preference Knowledge |

**No input uses individual recipient data.** All signals are derived from aggregate platform metrics.

---

## 11. Provider Abstraction

### 11.1 AnalyticsProvider Interface

Each channel's metric provider implements:

```php
interface AnalyticsProvider
{
    /**
     * Pull metrics for a published item identified by its platform-assigned ID.
     * Returns the raw provider response. No normalisation.
     */
    public function pull(string $platformId, ChannelCredentials $credentials): array;

    /**
     * Normalise the raw provider response into the standard metric key map (see §5).
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
     * Returns the provider type string this provider handles (e.g., 'postmark', 'instagram_graph').
     */
    public function supports(string $providerType): bool;
}
```

### 11.2 AnalyticsProviderRegistry

```php
class AnalyticsProviderRegistry
{
    public function register(AnalyticsProvider $provider): void;
    public function for(string $providerType): AnalyticsProvider;
    // throws UnknownAnalyticsProviderException if none registered
}
```

Registered in `AnalyticsServiceProvider`. Binding mirrors `PublisherServiceProvider`.

### 11.3 FakeAnalyticsProvider

For tests — implements `AnalyticsProvider`:

```php
class FakeAnalyticsProvider implements AnalyticsProvider
{
    public function queueMetrics(array $metrics): static;
    public function queueFailure(\Throwable $e): static;
    public function pull(string $platformId, ChannelCredentials $credentials): array;
    public function normalize(array $raw): array;
    public function assertPulled(int $count = 1): void;
    public function assertNotPulled(): void;
    public function supports(string $providerType): bool; // returns true always
}
```

No test calls a real platform API. All analytics tests use `FakeAnalyticsProvider`.

### 11.4 Provider Map

| Channel type | Default analytics provider | Provider type string |
|--------------|---------------------------|---------------------|
| `email` (Postmark) | `PostmarkAnalyticsProvider` | `'postmark'` |
| `email` (Mailgun) | `MailgunAnalyticsProvider` | `'mailgun'` |
| `instagram` | `InstagramGraphAnalyticsProvider` | `'instagram_graph'` |
| `facebook` | `FacebookGraphAnalyticsProvider` | `'facebook_graph'` |
| `linkedin` | `LinkedInAnalyticsProvider` | `'linkedin'` |
| `x` | `XAnalyticsProvider` | `'x_api_v2'` |
| `sms` (Twilio) | `TwilioAnalyticsProvider` | `'twilio'` |
| `blog` | `LogAnalyticsProvider` (no-op) | `'log'` |
| `landing_page` | `LogAnalyticsProvider` (no-op) | `'log'` |

`LogAnalyticsProvider` returns an empty `metrics` array and sets `is_final = true` immediately. It is the default for channels with no API-based metric retrieval.

---

## 12. Data Retention

| Data | Retention | Reason |
|------|-----------|--------|
| `ExecutionMetric.raw` | 1 year | Full provider response; useful for debugging and re-processing |
| `ExecutionMetric.metrics` | Permanent | Normalised KPIs feed Learning records; never purge |
| `CampaignKpiSnapshot` | Permanent | Historical performance is load-bearing for trend analysis and decision effectiveness |
| `MetricRetrievalLog` | 90 days | Audit trail for debugging retrieval failures |
| Webhook payloads (in `MetricRetrievalLog.error` or processed context) | 90 days | Same as above |

**Raw metric pruning:** A scheduled `PruneRawMetrics` job runs monthly and nulls `ExecutionMetric.raw` for records older than 1 year. The `metrics` (normalised) column is never nulled.

**Hard deletes:** No analytics record is ever hard-deleted unless its parent `Execution` is purged, which is not supported in the current data model.

---

## 13. Privacy Considerations

### 13.1 Principles

Atlas does not:

- Track individual email recipients across campaigns
- Store recipient email addresses or identifiers
- Place tracking pixels on external web pages not owned by Atlas
- Participate in cross-site or cross-device attribution
- Sell, share, or aggregate data across companies

Atlas does:

- Store aggregate, platform-reported metrics (opens, clicks, impressions) at the campaign level
- Store the ESP's message ID (`platform_id`) as a lookup key, not as a way to identify individuals
- Use normalised engagement rates for internal ML/scoring — never raw recipient data

### 13.2 Email-Specific Considerations

Email engagement metrics (opens, clicks) are subject to two important caveats:

1. **Apple Mail Privacy Protection (MPP):** iOS/macOS Mail pre-fetches images, causing machine-generated "opens" that are not human reads. When computing `open_rate`, Atlas notes that email open data may be inflated due to MPP and does not use raw open counts as a primary signal in decision scoring.
2. **CAN-SPAM / GDPR compliance:** Unsubscribe signals must be honoured immediately. When `Learning.signal = 'high_unsubscribe_rate'` is created, the system must surface a notification to the company owner. Atlas does not manage the recipient list — it reports the signal and surfaces it to the user.

### 13.3 Platform API TOS Compliance

Metric data is fetched using platform-provided APIs and used only for:
- Displaying campaign performance to the Company that published the content
- Generating Learning records to improve Atlas's recommendations for that same Company

Metric data is not shared across companies, not aggregated into benchmarks without anonymisation, and not used to train models that benefit other companies without explicit consent.

### 13.4 Data Classification

`ExecutionMetric` data is classified as **Company Confidential** under the data classification policy in `docs/technical/Database.md`. Access is restricted to authenticated Company members. Metric data is never returned to users of other Companies.

---

## 14. Acceptance Criteria

All criteria are verifiable by automated tests using `FakeAnalyticsProvider`. No test calls a real platform API.

### Metric Retrieval

- [ ] `ExecutionCompleted` event triggers scheduling of `RetrieveExecutionMetrics` job with correct delay
- [ ] `RetrieveExecutionMetrics` calls `AnalyticsProvider::pull()` with the correct `platform_id` and credentials
- [ ] A retrieved `ExecutionMetric` record is created with normalised `metrics` populated
- [ ] A `MetricRetrievalLog` record is appended for every retrieval attempt (success and failure)
- [ ] If the window is not closed, the job re-schedules itself with `repollingIntervalHours()` delay
- [ ] If the window is closed, the job calls `CampaignKpiService::snapshotIfReady()`
- [ ] A failed retrieval (provider error) is logged to `MetricRetrievalLog` with `status = 'failed'`; the job retries

### Webhook Processing

- [ ] `POST /api/analytics/webhooks/{provider}` accepts a webhook from a registered provider
- [ ] Invalid HMAC signature returns `401` without processing
- [ ] A valid Postmark `Open` event is parsed and merged into the correct `ExecutionMetric.metrics`
- [ ] Duplicate webhook events for the same `platform_id` + `event_type` are idempotent (increment count, not new record)
- [ ] An unknown provider type returns `422`

### Campaign KPI Snapshot

- [ ] A `CampaignKpiSnapshot` with `snapshot_type = 'interim'` is created after the first execution window closes
- [ ] A `CampaignKpiSnapshot` with `snapshot_type = 'final'` is created after all execution windows are closed
- [ ] `actual_kpis.best_channel` identifies the channel with the highest `normalised_engagement_rate`
- [ ] `performance_rating = 'exceeded'` when actual engagement ≥ 125% of expected baseline
- [ ] `performance_rating = 'insufficient_data'` when no metrics were retrieved for any execution

### Learning Records

- [ ] `LearningService::recordFromMetrics()` creates a `Learning` record with `signal = 'channel_outperformed'` when one channel's rate is ≥ 1.5× the next
- [ ] `LearningService::recordFromMetrics()` creates a `Learning` record with `signal = 'email_deliverability_issue'` when bounce rate > 5%
- [ ] Learning records have `applied_at = null` (not applied until Phase 8)
- [ ] No Learning record references individual recipient data

### Provider Abstraction

- [ ] Swapping from `PostmarkAnalyticsProvider` to `MailgunAnalyticsProvider` requires only a credential update (`provider_type`) — no code changes
- [ ] `AnalyticsProviderRegistry::for()` throws `UnknownAnalyticsProviderException` for unregistered provider types
- [ ] All tests use `FakeAnalyticsProvider` — zero real API calls in CI

### Privacy

- [ ] `ExecutionMetric` records are not accessible via any endpoint to members of a different Company
- [ ] `ExecutionMetric.raw` does not contain subscriber email addresses or PII from the provider response (provider API responses must be scrubbed in `normalize()` before storing in `metrics`)

---

## 15. Future Extensibility

### 15.1 Optimal Send Time

Once enough `ExecutionMetric` records accumulate for a company, a future `OptimalSendTimeAnalyst` can identify the hours-of-day and days-of-week with the highest `open_rate` or `engagement_rate` for each channel. The `ContentAsset.scheduled_at` would be set automatically based on this analysis rather than defaulting to immediate dispatch.

### 15.2 Cross-Channel Attribution Model

The MVP uses platform-reported metrics in isolation. A future `AttributionService` could implement multi-touch attribution: a customer who sees a LinkedIn post, then opens an email, then clicks a landing page gets credit distributed across channels. This requires a deterministic identifier (company-provided CRM contact ID) — never introduced without explicit product decision.

### 15.3 A/B Content Testing

With the existing `Execution` model (one per `ContentAsset`), A/B testing requires only that two `ContentAsset` records exist in the same campaign, each with a different `body` or `metadata`, published to the same channel at the same time. `CampaignKpiService` would compare the two `ExecutionMetric` records and record the winner as a Learning signal. No structural changes required.

### 15.4 Benchmark Comparisons

As Atlas accumulates campaign data across companies (with consent and anonymisation), a future `IndustryBenchmarkService` could provide: "Your email open rate is 12.4% — the average for auction/collectibles companies using Atlas is 9.1%." This is intentionally excluded from Phase 7 to avoid premature cross-company data sharing.

### 15.5 Real-Time Analytics

The current polling model is sufficient for campaign-level analytics. A future real-time tier would use a streaming queue (Kafka, SQS FIFO) for webhook events and aggregate them with a windowed counter (Redis sorted sets) to support live dashboards. The `AnalyticsWebhookHandler` interface is already designed for this — the streaming back-end is a transport concern, not an interface concern.

### 15.6 Prompt Performance Tracking

Phase 8 specifies tracking approval rates per `prompt_version`. Analytics data extends this: a future `PromptPerformanceService` would correlate `prompt_version` on `ContentAsset` (via `Campaign.prompt_version`) with `CampaignKpiSnapshot.performance_rating` to identify which prompt versions produce higher-performing campaigns, not just higher approval rates.
