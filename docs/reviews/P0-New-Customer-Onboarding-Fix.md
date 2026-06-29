# P0 — Onboarding Website Submission Does Not Start Analysis

**Status:** Complete (Phase 1 + Phase 2 + Phase 3)  
**Date:** 2026-06-28  
**Tests:** 603 total (601 passing, 2 Redis skipped) — Phase 3 added 2 tests  
**PHPStan:** Level 8 — 0 errors  
**Pint:** Clean  
**Frontend build:** 0 errors  

---

## Problem

After a user submitted their website URL during onboarding, the integration record was created but the analysis pipeline never ran:

- `last_run_at = null` (sync job never executed)
- `observation_count = 0`, `fact_count = 0`, `opportunity_count = 0`, `recommendation_count = 0`
- No failed jobs in the queue

The status page polled indefinitely with no progress, showing the spinner until the 5-minute timeout message appeared.

---

## Root Cause

`IntegrationService::create()` dispatched `SyncIntegration` via `dispatch()`, which enqueues the job on the `observations` Redis queue. With `QUEUE_CONNECTION=redis` (the default) and no queue worker running, the job sat in Redis unprocessed.

The prior review documented the pipeline as "confirmed wired, no changes needed" — this was correct for environments where a queue worker is running. The gap was that the **first onboarding sync required an external worker process**, which is not guaranteed to be running during development or initial setup.

---

## Fix

### 1. `IntegrationService::create()` — Remove auto-dispatch

Dispatch was removed from `create()`. Callers now own the dispatch decision, which allows them to choose between sync (immediate) and async (queued) execution. The service's responsibility is narrowly scoped to persisting the record.

**Before:**
```php
$integration = Integration::create([...]);
SyncIntegration::dispatch($integration);  // enqueued to observations queue
return $integration;
```

**After:**
```php
return Integration::create([...]);  // no dispatch — caller decides
```

### 2. `OnboardingController::createIntegration()` — Run first sync inline

The controller now calls `SyncIntegration::dispatchSync()` immediately after creating the integration. `dispatchSync()` runs the job in the same PHP process, synchronously, before issuing the redirect. This guarantees observations are recorded before the user lands on the status page, regardless of queue driver or worker availability.

If the sync throws (e.g., website unreachable, SSRF blocked, DNS failure), the exception is caught, the integration is marked `status = 'error'`, the error is reported, and the user is redirected to the status page which shows the failure state.

```php
$integration = $this->integrationService->create(
    $company,
    'website_crawl',
    ['url' => $validated['website_url']]
);

try {
    SyncIntegration::dispatchSync($integration);
} catch (Throwable $e) {
    $integration->markAsError($e->getMessage());
    report($e);
}
```

Subsequent scheduled syncs (triggered via the scheduler or `SettingsController::syncIntegration()`) continue to use async `dispatch()` through the queue.

### 3. `ConnectorServiceProvider` — Configurable crawl limit

`WebPageCrawler` `maxPages` is now configurable via `CRAWLER_MAX_PAGES` env var (default: 20, driven by `config/crawler.php`). This allows dev environments to reduce the crawl limit for faster initial syncs without changing production behavior.

### 4. `OnboardingStatusController` — Expose integration state

Two new fields added to `GET /api/onboarding/status`:

| Field | Type | Meaning |
|-------|------|---------|
| `integration_status` | `string\|null` | Integration's current status (`active`, `error`, `null` if no integration) |
| `sync_started` | `bool` | `true` when `last_run_at` is set (sync has executed at least once) |

### 5. `Status.vue` — Error state

The status page now renders a distinct error state when `integration_status === 'error'`:
- Red warning icon
- "Atlas couldn't reach your website" message
- "Try a different URL" link back to `/onboarding` (step 2)
- Polling stops immediately on error detection (no more spinning forever)

The progress list gained a new first step: **"Website scanned"** driven by `sync_started`, so users see confirmation that the crawl completed before AI processing begins.

---

## Behaviour Comparison

| Event | Before | After |
|-------|--------|-------|
| Form submit | Integration created, job queued | Integration created, crawl runs inline |
| Queue worker not running | Job never executes, `last_run_at = null` forever | Crawl always runs; AI pipeline needs worker for subsequent steps |
| Connector throws | Job fails in queue (silent in dev) | Integration marked `error`, user sees failure state immediately |
| Status page on error | Spins for 5 min then shows timeout | Immediately shows "couldn't reach website" + retry link |
| Status page first step | "Facts gathered" (requires AI pipeline) | "Website scanned" (reflects crawl completion) |

---

## Tests

| Test | File |
|------|------|
| `test_integration_step_creates_integration_and_redirects_to_status` | `OnboardingControllerTest` — updated: `Queue::fake()` → `Bus::fake()` |
| `test_integration_step_dispatches_sync_job_synchronously` | `OnboardingControllerTest` — renamed + updated for `dispatchSync` |
| `test_integration_step_marks_error_when_sync_fails` | `OnboardingControllerTest` — **new**: mocks `ConnectorRegistry` to throw, asserts `status=error` |
| `test_does_not_auto_dispatch_sync_job` | `IntegrationServiceTest` — updated: confirms `create()` no longer dispatches |
| `test_returns_nulls_when_user_has_no_membership` | `OnboardingStatusControllerTest` — **new API test** |
| `test_returns_integration_status_active_before_first_sync` | `OnboardingStatusControllerTest` — **new**: `sync_started=false` before run |
| `test_returns_sync_started_true_after_first_run` | `OnboardingStatusControllerTest` — **new**: `sync_started=true` after run |
| `test_returns_error_status_when_integration_failed` | `OnboardingStatusControllerTest` — **new**: error propagates to API |

---

---

## Phase 2 — Onboarding Status Stuck, "Website Scanned" Not Appearing

### Problem

After Phase 1, the form submission still blocked for several minutes before redirecting. The "Website scanned" step never appeared. Root causes found on investigation:

1. **`connect_timeout` hardcoded to `10` in `WebPageCrawler`** — the `connectTimeout` constructor param was declared but the Guzzle `Client` was initialised with the literal `10` instead of `$this->connectTimeout`. The per-page connect deadline was therefore always 10 s regardless of config.

2. **Default `maxPages = 20` meant up to 500 s of blocking** — worst-case crawl is `maxPages × (requestTimeout + connectTimeout)` = `20 × 25 s = 500 s`. `dispatchSync()` holds the HTTP connection open for the entire crawl, so `php artisan serve` (single-threaded) cannot respond to any other request — including the status-page API polls — until it finishes.

3. **`ConnectException` is not a subclass of `RequestException`** — Guzzle's `ConnectException` (TCP timeout / refused) extends `TransferException` directly, not `RequestException`. `fetchAndParse` only caught `RequestException`, so a connection failure on any crawled page propagated up through the job and was caught by the controller's try-catch, marking the integration as `error`. This is the **correct** behaviour for the onboarding start URL — the error card is shown, the user can retry — but it was not obvious or tested.

### Fix

#### 1. `WebPageCrawler` — `connectTimeout` constructor param (bug fix)

```php
// Before
'connect_timeout' => 10,           // hardcoded — constructor param ignored

// After
'connect_timeout' => $this->connectTimeout,   // uses new constructor param (default 5)
```

Constructor signature:
```php
public function __construct(
    private readonly int $maxPages = 20,
    private readonly int $maxDepth = 3,
    private readonly int $requestTimeout = 15,
    private readonly int $connectTimeout = 5,   // new
    ?SsrfValidator $ssrfValidator = null,
)
```

#### 2. `config/crawler.php` — strict defaults

| Key | Default | Env override |
|-----|---------|--------------|
| `max_pages` | `1` | `CRAWLER_MAX_PAGES` |
| `connect_timeout` | `5` | `CRAWLER_CONNECT_TIMEOUT` |
| `request_timeout` | `10` | `CRAWLER_REQUEST_TIMEOUT` |

`max_pages = 1` means the onboarding crawl covers only the home page by default — form submission takes at most 15 s (5 s connect + 10 s read) before redirecting. Production deployments set `CRAWLER_MAX_PAGES=20` for thorough recurring syncs.

#### 3. `ConnectorServiceProvider` — wires all three config values

```php
new WebsiteConnector(new WebPageCrawler(
    maxPages:       (int) config('crawler.max_pages', 1),
    requestTimeout: (int) config('crawler.request_timeout', 10),
    connectTimeout: (int) config('crawler.connect_timeout', 5),
)),
```

### Why "Website Scanned" Was Not Appearing

With `maxPages = 20` and 25 s per-page timeout, `php artisan serve` (single-threaded PHP built-in server) was blocked processing the crawl for up to 500 s. The browser loaded the status page but every `/api/onboarding/status` poll timed out because the server couldn't handle a second request. The status page remained on "Checking in with Atlas…" until the server finally responded — at which point `sync_started = true` and the step immediately checked. From the user's perspective it looked frozen.

With `maxPages = 1`, worst-case blocking drops to ≤ 15 s. The redirect and first API poll complete almost immediately.

### Tests Added (Phase 2)

8 new unit tests in `tests/Unit/Observatory/WebPageCrawlerTest.php`:

| Test | Verifies |
|------|----------|
| `test_crawl_returns_page_data_for_successful_response` | HTML parsed, title extracted |
| `test_crawl_skips_non_html_responses` | JSON/other content-types skipped |
| `test_crawl_silently_skips_http_error_responses` | 4xx/5xx RequestException swallowed |
| `test_crawl_propagates_connect_exception` | ConnectException propagates (not caught) |
| `test_crawl_respects_max_pages_limit` | BFS stops at `maxPages` |
| `test_crawl_blocks_ssrf_private_ip` | Link-local IP blocked before HTTP |
| `test_crawl_blocks_loopback` | Loopback blocked before HTTP |
| `test_connect_timeout_param_is_accepted` | Constructor accepts `connectTimeout` param |

---

## Known Limitations

- `dispatchSync()` runs the website crawl in the HTTP request. With the default `CRAWLER_MAX_PAGES=1` the worst case is ~15 s. Production should set `CRAWLER_MAX_PAGES=20` (or higher) in `.env` for thorough recurring syncs.
- The AI pipeline (fact extraction, opportunity detection, decision evaluation, recommendations) still requires a queue worker with workers on the `high`, `ai`, `default`, `observations`, and `maintenance` queues. Without a worker, the status page will show "Website scanned" permanently and time out after 5 minutes. For local development, run: `php artisan queue:work --queue=high,ai,default,observations,maintenance`
- Production should set `QUEUE_CONNECTION=redis` and run supervised queue workers.

---

## Phase 3 — Observation Created But Facts Never Extract

### Problem

After Phase 2 the crawl ran successfully and `sync_started = true` appeared, but `fact_count`, `opportunity_count`, and `recommendation_count` all stayed at 0. The status page spun forever.

### Root Causes

**Root cause 1 — Queue driver mismatch.** `ProcessObservation` dispatches to the `ai` queue via `dispatch()`, not `dispatchSync()`. With `QUEUE_CONNECTION=redis` and no running worker, the job sits in Redis unprocessed and facts are never extracted. The `.env.example` default was `QUEUE_CONNECTION=redis`, which is correct for production but wrong for local development.

**Root cause 2 — AI provider not available in local environment.** `AppServiceProvider` bound `AnthropicProvider` for any non-testing environment. Without `ANTHROPIC_API_KEY` in `.env`, every AI call failed silently or threw, stopping the pipeline at the first `AiProvider::complete()` call.

**Root cause 3 — No active Channel for new companies.** `DecisionEngine::evaluate()` Guard 5 requires at least one active Channel. `CompanyService::create()` creates Company + Catalog + DigitalTwin + Membership — but no Channel. Without a Channel, `evaluate()` returns null and no Decision is committed; no Decision means no Campaign or Recommendation.

**Root cause 4 — No pipeline logging.** The pipeline failed silently. No log entries in `laravel.log` to indicate where it stopped.

### Fixes

#### 1. `LocalAiProvider` — deterministic stubs for local development

New `app/AI/Providers/LocalAiProvider.php` implements `AiProvider` and returns hardcoded stub JSON for all 5 prompt types:

| Prompt | Stub behaviour |
|--------|---------------|
| `FactExtractionPrompt` | 3 facts: `brand_name`, `primary_service`, `value_proposition` |
| `OpportunityDetectionPrompt` | 1 re-engagement opportunity |
| `RationaleGenerationPrompt` | Full rationale with all 4 required keys |
| `CampaignPreparationPrompt` | Blueprint with `blog` channel strategy; passes `validateBlueprint()` validation |
| Content prompts (all 5) | Title + body stub suitable for any channel |

Bound in `AppServiceProvider` for the `local` environment:
```php
if ($this->app->environment('local')) {
    $this->app->singleton(AiProvider::class, LocalAiProvider::class);
}
```

#### 2. `QUEUE_CONNECTION=sync` in `.env.example`

Changed default from `redis` to `sync` so all jobs run inline without a worker. A comment explains when to switch to `redis`:
```
# sync: runs all jobs inline — no worker needed, ideal for local dev.
# redis: production mode — requires a queue worker.
QUEUE_CONNECTION=sync
```

#### 3. Default blog Channel in `OnboardingController`

After creating the integration, the controller now seeds a default Blog channel if none exists:
```php
if (! Channel::where('company_id', $company->id)->exists()) {
    Channel::create([
        'company_id' => $company->id,
        'type' => 'blog',
        'name' => 'Blog',
        'is_active' => true,
    ]);
}
```
This unblocks `DecisionEngine::evaluate()` Guard 5.

#### 4. Pipeline logging

Added `Log::info()` checkpoints at each pipeline stage:
- `ObservationService::record()` — "recording observation" + "ObservationRecorded dispatched"
- `ProcessObservation::handle()` — "starting fact extraction", "facts extracted" (with count), "synthesizing knowledge", "processed successfully"; `Log::error()` on failure
- `OpportunityEngine::scan()` — "scanning for opportunities" + "scan complete" (with candidate and persisted counts)

#### 5. `pipeline_stalled` API field

`OnboardingStatusController` now returns `pipeline_stalled: true` when:
- `sync_started = true` (crawl ran)
- `fact_count = 0` (no facts extracted)
- `integration.status !== 'error'`
- `last_run_at < now - 90s`

This diagnoses the queue driver mismatch at the API level.

#### 6. Status.vue stalled state card

When `pipeline_stalled` is true and no error, the status page renders a yellow warning card explaining that the queue worker is not running, with the command to start it:
```
php artisan queue:work --queue=high,ai,default,observations,maintenance
```

### Tests Added (Phase 3)

| Test | File | What it covers |
|------|------|---------------|
| `test_full_onboarding_pipeline_produces_recommendation` | `OnboardingPipelineTest` | Full crawl → observation → facts → opportunities → decision → campaign → recommendation; mocks ConnectorRegistry; uses blog channel (matching onboarding default); asserts integration timestamps, observation status, facts, DigitalTwin activation, opportunity, recommendation, 5 AI calls |
| `test_failed_crawl_marks_integration_as_error` | `OnboardingPipelineTest` | Connector throws during sync; asserts integration status = `error`, no observations, 0 AI calls |

A new `tests/Fixtures/AI/blog-content.json` fixture was added for the blog channel content generation step.

### Behaviour Comparison (full pipeline)

| Scenario | Before Phase 3 | After Phase 3 |
|----------|---------------|---------------|
| Local dev with `QUEUE_CONNECTION=redis`, no worker | Facts never extracted; `fact_count=0` forever | Same — but status page now shows "queue worker needed" card after 90s instead of infinite spinner |
| Local dev with `QUEUE_CONNECTION=sync` | N/A (wasn't the default) | Full pipeline runs inline; recommendation ready in seconds |
| No AI provider configured | Silent failure at first AI call | `LocalAiProvider` returns deterministic stubs in `local` env; pipeline completes |
| New company with no Channel | `DecisionEngine` returns null; no recommendation | Blog channel seeded automatically; pipeline completes |
