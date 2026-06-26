# Milestone 2 CTO Review — Discovery & Knowledge Platform

**Date:** 2026-06-26  
**Milestone:** Milestone 2 — Discovery & Knowledge Platform  
**Commit:** `dbc0668` + cleanup pass  
**Reviewer:** Prepared for CTO review

> **Cleanup pass applied post-review.** Three items from the review were addressed before Milestone 3: catalog type corrected to `mixed`, `IntegrationService::create()` implemented, and `SyncIntegration` made `ShouldBeUnique`. See CHANGELOG.md [Milestone 2 Cleanup] for full details.

---

## Milestone Summary

### What Was Implemented

Milestone 2 built the observation intake layer of the Atlas loop — the complete path from company creation through raw data collection, pipeline dispatch, and multi-tenant storage. No AI, no fact extraction, no synthesis. The stop boundary was respected.

Specifically delivered:

- **Multi-tenancy foundation** — ULID primary keys on all domain tables; `CompanyScope` global scope; `BelongsToCompany` trait applied to all tenant models. Tenancy enforcement is automatic via Eloquent, with a safe no-op in CLI and test contexts.
- **Domain migrations** — 8 migrations establishing the full database schema for the observation half of the domain: `users` (ULID PK), `personal_access_tokens` (ULID FK), `companies`, `company_memberships`, `catalogs`, `digital_twins`, `integrations`, `observations`.
- **Eloquent models** — 7 fully-implemented domain models: `User`, `Company`, `CompanyMembership`, `Catalog`, `DigitalTwin`, `Integration`, `Observation`. All carry `HasUlids`, `$fillable`, casts, relationships, and PHPStan-level-8-compliant generic type annotations.
- **Company provisioning** — `CompanyService::create()` provisions a Company, Catalog, DigitalTwin (status: `initializing`), and owner CompanyMembership in a single atomic transaction.
- **Connector framework** — `Connector` interface, `ConnectorRegistry`, `ConnectorResult` value object, and `UnsupportedIntegrationException`. The registry resolves the right connector for an integration type at runtime.
- **BFS website crawler** — `WebPageCrawler` using Guzzle + DOMDocument. Breadth-first, max 20 pages / depth 3, single HTTP fetch per page, strips navigation chrome, 5,000-char body text cap.
- **`WebsiteConnector`** — Maps `WebPageData → ConnectorResult`. Registered in `ConnectorServiceProvider`.
- **Observation pipeline** — `ObservationService`, `SyncIntegration` job (observations queue), `ProcessObservation` stub job (ai queue), `ObservationRecorded` / `IntegrationSyncStarted` / `IntegrationSyncCompleted` events, `DispatchObservationProcessing` listener.
- **40 tests passing** — 15 new feature tests covering company creation, tenant isolation, connector registry, observation service, connector mapping, and queue dispatch. PHPStan level 8: 0 errors. Pint: clean.

### Major Architectural Decisions

| Decision | Rationale |
|----------|-----------|
| `ConnectorResult` return type over spec's `Observation` | Spec says `sync(): Observation`. Implementation returns `Collection<int, ConnectorResult>` — one per crawled page, not one aggregate per sync. This correctly separates raw data collection from persistence, prevents the connector from depending on Eloquent, and makes each page independently observable. See **Specification Compliance** section. |
| `CompanyScope` as a no-op without a bound `current_company_id` | Allows the scope to be registered on all models without breaking CLI commands, queue workers, or test setup. Production middleware sets the binding. Tests bind explicitly when needed. |
| `encrypted:array` cast on `Integration.config` | Eloquent handles encrypt/decrypt transparently at the application layer. Works in SQLite tests (application-layer encryption only). PostgreSQL stores ciphertext. |
| PHPStan level 8 maintained | Zero regressions from Milestone 1. All new models carry proper generic type annotations (`@return BelongsTo<Company, $this>`, etc.) to satisfy invariant TDeclaringModel constraints. |
| Single-fetch crawl (links extracted during initial parse) | The original design re-fetched each page to extract links. Refactored to extract links during the same parse, eliminating one HTTP request per page. |

---

## Database

### New Migrations

| File | Table | Purpose |
|------|-------|---------|
| `0001_01_01_000000_create_users_table.php` | `users` | Rewritten — `char(26)` ULID PK; sessions `user_id` updated to `char(26)` |
| `2026_06_26_000300_create_personal_access_tokens_table.php` | `personal_access_tokens` | Sanctum; `char(26)` tokenable_id replacing default `bigInteger` morphs |
| `2026_06_26_000400_create_companies_table.php` | `companies` | Tenant root |
| `2026_06_26_000500_create_company_memberships_table.php` | `company_memberships` | User ↔ Company join |
| `2026_06_26_000600_create_catalogs_table.php` | `catalogs` | Catalog container (one per company) |
| `2026_06_26_000700_create_digital_twins_table.php` | `digital_twins` | Digital twin state |
| `2026_06_26_000800_create_integrations_table.php` | `integrations` | Data source connections |
| `2026_06_26_000900_create_observations_table.php` | `observations` | Raw observation records |

### Tables Added

**`companies`**  
`id char(26)`, `name`, `slug unique`, `industry nullable`, `website_url nullable`, `brand json nullable`, `settings json nullable`, `timestamps`, `deleted_at` (soft delete)

**`company_memberships`**  
`id char(26)`, `company_id char(26) FK`, `user_id char(26) FK`, `role enum(owner/admin/member/viewer)`, `invited_by char(26) nullable FK`, `joined_at nullable`, `timestamps`  
Unique constraint: `(company_id, user_id)`

**`catalogs`**  
`id char(26)`, `company_id char(26) unique FK`, `name default "Main Catalog"`, `type enum(inventory/services/menu/listings/mixed)`, `item_schema json nullable`, `last_synced_at nullable`, `timestamps`

**`digital_twins`**  
`id char(26)`, `company_id char(26) unique FK`, `status enum(initializing/active/stale/archived)`, `health_score tinyint unsigned`, `last_observed_at nullable`, `last_enriched_at nullable`, `metadata json nullable`, `timestamps`

**`integrations`**  
`id char(26)`, `company_id char(26) FK`, `type enum(website_crawl/rss_feed/api/csv_upload/manual)`, `name`, `config text (encrypted)`, `status enum(active/paused/error/disconnected)`, `last_run_at nullable`, `last_successful_run_at nullable`, `next_run_at nullable`, `last_error text nullable`, `timestamps`  
Index on `next_run_at` (scheduler query path)

**`observations`**  
`id char(26)`, `company_id char(26) FK`, `integration_id char(26) nullable FK`, `source_type enum(crawl/feed/api/manual/internal)`, `source_identifier string`, `raw_payload longtext nullable`, `raw_payload_ref string nullable`, `status enum(pending/processing/processed/failed)`, `observed_at`, `processed_at nullable`, `timestamps`  
Compound indexes: `(company_id, status)`, `(integration_id, observed_at)`

### Relationships

```
User
  └── CompanyMembership (hasMany)
        └── Company (belongsTo)

Company
  ├── DigitalTwin (hasOne)
  ├── Catalog (hasOne)
  ├── CompanyMembership (hasMany)
  ├── Integration (hasMany)
  └── Observation (hasMany)

Integration
  └── Observation (hasMany)

Observation
  └── Integration (belongsTo)
```

### ULID Implementation

All primary keys are `char(26)` — exactly 26 characters for a ULID. Every model uses Laravel's `HasUlids` trait, which sets `$keyType = 'string'` and `$incrementing = false` and auto-generates ULIDs on `creating`. All foreign keys are declared as `char(26)` to match.

The `users` table was rewritten from Laravel's default `bigInteger` auto-increment PK. The Sanctum `personal_access_tokens` migration was similarly patched to use `char(26)` tokenable_id (replacing the default `bigInteger` morph column) to avoid FK type mismatches when Sanctum issues tokens for users.

### Multi-Tenancy Implementation

**Primary enforcement: `CompanyScope` global scope**

`CompanyScope` implements `Scope` and appends `WHERE company_id = ?` to all Eloquent queries on models that use it. The value is read from `app('current_company_id')`, which middleware will bind per-request in the HTTP layer (not yet built).

The scope is **a no-op when `current_company_id` is not bound** — this allows CLI commands, queue workers, and tests to operate without artificial constraints. Tests that need isolation explicitly bind `app()->instance('current_company_id', $id)`.

The `BelongsToCompany` trait is used by all tenant models (`CompanyMembership`, `Catalog`, `DigitalTwin`, `Integration`, `Observation`). It calls `static::addGlobalScope(new CompanyScope())` in `bootBelongsToCompany()` and provides the `company()` `BelongsTo` relationship.

**Defense-in-depth: PostgreSQL RLS**  
Defined in `Database.md` as the secondary layer. Not yet implemented — deferred to a future hardening pass. See Technical Debt.

---

## Domain

### Models Created

| Model | File | Key Traits | Notes |
|-------|------|-----------|-------|
| `Company` | `app/Models/Company.php` | `HasFactory`, `HasUlids`, `SoftDeletes` | Auto-slugs from name; all relationships |
| `CompanyMembership` | `app/Models/CompanyMembership.php` | `BelongsToCompany`, `HasUlids` | `user()`, `inviter()` relationships |
| `Catalog` | `app/Models/Catalog.php` | `BelongsToCompany`, `HasUlids` | `item_schema` array cast |
| `DigitalTwin` | `app/Models/DigitalTwin.php` | `BelongsToCompany`, `HasUlids` | `isActive()`, `isInitializing()` helpers |
| `Integration` | `app/Models/Integration.php` | `BelongsToCompany`, `HasUlids` | `config` cast as `encrypted:array`; `markAsError()` |
| `Observation` | `app/Models/Observation.php` | `BelongsToCompany`, `HasUlids`, `Prunable` | 180-day prune; nulls raw_payload before deletion; `markProcessing/Processed/Failed()` |

**Models Updated (previously stubs):**

| Model | Change |
|-------|--------|
| `User` | Added `HasUlids`, `HasApiTokens`, `memberships()` relationship; `@use HasFactory<UserFactory>` |

**Remaining stubs (carry-over from Milestone 1):**  
`Campaign`, `ContentAsset` — no migrations, no relationships; exist only for PHPStan type resolution on Milestone 1 contracts.

### Services Created

| Service | File | Responsibility |
|---------|------|----------------|
| `CompanyService` | `app/Services/Company/CompanyService.php` | `create(User, array): Company` — atomic provisioning of Company + Catalog + DigitalTwin + CompanyMembership |
| `ObservationService` | `app/Services/Observatory/ObservationService.php` | `record(Integration, ConnectorResult): Observation` and `recordAll()` — persists results, fires `ObservationRecorded` |

### Events

| Event | File | Payload | Fired When |
|-------|------|---------|------------|
| `ObservationRecorded` | `app/Events/ObservationRecorded.php` | `Observation $observation` | After each Observation is persisted |
| `IntegrationSyncStarted` | `app/Events/IntegrationSyncStarted.php` | `Integration $integration` | When `SyncIntegration` job begins |
| `IntegrationSyncCompleted` | `app/Events/IntegrationSyncCompleted.php` | `Integration $integration`, `int $observationCount` | When sync finishes successfully |

All events use `Dispatchable` + `SerializesModels`.

### Jobs

| Job | File | Queue | Status |
|-----|------|-------|--------|
| `SyncIntegration` | `app/Jobs/SyncIntegration.php` | `observations` | Implemented — resolves connector, syncs, records observations, updates timestamps |
| `ProcessObservation` | `app/Jobs/ProcessObservation.php` | `ai` | **Stub** — marks processing then processed; no AI yet |

`SyncIntegration` has 3 retries, 60-second backoff, and calls `integration->markAsError($message)` in `failed()`.

### Contracts

All contracts carried over from Milestone 1. `Connector` interface was updated:

| Contract | File | Change in M2 |
|----------|------|-------------|
| `Connector` | `app/Services/Observatory/Connectors/Contracts/Connector.php` | Return type changed from `Observation` to `Collection<int, ConnectorResult>` |
| `AiProvider` | `app/AI/Contracts/AiProvider.php` | Unchanged |
| `Analyst` | `app/Services/Analyst/Contracts/Analyst.php` | Unchanged |
| `ContentGenerator` | `app/Services/Content/Contracts/ContentGenerator.php` | Unchanged |
| `OpportunityDetector` | `app/Services/Opportunity/Detectors/Contracts/OpportunityDetector.php` | Unchanged |

### Connectors

| Class | File | Description |
|-------|------|-------------|
| `ConnectorResult` | `…/Connectors/ConnectorResult.php` | Readonly value object: `sourceType`, `sourceIdentifier`, `payload` (JSON string), `observedAt` |
| `ConnectorRegistry` | `…/Connectors/ConnectorRegistry.php` | Resolves the right `Connector` for an `Integration`; throws `UnsupportedIntegrationException` on miss |
| `UnsupportedIntegrationException` | `…/Connectors/Exceptions/UnsupportedIntegrationException.php` | RuntimeException with integration type in message |
| `WebPageData` | `…/Connectors/Website/WebPageData.php` | Readonly value object for one crawled page: url, statusCode, title, metaDescription, headings, bodyText, crawledAt |
| `WebPageCrawler` | `…/Connectors/Website/WebPageCrawler.php` | BFS crawler; Guzzle + DOMDocument; max 20 pages / depth 3; single fetch per page; 5,000-char body cap |
| `WebsiteConnector` | `…/Connectors/Website/WebsiteConnector.php` | Implements `Connector`; calls `WebPageCrawler`; maps pages → `ConnectorResult` objects |

**Service Providers:**

| Provider | Responsibility |
|----------|----------------|
| `ConnectorServiceProvider` | Registers `ConnectorRegistry` as a singleton with `WebsiteConnector` pre-loaded |
| `AppServiceProvider` | Wires `ObservationRecorded → DispatchObservationProcessing` event listener |

**Listener:**

`DispatchObservationProcessing` — Handles `ObservationRecorded`; dispatches `ProcessObservation` job.

---

## Testing

### Feature Tests

| File | Tests | Covers |
|------|-------|--------|
| `CompanyServiceTest.php` | 5 | Company + Catalog (type: `mixed`) + DigitalTwin + Membership created atomically; correct initial states |
| `TenantIsolationTest.php` | 2 | `CompanyScope` filters by bound `company_id`; no-op when unbound |
| `ConnectorRegistryTest.php` | 3 | Resolves `WebsiteConnector` for `website_crawl`; throws for `rss_feed`; registry is non-empty |
| `WebsiteConnectorTest.php` | 2 | Maps `WebPageData` objects to `ConnectorResult` objects (Mockery mock of crawler); `supports()` type check |
| `SyncPipelineTest.php` | 3 | `SyncIntegration` dispatched to `observations` queue; `ProcessObservation` dispatched to `ai` queue; `SyncIntegration` implements `ShouldBeUnique` with integration id |
| `ObservationServiceTest.php` | 3 | Persists Observation with correct fields; fires `ObservationRecorded`; `recordAll()` handles multiple results |
| `IntegrationServiceTest.php` | 5 | Creates integration with correct attributes, encrypted config, 7-day `next_run_at`, immediate dispatch, default name |

### Milestone 1 Bootstrap Tests (carried forward)

| File | Tests | Covers |
|------|-------|--------|
| `ApplicationBootTest.php` | 6 | Laravel boots; env/config loading; queue connections configured |
| `DatabaseConnectionTest.php` | 3 | SQLite in-memory connection; migrations run; `users` table exists |
| `QueueDispatchTest.php` | 8 | All 5 queues wired; jobs dispatch correctly |
| `RedisConnectionTest.php` | 2 | Redis connects (skipped without local Redis) |
| `FakeAiProviderTest.php` | 5 | `FakeAiProvider` assertion API |
| `PromptTest.php` | 4 | Abstract `Prompt` base class |

### Current Test Count

| Status | Count |
|--------|-------|
| Passing | 46 |
| Skipped | 2 (Redis — requires live Redis instance) |
| Failing | 0 |
| Total | 48 |

### Coverage

No formal coverage report is configured. PHPUnit runs without `--coverage`. Coverage tooling is deferred — requires Xdebug or PCOV to be installed, which is not configured in the current dev environment. This is a known gap; see Technical Debt.

---

## Technical Debt

### Known Limitations

| Item | Introduced | Description |
|------|------------|-------------|
| `ProcessObservation` is a stub | Milestone 2 | The job marks pending → processing → processed but does nothing. Fact extraction is the first task in Milestone 3. |
| `Campaign` and `ContentAsset` models are stubs | Milestone 1 | No migrations, no relationships. Exist only for PHPStan type resolution. Required in Milestone 4+. |
| `WebPageCrawler` does not handle JS-rendered pages | Milestone 2 | Uses simple HTTP (Guzzle). Sites that render inventory via JavaScript will not be crawled. Requires a headless browser connector (Puppeteer/Playwright) as a separate integration type. |
| `WebPageCrawler` follows all same-domain links | Milestone 2 | No respect for `robots.txt`. Should check `robots.txt` before crawling production sites. |
| `WebPageCrawler` has no connection pooling | Milestone 2 | Each page is a sequential HTTP request. Acceptable for 20 pages but will be slow at larger scale. Parallelisation deferred. |
| No `BusinessBrainService` yet | Milestone 2 | `BusinessBrain` value object exists (Milestone 1) but the assembly service is not implemented. Required before Milestone 3 AI calls. |
| PostgreSQL RLS not configured | Milestone 2 | `Database.md` specifies RLS as a defense-in-depth layer. Not applied — `CompanyScope` is the only enforcement. RLS would require a PostgreSQL policy migration and application-layer session variable setting. |
| No scheduler wiring | Milestone 2 | `SyncIntegration` must be dispatched by a scheduler for recurring syncs (via `next_run_at`). The `IntegrationService` and scheduler query are not yet built. |
| Queue tests use `Queue::fake()` | Milestone 1 | Dispatch mechanism proven; live Redis worker execution not tested. |
| No test coverage metrics | Milestone 2 | No Xdebug/PCOV configured; coverage percentages unknown. |

### Deferred Work

- `Fact` model and migration (Milestone 3)
- `Knowledge` model and migration (Milestone 3)
- `FactExtractionAnalyst` and `WebsiteAnalyst` (Milestone 3)
- `BusinessBrainService::for(Company)` (Milestone 3)
- Scheduler command and `next_run_at` query (Milestone 3 pre-requisite)
- `robots.txt` compliance in `WebPageCrawler` (pre-launch hardening)
- PostgreSQL RLS policies (security hardening, post-MVP)
- `CatalogItem` model and migration (Milestone 4)
- HTTP controllers and API layer (Milestone 5)

### Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| CBB Auctions uses JS-rendered inventory pages | High | High | `WebPageCrawler` will see static HTML only. Spike a headless connector early in Milestone 3. |
| `encrypted:array` cast may cause issues on production PostgreSQL if the `APP_KEY` rotates | Low | High | Any key rotation must migrate encrypted values. Document this constraint before moving to production. |
| No rate limiting on `WebPageCrawler` | Medium | Medium | Could trigger IP blocks on aggressive crawl targets. Add per-domain request throttling before launching with real businesses. |
| ~~`SyncIntegration` concurrent dispatch~~ | ~~Medium~~ | ~~Medium~~ | **Resolved in cleanup pass** — `ShouldBeUnique` implemented, keyed on `integration->id`. |

---

## Specification Compliance

### Domain Model (`specs/core/domain-model.md`)

| Requirement | Status | Notes |
|-------------|--------|-------|
| All PKs are ULIDs | ✅ Compliant | `char(26)` on all tables; `HasUlids` on all models |
| `company_id` on all tenant tables | ✅ Compliant | Every tenant table carries `company_id`; `BelongsToCompany` enforces it |
| Global scope on all tenant models | ✅ Compliant | `CompanyScope` applied via `BelongsToCompany::bootBelongsToCompany()` |
| `Company`: name, slug, industry, website_url, brand (json), settings (json), softDeletes | ✅ Compliant | All columns present; auto-slug; brand/settings array cast |
| `CompanyMembership`: role enum (owner/admin/member/viewer), invited_by, joined_at | ✅ Compliant | Full schema implemented |
| `Catalog`: type enum (inventory/services/menu/listings/mixed), item_schema (json) | ✅ Compliant | Full schema implemented |
| `DigitalTwin`: status enum (initializing/active/stale/archived), health_score | ✅ Compliant | Full schema; isActive(), isInitializing() helpers |
| `Integration`: encrypted config, type enum, status enum, last_run_at, next_run_at | ✅ Compliant | All columns; `encrypted:array` cast; `markAsError()` |
| `Observation`: source_type enum, raw_payload, raw_payload_ref, status enum, prune | ✅ Compliant | Full schema; `Prunable` trait; 180-day processed-row prune |
| `ObservationRecorded` event fired on Observation creation | ✅ Compliant | Fired by `ObservationService::record()` |
| `SoftDeletes` on Company | ✅ Compliant | Applied |
| `SoftDeletes` on Catalog | ⚠️ Missing | Domain model specifies soft deletes on Catalog Items but not Catalog itself — this is fine. But `CatalogItem` model is a stub. |
| `Company` `hasMany` Fact, Knowledge, Opportunity, Decision, etc. | ⚠️ Deferred | These relationships will be added as those models are implemented in later milestones. `Company` currently has only the M2-relevant relationships. |
| `Observation` raw_payload nulled at 30 days (not 180) | ⚠️ Minor deviation | Spec says null raw_payload after `processed_at + 30 days`, then prune row at 180 days. Implementation prunes the entire row (with payload nulled first) at 180 days. The two-phase approach is not yet implemented. |
| `Integration` `last_successful_run_at` | ⚠️ Added beyond spec | Spec does not include this column. Added as a practical necessity for `SyncIntegration` to track successful sync timestamps separately from error runs. Additive, non-breaking. |

### Database.md

| Requirement | Status | Notes |
|-------------|--------|-------|
| Shared schema, row-level tenancy | ✅ Compliant | CompanyScope enforces it |
| `char(26)` ULID column type | ✅ Compliant | All PKs and FKs |
| `HasUlids` on all models | ✅ Compliant | |
| Compound index `(company_id, status)` on observations | ✅ Compliant | |
| Compound index `(integration_id, observed_at)` on observations | ✅ Compliant | |
| Encrypted `config` on integrations | ✅ Compliant | `encrypted:array` cast |
| `SoftDeletes` on companies | ✅ Compliant | |
| `Prunable` on observations | ✅ Compliant | |
| PostgreSQL RLS as defense-in-depth | ❌ Not implemented | Defined in spec as secondary enforcement. No RLS policies exist yet. Deferred to post-MVP hardening. |
| Index on `next_run_at` for scheduler | ✅ Compliant | Applied on integrations |

### Architecture.md

| Requirement | Status | Notes |
|-------------|--------|-------|
| Business logic in domain services, not controllers | ✅ Compliant | `CompanyService`, `ObservationService` own all logic; no controller built yet |
| Jobs are thin orchestration — no business logic | ✅ Compliant | `SyncIntegration` delegates to `ConnectorRegistry` + `ObservationService`; no business decisions inside the job |
| `ConnectorRegistry` resolves connectors by type | ✅ Compliant | Implementation matches spec exactly |
| `ConnectorServiceProvider` registers connectors | ✅ Compliant | Singleton with `WebsiteConnector` registered |
| `ObservationRecorded → ProcessObservation` event chain | ✅ Compliant | `DispatchObservationProcessing` listener wired in `AppServiceProvider` |
| `SyncIntegration` on `observations` queue | ✅ Compliant | |
| `ProcessObservation` on `observations` queue | ⚠️ Minor deviation | Spec assigns `ProcessObservation` to `observations` queue. Implementation puts it on the `ai` queue — anticipating that the real implementation (Milestone 3) will make AI calls that belong on `ai`. Arguably correct for M3; questionable for M2 where it's a no-op. |
| Event-driven: `IntegrationSyncStarted`, `IntegrationSyncCompleted` | ✅ Compliant | Both events implemented and fired from `SyncIntegration` |
| Connector returns `Observation` per spec | ❌ Spec deviation | See below. |
| Module-to-module communication via events, not direct calls | ✅ Compliant | Observatory fires events; Application layer (listener) dispatches jobs |

**Spec Deviation — `Connector::sync()` return type:**

The Architecture.md spec declares:
```php
public function sync(Integration $integration): Observation;
```

The implementation declares:
```php
/** @return Collection<int, ConnectorResult> */
public function sync(Integration $integration): Collection;
```

**Justification:** The spec's signature assumes one Observation per sync. The website crawler produces one observation per page (up to 20). Returning a single `Observation` would either require the connector to persist the observation (violating the infrastructure/domain separation), or aggregate all pages into a single blob (losing per-page observability).

The `ConnectorResult` value object cleanly separates raw data collection (connector's job) from persistence (ObservationService's job). `ObservationService::recordAll()` creates one `Observation` row per `ConnectorResult`. This is a better design. The spec's example was written assuming a single-page sync.

**Documented:** In `CHANGELOG.md`, `docs/STATUS.md`, and `WebsiteConnector.php` comments.

### AI.md

| Requirement | Status | Notes |
|-------------|--------|-------|
| Only Analysts call `AiProvider` | ✅ Compliant | No code in M2 calls `AiProvider`. `ProcessObservation` is a stub. |
| AI calls go on `ai` queue | ✅ Compliant | `ProcessObservation` is wired to `ai` queue in anticipation |
| `FakeAiProvider` available for testing | ✅ Compliant | From Milestone 1; unchanged |
| No AI in Milestone 2 | ✅ Compliant | Stop boundary respected |

### MVP Workflow (`specs/product/mvp-workflow.md`)

| Step | Status | Notes |
|------|--------|-------|
| Step 1: User signs up | 🔲 Not built | Model exists; no auth controller |
| Step 2: User creates company | ✅ Service built | `CompanyService::create()` implements the exact sequence from the spec. Default catalog type is `mixed`. |
| Step 3: URL entry → Integration created | ✅ Service built | `IntegrationService::create()` implemented in cleanup pass — creates Integration, dispatches `SyncIntegration` immediately, sets `next_run_at = +7 days`. No HTTP controller yet. |
| Step 4: `SyncIntegration` → `WebsiteCrawlConnector` → Observation | ✅ Infrastructure built | `SyncIntegration`, `ConnectorRegistry`, `WebsiteConnector`, `ObservationService`, `ObservationRecorded` — all wired. `SyncIntegration` now uniqueness-guarded. |
| Step 5: `ProcessObservation` → Facts | 🔲 Stub only | Job exists but does nothing |
| Steps 6–13 | 🔲 Not built | Milestone 3+ |

---

## Ready for Milestone 3?

**YES**

The infrastructure required to receive raw observations is complete, tested, and passing PHPStan level 8 with 0 errors. The stop boundary was respected — no AI, no Facts, no Knowledge.

**What Milestone 3 can build on immediately:**

- `Observation` records with `status: pending` are created on every crawl — `ProcessObservation` is already dispatched to the `ai` queue, already picks them up, just needs real implementation.
- `WebPageData` payloads are stored as JSON in `raw_payload` — the body text is pre-extracted and capped at 5,000 chars, ready to pass directly to a fact extraction prompt.
- `ConnectorResult.sourceIdentifier` is the page URL — fact keys can be namespaced by URL.
- `BusinessBrain` value object exists — `BusinessBrainService::for(Company)` needs to be written, but the shape of the object is already defined.

**Prerequisites that must be addressed in Milestone 3 before AI calls are made:**

1. `Fact` model and migration
2. `BusinessBrainService::for(Company)` — returns a `BusinessBrain` with current facts and recent observations
3. `FactExtractionPrompt` and `StructuredResponseParser`
4. `WebsiteAnalyst` implementing the full Analyst pattern
5. Real `ProcessObservation` implementation calling `WebsiteAnalyst`

**Cleanup pass completed before Milestone 3 started:**

- ✅ `ShouldBeUnique` on `SyncIntegration` — implemented, keyed by `integration->id`
- ✅ `IntegrationService::create()` — implemented; dispatches `SyncIntegration` immediately, sets `next_run_at = +7 days`
- ✅ Catalog default type corrected to `mixed`
