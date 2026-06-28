# P0 — Onboarding Website Submission Does Not Start Analysis

**Status:** Complete  
**Date:** 2026-06-28  
**Tests:** 593 total (591 passing, 2 Redis skipped) — 5 new tests added  
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

## Known Limitations

- `dispatchSync()` runs the website crawl in the HTTP request. For websites with many pages or slow connections, form submission may take 5–30 seconds before redirecting. The `CRAWLER_MAX_PAGES` env var mitigates this.
- The AI pipeline (fact extraction, opportunity detection, decision evaluation, recommendations) still requires a queue worker with workers on the `high`, `ai`, `default`, `observations`, and `maintenance` queues. Without a worker, the status page will show "Website scanned" permanently and time out after 5 minutes. For local development, run: `php artisan queue:work --queue=high,ai,default,observations,maintenance`
- Production should set `QUEUE_CONNECTION=redis` and run supervised queue workers.
