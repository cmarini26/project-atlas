# Milestone 8 — Analytics Engine Implementation Plan

**Spec:** `specs/core/analytics-engine.md` (authoritative — read it before writing any code)  
**Roadmap phase:** Phase 7 — Analytics  
**Status:** Planning — not yet started  
**Created:** 2026-06-26

---

## Overview

Milestone 8 implements the Analytics Engine. After a Campaign is published, Atlas has no feedback on what happened — it cannot measure whether the content performed, cannot compare actual engagement against the Decision's `expected_impact`, and cannot create the Learning records that feed Phase 8 (Learning).

This milestone closes that gap. When Milestone 8 is complete:

- Every completed `Execution` triggers metric retrieval from the publishing platform
- Webhook callbacks from email providers enrich metrics in near-real-time
- Campaign KPIs are computed and stored after each retrieval window closes
- Actual performance is compared to `expected_impact` from the original Decision
- `Learning` records are created from finalized KPI snapshots, ready for Phase 9 to apply

Atlas will be able to answer: "Did this campaign work? What performed best? What should we do differently?"

---

## Scope

### In Scope

- Three new database tables: `execution_metrics`, `campaign_kpi_snapshots`, `metric_retrieval_logs`
- Three new Eloquent models: `ExecutionMetric`, `CampaignKpiSnapshot`, `MetricRetrievalLog`
- `AnalyticsProvider` interface and `AnalyticsProviderRegistry`
- `FakeAnalyticsProvider` (test double — all tests use this; no real API calls in CI)
- `LogAnalyticsProvider` (no-op provider for blog/landing page channels)
- `AnalyticsWebhookHandler` interface and `WebhookHandlerRegistry`
- `WebhookEvent` value object
- `AnalyticsWebhookController` — webhook ingestion endpoint with HMAC validation
- `PostmarkWebhookHandler` — first real webhook handler (email opens, clicks, bounces, complaints)
- `RetrieveExecutionMetrics` job — scheduled polling, self-rescheduling until window closes
- `ProcessAnalyticsWebhookEvent` job — processes a parsed `WebhookEvent` into `ExecutionMetric`
- `PruneRawMetrics` job — monthly; nulls `ExecutionMetric.raw` for records older than 1 year
- `ScheduleMetricRetrieval` listener — responds to `ExecutionCompleted`, dispatches first poll
- `CampaignKpiService` — `aggregate()`, `snapshotIfReady()`, `ratePerformance()`, `bestChannel()`
- `RecommendationKpiService` — approval/rejection/edit rates and trends (no persistence)
- `DecisionEffectivenessService` — decision accuracy metrics (no persistence)
- `LearningService::recordFromMetrics()` — creates Learning records from finalized snapshots; 8 signal types
- `AnalyticsServiceProvider` — binds registries as singletons; registers `LogAnalyticsProvider`
- Filament campaign performance view — expected vs. actual KPIs, performance rating badge
- Webhook route: `POST /api/analytics/webhooks/{provider}`
- Scheduled task: `PruneRawMetrics` monthly via Laravel scheduler
- Test suite: ~40 new tests, all using `FakeAnalyticsProvider`

### Out of Scope

The following are explicitly **not** part of Milestone 8. Do not implement them.

| Item | Reason |
|------|--------|
| `ApplyLearnings` job | Phase 9 (Learning) — Milestone 8 creates Learning records, Phase 9 applies them |
| Scoring weight recalibration | Phase 9 |
| Cross-company aggregate analytics | Phase 9 |
| `PostmarkAnalyticsProvider` (pull-based) | Requires live Postmark API — implement after `FakeAnalyticsProvider` and `LogAnalyticsProvider` are in place and tested |
| `InstagramGraphAnalyticsProvider`, `FacebookGraphAnalyticsProvider`, `LinkedInAnalyticsProvider`, `XAnalyticsProvider`, `TwilioAnalyticsProvider` | Not in M8 — social/SMS publishers aren't real yet |
| Paid media analytics | Not on roadmap |
| Individual subscriber/contact tracking | Never |
| Real-time streaming analytics | Not on roadmap |
| Frontend UI beyond Filament admin | Not in scope until frontend framework is decided |
| Optimal send time calculation | Future extensibility (§15.1 of spec) |

---

## Dependencies

Everything listed below must exist and be in a working state before Milestone 8 begins. Verify each before writing the first migration.

### Models (existing)

| Model | Used for |
|-------|---------|
| `Execution` | Source of `platform_id`, `result`, `completed_at`, `channel_id`, `company_id` |
| `Campaign` | Joined to `CampaignKpiSnapshot`; `decision_id` used in effectiveness metrics |
| `Decision` | `rationale.expected_impact` copied into `CampaignKpiSnapshot` |
| `ChannelCredentials` | Resolved in `RetrieveExecutionMetrics` via `ChannelCredentialsRepository` |
| `Channel` | `type` column used to resolve correct `AnalyticsProvider` |
| `Learning` | Created by `LearningService::recordFromMetrics()` |
| `Opportunity` | `type` and `composite_score` used in `DecisionEffectivenessService` |
| `Recommendation` | `Approval` records linked; used by `RecommendationKpiService` |
| `Approval` | `action` and `acted_at` used by `RecommendationKpiService` |

### Services (existing)

| Service | Used for |
|---------|---------|
| `ChannelCredentialsRepository::for()` | Credentials lookup inside `RetrieveExecutionMetrics` |
| `ExecutionService` | Provides `completed` Execution records as input |

### Events (existing)

| Event | Trigger |
|-------|---------|
| `ExecutionCompleted` | Triggers `ScheduleMetricRetrieval` listener |

### Jobs (existing)

| Job | Relationship |
|-----|-------------|
| `PublishContent` | Fires `ExecutionCompleted` which starts the analytics chain |

### Queue

The `observations` queue already exists and is configured. All new analytics jobs run on `observations`. No new queue is needed.

### Migrations (existing)

| Migration | Why relevant |
|-----------|-------------|
| `create_executions_table` | `execution_metrics` FK target |
| `create_channel_credentials_table` | Credentials lookup for provider resolution |

---

## Implementation Phases

Work through these phases in order. Each phase is independently committable and testable before the next begins.

---

### Phase 1 — Analytics Domain Models

**Goal:** Three tables and three models. No logic yet — just schema and Eloquent.

#### 1.1 Migrations

Create in this order (FK dependencies):

**`create_execution_metrics_table`**

```
id                  char(26)    PK (ULID)
company_id          char(26)    FK companies.id
execution_id        char(26)    FK executions.id  UNIQUE
campaign_id         char(26)    FK campaigns.id
channel_type        varchar(50)
provider_type       varchar(50)
platform_id         varchar(255)
retrieved_at        timestamp   nullable
window_closes_at    timestamp   nullable
is_final            boolean     default false
raw                 json        nullable
metrics             json        nullable
created_at          timestamp
updated_at          timestamp
```

Indexes: `UNIQUE (execution_id)`; `INDEX (campaign_id, is_final)`; `INDEX (company_id, channel_type, retrieved_at)`

**`create_campaign_kpi_snapshots_table`**

```
id                  char(26)    PK (ULID)
company_id          char(26)    FK companies.id
campaign_id         char(26)    FK campaigns.id
snapshot_type       enum        ['interim', 'final']
snapshotted_at      timestamp
channels_included   json
expected_impact     json        nullable
actual_kpis         json
performance_rating  enum        ['exceeded', 'met', 'below', 'insufficient_data']
created_at          timestamp
```

No `updated_at` — each snapshot is an immutable point-in-time record. Indexes: `INDEX (campaign_id, snapshot_type)`; `INDEX (company_id, snapshotted_at)`

**`create_metric_retrieval_logs_table`**

```
id                  char(26)    PK (ULID)
execution_id        char(26)    FK executions.id
provider_type       varchar(50)
attempted_at        timestamp
status              enum        ['success', 'failed', 'skipped']
error               text        nullable
response_code       smallint    nullable
created_at          timestamp
```

No `updated_at`. Append-only. Index: `INDEX (execution_id)`

#### 1.2 Models

**`App\Models\ExecutionMetric`**
- `use HasUlids, BelongsToCompany`
- `$casts`: `raw → array`, `metrics → array`, `retrieved_at → datetime`, `window_closes_at → datetime`, `is_final → boolean`
- `belongsTo` Company, Execution, Campaign
- Scope: `::forCampaign(Campaign)` → `where('campaign_id', ...)`
- Scope: `::pending()` → `where('is_final', false)`

**`App\Models\CampaignKpiSnapshot`**
- `use HasUlids, BelongsToCompany`
- `$casts`: `channels_included → array`, `expected_impact → array`, `actual_kpis → array`, `snapshotted_at → datetime`
- `belongsTo` Company, Campaign
- Scope: `::final()` → `where('snapshot_type', 'final')`
- No `updated_at` (set `public $timestamps = false` and manually cast `created_at`)

**`App\Models\MetricRetrievalLog`**
- `use HasUlids`
- No `BelongsToCompany` — queried via `execution_id`
- `$casts`: `attempted_at → datetime`, `response_code → integer`
- `updated_at = false`
- `belongsTo` Execution

#### 1.3 Relationships to add to existing models

- `Execution::hasOne(ExecutionMetric::class)`
- `Campaign::hasMany(CampaignKpiSnapshot::class)`

#### 1.4 Exit criteria for Phase 1

- [ ] All three migrations run cleanly against PostgreSQL (local and CI)
- [ ] All three models pass PHPStan level 8
- [ ] `ExecutionMetric::forCampaign()` and `::pending()` scopes tested
- [ ] `CampaignKpiSnapshot::final()` scope tested
- [ ] No `updated_at` on `CampaignKpiSnapshot` or `MetricRetrievalLog`

---

### Phase 2 — Analytics Provider Infrastructure

**Goal:** The provider interface, registry, and test double. No real API calls. No jobs yet.

#### 2.1 Interface

**`App\Services\Analytics\Contracts\AnalyticsProvider`** (interface)

```
pull(string $platformId, ChannelCredentials $credentials): array
normalize(array $raw): array
isWindowClosed(Execution $execution): bool
pollingDelayHours(): int
repollingIntervalHours(): int
supports(string $providerType): bool
```

#### 2.2 Registry

**`App\Services\Analytics\AnalyticsProviderRegistry`**
- Same pattern as `ChannelPublisherRegistry` — first-match by `supports()`
- `register(AnalyticsProvider)`, `for(string $providerType)`, `all()`
- `for()` throws `UnknownAnalyticsProviderException` when no provider matches

**`App\Services\Analytics\Exceptions\UnknownAnalyticsProviderException`**
- Extends `\RuntimeException`
- Message: "No analytics provider registered for type '{type}'"

#### 2.3 FakeAnalyticsProvider

**`App\Services\Analytics\FakeAnalyticsProvider`** (implements `AnalyticsProvider`)

```
queueMetrics(array $metrics): static
queueFailure(\Throwable $e): static
pull(string $platformId, ChannelCredentials $credentials): array
normalize(array $raw): array
isWindowClosed(Execution $execution): bool   — returns true by default (configurable)
pollingDelayHours(): int   — returns 0
repollingIntervalHours(): int   — returns 0
assertPulled(int $count = 1): void
assertNotPulled(): void
sentCount(): int
supports(string $providerType): bool   — returns true always
```

Pull behaviour: dequeues from a queue (throws exception if queue is empty and no fallback); stores call in `$pulled[]`.

#### 2.4 LogAnalyticsProvider

**`App\Services\Analytics\LogAnalyticsProvider`** (implements `AnalyticsProvider`)

- `pull()`: returns `[]` (no API)
- `normalize()`: returns `[]`; adds three normalised keys all as `null`
- `isWindowClosed()`: returns `true` immediately (no collection window)
- `pollingDelayHours()`: `0`
- `supports()`: `$providerType === 'log'`

Used for `blog` and `landing_page` channel types.

#### 2.5 WebhookEvent value object

**`App\Domain\Analytics\ValueObjects\WebhookEvent`** (readonly)

```
providerType: string
platformMessageId: string
eventType: string   — 'delivery', 'open', 'click', 'bounce', 'complaint'
occurredAt: \DateTimeImmutable
metadata: array
```

#### 2.6 AnalyticsServiceProvider

**`App\Providers\AnalyticsServiceProvider`**

```
register(): bind AnalyticsProviderRegistry and WebhookHandlerRegistry as singletons
boot(): register LogAnalyticsProvider in AnalyticsProviderRegistry
```

Register in `config/app.php` providers array.

#### 2.7 Exit criteria for Phase 2

- [ ] `AnalyticsProviderRegistry::for('log')` resolves `LogAnalyticsProvider`
- [ ] `AnalyticsProviderRegistry::for('unknown')` throws `UnknownAnalyticsProviderException`
- [ ] `FakeAnalyticsProvider::assertPulled(1)` passes after one `pull()` call
- [ ] `FakeAnalyticsProvider::queueFailure()` causes `pull()` to throw
- [ ] PHPStan level 8 clean

---

### Phase 3 — Retrieval Jobs and Event Wiring

**Goal:** Metric polling activated by `ExecutionCompleted`. Self-rescheduling until window closes.

#### 3.1 ScheduleMetricRetrieval listener

**`App\Listeners\ScheduleMetricRetrieval`**

- Listens on: `ExecutionCompleted`
- Pre-condition check: `$event->execution->result['platform_id'] ?? null` — skip if null (e.g., LogChannelPublisher executions)
- Resolves credentials and provider type from `ChannelCredentialsRepository`
- Dispatches `RetrieveExecutionMetrics::dispatch($execution)->onQueue('observations')->delay(hours: $provider->pollingDelayHours())`
- If `pollingDelayHours() === 0` (log provider), dispatches immediately

Register in `AppServiceProvider::$listen`:
```
ExecutionCompleted::class => [ScheduleMetricRetrieval::class]
```

#### 3.2 RetrieveExecutionMetrics job

**`App\Jobs\RetrieveExecutionMetrics`** (implements `ShouldQueue`)

- Queue: `observations`
- Tries: `3`
- Timeout: `60` seconds
- Constructor: `Execution $execution` (or execution ID for serialisation)

Handle flow:
1. Reload `Execution::withoutGlobalScopes()->findOrFail($id)` — bail if status is not `completed`
2. Resolve `ChannelCredentials` via `ChannelCredentialsRepository::for($execution->company_id, $channelType)`
3. Resolve `AnalyticsProvider` via `AnalyticsProviderRegistry::for($credentials->provider_type)`
4. Call `$provider->pull($execution->result['platform_id'], $credentials)` — wrap in try/catch
5. On success: `ExecutionMetric::updateOrCreate(['execution_id' => $execution->id], [fields])` — set `is_final = $provider->isWindowClosed($execution)`; set `window_closes_at` if not already set
6. Append `MetricRetrievalLog::create([status: 'success', ...])`
7. If window not closed: self-dispatch with `->delay(hours: $provider->repollingIntervalHours())`
8. If window closed: call `CampaignKpiService::snapshotIfReady($execution->campaign_id)`
9. On provider failure: `MetricRetrievalLog::create([status: 'failed', error: $e->getMessage()])` → re-throw (job retries)

**Important:** `updateOrCreate` ensures idempotency — duplicate dispatches do not create duplicate `ExecutionMetric` rows.

#### 3.3 PruneRawMetrics job

**`App\Jobs\PruneRawMetrics`** (implements `ShouldQueue`)

- Queue: `maintenance`
- Nulls `ExecutionMetric.raw` where `retrieved_at < now()->subYear()`
- Logs count of records pruned to `analytics` log channel

Scheduler entry (in `routes/console.php` or `Kernel.php`):
```php
$schedule->job(PruneRawMetrics::class)->monthly();
```

#### 3.4 Exit criteria for Phase 3

- [ ] `ExecutionCompleted` event causes `RetrieveExecutionMetrics` to be dispatched with correct delay
- [ ] If `platform_id` is null in `Execution.result`, listener does not dispatch
- [ ] `RetrieveExecutionMetrics` creates `ExecutionMetric` on success
- [ ] `RetrieveExecutionMetrics` appends `MetricRetrievalLog` on both success and failure
- [ ] `RetrieveExecutionMetrics` re-dispatches itself when `isWindowClosed()` returns false
- [ ] `RetrieveExecutionMetrics` calls `CampaignKpiService::snapshotIfReady()` when window closes
- [ ] A second dispatch for the same `execution_id` does not create a second `ExecutionMetric` row
- [ ] `PruneRawMetrics` nulls `raw` on records older than 1 year and does not touch `metrics`

---

### Phase 4 — Webhook Infrastructure

**Goal:** Secure, provider-agnostic webhook ingestion. First real handler: Postmark.

#### 4.1 AnalyticsWebhookHandler interface

**`App\Services\Analytics\Contracts\AnalyticsWebhookHandler`** (interface)

```
verify(Request $request): void     — throws on invalid signature; never swallows
parse(Request $request): array     — returns list<WebhookEvent>
supports(string $providerType): bool
```

#### 4.2 WebhookHandlerRegistry

**`App\Services\Analytics\WebhookHandlerRegistry`**
- Same pattern as `ChannelPublisherRegistry`
- `register()`, `for(string $providerType)`, `all()`
- `for()` throws `UnknownWebhookProviderException` when no handler matches

#### 4.3 AnalyticsWebhookController

**`App\Http\Controllers\Api\AnalyticsWebhookController`**

Route: `POST /api/analytics/webhooks/{provider}`  
No CSRF (API-only; stateless). Add to API middleware group.

Flow:
1. `$handler = $this->registry->for($provider)` — return `422` if `UnknownWebhookProviderException`
2. `$handler->verify($request)` — return `401` on failure; do not log payload on auth failure
3. Append raw payload to `MetricRetrievalLog` (status: `skipped`, before processing)
4. `$events = $handler->parse($request)`
5. Dispatch `ProcessAnalyticsWebhookEvent::dispatch($event)->onQueue('observations')` for each
6. Return `200 {"accepted": N}`

#### 4.4 PostmarkWebhookHandler

**`App\Services\Analytics\Webhooks\PostmarkWebhookHandler`** (implements `AnalyticsWebhookHandler`)

- `verify()`: validates `X-Postmark-Signature` header using HMAC-SHA256 with the webhook secret from config
- `parse()`: handles Postmark's JSON event structure; maps `RecordType` → `eventType`:
  - `Delivery` → `'delivery'`
  - `Open` → `'open'`
  - `Click` → `'click'`
  - `Bounce` → `'bounce'` (include `BounceType` in metadata)
  - `SpamComplaint` → `'complaint'`
- Maps `MessageID` → `platformMessageId`
- `supports()`: `$providerType === 'postmark'`

Register in `AnalyticsServiceProvider::boot()`.

#### 4.5 ProcessAnalyticsWebhookEvent job

**`App\Jobs\ProcessAnalyticsWebhookEvent`** (implements `ShouldQueue`)

- Queue: `observations`
- Constructor: `WebhookEvent $event`

Handle flow:
1. Find `ExecutionMetric` where `platform_id = $event->platformMessageId` — bail silently if not found (event arrived before poll created the row; acceptable loss — poll will pick it up)
2. Merge event into `metrics` JSON idempotently:
   - For counts (opens, clicks): increment existing value or initialise to 1
   - Track per-event-type counters: `webhook_opens`, `webhook_clicks`, `webhook_bounces`, `webhook_complaints`
3. Re-save `ExecutionMetric`; do not change `is_final`

**Idempotency:** Use a per-event-type counter in `metrics` — duplicate events increment the counter rather than being rejected. This is safe because counts are cumulative.

#### 4.6 Exit criteria for Phase 4

- [ ] `POST /api/analytics/webhooks/postmark` with a valid Postmark `Open` payload merges into `ExecutionMetric.metrics`
- [ ] Invalid HMAC returns `401`; no processing occurs
- [ ] Unknown provider type returns `422`
- [ ] Duplicate `Open` events for the same message ID increment `webhook_opens` rather than creating a new record
- [ ] A webhook event with no matching `ExecutionMetric` silently no-ops (does not error)
- [ ] All handling is tested with a recorded Postmark payload fixture; no live Postmark calls

---

### Phase 5 — Metric Normalisation

**Goal:** Every `AnalyticsProvider::normalize()` produces the standard metric key map. Three cross-channel keys computed on every provider.

#### 5.1 Normalisation contract

`normalize(array $raw): array` takes the raw provider API response and returns a flat associative array using the standardised keys from `specs/core/analytics-engine.md §5`.

Rules:
- Keys not available from the provider are **omitted** — never set to `null` or `0`
- Derived rates (e.g., `open_rate = unique_opens / delivered`) are computed inside `normalize()`, not stored separately
- Division by zero returns the key omitted (not `null`, not `0`, not `INF`)

#### 5.2 Cross-channel normalised keys

Every `normalize()` implementation must emit three keys by selecting the best available proxy:

| Key | Email source | Social source | SMS source | Blog/LP source |
|-----|-------------|---------------|-----------|----------------|
| `normalised_reach` | `delivered` | `reach` or `unique_impressions` | `delivered` | `unique_visitors` |
| `normalised_engagement` | `unique_clicks` | sum of likes+comments+shares+saves | `clicked` | `cta_clicks` or `page_views` |
| `normalised_engagement_rate` | `unique_clicks / delivered` | `(likes+comments+shares+saves) / reach` | `clicked / delivered` | `cta_clicks / unique_visitors` |

If neither source key is available, omit the normalised key.

#### 5.3 LogAnalyticsProvider normalisation

Returns `[]` — all keys absent. `is_final = true` immediately after creation.

#### 5.4 FakeAnalyticsProvider normalisation

`normalize()` returns the `metrics` array that was queued via `queueMetrics()`. It does not validate or transform. The test author is responsible for passing a correctly shaped fixture.

#### 5.5 isWindowClosed() implementation

Window closure is time-based: `$execution->completed_at + pollingWindowHours() < now()`.

Each provider defines `pollingWindowHours()` returning the maximum window (e.g., 48 for email/social, 168 for blog). `isWindowClosed()` computes `$execution->completed_at->addHours($this->pollingWindowHours())->isPast()`.

`LogAnalyticsProvider` returns `true` from `isWindowClosed()` unconditionally.

#### 5.6 Exit criteria for Phase 5

- [ ] `FakeAnalyticsProvider::normalize()` returns the queued metrics unchanged
- [ ] `LogAnalyticsProvider::normalize()` returns `[]`
- [ ] All three normalised keys are present in `FakeAnalyticsProvider` output when source keys exist
- [ ] Division by zero for `normalised_engagement_rate` omits the key (no INF, no exception)
- [ ] `LogAnalyticsProvider::isWindowClosed()` always returns true
- [ ] `FakeAnalyticsProvider::isWindowClosed()` returns true by default

---

### Phase 6 — Campaign KPI Aggregation

**Goal:** `CampaignKpiService` producing `CampaignKpiSnapshot` records with performance ratings.

#### 6.1 CampaignKpiService

**`App\Services\Analytics\CampaignKpiService`**

**`aggregate(string $campaignId): array`**

1. Load all `ExecutionMetric` records for the campaign
2. For each, extract `metrics.normalised_reach`, `metrics.normalised_engagement`, `metrics.normalised_engagement_rate`, `metrics.unique_clicks` (or channel-appropriate click key)
3. Sum across executions into `total_reach`, `total_engagement`, `total_clicks`
4. Compute `total_engagement_rate = total_engagement / total_reach` (omit if reach is 0)
5. Build `channel_breakdown` map keyed by `channel_type`
6. Identify `best_channel` via `bestChannel()`
7. Return the full KPI map (see spec §6 for exact shape)

**`snapshotIfReady(string $campaignId): ?CampaignKpiSnapshot`**

1. Load all `ExecutionMetric` for campaign
2. If none: return null
3. If all `is_final = true`: create/update final snapshot (type: `'final'`)
4. If any `is_final = false`: create interim snapshot only if no interim exists yet (type: `'interim'`)
5. `expected_impact`: read from `Campaign → Decision → rationale.expected_impact`
6. `performance_rating`: `ratePerformance($actualKpis, $expectedImpact)`

**`ratePerformance(array $actualKpis, array $expectedImpact): string`**

Performance rating logic:
- If no `normalised_engagement_rate` in `$actualKpis`: return `'insufficient_data'`
- Parse a numeric baseline from `$expectedImpact`. Expected impact is qualitative text in the MVP — attempt to extract a numeric engagement rate or reach target. If no numeric baseline can be extracted: return `'insufficient_data'`
- `>= 1.25 × baseline` → `'exceeded'`
- `>= 0.75 × baseline` → `'met'`
- `< 0.75 × baseline` → `'below'`

**Note on expected_impact parsing:** The `Decision.rationale.expected_impact` is structured JSON with a `summary` field (qualitative) and may optionally carry a `target_engagement_rate` or `target_reach` field set by `RationaleGenerationAnalyst`. If no numeric field is present, `ratePerformance()` returns `'insufficient_data'`. Phase 9 should update `RationaleGenerationAnalyst` to emit structured numeric targets in `expected_impact` — but this is deferred to not block Milestone 8.

**`bestChannel(array $channelBreakdown): string`**

Returns the channel type key with the highest `engagement_rate` value. Returns empty string if no channels with data.

#### 6.2 Trigger point

`CampaignKpiService::snapshotIfReady()` is called by `RetrieveExecutionMetrics` after the window closes. It is not called from a listener — it is driven directly by the job.

#### 6.3 Exit criteria for Phase 6

- [ ] `aggregate()` sums reach and engagement correctly across multiple `ExecutionMetric` records
- [ ] `bestChannel()` returns the channel with the highest `normalised_engagement_rate`
- [ ] `snapshotIfReady()` creates `snapshot_type = 'interim'` after first window closes if others remain open
- [ ] `snapshotIfReady()` creates `snapshot_type = 'final'` when all windows are closed
- [ ] `snapshotIfReady()` does not create a duplicate `final` snapshot if called twice
- [ ] `ratePerformance()` returns `'exceeded'` at ≥ 125% of baseline
- [ ] `ratePerformance()` returns `'met'` at 100% of baseline
- [ ] `ratePerformance()` returns `'insufficient_data'` when no numeric baseline exists
- [ ] `expected_impact` is copied from the Decision onto the snapshot at creation time

---

### Phase 7 — Recommendation and Decision KPIs

**Goal:** Read-only KPI computation services for Filament UI and future Learning inputs. No new tables.

#### 7.1 RecommendationKpiService

**`App\Services\Analytics\RecommendationKpiService`**

All methods take a `Company` or `companyId` and query existing records.

**`forCompany(string $companyId): array`** — returns:
```
approval_rate                   float (0–1)
rejection_rate                  float (0–1)
edit_rate                       float (0–1, proportion of approvals that were edited_and_approved)
median_time_to_decision_hours   float|null
approval_rate_by_opportunity_type  array<string, float>
approval_rate_by_channel           array<string, float>
approval_rate_trend_30d            array{current: float, prior: float, delta: float}
total_recommendations           int
acted_on                        int
```

**Query approach:** Join `recommendations → approvals → decisions → opportunities`. Use `DB::raw` for median calculation rather than loading all records. The company's entire Recommendation history is queried — no pagination in the service (Filament can paginate the UI).

#### 7.2 DecisionEffectivenessService

**`App\Services\Analytics\DecisionEffectivenessService`**

**`forCompany(string $companyId): array`** — returns:
```
decisions_total                     int
exceeded_pct                        float
met_pct                             float
below_pct                           float
accuracy_rate                       float   (exceeded + met) / total
accuracy_by_detector                array<string, float>
accuracy_by_campaign_type           array<string, float>
avg_composite_score_for_exceeded    float|null
avg_composite_score_for_below       float|null
```

**Query approach:** Join `campaign_kpi_snapshots (final) → campaigns → decisions → opportunities`. Detector name comes from `Opportunity.type` (the detector that produced the opportunity type is implied by convention — `featured_item` → `FeaturedItemDetector`). If direct detector attribution is needed later, add a `detector_class` column to `opportunities`.

#### 7.3 Exit criteria for Phase 7

- [ ] `RecommendationKpiService::forCompany()` returns correct `approval_rate` given seeded data
- [ ] `RecommendationKpiService::forCompany()` returns correct `edit_rate` (approvals with `edited_and_approved` action)
- [ ] `approval_rate_trend_30d` correctly identifies improvement when recent approvals > prior period
- [ ] `DecisionEffectivenessService::forCompany()` returns `accuracy_rate = 1.0` when all snapshots are `exceeded`
- [ ] `DecisionEffectivenessService::forCompany()` handles zero decisions (no division by zero)
- [ ] Both services return sensible defaults (zeroes / nulls) when no data exists

---

### Phase 8 — BusinessBrain Feedback (Learning Records)

**Goal:** `LearningService::recordFromMetrics()` creates the eight analytics-driven signal types.

#### 8.1 LearningService::recordFromMetrics()

**`App\Services\Learning\LearningService::recordFromMetrics(Campaign $campaign, CampaignKpiSnapshot $snapshot): void`**

This method implements the eight signals from `specs/core/analytics-engine.md §9.2`.

For each signal, if the condition is met, call `LearningService::create(...)` (or the existing Learning creation path) with:
- `source_type: 'execution_result'`
- `source_id: $snapshot->id`
- `subject_type: appropriate type`
- `subject_id: relevant ID or null`
- `signal: signal string`
- `value: structured payload JSON`
- `applied_at: null` (Phase 9 applies)

**Signal conditions to implement:**

| Signal | Condition | Check |
|--------|-----------|-------|
| `channel_outperformed` | best channel `normalised_engagement_rate` ≥ 1.5× second-best channel rate | Only when ≥ 2 channels have data |
| `channel_underperformed` | a channel's `normalised_engagement_rate` < 50% of campaign average | Any channel below half the average |
| `campaign_type_succeeded` | `performance_rating = 'exceeded'` | Per finalized snapshot |
| `campaign_type_underperformed` | `performance_rating = 'below'` for 2+ consecutive final snapshots of the same campaign type | Requires looking at recent history for the company |
| `email_deliverability_issue` | `metrics.bounces_hard > 0` or `spam_complaints / delivered > 0.001` | Email channels only |
| `high_unsubscribe_rate` | `metrics.unsubscribes / metrics.delivered > 0.01` | Email channels only |
| `content_angle_engaged` | `performance_rating = 'exceeded'` AND `channel_strategy.angle` is identifiable in the Campaign blueprint | Read `Campaign.blueprint.channel_strategy[*].angle` |
| `optimal_timing_signal` | Email channel `open_rate` is in top quartile for this company's email history AND `published_at` hour is identifiable | Requires ≥ 4 prior email `ExecutionMetric` records to compute quartile |

**Key discipline:** `recordFromMetrics()` checks each signal independently. It may create 0 to N `Learning` records per call. It is called once per finalized `CampaignKpiSnapshot`. It is idempotent: if a `Learning` already exists with the same `source_id` and `signal`, it does not create a duplicate.

#### 8.2 Trigger point

Add a call to `LearningService::recordFromMetrics()` inside `CampaignKpiService::snapshotIfReady()` after creating the `final` snapshot:

```php
if ($snapshot->snapshot_type === 'final') {
    $this->learningService->recordFromMetrics($campaign, $snapshot);
}
```

`LearningService` is injected into `CampaignKpiService` constructor.

#### 8.3 Exit criteria for Phase 8

- [ ] `recordFromMetrics()` creates `channel_outperformed` when one channel's rate is ≥ 1.5× next
- [ ] `recordFromMetrics()` does NOT create `channel_outperformed` when only one channel has data
- [ ] `recordFromMetrics()` creates `email_deliverability_issue` when `spam_complaints / delivered > 0.001`
- [ ] `recordFromMetrics()` creates `campaign_type_underperformed` on second consecutive `below` for same type
- [ ] `recordFromMetrics()` creates `optimal_timing_signal` only when ≥ 4 prior email records exist
- [ ] A second call to `recordFromMetrics()` with the same `snapshot_id` does not create duplicate Learning records
- [ ] All Learning records have `applied_at = null`
- [ ] No Learning record's `value` JSON contains email addresses or individual-identifiable data

---

### Phase 9 — Filament UI

**Goal:** Campaign performance visible in the admin panel. Expected vs. actual. Performance badge.

#### 9.1 Campaign performance panel

Add a `CampaignKpiSnapshot` tab or section to the existing `CampaignResource` view page.

Display per campaign:
- Performance rating badge (`exceeded` → green, `met` → blue, `below` → amber, `insufficient_data` → grey)
- `actual_kpis.total_reach` and `actual_kpis.total_engagement`
- `actual_kpis.best_channel` with reason
- `actual_kpis.channel_breakdown` table: channel, reach, engagement, engagement_rate
- `expected_impact` from the Decision (the text they said this campaign would achieve)
- Timestamp: `snapshotted_at` and `snapshot_type` badge

Do not build a standalone `CampaignKpiSnapshotResource` — surface all data from the Campaign view.

#### 9.2 ExecutionMetric visibility

Add `ExecutionMetric` to the `ExecutionResource` view page as a sub-table or info panel:
- `channel_type`, `provider_type`, `retrieved_at`, `is_final`
- Key `metrics` values rendered as readable labels
- `window_closes_at` countdown (static — no live updates)

#### 9.3 Company analytics summary

Add a `RecommendationKpiService` output panel to `CompanyResource` show page:
- Approval rate, rejection rate, edit rate
- 30-day trend: up/down arrow

#### 9.4 Exit criteria for Phase 9

- [ ] A Campaign with a `final` snapshot shows the performance rating badge in Filament
- [ ] A Campaign without any snapshot shows "awaiting metrics" placeholder
- [ ] ExecutionMetric data is visible on the Execution show page
- [ ] Company approval rate is visible on the Company show page
- [ ] No Filament page returns data for a different Company (tenancy scoped correctly)

---

### Phase 10 — Tests

**Goal:** ≥ 40 new tests. All use `FakeAnalyticsProvider`. Zero real API calls.

#### Test files to create

**Domain models (`tests/Feature/Analytics/`)**

- `ExecutionMetricTest.php` — model creation, scopes (`forCampaign`, `pending`), casts
- `CampaignKpiSnapshotTest.php` — model creation, `final` scope, no `updated_at`
- `MetricRetrievalLogTest.php` — append-only creation, no `updated_at`

**Provider layer**

- `AnalyticsProviderRegistryTest.php` — register, resolve, unknown throws, first-match
- `FakeAnalyticsProviderTest.php` — `queueMetrics`, `queueFailure`, `assertPulled`, `assertNotPulled`
- `LogAnalyticsProviderTest.php` — `normalize` returns `[]`, `isWindowClosed` always true

**Retrieval pipeline**

- `ScheduleMetricRetrievalTest.php` — `ExecutionCompleted` dispatches job with correct delay; skips when `platform_id` is null
- `RetrieveExecutionMetricsTest.php` — creates `ExecutionMetric` on success; appends `MetricRetrievalLog`; re-dispatches when window open; calls `snapshotIfReady` when window closed; idempotent on duplicate dispatch; logs failure without re-throwing permanently
- `PruneRawMetricsTest.php` — nulls `raw` on old records; does not touch `metrics`

**Webhook pipeline**

- `AnalyticsWebhookControllerTest.php` — accepts valid Postmark payload; rejects invalid HMAC with 401; unknown provider returns 422; dispatches `ProcessAnalyticsWebhookEvent`
- `PostmarkWebhookHandlerTest.php` — parses Open/Click/Bounce/Delivery/SpamComplaint events; HMAC verification passes and fails correctly
- `ProcessAnalyticsWebhookEventTest.php` — merges event into correct `ExecutionMetric`; increments counters idempotently; no-ops on unknown `platform_id`

**KPI services**

- `CampaignKpiServiceTest.php` — `aggregate()` sums correctly; `bestChannel()` returns highest rate; `snapshotIfReady()` creates interim then final; does not duplicate final; `ratePerformance()` — exceeded/met/below/insufficient\_data boundary cases
- `RecommendationKpiServiceTest.php` — approval/rejection/edit rates; 30-day trend delta; zero-data case
- `DecisionEffectivenessServiceTest.php` — accuracy\_rate; handles zero decisions; accuracy\_by\_campaign\_type breakdown

**Learning integration**

- `LearningServiceMetricsTest.php` — each of the 8 signals; idempotency; no duplicate Learning records; no PII in value JSON; `applied_at = null` on all created records

#### Test setup discipline

- All tests that need `ExecutionMetric` data should use `ExecutionMetric::factory()` (create factories for all three new models in Phase 1)
- `FakeAnalyticsProvider` is bound in `AppServiceProvider` for the `testing` environment — same pattern as `FakeAiProvider`
- No test seeds a `ChannelCredentials` record and expects `AnalyticsProviderRegistry` to resolve a real provider — use `FakeAnalyticsProvider`'s catch-all `supports()` method

#### Exit criteria for Phase 10

- [ ] All new tests pass
- [ ] Test count ≥ 268 (current) + 40 new
- [ ] PHPStan level 8 — 0 errors
- [ ] Pint clean
- [ ] Zero real API calls in CI

---

## Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| `expected_impact` is qualitative — no numeric baseline to compare against | High | Medium | `ratePerformance()` returns `'insufficient_data'` when no numeric field; spec §4.4 acknowledged this; update `RationaleGenerationAnalyst` in a follow-up to emit structured targets |
| Platform API rate limits during polling storm (many executions completing near-simultaneously) | Medium | Medium | Polling runs on `observations` queue (low-priority, throttleable); initial delays stagger requests; future: add per-provider rate-limit tracking to `AnalyticsProviderRegistry` |
| Window closure time-drift — a job delayed by queue backlog may not re-dispatch until after the window has closed | Low | Low | `isWindowClosed()` is checked at job execution time, not dispatch time — drift causes one extra no-op poll at most, not data loss |
| Postmark webhook HMAC secret management | Medium | High | Store secret in Laravel config (encrypted); never commit to repo; test HMAC validation against a recorded real Postmark payload fixture before marking phase complete |
| Webhook arrives before `ExecutionMetric` row is created | Low | Low | `ProcessAnalyticsWebhookEvent` silently no-ops — poll will pick up the metrics in the first `RetrieveExecutionMetrics` run |
| `LearningService::recordFromMetrics()` creates incorrect signals due to insufficient data | Medium | Medium | The `optimal_timing_signal` guard (`≥ 4 prior records`) prevents premature signals; all signal conditions have explicit tests; signals are only acted on in Phase 9 — there is a correction window |
| `CampaignKpiSnapshot` created before all `ExecutionMetric` windows close | Low | Low | `snapshotIfReady()` creates `interim` first, then upgrades to `final` — no data is discarded; final rating only assigned when all windows closed |
| PHPStan level 8 errors from JSON-typed columns | Medium | Low | Cast all JSON columns to `array`; use `/** @var array<string, mixed> */` doc blocks; pattern established by existing models |

---

## Acceptance Criteria

These are the spec's acceptance criteria translated into implementation-level checkpoints. Every item is testable with `FakeAnalyticsProvider`.

### Polling

- [ ] An `Execution` with `status = 'completed'` and a non-null `platform_id` triggers `RetrieveExecutionMetrics` dispatch
- [ ] An `Execution` where `result['platform_id']` is null or absent does not trigger dispatch
- [ ] `RetrieveExecutionMetrics` calls `AnalyticsProvider::pull()` exactly once per execution
- [ ] An `ExecutionMetric` row exists after a successful poll
- [ ] `ExecutionMetric.metrics` contains `normalised_reach`, `normalised_engagement`, `normalised_engagement_rate`
- [ ] A `MetricRetrievalLog` row is appended for every poll attempt, success or failure
- [ ] When `isWindowClosed()` returns false, the job re-dispatches itself with `repollingIntervalHours()` delay
- [ ] When `isWindowClosed()` returns true, `CampaignKpiService::snapshotIfReady()` is called
- [ ] A second dispatch for the same execution does not create a second `ExecutionMetric` row

### Webhooks

- [ ] `POST /api/analytics/webhooks/postmark` with a valid Postmark `Open` payload: returns 200, dispatches `ProcessAnalyticsWebhookEvent`
- [ ] Invalid HMAC: returns 401, no job dispatched
- [ ] Unknown provider: returns 422
- [ ] `ProcessAnalyticsWebhookEvent` increments `webhook_opens` on the correct `ExecutionMetric` record
- [ ] Sending the same `Open` event twice increments the counter to 2, not creates two records

### Campaign KPIs

- [ ] `CampaignKpiSnapshot` with `snapshot_type = 'interim'` is created after the first execution window closes and others remain open
- [ ] `CampaignKpiSnapshot` with `snapshot_type = 'final'` is created after all execution windows are closed
- [ ] `actual_kpis.best_channel` identifies the channel with the highest `normalised_engagement_rate`
- [ ] `performance_rating = 'exceeded'` when `normalised_engagement_rate ≥ 1.25 × baseline`
- [ ] `performance_rating = 'insufficient_data'` when no numeric baseline in `expected_impact`
- [ ] `performance_rating = 'insufficient_data'` when `metrics = {}` for all executions

### Learning Records

- [ ] `Learning` with `signal = 'channel_outperformed'` is created when best channel rate ≥ 1.5× second-best
- [ ] `Learning` with `signal = 'email_deliverability_issue'` is created when `spam_complaints / delivered > 0.001`
- [ ] `Learning` with `signal = 'campaign_type_underperformed'` is NOT created on the first `below` result — only the second consecutive
- [ ] All Learning records: `applied_at = null`
- [ ] No Learning record's `value` JSON contains recipient email addresses, message IDs that can identify individuals, or any PII

### Provider abstraction

- [ ] `AnalyticsProviderRegistry::for('unknown')` throws `UnknownAnalyticsProviderException`
- [ ] `LogAnalyticsProvider::normalize()` returns `[]`
- [ ] `LogAnalyticsProvider::isWindowClosed()` returns true
- [ ] All test assertions use `FakeAnalyticsProvider` — no test references a real provider implementation

### Tenancy

- [ ] `ExecutionMetric` is never accessible from a request scoped to a different Company
- [ ] `CampaignKpiSnapshot` is never accessible from a request scoped to a different Company
- [ ] `RetrieveExecutionMetrics` uses `withoutGlobalScopes()` when loading `Execution` (consistent with all other jobs)

---

## Deliverables

### Migrations (3)

- `create_execution_metrics_table`
- `create_campaign_kpi_snapshots_table`
- `create_metric_retrieval_logs_table`

### Models (3)

- `App\Models\ExecutionMetric`
- `App\Models\CampaignKpiSnapshot`
- `App\Models\MetricRetrievalLog`

### Value Objects (1)

- `App\Domain\Analytics\ValueObjects\WebhookEvent`

### Interfaces (2)

- `App\Services\Analytics\Contracts\AnalyticsProvider`
- `App\Services\Analytics\Contracts\AnalyticsWebhookHandler`

### Services (5)

- `App\Services\Analytics\AnalyticsProviderRegistry`
- `App\Services\Analytics\WebhookHandlerRegistry`
- `App\Services\Analytics\CampaignKpiService`
- `App\Services\Analytics\RecommendationKpiService`
- `App\Services\Analytics\DecisionEffectivenessService`

### Provider Implementations (3)

- `App\Services\Analytics\FakeAnalyticsProvider`
- `App\Services\Analytics\LogAnalyticsProvider`
- `App\Services\Analytics\Webhooks\PostmarkWebhookHandler`

### Exceptions (2)

- `App\Services\Analytics\Exceptions\UnknownAnalyticsProviderException`
- `App\Services\Analytics\Exceptions\UnknownWebhookProviderException`

### Jobs (3)

- `App\Jobs\RetrieveExecutionMetrics`
- `App\Jobs\ProcessAnalyticsWebhookEvent`
- `App\Jobs\PruneRawMetrics`

### Listeners (1)

- `App\Listeners\ScheduleMetricRetrieval`

### Controllers (1)

- `App\Http\Controllers\Api\AnalyticsWebhookController`

### Service Provider (1)

- `App\Providers\AnalyticsServiceProvider`

### Modified service (1)

- `App\Services\Learning\LearningService` — add `recordFromMetrics(Campaign, CampaignKpiSnapshot): void`

### Modified models (2)

- `App\Models\Execution` — add `hasOne ExecutionMetric`
- `App\Models\Campaign` — add `hasMany CampaignKpiSnapshot`

### Modified Filament resources (2)

- `App\Filament\Resources\CampaignResource` — add KPI snapshot panel
- `App\Filament\Resources\ExecutionResource` — add metric sub-panel

### Test suites (~40 tests across 13 files)

- `tests/Feature/Analytics/ExecutionMetricTest.php`
- `tests/Feature/Analytics/CampaignKpiSnapshotTest.php`
- `tests/Feature/Analytics/MetricRetrievalLogTest.php`
- `tests/Feature/Analytics/AnalyticsProviderRegistryTest.php`
- `tests/Feature/Analytics/FakeAnalyticsProviderTest.php`
- `tests/Feature/Analytics/LogAnalyticsProviderTest.php`
- `tests/Feature/Analytics/ScheduleMetricRetrievalTest.php`
- `tests/Feature/Analytics/RetrieveExecutionMetricsTest.php`
- `tests/Feature/Analytics/PruneRawMetricsTest.php`
- `tests/Feature/Analytics/AnalyticsWebhookControllerTest.php`
- `tests/Feature/Analytics/PostmarkWebhookHandlerTest.php`
- `tests/Feature/Analytics/ProcessAnalyticsWebhookEventTest.php`
- `tests/Feature/Analytics/CampaignKpiServiceTest.php`
- `tests/Feature/Analytics/RecommendationKpiServiceTest.php`
- `tests/Feature/Analytics/DecisionEffectivenessServiceTest.php`
- `tests/Feature/Analytics/LearningServiceMetricsTest.php`

### Documentation (1)

- `docs/reviews/Milestone-8-Review.md` (to be written at completion)

---

## Milestone Exit Criteria

Milestone 8 is complete when **all of the following are true**:

1. **All three migrations run cleanly** against a fresh PostgreSQL database with no errors
2. **All new tests pass** — no skipped, no failures
3. **Total test count** ≥ 308 (268 current + 40 new)
4. **PHPStan level 8 — 0 errors** across all new and modified files
5. **Pint — clean** with no reformatting needed
6. **`ExecutionCompleted` → `ScheduleMetricRetrieval` → `RetrieveExecutionMetrics` → `ExecutionMetric`** pipeline is verified end-to-end in `RetrieveExecutionMetricsTest.php`
7. **Webhook ingestion is verified** — a valid Postmark `Open` payload (using a recorded fixture, not a live call) reaches `ExecutionMetric.metrics`
8. **`CampaignKpiSnapshot`** with `snapshot_type = 'final'` is created when all windows close
9. **`LearningService::recordFromMetrics()`** creates at least `channel_outperformed` and `email_deliverability_issue` Learning records in test
10. **`FakeAnalyticsProvider` is bound in the testing environment** — no test calls a real analytics API
11. **Filament `CampaignResource`** shows performance rating badge on the show page
12. **`docs/reviews/Milestone-8-Review.md`** written and committed
13. **`docs/STATUS.md`**, **`CHANGELOG.md`** updated
14. **Committed and pushed** to `main`

---

*Implementation order: follow the phases in sequence — Phase 1 through Phase 10. Each phase is committable independently. Do not begin Phase 3 (jobs) before Phase 2 (provider infrastructure) is tested. Do not begin Phase 6 (KPI aggregation) before Phase 5 (normalisation) is verified.*
