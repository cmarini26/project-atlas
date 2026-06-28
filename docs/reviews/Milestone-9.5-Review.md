# Milestone 9.5 — Version 0.1 Stabilization Sprint

**Status:** Complete  
**Completed:** 2026-06-27  
**Tests:** 519 (517 passing, 2 Redis skipped)  
**PHPStan:** Level 8 — 0 errors  
**Pint:** Clean  

---

## Objective

Resolve all blocking items identified in the Version 0.1 Architecture Audit before the first customer data runs through the pipeline. This sprint targeted the five production-blocking gaps: `AnthropicProvider`, Filament superadmin gate, SSRF protection, health endpoints, and the end-to-end smoke test.

The sprint also uncovered and fixed two systemic pipeline defects that were silently corrupting every Atlas job dispatch. Without these fixes, no AI job in Atlas would have run in any real environment.

---

## Delivered

### 1. AnthropicProvider

`app/AI/Providers/AnthropicProvider.php` implements the full `AiProvider` interface against the Anthropic Messages API.

- All three Atlas prompt types supported: `generate` (standard message completion), `tool_use` (structured JSON output via forced tool call), `embed` (stubbed — raises `UnsupportedOperationException` until embeddings are needed)
- Configured via `config/ai.php`: model, temperature, max tokens, API key from `ANTHROPIC_API_KEY`
- Bound in `AppServiceProvider::register()` — non-testing environments only; tests continue to use `FakeAiProvider`
- `.env.example` updated with `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`, `ANTHROPIC_MAX_TOKENS`, `ANTHROPIC_TEMPERATURE`

### 2. Filament Superadmin Gate

`database/migrations/..._add_is_superadmin_to_users_table.php` — adds `is_superadmin` boolean column.

- `User::$isSuperadmin` property; factory state `->superadmin()` for tests
- `AdminPanelProvider` — `canAccess()` checks `$user->is_superadmin`; access denied returns 403
- All Filament resources and pages inaccessible to non-superadmin users
- Tests: `FilamentSuperadminTest` — non-superadmin denied, superadmin permitted

### 3. SSRF Protection

`app/Services/Observatory/Connectors/Website/SsrfValidator.php` — validates every outbound URL before Guzzle makes a request.

- Blocks 14 CIDR ranges: loopback, private class A/B/C, link-local, cloud metadata, shared address space, IETF reserved, test nets, class E, broadcast
- Blocks hardcoded hostnames: `localhost`, `ip6-localhost`, `ip6-loopback`
- Validates IPv4, IPv6, IPv6-mapped IPv4, and DNS-resolved hostnames
- DNS-resolved hostnames: all returned A/AAAA records must pass validation (prevents TOCTOU)
- `SsrfBlockedException` with `blockedUrl()` factory and `blockedIp()` factory
- `WebPageCrawler` now calls `SsrfValidator::validate()` before every Guzzle request
- Tests: `SsrfValidatorTest` (13 cases) + `WebPageCrawlerTest` SSRF integration

### 4. Health Endpoints

`app/Http/Controllers/Api/HealthController.php` — three health check routes.

| Route | Purpose |
|-------|---------|
| `GET /health` | General health — DB + cache connectivity |
| `GET /health/live` | Liveness probe — always 200 if PHP is running |
| `GET /health/ready` | Readiness probe — DB + cache must be up |

- `GET /health` and `GET /health/ready` return 200 on success, 503 if DB or cache unreachable
- `GET /health/live` always returns 200
- Routes registered in `bootstrap/app.php` without auth middleware
- Tests: `HealthEndpointsTest` (8 cases) — success paths + DB failure mocking

### 5. End-to-End Pipeline Smoke Test

`tests/Feature/PipelineSmokeTest.php` — two tests exercising the full `ObservationRecorded → Recommendation` pipeline.

- `test_full_pipeline_produces_recommendation_from_observation` — fires 5 AI fixtures in order: `website-facts`, `opportunity-detection`, `rationale-generation`, `campaign-blueprint`, `email-content`. Asserts every intermediate and final state: observation processed, facts created, twin activated, opportunity detected, decision committed with all 4 rationale keys, recommendation created in `pending` status, decision in `recommended` status, exactly 5 AI calls consumed.
- `test_pipeline_does_not_publish_without_approval` — confirms no content asset reaches `published` status without explicit approval.
- Both tests rely on `QUEUE_CONNECTION=sync` (set in `phpunit.xml`) so every dispatched job runs inline.

---

## Systemic Defects Fixed

Two silent defects were discovered during the smoke test. Both had been masking failures across the entire test suite.

### Defect 1 — Jobs Silently Not Dispatching (queue() Method Conflict)

**Root cause:** Every Atlas job had `public function queue(): string { return 'queue-name'; }`. Laravel's `Bus\Dispatcher::dispatchToQueue()` checks `method_exists($command, 'queue')` and, when true, treats the return value as a job ID and silently drops the dispatch.

**Impact:** All 10 Atlas AI jobs were silently never enqueued in any environment. The pipeline appeared to work in tests only because `Queue::fake()` doesn't invoke the dispatcher. The smoke test with `QUEUE_CONNECTION=sync` was the first test to expose this.

**Fix:** Removed `queue()` method from all 10 affected jobs. Replaced with `$this->onQueue('name')` called in each job's constructor. The Queueable trait stores the queue name in `$this->queue` (property), which is what Laravel reads.

**Jobs fixed:** `ProcessObservation`, `DetectOpportunities`, `CommitDecision`, `PrepareCampaign`, `GenerateContent`, `CreateRecommendation`, `PublishCampaign`, `PublishContent`, `PublishScheduledContent`, `CheckChannelHealth`.

### Defect 2 — Duplicate Event Listeners (Auto-Discovery Conflict)

**Root cause:** Laravel's `EventServiceProvider::$shouldDiscoverEvents` defaults to `true`, causing it to scan `app/Listeners/` and auto-register every listener it finds. `AppServiceProvider::boot()` was also manually registering all the same listeners. Every event had two registered listeners — both pointing to the same class.

**Impact:** Every cascade in the pipeline fired twice. When `CampaignAssetsReady` fired, `TriggerRecommendationCreation` ran twice, dispatching `CreateRecommendation` twice. The second dispatch hit a `UNIQUE constraint violation` on `recommendations.decision_id` and failed. The smoke test surfaced this as a constraint violation; the underlying cause required tracing raw listener arrays to identify.

**Fix:** Added `EventServiceProvider::disableEventDiscovery()` call in `AppServiceProvider::register()`. All listeners continue to be registered manually in `boot()` — the explicit registration is the authoritative source, and auto-discovery is disabled to prevent double registration.

---

## Test Suite Stabilization

After both systemic defects were fixed, the full test suite revealed 13 additional failures in unit tests that had been passing only because jobs were silently dropped. With the pipeline now actually running, those tests needed proper isolation.

**Pattern applied across all fixes:** Unit tests that only care about a specific layer of the pipeline should fake the events that would cascade beyond that layer. This is explicit in test intent — it makes clear what the test is actually verifying.

| Test file | Fix |
|-----------|-----|
| `KnowledgeServiceTest` | Added `Event::fake([DigitalTwinActivated::class])` to 3 tests that don't need the full cascade |
| `ProcessObservationTest` | Added `Event::fake([DigitalTwinActivated::class])` in `setUp()`; updated `test_fires_observation_processed_event` to also fake `DigitalTwinActivated` |
| `DecisionEngineTest` | Added `Event::fake([DecisionCommitted::class])` to 2 tests that commit decisions but don't exercise the campaign phase |
| `CampaignPipelineTest` | Fixed stale assertion: `test_campaign_assets_ready_event_dispatches_create_recommendation_job` was asserting `Event::assertNotDispatched(CampaignAssetsReady)` — the opposite of the test's stated intent. Fixed to `Bus::assertDispatched(CreateRecommendation::class)` |
| `ApprovalServiceTest` | Added `Event::fake([RecommendationApproved::class])` to `test_approve_transitions_campaign_to_approved` — without the fake, the publishing cascade ran, `PublishContent` failed with `UnknownChannelException`, and `checkCampaignCompletion()` set the campaign to `cancelled` |
| `PublishCampaignJobTest` | Changed `$job->queue()` to `$job->queue` — `queue()` method was removed from all jobs; the queue name is now the `$queue` property (set by `onQueue()`) |

---

## PHPStan Fix

`SsrfValidator::BLOCKED_CIDRS` had a wrong `@var` annotation (`array<string, array{network: int, mask: int}>`). The constant is a `list<string>` of CIDR strings. Fixed the annotation.

---

## Final State

| Metric | Value |
|--------|-------|
| Tests | 519 |
| Passing | 517 |
| Skipped | 2 (Redis — expected; Redis not required for development) |
| Failed | 0 |
| PHPStan level 8 | 0 errors |
| Pint | Clean |

### Production Blockers Resolved

| Blocker | Status |
|---------|--------|
| AnthropicProvider | ✅ Implemented |
| Filament superadmin gate | ✅ Implemented |
| SSRF protection on WebPageCrawler | ✅ Implemented |
| Health check endpoints | ✅ Implemented |
| End-to-end smoke test | ✅ Passing |

### Remaining Pre-Production Items

| Item | Notes |
|------|-------|
| PostgreSQL RLS | Strategy confirmed deferred; `CompanyScope` is the enforcement mechanism for MVP |
| `BusinessBrainService` Redis caching | Customer-dashboard-blocking; not production-critical for initial launch |
| Rate limiting on analytics webhooks | Customer-dashboard-blocking |
| Spec/code column drift | `Learning.value` vs spec `payload`; cleanup only |
| `ApplyLearnings` queue alignment | Architecture.md says `maintenance`; implementation uses `ai`; low-risk misalignment |
