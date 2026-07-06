# P0 — Onboarding Website Submission Does Not Start Analysis

**Status:** Complete (Phase 1 + Phase 2 + Phase 3 + Phase 4 + Phase 5 + Phase 6 + Phase 7)  
**Date:** 2026-07-05  
**Tests:** 633 total (631 passing, 2 Redis skipped) — Phase 7 added 6 tests  
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

---

## Phase 4 — Real Crawls Produce 0 Facts (body_text Key Mismatch)

### Problem

After Phase 3, users who added `ANTHROPIC_API_KEY` and restarted Laravel saw the same symptom: `facts=0`, `opportunities=0`, `recommendations=0` after every real website crawl. `QUEUE_CONNECTION=sync` was set and the queue worker was not needed. The pipeline appeared to complete — no errors, observation marked `processed` — but nothing was extracted.

### Root Cause

**`WebsiteAnalyst::analyze()` read the wrong payload key.**

`WebPageData::toArray()` (the output of every real crawl) produces `body_text` (snake_case):

```php
return [
    'body_text' => $this->bodyText,   // ← snake_case
    ...
];
```

`WebsiteAnalyst::analyze()` read `$payload['bodyText']` (camelCase):

```php
// Before — always triggers early return for real crawls:
if (! is_array($payload) || empty($payload['bodyText'])) {
    return collect();    // ← always hit; key never exists
}
// ...
bodyText: (string) $payload['bodyText'],   // ← also wrong
```

`empty($payload['bodyText'])` evaluated to `true` on every real crawl because the key simply did not exist. The analyst returned an empty `Collection<FactData>`, logged nothing, marked the observation `processed`, and the rest of the pipeline received 0 facts.

**Why tests passed:** Tests created observation payloads manually with `'bodyText'` (camelCase), matching the old broken analyst. They never exercised the `WebPageData::toArray()` path, so the mismatch was invisible.

**Secondary root cause — `ANTHROPIC_API_KEY` ignored in local env.** Even with an API key set, `AppServiceProvider` always bound `LocalAiProvider` for `APP_ENV=local`. Users who set an API key expecting Anthropic responses got stub responses and had no way to know why.

### Fixes

#### 1. `WebsiteAnalyst::analyze()` — `body_text` key

Changed both occurrences:

```php
// After:
if (! is_array($payload) || empty($payload['body_text'])) {
    Log::warning('WebsiteAnalyst: observation payload missing body_text, skipping.', [
        'observation_id' => $observation->id,
        'keys' => is_array($payload) ? array_keys($payload) : [],
    ]);
    return collect();
}
// ...
Log::info('WebsiteAnalyst: starting fact extraction.', ['observation_id' => $observation->id]);
$prompt = new FactExtractionPrompt(
    ...
    bodyText: (string) $payload['body_text'],   // ← correct
);
// ...
Log::info('WebsiteAnalyst: fact extraction complete.', [
    'observation_id' => $observation->id,
    'fact_count' => count($rawFacts),
]);
```

The `Log::warning()` on missing/empty `body_text` means future breakage will be immediately visible in `laravel.log` rather than silently returning empty.

#### 2. `AppServiceProvider` — respect `ANTHROPIC_API_KEY` in local env

```php
// Before:
if ($this->app->environment('local')) {
    $this->app->singleton(AiProvider::class, LocalAiProvider::class);
} elseif (! $this->app->environment('testing')) {
    $this->app->singleton(AiProvider::class, AnthropicProvider::class);
}

// After:
if ($this->app->environment('testing')) {
    $this->app->singleton(AiProvider::class, FakeAiProvider::class);
} elseif ($this->app->environment('local') && empty(config('services.anthropic.api_key'))) {
    $this->app->singleton(AiProvider::class, LocalAiProvider::class);
} else {
    $this->app->singleton(AiProvider::class, AnthropicProvider::class);
}
```

`LocalAiProvider` is now only the fallback for local dev without a key — not the default for all `local` environments.

#### 3. Test payloads — `bodyText` → `body_text`

Four test files created observation payloads with `'bodyText'` to match the old broken key. Updated to `'body_text'` to match `WebPageData::toArray()`:

- `tests/Feature/PipelineSmokeTest.php` — 2 occurrences
- `tests/Feature/OnboardingPipelineTest.php` — 1 occurrence
- `tests/Feature/Brain/WebsiteAnalystTest.php` — 3 occurrences
- `tests/Feature/Brain/ProcessObservationTest.php` — 1 occurrence

#### 4. `OnboardingStatusController` — `crawl_succeeded` and `ai_failed` fields

Two new fields added to `GET /api/onboarding/status`:

| Field | Type | Meaning |
|-------|------|---------|
| `crawl_succeeded` | `bool` | At least one Observation exists — website was reachable and the connector returned data |
| `ai_failed` | `bool` | An Observation exists but has `status = 'failed'` — AI provider threw or returned an unusable response |

These allow the UI to distinguish three distinct failure modes:
- `integration_status === 'error'` → crawl failed (website unreachable)
- `ai_failed === true` → crawl succeeded, AI processing failed
- `pipeline_stalled === true` → crawl succeeded, AI jobs not dequeued (no worker)

`pipeline_stalled` now also requires `!$aiFailed` so the two states are mutually exclusive.

#### 5. `Status.vue` — AI failure card

New error card shown when `ai_failed` is true:
- Red warning icon (same as crawl failure)
- "AI analysis encountered an error" heading
- Explanation: likely caused by missing/invalid `ANTHROPIC_API_KEY`
- Guidance to check `.env` and restart the server
- Polling stops immediately — `isAiFailed.value` stops the interval alongside `isFailed.value`

#### 6. `SettingsControllerTest` — `Bus::fake()`

`test_sync_integration_dispatches_job` dispatched `SyncIntegration` without faking the bus. With `QUEUE_CONNECTION=sync` the full pipeline ran inline — hitting `FakeAiProvider::complete()` with an empty queue and throwing a 500. Added `Bus::fake()` + `Bus::assertDispatched(SyncIntegration::class)` so the test only verifies dispatch.

### Tests Changed (Phase 4)

| Test file | Change |
|-----------|--------|
| `tests/Feature/Brain/WebsiteAnalystTest.php` | `'bodyText'` → `'body_text'` in 3 observation payloads |
| `tests/Feature/Brain/ProcessObservationTest.php` | `'bodyText'` → `'body_text'` in 1 observation payload |
| `tests/Feature/PipelineSmokeTest.php` | `'bodyText'` → `'body_text'` in 2 observation payloads |
| `tests/Feature/OnboardingPipelineTest.php` | `'bodyText'` → `'body_text'` in 1 fake connector payload |
| `tests/Feature/App/SettingsControllerTest.php` | Added `Bus::fake()` + `Bus::assertDispatched()` |

### Behaviour Comparison (Phase 4)

| Scenario | Before Phase 4 | After Phase 4 |
|----------|---------------|---------------|
| Real crawl with any AI provider | `fact_count=0` silently; no log | Facts extracted correctly; `Log::info()` confirms count |
| `ANTHROPIC_API_KEY` set in local env | `LocalAiProvider` used regardless; stub responses | `AnthropicProvider` used; real Anthropic API called |
| AI provider throws / returns invalid JSON | Observation stays `pending` or `failed`; status page spins | `ai_failed=true` in API; status page shows "AI analysis encountered an error" card |
| `SettingsController::syncIntegration()` test | 500 from empty `FakeAiProvider` queue | `Bus::fake()` prevents job execution; test passes cleanly |

---

## Phase 5 — Real Anthropic Responses Produce 0 Facts (max_tokens Truncation + Silent Empty Success)

### Problem

After Phase 4, real crawls with `ANTHROPIC_API_KEY` set reached the AI, the call completed, and the pipeline logged:

- `WebsiteAnalyst: starting fact extraction.` ✓
- Anthropic call completes ✓
- `WebsiteAnalyst: fact extraction complete. fact_count=0` ✗
- Observation marked `processed` successfully ✗

Zero facts, zero opportunities, zero recommendations — but no error anywhere. Onboarding spun until timeout.

### Root Causes

**Root cause 1 — `FactExtractionPrompt::maxTokens()` was 1024.** A real page yields dozens of facts; the structured tool-use JSON for them easily exceeds 1024 output tokens. When the Messages API hits `max_tokens` mid-way through a forced tool call, it cannot return the partial JSON — `tool_use.input` comes back empty (or the block is dropped). Fixture-driven tests never hit this because fixtures bypass the provider entirely.

**Root cause 2 — `AnthropicProvider` ignored `stop_reason`.** A truncated response (`stop_reason=max_tokens`) was indistinguishable from a valid one. The empty `input` was re-encoded (as `[]`, not even `{}` — PHP array cast) and handed to the parser as if it were the model's answer.

**Root cause 3 — `WebsiteAnalyst` treated a missing/empty `facts` array as success.** `$data['facts'] ?? []` silently produced an empty collection, the observation was marked `processed`, and the onboarding UI had nothing to key an error state off.

**Secondary issues found:** `temperature` from prompts was never sent to the API; a schema-prompt response with no `tool_use` block returned `''` content, which surfaced later as a confusing "not valid JSON" parse error.

### Fixes

#### 1. `FactExtractionPrompt::maxTokens()` — 1024 → 4096

Headroom for dozens of facts in the structured tool call output.

#### 2. `AnthropicProvider` — stop_reason handling + hard failures on abnormal structured responses

- `AiResponse` gained a nullable `stopReason` field, populated from the API response.
- When a schema prompt's response has `stop_reason=max_tokens`, the provider now **throws** — truncated structured output is never treated as data.
- When a schema prompt's response contains no `tool_use` block despite forced `tool_choice` (refusal, filter, API change), the provider now **throws** instead of returning `''`.
- Empty tool input is re-encoded as `{}` (object cast) rather than `[]`, so downstream parsing sees the correct JSON shape.
- `temperature` from the prompt is now sent with every request (fact extraction runs at 0.1).
- The raw API response body is logged at `debug` level **only when `app.debug` is true** — raw bodies can contain crawled page content and must not be logged in production.

#### 3. `WebsiteAnalyst` — invalid/empty AI output is now a failure

New exception: `App\Services\Analyst\Exceptions\FactExtractionFailedException`. Thrown when:

- the parser rejects the response (invalid JSON) — wrapped with observation context
- the decoded payload has no `facts` array (e.g. `{}` from a truncated tool call)
- the facts array is empty, or every entry is malformed, despite the page having body text

Malformed individual entries (missing `key`/`value`/`data_type`/`confidence`) are skipped with a `Log::warning()` while valid ones are kept. The raw AI response content is debug-logged when `app.debug` is true.

`ProcessObservation` already catches any throwable, marks the observation `failed`, and rethrows — so `FactExtractionFailedException` flows into the existing `ai_failed` signal in `GET /api/onboarding/status` with no job changes.

#### 4. `Status.vue` — broader AI-failure copy + retry action

The `ai_failed` card previously blamed only API-key configuration. Since a zero-fact extraction now also lands here, the copy explains both causes (provider misconfiguration or a page without enough readable business text) and offers **Try a different URL** alongside **Go to dashboard**.

### Tests Added (Phase 5)

| Test | File |
|------|------|
| `test_throws_when_schema_prompt_response_has_no_tool_use_block` | `AnthropicProviderTest` — replaces old empty-string behavior |
| `test_throws_when_structured_response_truncated_at_max_tokens` | `AnthropicProviderTest` |
| `test_max_tokens_stop_reason_is_fine_for_plain_text_prompt` | `AnthropicProviderTest` — truncation only fatal for structured output |
| `test_empty_tool_input_serializes_as_json_object` | `AnthropicProviderTest` — `{}` not `[]` |
| `test_sends_prompt_temperature` | `AnthropicProviderTest` |
| `test_captures_stop_reason_on_response` | `AnthropicProviderTest` |
| `test_parses_realistic_fact_extraction_response` | `AnthropicProviderTest` — full realistic Messages API payload (real IDs, usage block, forced tool call) |
| `test_throws_when_ai_returns_invalid_json` | `WebsiteAnalystTest` |
| `test_throws_when_ai_returns_empty_facts_array` | `WebsiteAnalystTest` |
| `test_throws_when_ai_response_is_missing_facts_key` | `WebsiteAnalystTest` |
| `test_skips_malformed_fact_entries_but_keeps_valid_ones` | `WebsiteAnalystTest` |
| `test_throws_when_all_fact_entries_are_malformed` | `WebsiteAnalystTest` |
| `test_extracts_facts_from_realistic_anthropic_response` | `WebsiteAnalystTest` — end-to-end through real `AnthropicProvider` + `StructuredResponseParser`, HTTP mocked |
| `test_marks_failed_when_ai_returns_empty_facts` / `test_marks_failed_when_ai_returns_invalid_json` | `ProcessObservationTest` — observation ends `failed`, no facts persisted |
| `test_failed_ai_analysis_surfaces_as_ai_failed_in_onboarding_status` | `ProcessObservationTest` — full loop: empty facts → observation `failed` → API returns `ai_failed=true` |

### Behaviour Comparison (Phase 5)

| Scenario | Before Phase 5 | After Phase 5 |
|----------|---------------|---------------|
| Fact extraction output exceeds 1024 tokens | Truncated → empty tool input → 0 facts, observation `processed`, UI spins | 4096-token budget; if still truncated, provider throws → `ai_failed` card |
| AI returns `{"facts": []}` or `{}` | 0 facts, observation `processed` successfully | `FactExtractionFailedException` → observation `failed` → `ai_failed=true` |
| AI returns invalid JSON | Generic `InvalidArgumentException` with no context | Wrapped in `FactExtractionFailedException` with observation ID |
| Refusal / no tool_use block | `''` content → confusing "not valid JSON" error | Provider throws with `stop_reason` in the message |
| Debugging a bad AI response locally | No visibility into raw output | Raw response logged at debug level when `APP_DEBUG=true` (provider + analyst) |
| Prompt temperature | Never sent (API default used) | Sent per prompt (0.1 for fact extraction) |

---

## Phase 6 — Anthropic overloaded_error Treated as Permanent Failure

### Problem

When Anthropic returned `overloaded_error` (HTTP 529) during onboarding — a temporary capacity condition on Anthropic's side — the exception propagated up the inline sync chain and the integration was marked `error` immediately. The user saw "Atlas couldn't reach your website" (wrong: the crawl succeeded) for an issue that resolves itself within seconds to minutes.

### Root Causes

1. **No retryability classification.** `AnthropicProvider` threw the same generic `RuntimeException` for every API error; callers could not distinguish "invalid API key" (permanent) from "overloaded" (transient).
2. **Two paths marked the integration `error`.** Both `SyncIntegration::failed()` (invoked by the sync queue driver before rethrowing) and `OnboardingController::createIntegration()`'s catch called `markAsError()` on any throwable.
3. **No retry state.** Observations only had `pending`/`processing`/`processed`/`failed` — nothing to represent "waiting for the provider, will retry".

### Fixes

#### 1. `AiProviderOverloadedException` + in-provider retry/backoff

New `App\AI\Exceptions\AiProviderOverloadedException` (carries a `requestId`). `AnthropicProvider::post()` now:

- classifies a response as overloaded when the HTTP status is **529** or the error body type is **`overloaded_error`** (authoritative even if a proxy rewrites the status);
- retries with backoff — default delays `[500 ms, 1500 ms, 3000 ms]` (4 attempts, ~5 s worst case, short enough to run inline during the onboarding request); delays are constructor-injectable for tests;
- logs each retry (`attempt`, `delay_ms`, `request_id`) and logs + throws `AiProviderOverloadedException` when retries are exhausted;
- logs and embeds the **`request-id` response header** in all API error logs and exception messages (also included in the debug raw-response log).

Non-overloaded errors are never retried and still throw `RuntimeException` immediately.

#### 2. New `retrying` observation status

- `Observation::markRetrying()`; base `create_observations_table` migration now includes `'retrying'` in the status enum (fresh databases, including sqlite test DBs).
- New migration `2026_07_05_000100_add_retrying_status_to_observations` rewrites the Postgres check constraint on existing databases (no-op on other drivers).

#### 3. `ProcessObservation` — overload is not a failure

A dedicated catch for `AiProviderOverloadedException` marks the observation **`retrying`** and rethrows. Only the *final queued attempt* (a real worker, `attempts() >= tries`) downgrades to `failed`. Added `$backoff = [30, 120]` so queued retries space out. Sync-mode runs (inline, no worker) always park in `retrying` — the status endpoint drives further retries (below).

#### 4. Integration is not marked `error` for overload

- `SyncIntegration::failed()` returns early for `AiProviderOverloadedException` — the crawl succeeded; only analysis is deferred.
- `OnboardingController::createIntegration()` catches `AiProviderOverloadedException` separately: `report()` only, no `markAsError()`.

#### 5. Status endpoint — `ai_retrying` + sync-mode self-healing retry

`GET /api/onboarding/status` now returns `ai_retrying: true` while an observation is in `retrying`, and `pipeline_stalled` excludes that state. With `QUEUE_CONNECTION=sync` (no worker to resume the job), the endpoint **re-dispatches stale retrying observations inline** — throttled to one attempt per 30 s per observation — since the status page polls every 5 s anyway. A successful inline retry is reflected in the same poll's response. With an async queue the job's own `$tries`/`$backoff` handle retries and the endpoint only reports the state.

#### 6. `Status.vue` — waiting state

New amber card when `ai_retrying` is true: **"Atlas is waiting for the AI provider"** — explains the provider is temporarily overloaded, Atlas will retry automatically, no action needed. Polling continues (unlike the failure cards, which stop it).

### Tests Added (Phase 6)

| Test | File |
|------|------|
| `test_retries_overloaded_error_then_succeeds` | `AnthropicProviderTest` — 529, 529, success → 3 requests |
| `test_throws_overloaded_exception_after_retries_exhausted` | `AnthropicProviderTest` — asserts `requestId` from the `request-id` header and 4 attempts |
| `test_overloaded_error_type_detected_regardless_of_status_code` | `AnthropicProviderTest` — 503 + `overloaded_error` body still retried |
| `test_non_overloaded_errors_are_not_retried` | `AnthropicProviderTest` — 401 fails immediately, 1 request |
| `test_api_error_message_includes_request_id_when_present` | `AnthropicProviderTest` |
| `test_marks_observation_retrying_when_provider_overloaded` | `ProcessObservationTest` — status `retrying`, not `failed` |
| `test_overloaded_provider_surfaces_as_ai_retrying_in_onboarding_status` | `ProcessObservationTest` — `ai_retrying=true`, `ai_failed=false`, `pipeline_stalled=false` |
| `test_status_endpoint_redispatches_stale_retrying_observation` | `ProcessObservationTest` — stale retrying observation reprocessed inline by the poll; facts appear in the same response |
| `test_overloaded_ai_provider_leaves_integration_active_and_marks_observation_retrying` | `OnboardingPipelineTest` — full inline chain: integration stays `active`, observation parked `retrying` |

`FakeAiProvider` gained `queueException(Throwable)` to simulate provider failures.

### Behaviour Comparison (Phase 6)

| Scenario | Before Phase 6 | After Phase 6 |
|----------|---------------|---------------|
| Anthropic returns overloaded_error once | Integration marked `error`; "couldn't reach your website" card | Provider retries inline (~0.5–5 s backoff); usually succeeds transparently |
| Overload persists through all provider retries | Same permanent failure | Observation parked `retrying`; integration stays `active`; status page shows "Atlas is waiting for the AI provider" and keeps polling |
| Overload recovery (sync queue) | Never — user had to restart onboarding | Status poll re-dispatches after 30 s; analysis resumes automatically |
| Overload recovery (async queue) | Job retried but observation flickered `failed` | Job retries with 30 s / 120 s backoff; observation shows `retrying`; only the final attempt marks `failed` |
| Debugging an Anthropic incident | No request correlation | `request-id` logged on every retry/error and embedded in exception messages |

---

## Phase 7 — Facts Created But No Opportunities Or Recommendations

### Problem

A real crawl extracted 37 facts, but opportunity count and recommendation count stayed at 0 forever. No errors anywhere; onboarding spun until timeout.

### Root Causes

**Root cause 1 — Opportunity detection was triggered only by a once-per-company-lifetime event.** `TriggerOpportunityDetection` listened to `DigitalTwinActivated`, which `KnowledgeService::updateTwin()` fires only on the `initializing → active` transition. Any company whose twin was already active — a retried onboarding, a recurring sync, or an earlier run whose downstream chain failed after activation — extracted facts and then dead-ended: nothing ever dispatched `DetectOpportunities` again. No scheduled job re-scans either.

**Root cause 2 — Downstream chain coupled to observation status.** `ObservationProcessed` was dispatched *inside* `ProcessObservation`'s try/catch. Under the sync queue the entire opportunities → decision → campaign → recommendation cascade ran inline there, so any downstream failure (e.g. AI overload during the opportunity prompt on a first run) marked the already-successful observation `failed` — and on retry the twin was already active, so the scan never re-fired (root cause 1).

**Root cause 3 — Legitimate "no opportunities" was silent.** When a scan found nothing (0 candidates, or all candidates deduped/below the composite threshold of 30), the log recorded it but the status page had no corresponding state — the spinner ran to the generic timeout.

**Checked and not the cause:** queue names (all jobs run on `ai`/`default`, both included in the documented worker command); `BusinessBrainService::for()` cache (invalidated on `FactExtracted` and `KnowledgeSynthesized`); DecisionEngine guards (onboarding seeds the blog channel).

### Fixes

#### 1. Opportunity scans now run after every processed observation

`TriggerOpportunityDetection` listens to `ObservationProcessed` instead of `DigitalTwinActivated`, guarded on the company having current facts. Repeat scans are safe: `DetectOpportunities` is now `ShouldBeUnique` per company (a multi-page crawl's burst of observations collapses to one queued scan) and the `OpportunityEngine` already deduplicates candidates against existing opportunities. `DigitalTwinActivated` still fires; it just no longer gates the pipeline.

#### 2. Downstream chain decoupled from observation status

`ProcessObservation` dispatches `ObservationProcessed` *after* the try/catch, wrapped in its own containment: a downstream failure is logged (`ProcessObservation: downstream pipeline failed after observation was processed.`) and reported, but never flips the processed observation to `failed` or aborts the sync request.

#### 3. `no_opportunities` state (API + UI)

`GET /api/onboarding/status` returns `no_opportunities: true` when facts exist, there are no open opportunities and no pending recommendations, no AI failure/retry is in flight, and the last processed observation is > 90 s old (before that the scan/decision chain may still be running). `Status.vue` renders a friendly terminal card: **"Atlas learned your business — no campaign opportunity yet"** with the fact count, next steps (review the Business Brain, connect channels / add catalog items, Atlas keeps scanning on future syncs), and links to the dashboard and Brain page. Polling stops on this state.

#### 4. Structured logging across the chain

| Stage | Log |
|-------|-----|
| Facts | `ProcessObservation: facts extracted.` (existing, with count) |
| Knowledge | `KnowledgeService: knowledge synthesis complete.` (fact_count, knowledge_entries) + `digital twin activated.` |
| Trigger | `TriggerOpportunityDetection: dispatching opportunity scan.` / `no current facts yet, skipping scan.` |
| Scan | `DetectOpportunities: starting opportunity scan.`; `OpportunityEngine: scan complete.` now includes `dropped_duplicate` and `dropped_below_threshold`; explicit `no opportunities persisted from this scan.` |
| Decision | `CommitDecision: evaluating decision.` / `decision committed.` / `no decision committed (engine guards not satisfied).` |
| Recommendation | `CreateRecommendation: recommendation created.` (existing) |

### Tests Added (Phase 7)

| Test | File |
|------|------|
| `test_pipeline_produces_recommendation_when_twin_already_active` | `OnboardingPipelineTest` — **the regression test**: twin pre-set to `active`, full sync still produces an opportunity and a pending recommendation (5 AI calls) |
| `test_no_opportunities_from_scan_is_legitimate_and_observation_stays_processed` | `OnboardingPipelineTest` — AI returns `{"opportunities": []}`: observation `processed`, integration `active`, 0 opportunities/recommendations, exactly 2 AI calls |
| `test_dispatches_opportunity_detection_after_processing` | `ProcessObservationTest` — `DetectOpportunities` dispatched for the observation's company |
| `test_downstream_failure_does_not_mark_observation_failed` | `ProcessObservationTest` — downstream AI failure contained; observation stays `processed` with its 4 facts |
| `test_no_opportunities_true_when_facts_exist_but_scan_found_nothing` | `OnboardingStatusControllerTest` — flag asserted with 2-minute-old processed observation |
| `test_no_opportunities_false_while_scan_may_still_be_running` | `OnboardingStatusControllerTest` — freshly processed observation does not assert the state |

### Behaviour Comparison (Phase 7)

| Scenario | Before Phase 7 | After Phase 7 |
|----------|---------------|---------------|
| Twin already active (retry / re-crawl / prior run) | Facts extracted, pipeline dead-ends; 0 opportunities forever | Scan runs after every processed observation; recommendation produced |
| Downstream failure after facts persisted | Observation flipped to `failed`; retry re-extracted facts but never scanned | Observation stays `processed`; failure logged + reported; next sync re-scans |
| Scan legitimately finds nothing | Spinner until generic 5-minute timeout | "Atlas learned your business — no campaign opportunity yet" card with fact count and next steps |
| Diagnosing where the chain stopped | Log gap between facts and (maybe) scan | Every stage logs: facts → knowledge → trigger → scan (with drop reasons) → decision → recommendation |
