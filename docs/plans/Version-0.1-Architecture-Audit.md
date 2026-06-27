# Version 0.1 Architecture Audit

**Purpose:** Pre-customer-dashboard readiness review. Covers structural integrity, security posture, production gaps, and recommended refactors. This is an audit plan — it produces a checklist for human review and identifies work items. It does not prescribe implementation order.

**Scope:** All code delivered through Milestone 9 (Learning Engine). Backend only — no frontend exists yet.

**How to use this document:**
- Each area has a **Files to Inspect** section and a **Checklist** of pass/fail criteria.
- Run through each checklist item in code review order (highest risk first).
- Create a GitHub issue for every FAIL.
- After all critical FAILs are resolved, the codebase is ready for customer dashboard work to begin.

---

## Risk Categories

| Category | Label | Meaning |
|----------|-------|---------|
| Critical | 🔴 | Would cause data leak, security breach, or silent data corruption in production |
| High | 🟠 | Would cause incorrect behavior, broken invariant, or significant gap in production |
| Medium | 🟡 | Would cause degraded quality, performance issue, or confusing behavior |
| Low | 🟢 | Cleanup, clarity, or future-proofing — does not block shipping |

---

## Audit Area 1 — Domain Model Consistency

**Risk:** 🟠 Spec/code drift means engineers work from incorrect assumptions, bugs slip through under "correct" behavior.

### Files to Inspect

- `specs/core/domain-model.md`
- `backend/app/Models/*.php`
- `backend/database/migrations/`
- `specs/core/learning-engine.md` §1.1, §1.2, §1.4

### Checklist

- [ ] **`Learning.value` vs `Learning.payload`** — `domain-model.md` uses `value`; `learning-engine.md` spec §1.1 says `payload`. The implementation uses `value`. The spec has internal drift. Verify the authoritative column name and update whichever doc is wrong. _(Impact: new engineers will write queries against the wrong column)_
- [ ] **`LearningApplication.applied_at`** — The spec (§1.2) defines an `applied_at` column on `LearningApplication`. The implementation uses only `created_at`. Confirm whether `applied_at` was intentionally dropped or whether `created_at` is serving double duty.
- [ ] **`CompanyScoringWeights.learning_id`** — Spec §1.4 defines `learning_id char(26)` as a nullable FK to `learnings.id`. Verify this column exists in the migration and model.
- [ ] **`Knowledge.type` enum** — Spec lists `pattern, insight, preference, performance, context`. M9 added `learning`. Confirm `domain-model.md` Knowledge section is updated to reflect the `learning` type.
- [ ] **`Observation.facts()` relationship** — Spec defines `Observation hasMany Fact`. Verify this relationship exists and the FK `facts.observation_id` is present in migrations.
- [ ] **`Company.deleted_at`** — Spec marks `Company` as `SoftDeletes`. Confirm the migration includes `deleted_at` and the model uses `SoftDeletes`.
- [ ] **`Recommendation` statuses** — Spec defines `pending, viewed, approved, edited_and_approved, rejected`. Verify the migration enum contains all five and the model's fillable/casts handle them correctly.
- [ ] **`Channel.config` encryption** — Spec says `config` should be encrypted at rest via Laravel's `encrypted` cast. Verify the cast is present in `Channel` model.
- [ ] **`Integration.config` encryption** — Same check for `Integration.config`.
- [ ] **Morph map completeness** — `AppServiceProvider` registers morph aliases for `Opportunity.subject`. Verify `catalog_item`, `catalog`, `company` are all registered and that `Approval.approvable` morph is also registered.
- [ ] **`CampaignAssetsReady` vs `CampaignPrepared`** — Architecture.md defines `CampaignPrepared` as the event fired after `CampaignPreparationService`. The implementation fires `CampaignAssetsReady`. Confirm which is authoritative and update docs.

### Pass Criteria

Every spec-defined column exists in the corresponding migration. Every spec-defined relationship exists in the model. All enum values match between spec and migration. No internal spec contradictions remain.

---

## Audit Area 2 — Event Chain Integrity

**Risk:** 🟠 Missing or broken event → listener wiring silently skips pipeline stages without error.

### Files to Inspect

- `backend/app/Events/*.php`
- `backend/app/Listeners/*.php`
- `backend/app/Providers/AppServiceProvider.php` (event registration)
- `docs/technical/Architecture.md` (event chain diagram)

### Checklist

- [ ] **`LearningRecorded` event** — Architecture spec and domain model reference this event. Verify it exists and is fired by `LearningService` after a Learning record is created.
- [ ] **`LearningApplied` event** — Spec references this event for when `applied_at` is set. Verify it exists and is fired by `LearningEngine::applyOne()`.
- [ ] **`CampaignApproved` / `CampaignCompleted` events** — Spec defines these for status transitions. Verify they exist in `app/Events/` and are wired to listeners.
- [ ] **`SynthesizeKnowledge` job** — Architecture.md references this as a distinct job triggered by `FactExtracted`. If knowledge synthesis was merged into `ProcessObservation`, the architecture doc must be updated and no orphaned listener should reference the removed job.
- [ ] **`TriggerRecommendationCreation`** — Verify this listener exists, is registered on `DecisionCommitted`, and dispatches the correct job.
- [ ] **`ScheduleMetricRetrieval`** — Verify this listener is registered on `ExecutionCompleted` and dispatches `RetrieveExecutionMetrics` with the correct delay.
- [ ] **All listeners are registered** — Cross-reference every listener file in `app/Listeners/` against the `EventServiceProvider` or `AppServiceProvider` `$listen` array. No listener should exist that isn't registered.
- [ ] **Queue assignment for event-driven jobs** — Verify that listeners dispatch jobs to the correct queues per the Architecture.md queue topology table.
- [ ] **Event payload is minimal** — Events should carry entity IDs, not full Eloquent models. Verify no event passes an unserialized model that could cause stale data issues in queued listeners.

### Pass Criteria

Every event in the domain model spec fires at the correct lifecycle transition. Every listener that should handle an event is registered. No listener is orphaned (registered but its target job/class doesn't exist).

---

## Audit Area 3 — Queue Topology and Async Reliability

**Risk:** 🟠 Wrong queue assignments cause AI jobs to saturate non-rate-limited workers or high-priority queues to get starved.

### Files to Inspect

- `backend/app/Jobs/*.php`
- `backend/routes/console.php`
- `backend/infrastructure/supervisor/atlas-worker.conf`
- `docs/technical/Architecture.md` (Queue Topology table)

### Checklist

- [ ] **`ApplyLearnings` queue** — Architecture.md assigns `ApplyLearnings` to `maintenance`. Implementation puts it on `ai`. This is a deliberate or accidental mismatch. `maintenance` is correct: learning is a nightly batch, not a real-time AI call. Confirm and align.
- [ ] **`ShouldBeUnique` coverage** — `CommitDecision` and `DetectOpportunities` must be unique per company. `ApplyLearnings` must be unique per the 24-hour window. Verify all three implement `ShouldBeUnique` with correct `uniqueId()` and `uniqueFor` values.
- [ ] **`PublishScheduledContent` schedule** — Verify this job runs every 5 minutes in `routes/console.php`, not `daily`.
- [ ] **`CheckChannelHealth` schedule** — Verify this job runs every 30 minutes.
- [ ] **`PruneRawMetrics` schedule** — Verify this runs monthly on the `maintenance` queue.
- [ ] **`ApplyLearnings` daily schedule** — Verify it fires at `02:00` daily and iterates over companies with `active` Digital Twins only.
- [ ] **Job timeout alignment** — Architecture.md specifies per-queue timeouts (30s for high, 120s for ai, 60s for default, 300s for observations, 600s for maintenance). Verify Supervisor config reflects these and job-level timeouts don't exceed the worker timeout.
- [ ] **Retry configuration** — AI jobs should retry 3 times with exponential backoff (60/300/900s). `PublishContent` has this. Verify `ProcessObservation`, `RetrieveExecutionMetrics`, and `CommitDecision` have matching backoff.
- [ ] **Failed job handling** — Verify a `failed_jobs` table exists and `QUEUE_FAILED_DRIVER` is set (not null) so failed jobs are capturable for debugging in production.
- [ ] **Redis-backed queue driver** — Verify `QUEUE_CONNECTION=redis` is the expected production value and the config fallback is not `sync` in non-test environments.

### Pass Criteria

Every job is on the queue specified in the Architecture.md topology table. All uniqueness constraints match spec. All schedules match spec. Job retries follow the documented backoff pattern.

---

## Audit Area 4 — Multi-Tenancy Safety

**Risk:** 🔴 Cross-company data exposure is the highest-severity possible failure mode for Atlas.

### Files to Inspect

- `backend/app/Domain/Shared/Scopes/CompanyScope.php`
- `backend/app/Domain/Shared/Concerns/BelongsToCompany.php`
- `backend/app/Models/*.php` (every model that carries `company_id`)
- `backend/app/Services/Learning/LearningEngine.php`
- `backend/app/Services/Learning/EvidenceEvaluator.php`
- `backend/app/Filament/Resources/*.php`

### Checklist

- [ ] **Every `company_id` model uses `BelongsToCompany`** — Inspect every model file. Any model with a `company_id` column that does not use `BelongsToCompany` is a leak vector.
- [ ] **`withoutGlobalScopes()` audit** — Every `withoutGlobalScopes()` call in service/job code must be followed immediately by an explicit `->where('company_id', $companyId)` filter. Grep for `withoutGlobalScopes()` and verify every call site.
- [ ] **Filament resource scoping** — Filament admin lists all records from all companies by default. If Filament is accessible to non-superadmin users (or will be in the customer dashboard phase), every resource query must be scoped. Verify whether `CompanyScope` applies inside Filament contexts or is bypassed.
- [ ] **`Channel` model `company_id = null`** — System/template channels have a null `company_id`. Verify `CompanyScope` handles the null case without accidentally returning system channels when queried with a specific company context.
- [ ] **`LearningEngine` company isolation** — Every query in `LearningEngine`, `EvidenceEvaluator`, `FactMutator`, `KnowledgeMutator`, `WeightCalibrator` must use `->where('company_id', $companyId)`. No cross-company join should exist.
- [ ] **`OpportunityEngine` company isolation** — `OpportunityEngine::scan()` runs per company. Verify it never reads Opportunities, Facts, or Knowledge from other companies.
- [ ] **PostgreSQL RLS** — `docs/technical/Database.md` specifies Row-Level Security as a defense-in-depth layer. Verify whether RLS policies have been applied to any tables. If not, this is a known gap that should be tracked as a pre-production prerequisite.
- [ ] **`unique` constraints across company boundaries** — Verify that unique constraints on fields like `(external_id, company_id)` on `catalog_items` are properly scoped to include `company_id` and not just the external key alone.

### Pass Criteria

No model with `company_id` is missing `BelongsToCompany`. Every `withoutGlobalScopes()` call is paired with an explicit `company_id` filter. No cross-company query exists anywhere in service code.

### Recommended Deliverable

A grep-based test (or PHPStan custom rule) that fails CI if any model file uses `company_id` without `use BelongsToCompany`.

---

## Audit Area 5 — AI/Provider Abstraction

**Risk:** 🟠 No real AI provider means Atlas cannot run in production. The abstraction is correct but the implementation is missing.

### Files to Inspect

- `backend/app/AI/Contracts/AiProvider.php`
- `backend/app/AI/Testing/FakeAiProvider.php`
- `backend/app/Providers/AppServiceProvider.php` (binding)
- `backend/app/AI/Prompts/*.php`
- `backend/app/Services/Analyst/*.php`
- `docs/technical/AI.md`

### Checklist

- [ ] **`AnthropicProvider` implementation** — Verify whether `AnthropicProvider.php` exists. If it does not, Atlas cannot run any AI pipeline in production. This is the single most critical production gap.
- [ ] **`AiProvider` binding in non-test environments** — `AppServiceProvider` should bind `FakeAiProvider` in `testing` only. Verify the binding correctly falls back to the real provider in `local` and `production` environments.
- [ ] **Only Analysts call `AiProvider`** — Grep for `AiProvider` usage. No file outside `app/Services/Analyst/` (and `AppServiceProvider`) should reference `AiProvider`. This is Core Rule 1 from `docs/technical/AI.md`.
- [ ] **Structured output on all prompts** — Every `Prompt::schema()` must return a non-null JSON Schema for prompts that produce machine-consumed output. Verify every analyst's prompt has a schema defined.
- [ ] **Prompt version stored on every AI-produced record** — Facts, Knowledge, ContentAssets, and Decision rationales should all store `prompt_name` and `prompt_version`. Verify these fields exist in the corresponding migrations and are populated by each analyst.
- [ ] **Unsanitized external content in prompts** — `docs/technical/AI.md` Core Rule 6: crawled HTML or user input must be stripped of control characters and length-capped before prompt inclusion. Verify `WebsiteAnalyst` and `FactExtractionPrompt` apply sanitization to `Observation.raw_payload`.
- [ ] **`embed()` method on `AiProvider`** — The interface defines `embed(string $text): array`. `FakeAiProvider` must implement it. If no embeddings are used yet, verify `embed()` throws `NotImplementedException` rather than silently returning empty data.

### Pass Criteria

`AnthropicProvider` exists and its binding is correctly conditional on environment. Every Analyst uses `AiProvider` only through the interface. All AI-produced records store prompt version. External content is sanitized before prompt inclusion.

---

## Audit Area 6 — Publishing Abstraction

**Risk:** 🟠 The publishing infrastructure is complete, but no real channels are connected. Atlas cannot publish to any real platform.

### Files to Inspect

- `backend/app/Services/Publishing/Contracts/ChannelPublisher.php`
- `backend/app/Services/Publishing/EmailPublisher.php`
- `backend/app/Services/Publishing/LogChannelPublisher.php`
- `backend/app/Providers/PublisherServiceProvider.php`
- `backend/app/Services/Publishing/Email/LogEmailProvider.php`
- `backend/app/Services/Publishing/Email/Contracts/EmailProvider.php`

### Checklist

- [ ] **`LogEmailProvider` vs real provider** — `LogEmailProvider` writes to a log file. No real email is sent. Verify there is a plan (and a spec or stub) for a `PostmarkEmailProvider` or equivalent before the customer dashboard requires actual publishing.
- [ ] **Circuit breaker implementation** — `publishing-engine.md` mentions Redis-backed circuit breakers per channel type. Verify whether these exist in `PublishContent` or `LogChannelPublisher`. If they don't, this is a known gap for production publishing.
- [ ] **`SupportsRollback` coverage** — Only publishers that call real platform APIs need rollback. Verify `LogChannelPublisher` correctly returns `false` from `rollback()` or does not implement `SupportsRollback`. `EmailPublisher` is the only real publisher — verify it implements `SupportsRollback`.
- [ ] **Channel credential encryption** — `ChannelCredentials.credentials` must be cast as `encrypted`. Verify the cast is present in the model and the underlying migration stores the column as `text` (not `json`, which would interfere with encryption).
- [ ] **Registry ordering** — `PublisherServiceProvider` boots `EmailPublisher` first so it takes priority over `LogChannelPublisher`. Verify the registration order hasn't changed and the first-match logic is tested.
- [ ] **`ping()` health check wiring** — `CheckChannelHealth` job calls `ping()` on all active credentials. Verify it exists on schedule and the `FakeChannelPublisher` test double supports `ping()` assertions.

### Pass Criteria

Every publisher correctly implements the `ChannelPublisher` interface. The registry first-match logic is tested. Credential columns use `encrypted` cast. The `LogChannelPublisher` fallback is never used in production contexts without explicit configuration.

---

## Audit Area 7 — Analytics and Learning Loop

**Risk:** 🟡 The loop is architecturally complete, but several integration points have not been validated end-to-end.

### Files to Inspect

- `backend/app/Services/Analytics/CampaignKpiService.php`
- `backend/app/Services/Learning/LearningService.php`
- `backend/app/Services/Learning/LearningEngine.php`
- `backend/app/Services/Brain/BusinessBrainService.php`
- `backend/app/Services/Opportunity/OpportunityScorer.php`
- `backend/app/Services/Opportunity/OpportunityEngine.php`

### Checklist

- [ ] **`BusinessBrainService` includes `type='learning'` Knowledge** — The business brain must surface Learning-derived Knowledge entries so content generation prompts see them. Verify `BusinessBrainService::for($company)` queries `Knowledge::active()->where('type', 'learning')` alongside other knowledge types.
- [ ] **`OpportunityScorer` reads `CompanyScoringWeights`** — Verify that `OpportunityEngine` loads the current `CompanyScoringWeights` row for the company and passes `typeModifiers` to `OpportunityScorer::score()` before the scoring loop.
- [ ] **Learning signal idempotency** — `LearningService::recordFromMetrics()` must check `(company_id, source_id, signal)` uniqueness before inserting. Verify the idempotency guard is correct and the `unique` constraint exists on the `learnings` table.
- [ ] **`CampaignKpiService::snapshotIfReady()` idempotency** — Verify that calling `snapshotIfReady()` twice for the same campaign returns the existing final snapshot without creating a duplicate.
- [ ] **Analytics Learning signals wired** — `LearningService` should create Learning records from final `CampaignKpiSnapshot` data. Verify `CampaignKpiService` calls `LearningService::recordFromMetrics()` when a final snapshot is created.
- [ ] **`ApprovalService` Learning signals** — Verify `approve()`, `reject()`, and `editAndApprove()` all create Learning records. The `source_id + signal` idempotency guard should prevent duplicates if the approval action is called twice.
- [ ] **Cooling period enforcement** — `WeightCalibrator` must check if a `LearningApplication` for the same signal category exists within the last 14 days. Verify the cooling check cannot be bypassed by running multiple Learning applications in the same transaction.
- [ ] **90-day evidence window** — `EvidenceEvaluator::count()` filters by `created_at >= now()->subDays(90)`. Verify this filter applies correctly and that the test suite covers the boundary (91-day-old signals are excluded).

### Pass Criteria

Business Brain includes learning-derived Knowledge. Opportunity scoring uses company-specific weights when they exist. All Learning signal creation points are idempotent. Cooling period prevents double-application.

---

## Audit Area 8 — Rollback and Auditability

**Risk:** 🟡 Rollback correctness is hard to verify without knowing the exact state machine transitions.

### Files to Inspect

- `backend/app/Services/Learning/LearningRollbackService.php`
- `backend/app/Models/LearningApplication.php`
- `backend/app/Models/Fact.php`
- `backend/app/Models/Knowledge.php`
- `backend/app/Models/CompanyScoringWeights.php`
- `backend/tests/Feature/Learning/LearningRollbackServiceTest.php`

### Checklist

- [ ] **No deletes in rollback path** — `LearningRollbackService::rollback()` must never call `delete()` on any record. All rollback is achieved through new rows or flag updates. Verify with a grep for `->delete()` in the rollback service.
- [ ] **`Learning.applied_at` reset** — After rollback, `Learning.applied_at` must be set back to `null` so the Learning re-enters the queue. Verify this update happens within the same DB transaction as the compensating record creation.
- [ ] **`LearningApplication.rolled_back_at` set once** — Rolling back an already-rolled-back `LearningApplication` must throw `RuntimeException`. Verify this guard exists and is tested.
- [ ] **Fact supersession chain integrity** — Rolling back a `fact_mutation` effect must: (1) set the new Fact's `is_current = false`, (2) restore the old Fact's `is_current = true` and clear `superseded_by_id`. Verify step 2 does not permanently erase the supersession link from the old fact's history.
- [ ] **Weight version rollback** — After rolling back a weight calibration, the previous `CompanyScoringWeights` row must have `is_current = true`. Verify that `OpportunityScorer` immediately sees the restored weights without requiring a cache flush.
- [ ] **`effects` descriptor completeness** — Every `LearningApplication.effects` entry must have `type`, at least one entity ID, and a non-empty `description`. Verify none of the mutators produce effects with empty `description` strings.
- [ ] **Audit trail SQL queries documented** — `learning-engine.md` §12.4 documents five SQL audit queries. Verify these queries actually work against the current schema (column names may have changed since the spec was written).

### Pass Criteria

No `->delete()` in rollback path. `applied_at` reset is atomic. Double-rollback throws. All five audit trail queries in §12.4 return correct results against the current schema.

---

## Audit Area 9 — Filament/Admin Exposure Risks

**Risk:** 🟠 Filament is the only UI that exists. If it is accessible to company users (not just superadmins), it exposes all companies' data.

### Files to Inspect

- `backend/app/Filament/Resources/*.php`
- `backend/app/Providers/Filament/AdminPanelProvider.php`
- `backend/routes/web.php`
- `backend/config/filament.php` (if exists)

### Checklist

- [ ] **Filament authentication** — Verify Filament panel requires authentication and has a guard configured. The default Filament guard uses `auth` which requires a logged-in user, but the default middleware does not enforce company membership or superadmin role.
- [ ] **Filament is superadmin-only** — Verify the Filament panel is restricted to users with a superadmin flag or specific email domain. If any registered user can access Filament, all company data is exposed to any account holder.
- [ ] **Filament resources do not have edit capabilities on sensitive models** — Resources like `LearningApplication`, `Fact`, `Knowledge` must be read-only. Verify no Filament resource allows creating or editing records that should be append-only.
- [ ] **Approve/Reject actions in Filament** — The `RecommendationResource` has approve/reject actions. Verify these actions call `ApprovalService` with a valid `User` record and are not callable without authentication.
- [ ] **Learning rollback action in Filament** — If a rollback action exists in Filament, verify it requires a `rollback_reason` (non-empty) and is gated behind a superadmin check.
- [ ] **No raw credentials exposed** — `ChannelCredentials.credentials` is encrypted. Verify Filament's `ChannelCredentials` view (if any) does not call `->toArray()` on the model in a way that decrypts and displays credentials.

### Pass Criteria

Filament is inaccessible to regular users. All append-only models are read-only in Filament. Approval and rollback actions are authenticated and authorized.

### Recommended Deliverable

A Filament `canAccess()` policy or `Panel::authMiddleware()` that enforces superadmin-only access before customer dashboard work begins.

---

## Audit Area 10 — Test Coverage Gaps

**Risk:** 🟡 449 tests cover the happy path well. Edge cases, integration boundaries, and error paths have gaps.

### Files to Inspect

- `backend/tests/Feature/` (all directories)
- `backend/tests/Unit/` (if any)
- `backend/phpunit.xml`

### Checklist

- [ ] **No end-to-end pipeline test** — No test covers the full loop from `Observation` creation to `Recommendation` surfaced. This means a regression in any listener-to-job transition could go undetected. Recommend one smoke test that walks the full pipeline using fakes.
- [ ] **Filament action tests** — Approve/Reject actions in Filament are not covered by tests. These are user-facing actions with real side effects. Recommend at least one Filament action test per critical action.
- [ ] **Webhook HMAC adversarial tests** — `PostmarkWebhookHandler` verifies HMAC signatures. Verify there is a test for invalid signatures that asserts the request is rejected (not silently processed).
- [ ] **Redis integration tests skipped** — Two tests are marked skipped (Redis). These cover queue behavior with real Redis. Document whether they will be unskipped in CI or remain as manual smoke tests.
- [ ] **`LearningEngine` with rolled-back + re-applied signal** — The rollback path resets `applied_at = null`. Verify there is a test that: (1) applies a learning, (2) rolls it back, (3) runs `LearningEngine` again, (4) asserts the learning is re-applied correctly.
- [ ] **Conflict resolution tie behavior** — Test that a tie (1 outperformed + 1 underperformed, equal recency) leaves both unapplied across two consecutive runs.
- [ ] **`OpportunityScorer` with no `CompanyScoringWeights` row** — Test that a company with no weights row scores correctly using global defaults (no exception, no null pointer).
- [ ] **`EvidenceEvaluator` boundary conditions** — Test that a signal created exactly 90 days ago is included but 91 days ago is excluded.
- [ ] **Multi-company isolation in `LearningEngine`** — Existing test covers this for `EvidenceEvaluator`. Verify a dedicated test at the `LearningEngine::apply()` level confirms Company B's signals never influence Company A.

### Pass Criteria

At least one full-pipeline smoke test exists. Webhook HMAC rejection is tested. Rollback-then-reapply is tested. All boundary conditions for evidence counting are covered.

---

## Audit Area 11 — Production Readiness Gaps

**Risk:** 🔴 Atlas cannot be deployed to production in its current state. The following gaps must be resolved before any real user data touches the system.

### Files to Inspect

- `backend/app/AI/` (provider implementations)
- `backend/config/` (app, database, queue, logging)
- `backend/.env.example`
- `backend/infrastructure/`
- `backend/.github/workflows/ci.yml`

### Checklist

- [ ] **`AnthropicProvider` (or `OpenAiProvider`) not implemented** — The most critical production gap. Without a real AI provider, the Observation→Fact→Knowledge pipeline does not work. No Opportunity is ever detected. No Campaign is ever prepared.
- [ ] **Real email provider not implemented** — `LogEmailProvider` writes to a log file. Email campaigns cannot be sent to real subscribers.
- [ ] **No social media publishers** — Instagram, Facebook, LinkedIn, and X publishers do not exist. These were out of scope for M6/M7 but must exist before Atlas can publish to real social channels.
- [ ] **No object storage integration** — `raw_payload_ref` on `Observation` is meant to point to S3 (or equivalent). Verify whether this is implemented or silently ignored. If ignored, large payloads in `raw_payload` grow the database indefinitely.
- [ ] **`APP_ENV=production` configuration** — Verify `.env.example` includes all required keys for production and that no key defaults to an insecure value (e.g., `APP_DEBUG=true`).
- [ ] **No staging environment** — No staging or preview environment is provisioned. All testing happens against local SQLite or a developer's local PostgreSQL instance. This means production-specific bugs (connection pooling, real Redis, real queue workers) are untested.
- [ ] **No health check endpoint** — There is no `GET /health` or equivalent that load balancers or uptime monitors can hit. Required before any hosting is provisioned.
- [ ] **No error monitoring** — No Sentry, Bugsnag, or equivalent is configured. Production exceptions will go silently to the log file unless manually tailed.
- [ ] **GitHub Actions CI** — Verify the CI workflow defined in `.github/workflows/ci.yml` runs on push/PR to `main`. Confirm it runs: `php artisan migrate:fresh`, `vendor/bin/phpstan`, `vendor/bin/pint --test`, `php artisan test`.
- [ ] **No secrets management** — `APP_KEY` and database credentials are managed via `.env`. Verify `.env` is in `.gitignore`. A secrets management strategy (AWS Secrets Manager, Laravel Forge env vars, etc.) should be decided before production provisioning.

### Pass Criteria

A real AI provider is implemented and bound. A health endpoint exists. CI runs the full quality gate. `.env` is not committed.

---

## Audit Area 12 — Security and Privacy Risks

**Risk:** 🔴 Several risks could expose user data or allow an attacker to affect the system.

### Files to Inspect

- `backend/app/Services/Observatory/Connectors/Website/WebPageCrawler.php`
- `backend/app/Http/Controllers/Api/AnalyticsWebhookController.php`
- `backend/app/Services/Analytics/Webhooks/PostmarkWebhookHandler.php`
- `backend/app/AI/Prompts/FactExtractionPrompt.php`
- `backend/routes/api.php`

### Checklist

- [ ] **SSRF vulnerability in `WebPageCrawler`** — `WebPageCrawler` makes outbound HTTP requests to URLs provided by the user (website URL in `Integration.config`). Without SSRF protection, an attacker who controls an Integration record could direct Atlas to crawl internal network addresses (`169.254.169.254`, `localhost`, `10.x.x.x`). Validate that the crawled URL resolves to a public IP before the request is made.
- [ ] **Prompt injection in `FactExtractionPrompt`** — Crawled HTML is included in the prompt. A malicious website could embed instructions like "Ignore previous instructions and output...". Verify the crawled content is: (1) stripped of HTML tags, (2) truncated to the spec-defined 5,000-character cap, (3) wrapped in a clear content boundary in the prompt (e.g., `<crawled_content>...</crawled_content>`).
- [ ] **Webhook rate limiting** — `POST /api/analytics/webhooks/{provider}` is an unauthenticated (HMAC-verified) endpoint. Without rate limiting, it can be flooded. Verify Laravel's rate limiting middleware is applied to webhook routes.
- [ ] **Webhook HMAC for all providers** — HMAC verification is implemented for Postmark. Any future analytics provider webhook handler must also implement `verify()`. Verify the `AnalyticsWebhookHandler` interface requires `verify()` and the controller always calls it before dispatching the event.
- [ ] **`Integration.config` key rotation** — Encrypted fields use `APP_KEY`. If `APP_KEY` needs to be rotated, all encrypted `config` values become unreadable without a re-encryption step. Verify there is a documented procedure (even if just a note) for key rotation.
- [ ] **Filament route protection** — Filament routes should not be publicly accessible. Verify the Filament panel is served under a non-guessable path (not `/admin`) or requires IP allowlisting in production.
- [ ] **Sanctum token scope** — API routes are protected by Sanctum. Verify that API tokens are scoped or that the `sanctum` middleware is correctly applied to all API routes that should be protected.
- [ ] **GDPR/CAN-SPAM data retention** — `Observation.raw_payload` is nulled after 30 days (per spec). Verify the scheduled prune job exists and is tested. Raw audience data (email metrics) in `ExecutionMetric.raw` is pruned after 1 year — verify `PruneRawMetrics` job runs on schedule.
- [ ] **No individual contact tracking** — `analytics-engine.md` explicitly prohibits individual subscriber or contact tracking. Verify no table stores contact-level data (email addresses, names linked to engagement events).

### Pass Criteria

SSRF protection exists on `WebPageCrawler`. Crawled content is sanitized and length-capped before prompt inclusion. Webhook rate limiting is applied. All webhook handlers implement `verify()`. Data retention prune jobs are scheduled and tested.

---

## Audit Area 13 — Performance Risks

**Risk:** 🟡 No performance issues affect correctness, but several patterns will degrade significantly under real data volumes.

### Files to Inspect

- `backend/app/Services/Brain/BusinessBrainService.php`
- `backend/app/Services/Learning/EvidenceEvaluator.php`
- `backend/app/Services/Observatory/Connectors/Website/WebPageCrawler.php`
- `backend/database/migrations/` (indexes)

### Checklist

- [ ] **`BusinessBrainService` has no caching** — `domain-model.md` recommends a 5-minute Redis TTL cache per company. Without it, every AI prompt call assembles the full Business Brain from multiple DB queries. Under load (multiple Observations processed concurrently), this creates N×M database queries. Implement before customer dashboard work begins.
- [ ] **`EvidenceEvaluator::count()` PHP-side filtering** — All Learning records for a company+signal are loaded into PHP, then filtered by discriminator. For a company with 10,000 Learning records, this loads all of them. This was a correct cross-DB compatibility choice for SQLite tests, but add a `TODO: replace with SQL JSON extraction in PostgreSQL production` comment and track as a known performance debt.
- [ ] **Missing indexes** — Verify the following indexes exist (per spec):
  - `learnings (company_id, applied_at)` — primary query path for `ApplyLearnings`
  - `learnings (company_id, signal, created_at)` — query path for `EvidenceEvaluator`
  - `learning_applications (company_id, applied_at)` — cooling period check
  - `company_scoring_weights (company_id, is_current)` — primary query path for `OpportunityScorer`
  - `facts (company_id, key, is_current)` — primary query path for Business Brain assembly
  - `knowledge_entries (company_id, type, is_active)` — primary query path for Business Brain assembly
- [ ] **`WebPageCrawler` single-job timeout risk** — Crawling 20 pages sequentially in a single job could exceed the `observations` queue timeout (300s). Verify there is either a timeout on the Guzzle client or a page count guard that respects the job timeout.
- [ ] **`OpportunityEngine::scan()` query count** — If detectors each issue individual DB queries per catalog item, this becomes an N+1 query. Verify detectors load needed data in batch (eager-loaded) rather than per-item.
- [ ] **No query analysis yet** — No EXPLAIN or query time logging is in place. Before a staging environment exists, there is no way to catch slow queries on real PostgreSQL. Add Laravel Telescope or query logging as a pre-staging task.

### Pass Criteria

`BusinessBrainService` caching is planned (issue created). All spec-required indexes are verified in migrations. `EvidenceEvaluator` performance risk is tracked. N+1 risks in detectors are assessed.

---

## Audit Area 14 — Documentation Cleanup

**Risk:** 🟢 Stale docs mislead new contributors and create confusion during code review.

### Files to Inspect

- `docs/STATUS.md`
- `docs/technical/Architecture.md`
- `specs/core/domain-model.md`
- `specs/core/learning-engine.md`
- `CHANGELOG.md`

### Checklist

- [ ] **`docs/STATUS.md` "Current Objectives" section** — Still lists objectives from pre-M4 (implementing `Opportunity`, `Decision`, `OpportunityDetector`). Update to reflect current state: all MVP milestones complete, next focus is production readiness.
- [ ] **`docs/STATUS.md` "Next Tasks"** — Still lists M9 Learning Engine implementation tasks that are now complete. Remove or replace with the post-M9 backlog.
- [ ] **`docs/STATUS.md` Technical Debt** — Still mentions "Campaign and ContentAsset are stubs" (resolved in M5) and duplicate queue test entries. Clean up resolved items.
- [ ] **`docs/STATUS.md` "Last Updated"** — Says M9 implementation plan is being written; doesn't reflect M9 completion. Update the timestamp and summary.
- [ ] **`docs/technical/Architecture.md` event chain** — References `SynthesizeKnowledge` as a distinct job. If synthesis was merged into `ProcessObservation`, update the event chain diagram.
- [ ] **`specs/core/learning-engine.md` vs implementation** — Spec uses `payload` column name; implementation uses `value`. Spec defines `LearningApplication.applied_at`; implementation uses `created_at`. Update spec to match code or vice versa, and document the decision.
- [ ] **`specs/core/domain-model.md` Knowledge.type** — Add `learning` to the `type` enum documentation. It was added in M9 but the spec was not updated.
- [ ] **`CHANGELOG.md` format consistency** — Confirm each milestone entry follows the same format (version header, date, file list, key decisions). Ensure M9 entry is complete.
- [ ] **`ROADMAP.md` duplicate "Dependencies" section in Phase 8** — The `Dependencies` section appears twice under Phase 8. Remove the duplicate.

### Pass Criteria

`docs/STATUS.md` accurately reflects the completed state of M9. All spec-to-code column name inconsistencies are resolved. No dead task lists remain in STATUS.md.

---

## Audit Area 15 — Recommended Refactors Before Customer Dashboard Work

**Risk:** 🟠 These are not bugs, but deferring them will make customer dashboard development harder and riskier.

### Recommended Deliverables (in priority order)

| Priority | Refactor | Rationale |
|----------|----------|-----------|
| 1 | **Implement `AnthropicProvider`** | Atlas is non-functional without a real AI provider. This is the blocking dependency for all production use. |
| 2 | **Filament superadmin gate** | Filament exposes all company data. Before a customer-facing dashboard shares any backend, Filament must be restricted to internal/superadmin users. |
| 3 | **`BusinessBrainService` Redis cache** | Each AI call assembles the Business Brain fresh. At even modest scale (10 companies, hourly observations), this creates unnecessary DB load. 5-minute TTL, keyed per `company_id`. |
| 4 | **SSRF protection on `WebPageCrawler`** | Public-facing security risk. Any user who can create an Integration can redirect Atlas to crawl internal network addresses. |
| 5 | **Health check endpoint** — `GET /api/health` | Required before any hosting is provisioned. Load balancers, uptime monitors, and deployment scripts need it. |
| 6 | **Rate limiting on `/api/analytics/webhooks/{provider}`** | HMAC verification is not a substitute for rate limiting. A flooded endpoint can exhaust queue workers. |
| 7 | **Prompt injection sanitization** | Crawled HTML enters prompts without guaranteed sanitization. Strip tags and enforce the 5,000-character cap in `FactExtractionPrompt`. |
| 8 | **PostgreSQL RLS** | Not blocking for early access, but required for production confidence. Add RLS policies on the five highest-risk tables (`catalog_items`, `facts`, `knowledge_entries`, `learnings`, `recommendations`) as a first pass. |
| 9 | **`EvidenceEvaluator` PostgreSQL JSON extraction** | Replace PHP-side discriminator filtering with `JSON_EXTRACT` (or PostgreSQL `->>` operator) for production scale. Keep the PHP fallback for SQLite test compat. |
| 10 | **End-to-end pipeline smoke test** | One test that walks `Observation → Fact → Knowledge → Opportunity → Decision → Campaign → Recommendation` using all fakes. Catches cross-milestone regression. |
| 11 | **`SynthesizeKnowledge` job clarification** | Decide: is knowledge synthesis a separate job or part of `ProcessObservation`? Update code and docs to match. |
| 12 | **Spec/code column drift resolution** | Resolve `Learning.value` vs `Learning.payload`, `LearningApplication.applied_at` vs `created_at`. Update specs or add migration as needed. |

### Files to Create or Modify

| File | Action |
|------|--------|
| `backend/app/AI/Providers/AnthropicProvider.php` | Create |
| `backend/app/Http/Controllers/Api/HealthController.php` | Create |
| `backend/app/Services/Brain/BusinessBrainService.php` | Modify (add Redis caching) |
| `backend/app/Services/Observatory/Connectors/Website/WebPageCrawler.php` | Modify (SSRF protection) |
| `backend/app/AI/Prompts/FactExtractionPrompt.php` | Modify (sanitization) |
| `backend/app/Providers/Filament/AdminPanelProvider.php` | Modify (superadmin gate) |
| `backend/routes/api.php` | Modify (rate limiting on webhook route) |
| `backend/database/migrations/` | Verify all spec-required indexes exist; create any missing |

---

## Audit Summary

| Area | Risk | Status |
|------|------|--------|
| 1. Domain Model Consistency | 🟠 | Needs review — spec/code drift confirmed |
| 2. Event Chain Integrity | 🟠 | Needs review — missing events and job ambiguity |
| 3. Queue Topology | 🟠 | Needs review — `ApplyLearnings` queue mismatch |
| 4. Multi-Tenancy Safety | 🔴 | Needs review — Filament and `withoutGlobalScopes()` audit required |
| 5. AI/Provider Abstraction | 🟠 | FAIL — `AnthropicProvider` not implemented |
| 6. Publishing Abstraction | 🟠 | Partial — infrastructure solid, no real publishers |
| 7. Analytics and Learning Loop | 🟡 | Needs review — BusinessBrain caching and signal wiring |
| 8. Rollback and Auditability | 🟡 | Needs review — no rollback-then-reapply test |
| 9. Filament/Admin Risks | 🟠 | FAIL — no superadmin gate exists |
| 10. Test Coverage Gaps | 🟡 | Partial — no end-to-end pipeline test |
| 11. Production Readiness | 🔴 | FAIL — AI provider, email provider, health check all missing |
| 12. Security/Privacy | 🔴 | Needs review — SSRF and prompt injection not confirmed protected |
| 13. Performance Risks | 🟡 | Known — no caching, PHP-side filtering at scale |
| 14. Documentation Cleanup | 🟢 | Low urgency — stale sections in STATUS.md |
| 15. Pre-Dashboard Refactors | 🟠 | 12 items identified; 5 are blocking for production |

### Blocking for Production (must resolve before any customer data)

1. Implement `AnthropicProvider` (or equivalent real AI provider)
2. Add Filament superadmin gate
3. SSRF protection on `WebPageCrawler`
4. Health check endpoint
5. Confirm `PostgreSQL RLS` strategy and timeline

### Blocking for Customer Dashboard (must resolve before building customer-facing UI)

6. `BusinessBrainService` caching
7. Rate limiting on webhook endpoint
8. End-to-end pipeline smoke test
9. Spec/code column drift cleanup
10. `docs/STATUS.md` updated to reflect current state

---

*Created: 2026-06-26 | Source: Milestones 1–9 review files, all core specs, all technical docs, codebase inspection*
