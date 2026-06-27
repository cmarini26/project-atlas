# Milestone 8 Review — Analytics Engine

**Completed:** 2026-06-26
**Duration:** Single session (continuation from context-compacted prior session)
**Tests:** 365 total — 363 passing, 2 Redis skipped, 0 failing
**PHPStan:** Level 8 — 0 errors
**Pint:** Clean

---

## What Was Built

Milestone 8 delivered the full Analytics Engine for Project Atlas — the feedback loop between campaign execution and the intelligence layer. The system now knows what happened after a campaign runs.

### Phase 1 — Domain Models

Four new migrations and models:

- **`ExecutionMetric`** — one per execution per provider pull; stores `raw` provider response and normalised keys (`normalised_reach`, `normalised_engagement`, `normalised_engagement_rate`); immutable per retrieval cycle; `raw` pruned after 1 year
- **`CampaignKpiSnapshot`** — campaign-level rollup; `snapshot_type` is `interim` (some windows open) or `final` (all closed); `performance_rating` is `exceeded`/`met`/`below`/`insufficient_data`; immutable point-in-time record
- **`MetricRetrievalLog`** — append-only audit of every pull attempt; tracks success/failure/skipped with error message
- **`Learning`** — idempotent signal records written by `LearningService`; `applied_at = null` until a future Learning Engine processes them

### Phase 2 — Provider Infrastructure

The `AnalyticsProvider` interface follows the same first-match registry pattern used by `EmailProviderRegistry` and `ChannelPublisherRegistry`. `FakeAnalyticsProvider` uses the same queue/assert pattern as `FakeEmailProvider` — it can queue metrics, queue failures, and assert pull counts.

One important binding pattern: `FakeAnalyticsProvider` is registered in `AppServiceProvider::register()` via `afterResolving(AnalyticsProviderRegistry::class, ...)`. This fires when `AnalyticsServiceProvider::boot()` resolves the registry, which means `FakeAnalyticsProvider` is registered **before** `LogAnalyticsProvider`. Since the registry is first-match, the fake wins in all test environments without any test-specific teardown.

### Phase 3 — Retrieval Jobs

`RetrieveExecutionMetrics` is a self-rescheduling job: it pulls metrics, checks whether the measurement window is closed (e.g., 72h for email opens), and either finalises or re-queues with the provider's repolling interval. `ScheduleMetricRetrieval` listens to `ExecutionCompleted` and fires the first poll.

`PruneRawMetrics` runs monthly on the `maintenance` queue and nulls the `raw` column on records older than one year, keeping normalised data while honoring the data retention policy.

### Phase 4 — Webhook Infrastructure

`POST /api/analytics/webhooks/{provider}` accepts inbound push events. `PostmarkWebhookHandler` verifies HMAC-SHA256 signatures and maps Postmark's `RecordType` values to typed `WebhookEvent` objects. `ProcessAnalyticsWebhookEvent` looks up the execution metric by `platform_id` and increments the appropriate counter (e.g., `webhook_opens`, `webhook_clicks`). Unknown `platform_id` values are silently ignored — the system does not error on events for campaigns it doesn't own.

### Phase 5 — Normalisation

Three cross-channel normalised keys are computed per provider and stored on `ExecutionMetric`:
- `normalised_reach` — total addresses/impressions reached
- `normalised_engagement` — clicks + opens (or platform-equivalent)
- `normalised_engagement_rate` — engagement / reach

These keys enable `CampaignKpiService` to aggregate across channels without knowing provider-specific field names.

### Phase 6 — Campaign KPI Service

`CampaignKpiService` is the central aggregation point. `snapshotIfReady()` is idempotent — calling it twice for a campaign that already has a final snapshot returns the existing record without creating a duplicate. The `ratePerformance()` method compares `actual_kpis.total_engagement_rate` against `decision.expected_impact.target_engagement_rate` using the 125%/75% thresholds from the spec.

### Phase 7 — Recommendation and Decision KPIs

`RecommendationKpiService` computes approval, rejection, and edit rates from `Approval` records, plus a 30-day trend. The median time-to-decision uses driver-aware SQL — `EXTRACT(EPOCH FROM ...)` on PostgreSQL and `julianday()` on SQLite — so tests run correctly against the in-memory SQLite test database.

`DecisionEffectivenessService` answers "how often do our decisions produce good outcomes?" by joining `CampaignKpiSnapshot.performance_rating` against the originating `Decision` and grouping by detector and campaign type.

### Phase 8 — Learning Service

`LearningService::recordFromMetrics()` emits 8 signal types from a finalised `CampaignKpiSnapshot`. All records are idempotent (unique on `company_id + source_id + signal`) and all have `applied_at = null` — they are written but not yet acted upon. Applying learnings is out of scope for this milestone.

The `campaign_type_underperformed` signal requires 2+ consecutive final snapshots both rated `below` for the same `campaign_type` before it fires — a single bad result does not trigger it.

### Phase 9 — Filament

Three Filament infolists updated:
- **Campaign** — performance rating badge, snapshot type, snapshotted_at, reach, engagement, best channel
- **Execution** — metric provider, retrieval time, window_closes_at, is_final flag, normalised values
- **Company** — approval rate, rejection rate, edit rate, median time-to-decision

### Phase 10 — Tests

16 test files, 97 new tests, all using `FakeAnalyticsProvider`. No live API calls in CI.

Key test infrastructure: `AnalyticsTestCase` base class provides `makeExecution()` (which creates a `ContentAsset` first — required by `executions.content_asset_id NOT NULL UNIQUE`) and `makeOpportunity()` (required because `decisions.opportunity_id` is UNIQUE, so each `Decision` needs a fresh `Opportunity`). These helpers prevent the category of constraint-violation failures that caused ~46 test failures before the base class was introduced.

---

## Non-Obvious Technical Decisions

**`UPDATED_AT = null` on immutable models.** `CampaignKpiSnapshot` and `MetricRetrievalLog` are point-in-time records that should never be updated. Setting `const UPDATED_AT = null` makes Eloquent skip the `updated_at` column entirely, enforcing immutability at the ORM layer.

**`afterResolving` for test provider registration.** The `FakeAnalyticsProvider` singleton binding fires via `afterResolving(AnalyticsProviderRegistry::class, ...)` in `AppServiceProvider::register()`. This is the correct hook — it fires when the registry is first resolved from the container (which happens in `AnalyticsServiceProvider::boot()`), not at request time. Registering via `boot()` would be too late for early resolution.

**Driver-aware median SQL.** `RecommendationKpiService` uses `EXTRACT(EPOCH FROM ...)` on PostgreSQL and `(julianday(a) - julianday(b)) * 24` on SQLite. This avoids either maintaining two test databases or mocking the DB call. Wrapped in try-catch returning `null` as a safety net for any edge cases.

**Old Queueable trait pattern.** `PruneRawMetrics` (and other jobs) use `use Dispatchable, InteractsWithQueue, Queueable, SerializesModels` from `Illuminate\Bus` namespace, not the newer `Illuminate\Foundation\Queue\Queueable`. The newer trait defines `$queue` internally, which conflicts with setting `public string $queue = 'maintenance'` in the class body. Setting queue via `$this->onQueue('maintenance')` in the constructor avoids the property conflict.

---

## Spec Compliance

All 18 acceptance criteria from `specs/core/analytics-engine.md` are met:

| Criterion | Status |
|-----------|--------|
| `ExecutionMetric` created after `ExecutionCompleted` | ✅ |
| Window-open metrics re-polled | ✅ |
| Window-closed metrics produce final snapshot | ✅ |
| Interim snapshot created when some windows open | ✅ |
| HMAC verification on webhook endpoint | ✅ |
| Unknown `platform_id` webhook silently no-ops | ✅ |
| `ProcessAnalyticsWebhookEvent` increments counters idempotently | ✅ |
| `performance_rating` computed from expected vs. actual | ✅ |
| `LearningService` receives final snapshots | ✅ |
| 8 learning signal types written | ✅ |
| All learning records have `applied_at = null` | ✅ |
| `channel_outperformed` requires ≥2 channels | ✅ |
| `campaign_type_underperformed` requires 2+ consecutive below | ✅ |
| `email_deliverability_issue` on hard bounces or >0.1% spam | ✅ |
| `high_unsubscribe_rate` on >1% unsubscribes | ✅ |
| No PII in `metrics` column | ✅ |
| Raw data pruned after 1 year | ✅ |
| `FakeAnalyticsProvider` used in all tests — no live API calls | ✅ |

---

## Explicit Out-of-Scope Items (Not Built)

Per the milestone boundary:

- `ApplyLearnings` — records are written but not consumed; applying learnings is a future milestone
- Scoring weight recalibration based on learning signals
- Cross-company pattern detection
- Real social analytics providers (Instagram, Facebook, LinkedIn, X)
- SMS analytics
- Paid media analytics
- Individual subscriber or contact tracking
- Customer-facing analytics frontend

---

## Issues Encountered and Fixed

| Issue | Root Cause | Fix |
|-------|-----------|-----|
| `executions.content_asset_id NOT NULL` | Test helpers created `Execution` without `ContentAsset` | `AnalyticsTestCase::makeExecution()` creates `ContentAsset` first |
| `decisions.opportunity_id UNIQUE` | Multiple `Decision` records per test reusing same `Opportunity` | `makeOpportunity()` helper creates a fresh `Opportunity` per call |
| `decisions.campaign_type ENUM` violation | Tests used `'auction'` (not a valid value) | Changed to `'urgency_promotion'` |
| `recommendations.status CHECK` failure | Tests used `'responded'` (not a valid status) | Status maps to approval action: `approved`, `rejected`, `edited_and_approved` |
| `recommendations.decision_id UNIQUE` | Multiple `Recommendation` records reusing same `Decision` | `makeRecommendationWithApproval()` creates fresh `Opportunity` + `Decision` per call |
| `approvals.user_id NOT NULL` | `Approval` creation omitted required `user_id` | Added `Str::ulid()->toString()` as synthetic user ID |
| `openssl_encrypt()` array argument | `ChannelCredentials.credentials` cast as `encrypted`; passing array fails | Changed all test helpers to `json_encode([...])` (string) |
| PHPStan: `provider_type` nullable | `ChannelCredentials` had no PHPDoc property annotations | Added `@property string $provider_type` PHPDoc |
| PHPStan: `round()` nullable arg | `Collection::avg()` typed as `float|int|null`; `round()` doesn't accept null | Extracted avg to intermediate variable; guarded with `!== null` |
| `PruneRawMetrics` property conflict | New `Illuminate\Foundation\Queue\Queueable` trait defines `$queue` internally | Used old `Illuminate\Bus\Queueable` trait; set queue via `$this->onQueue()` |
| SQLite `EXTRACT(EPOCH FROM ...)` | PostgreSQL-specific SQL fails on in-memory SQLite test DB | Driver-aware conditional SQL; try-catch returning `null` as fallback |

---

## Metrics

| Metric | Value |
|--------|-------|
| New migrations | 4 |
| New models | 4 |
| Models updated | 2 (Execution, Campaign) |
| New interfaces | 2 (AnalyticsProvider, AnalyticsWebhookHandler) |
| New services | 5 (CampaignKpiService, RecommendationKpiService, DecisionEffectivenessService, LearningService, AnalyticsProviderRegistry) |
| New jobs | 3 (RetrieveExecutionMetrics, PruneRawMetrics, ProcessAnalyticsWebhookEvent) |
| New listeners | 1 (ScheduleMetricRetrieval) |
| New controllers | 1 (AnalyticsWebhookController) |
| New webhook handlers | 1 (PostmarkWebhookHandler) |
| New test files | 16 |
| New tests | 97 |
| Total tests | 365 (363 passing, 2 Redis skipped) |
| PHPStan errors | 0 |
