# Changelog

All notable changes to Project Atlas are documented here. Entries are organized by milestone, then by commit.

Format: each entry identifies what changed, which files/paths are affected, and why the change was made.

---

## [Milestone 4 Specification — CTO Review & Scope Finalisation] — 2026-06-25

### Updated

- `specs/core/opportunity-engine.md` — CTO reviewed; implementation scope section rewritten and moved to the top of the document (immediately after the header block), replacing the earlier Section 15 draft

**Scope section now records authoritatively:**
- Required opportunity types in M4: `featured_item`, `urgency`, `new_arrival`, `re_engagement`
- Optional / spec-defined but not required in M4: `seasonal`, `milestone`
- Supporting models permitted: `CatalogItem`, `Campaign`, `Recommendation` — intentionally minimal; exist only to support detection, subject validation, evidence tracking, deduplication, cooldown checks, and duplicate recommendation guard conditions
- Hard DO NOT list: Campaign Engine behavior, campaign preparation, Marketing Assets, ContentAssets, channel renderers, any publishing integration (Facebook, Instagram, Email, SMS, LinkedIn, Google Ads, Meta Ads, Blog, Landing Pages), analytics, learning
- Goal of Milestone 4: produce a validated Decision with a complete rationale; Campaign creation begins in Milestone 5

---

## [Milestone 4 Specification] — Opportunity Engine — 2026-06-25

### Added

- `specs/core/opportunity-engine.md` — authoritative design specification for Milestone 4; supersedes any conflicting guidance in other documents for the Opportunity Engine, OpportunityDetectors, OpportunityScorer, and DecisionEngine

**Document covers:**
- What an Opportunity is and what it is not (not content, not a suggestion — a scored claim with evidence and expiry)
- Opportunity lifecycle: `open → selected → [Campaign created]`; also `dismissed` and `expired` transitions and who sets each
- Six opportunity types with trigger conditions, required evidence, scoring profiles, and vertical examples: `featured_item`, `urgency`, `new_arrival`, `re_engagement`, `seasonal`, `milestone`
- Composite scoring formula: `(relevance × 0.30) + (timing × 0.25) + (confidence × 0.25) + (urgency × 0.20)`; minimum threshold 30; component definitions with 0–100 ranges; tie-breaking rules
- Evidence chain: Facts → Knowledge → Opportunity description → Decision rationale; requirement that detectors read from `BusinessBrain` only (no direct DB queries except CatalogItem lookups)
- Expiration rules per type with rationale; `ExpireOpportunities` nightly maintenance job
- Deduplication rule: no new Opportunity persisted if an open or selected Opportunity with same `(type, subject_type, subject_id)` exists for the company; cooldown windows per campaign type enforced separately in `DecisionEngine`
- `OpportunityDetector` interface contract with `appliesTo(): string[]` and `detect(Company, BusinessBrain): Collection<int, OpportunityCandidate>`; full list of detector rules (no DB writes, no AI calls, return empty on sparse data)
- `OpportunityCandidate` readonly value object definition
- Four MVP rule-based detectors: `FeaturedItemDetector`, `UrgencyDetector`, `NewArrivalDetector`, `ReEngagementDetector`
- `OpportunityDetectionAnalyst` for AI-assisted detection: runs after rule-based pass; AI failure is non-fatal; confidence cap at 75 for AI-detected candidates
- Decision Engine selection algorithm: ordered by composite score; three guard conditions (no duplicate open recommendation, cooldown window, catalog availability); `RationaleGenerationAnalyst` generates all five rationale keys or throws `RationaleGenerationFailedException`
- How Decisions become Campaigns: field mapping from Decision to Campaign Engine (Milestone 5 scope); Milestone 4 stops at `Decision.status = "pending"` + `DecisionCommitted` event
- Full Milestone 4 acceptance criteria checklist (detection, detectors, Decision Engine, scoring, expiry, tests)
- Future extensibility: new detector pattern, new opportunity types, per-company weighted scoring (Phase 8), cross-company patterns (Phase 8), vertical-specific detectors, manual opportunity creation
- Scoring appendix: three worked examples (CBB urgency, exotic dealer featured item, dealer re-engagement) with per-component breakdown

### Updated

- `docs/STATUS.md` — current milestone section updated to reflect spec complete and implementation pending; Next Tasks rewritten with specific Milestone 4 implementation steps; Recently Completed updated

---

## [Milestone 3 Cleanup] — 2026-06-26

### Fixed

- `app/Models/Observation.php` — added `facts(): HasMany<Fact>` relationship; resolves the deferred spec compliance item from the M3 review
- `app/Services/Brain/KnowledgeService.php` — `updateTwin()` (renamed from `activateTwinIfReady()`) now updates `last_enriched_at` on every synthesis run, not only when the twin first transitions from `initializing → active`
- `tests/Feature/Brain/KnowledgeServiceTest.php` — added `test_updates_last_enriched_at_on_every_synthesis` to assert the fix

### Result

- 83 tests total; 81 passing, 2 skipped (Redis); PHPStan level 8 — 0 errors; Pint — clean

---

## [Milestone 3] — Fact Extraction & Knowledge Synthesis — 2026-06-26

### Added

**Database Migrations (`backend/database/migrations/`)**
- `2026_06_26_001000_create_facts_table.php` — `facts` table; `char(26)` ULID PK; `is_current` boolean; `superseded_by_id` self-referential; compound index `(company_id, key, is_current)`
- `2026_06_26_001100_create_knowledge_entries_table.php` — `knowledge_entries` table; `char(26)` ULID PK; type enum; `is_active` boolean; `expires_at` nullable; compound index `(company_id, type, is_active)`

**Eloquent Models (`backend/app/Models/`)**
- `Fact.php` — `BelongsToCompany`, `HasUlids`; `value` cast as `json`; `is_current` boolean cast; `current()` local scope; `observation()` and `supersededBy()` relationships
- `Knowledge.php` — `BelongsToCompany`, `HasUlids`; table `knowledge_entries`; `active()` local scope with `expires_at` handling
- `Company.php` — added `facts()` and `knowledge()` `hasMany` relationships

**AI Layer (`backend/app/AI/`)**
- `Prompts/FactExtractionPrompt.php` — extends `Prompt`; structured JSON schema; version `1.0`; temperature `0.1`; system prompt defines fact key conventions and confidence rules
- `StructuredResponseParser.php` — parses AI response to `array`; strips markdown code fences; throws `InvalidArgumentException` on non-JSON or non-array

**Analysts (`backend/app/Services/Analyst/`)**
- `WebsiteAnalyst.php` — implements `Analyst`; reads `Observation.raw_payload` as WebPageData JSON; calls `AiProvider::complete(FactExtractionPrompt)`; returns `Collection<int, FactData>`; short-circuits on empty `bodyText`

**Brain Services (`backend/app/Services/Brain/`)**
- `Data/FactData.php` — readonly VO: key, value, dataType, confidence
- `FactRepository.php` — `findCurrent(companyId, key)`, `currentForCompany(companyId)` — always `withoutGlobalScopes()`
- `KnowledgeRepository.php` — `activeForCompany(companyId)`, `findActiveForSubject(companyId, subject)`
- `FactService.php` — `storeExtracted(Observation, Collection<FactData>): Collection<Fact>`; creates new Facts; supersedes existing current fact for same key; fires `FactExtracted`
- `KnowledgeService.php` — `synthesizeForCompany(Company)`: groups current Facts by domain key; upserts Knowledge (type: `context`); fires `KnowledgeSynthesized`; activates DigitalTwin if `initializing`
- `BusinessBrainService.php` — `for(Company): BusinessBrain`; assembles from current Facts, active Knowledge, recent Observations, DigitalTwin, Catalog

**Events (`backend/app/Events/`)**
- `FactExtracted.php` — fired per Fact created by `FactService`
- `KnowledgeSynthesized.php` — fired per Knowledge entry upserted
- `ObservationProcessed.php` — fired when `ProcessObservation` marks an observation processed
- `DigitalTwinActivated.php` — fired when `KnowledgeService` transitions twin `initializing → active`

**Jobs (`backend/app/Jobs/`)**
- `ProcessObservation.php` — fully implemented (was stub); pipeline: `markProcessing → WebsiteAnalyst → FactService → KnowledgeService → markProcessed → ObservationProcessed`; `markFailed()` + re-throw on error

**Providers**
- `AppServiceProvider.php` — `register()` binds `AiProvider` to `FakeAiProvider` in `testing` environment

**Test Fixture**
- `tests/Fixtures/AI/website-facts.json` — 4-fact sample response used by analyst and pipeline tests

**Feature Tests (`backend/tests/Feature/Brain/`)**
- `WebsiteAnalystTest.php` — 3 tests: fact extraction, field mapping, empty payload short-circuit
- `FactServiceTest.php` — 4 tests: persist, supersede, observation linkage, empty input
- `KnowledgeServiceTest.php` — 6 tests: synthesis, events, twin activation, no duplicate, idempotent, empty input
- `BusinessBrainServiceTest.php` — 6 tests: company/twin, current facts, superseded excluded, active knowledge, catalog, empty M3 collections
- `ProcessObservationTest.php` — 6 tests: observation processed, facts created, knowledge created, twin activated, event fired, failure path

**Unit Tests (`backend/tests/Unit/AI/`)**
- `StructuredResponseParserTest.php` — 4 tests: plain JSON, markdown fences, code fences, invalid JSON exception
- `FactExtractionPromptTest.php` — 5 tests: system/user strings, schema structure, version, low temperature

### Result

- 82 tests total; 80 passing, 2 skipped (Redis); PHPStan level 8 — 0 errors; Pint — clean

### Spec Deviations

None. All implemented entities match `specs/core/domain-model.md` exactly.

### Technical Debt Introduced

| Item | Notes |
|------|-------|
| No production `AiProvider` implementation | Production deployment requires `AnthropicProvider` before AI jobs run |
| Knowledge synthesis is rule-based in M3 | AI-powered pattern synthesis deferred to M4+ |
| `DigitalTwin.last_enriched_at` only updated on activation | Should also update on re-synthesis |
| `Observation hasMany Fact` not added to Observation model | Deferred — not yet needed by any query path |

---

## [Milestone 2 Cleanup] — 2026-06-26

### Fixed

- `app/Services/Company/CompanyService.php` — default Catalog type corrected from `'inventory'` to `'mixed'`; `'mixed'` is the correct generic default for a newly onboarded company
- `tests/Feature/Discovery/CompanyServiceTest.php` — `test_creates_catalog_for_company` now explicitly asserts `type = 'mixed'`

### Added

- `app/Services/Observatory/IntegrationService.php` — `create(Company, string $type, array $config): Integration`; sets `name` via `defaultName()` match, `status: active`, `next_run_at: +7 days`; dispatches `SyncIntegration` immediately on creation
- `app/Jobs/SyncIntegration.php` — now implements `ShouldBeUnique`; `uniqueId()` returns `$this->integration->id` — prevents duplicate sync jobs from stacking in the queue
- `tests/Feature/Discovery/IntegrationServiceTest.php` — 5 new tests: correct attributes, encrypted config, `next_run_at` 7-day window, immediate `SyncIntegration` dispatch, default name for `website_crawl`
- `tests/Feature/Discovery/SyncPipelineTest.php` — `test_sync_integration_is_unique_per_integration` asserts job implements `ShouldBeUnique` and `uniqueId()` returns integration id

### Result

- 48 tests total; 46 passing, 2 skipped (Redis); PHPStan level 8 — 0 errors; Pint — clean

---

## [Milestone 2] — Discovery & Knowledge Platform — 2026-06-26

### Added

**Database Migrations (`backend/database/migrations/`)**
- `2026_06_26_000000_create_users_table.php` — rewrites Laravel default; `char(26)` ULID PK; sessions table `user_id` updated to `char(26)`
- `2026_06_26_000300_create_personal_access_tokens_table.php` — Sanctum migration; `char(26)` tokenable_id replacing default `bigInteger` morphs
- `2026_06_26_000400_create_companies_table.php` — `char(26)` PK, `slug` unique, `brand`/`settings` JSON, `softDeletes`
- `2026_06_26_000500_create_company_memberships_table.php` — `char(26)` PK/FKs, role enum (owner/admin/member/viewer)
- `2026_06_26_000600_create_catalogs_table.php` — `char(26)` PK, one per company, type enum (inventory/services/menu/listings/mixed)
- `2026_06_26_000700_create_digital_twins_table.php` — `char(26)` PK, status enum (initializing/active/stale/archived), health_score
- `2026_06_26_000800_create_integrations_table.php` — `char(26)` PK, type enum, encrypted config column, `last_successful_run_at`
- `2026_06_26_000900_create_observations_table.php` — `char(26)` PK, status enum (pending/processing/processed/failed), compound indexes

**Eloquent Models (`backend/app/Models/`)**
- `User.php` — `HasUlids`, `HasApiTokens`, `HasFactory<UserFactory>`; `memberships()` relationship
- `Company.php` — `HasUlids`, `SoftDeletes`, `HasFactory<CompanyFactory>`; auto-slugs from name; all relationships with generic type annotations
- `CompanyMembership.php` — `BelongsToCompany`, `HasUlids`; `user()`, `inviter()` relationships
- `Catalog.php` — `BelongsToCompany`, `HasUlids`; `item_schema` array cast
- `DigitalTwin.php` — `BelongsToCompany`, `HasUlids`; `isActive()`, `isInitializing()` helpers
- `Integration.php` — `BelongsToCompany`, `HasUlids`; `config` cast as `encrypted:array`; `markAsError()`; `last_successful_run_at`
- `Observation.php` — `BelongsToCompany`, `HasUlids`, `Prunable`; 180-day prune with payload nulling; `markProcessing/Processed/Failed()`

**Multi-Tenancy Foundation (`backend/app/Domain/Shared/`)**
- `Scopes/CompanyScope.php` — applies `WHERE company_id = ?` when `current_company_id` is bound in the container; no-op otherwise
- `Concerns/BelongsToCompany.php` — registers `CompanyScope`; provides `company()` `BelongsTo` relationship

**Connector Framework (`backend/app/Services/Observatory/Connectors/`)**
- `Contracts/Connector.php` — `supports(Integration)`, `sync(Integration): Collection<int, ConnectorResult>`
- `ConnectorResult.php` — readonly value object: `sourceType`, `sourceIdentifier`, `payload`, `observedAt`
- `ConnectorRegistry.php` — `resolve(Integration): Connector` (throws `UnsupportedIntegrationException`); `all(): array`
- `Exceptions/UnsupportedIntegrationException.php` — thrown when no connector supports an integration type
- `Website/WebPageData.php` — readonly value object for a single crawled page; `toArray()` serialises for payload
- `Website/WebPageCrawler.php` — BFS crawler; Guzzle HTTP + DOMDocument + DOMXPath; max 20 pages / depth 3; strips nav/footer/scripts; 5,000-char body text cap; single fetch per page (links extracted from same parse)
- `Website/WebsiteConnector.php` — implements `Connector`; crawls URL from `integration->config['url']`; maps `WebPageData → ConnectorResult`

**Observation Pipeline**
- `app/Services/Company/CompanyService.php` — `create(User, array): Company`; one DB transaction wraps Company + Catalog + DigitalTwin + owner CompanyMembership
- `app/Services/Observatory/ObservationService.php` — `record()` / `recordAll()`; persists `ConnectorResult` as `Observation`; dispatches `ObservationRecorded`
- `app/Events/ObservationRecorded.php` — fired after each Observation is persisted
- `app/Events/IntegrationSyncStarted.php` — fired when `SyncIntegration` begins
- `app/Events/IntegrationSyncCompleted.php` — fired when sync finishes; carries observation count
- `app/Jobs/SyncIntegration.php` — resolves connector via registry; syncs; records observations; updates timestamps; on `observations` queue; marks integration as error on failure
- `app/Jobs/ProcessObservation.php` — stub job on `ai` queue; no-op until Milestone 3 adds AI fact extraction
- `app/Listeners/DispatchObservationProcessing.php` — listens to `ObservationRecorded`; dispatches `ProcessObservation`

**Service Providers**
- `app/Providers/ConnectorServiceProvider.php` — registers `ConnectorRegistry` singleton with `WebsiteConnector`
- `app/Providers/AppServiceProvider.php` — wires `ObservationRecorded → DispatchObservationProcessing`
- `bootstrap/providers.php` — registers `ConnectorServiceProvider`

**Factories**
- `database/factories/CompanyFactory.php` — generates realistic company data for tests

**Feature Tests (`backend/tests/Feature/Discovery/`)**
- `CompanyServiceTest.php` — 5 tests: company creation, catalog, digital twin status, owner membership, atomicity
- `TenantIsolationTest.php` — 2 tests: CompanyScope filters by bound company; no-op when no company bound
- `ConnectorRegistryTest.php` — 3 tests: resolves WebsiteConnector; throws for unsupported type; registry is non-empty
- `WebsiteConnectorTest.php` — 2 tests: maps crawled pages to ConnectorResults; `supports()` correctly typed
- `SyncPipelineTest.php` — 2 tests: `SyncIntegration` dispatches to `observations` queue; `ProcessObservation` dispatches to `ai` queue

### Changed

- `backend/app/Models/Observation.php` — import order fixed by Pint
- `backend/app/Domain/Shared/Scopes/CompanyScope.php` — `@implements Scope<Model>` annotation added; FQCN fix by Pint

### Spec Deviation

- `Connector::sync()` declared as `sync(): Collection<int, ConnectorResult>` instead of spec's `sync(): Observation` — one result per crawled page/feed item, not one aggregate per sync. `ObservationService` is responsible for persisting each `ConnectorResult` as its own `Observation`.

---

## [Milestone 1 Hardening] — 2026-06-25

### Changed

- `backend/phpstan.neon` — raised from level 6 to **level 8**; passes with 0 errors; no code changes required
- `docs/STATUS.md` — stack table added (PHP 8.3+, Laravel 13.x, PHPStan level 8); technical debt section expanded with three named items; next tasks reordered to put ULID `User` PK conversion first; PHPStan level 8 decision recorded; project health notes clarified to distinguish placeholder models from implemented persistence
- `CHANGELOG.md` — this entry

### Technical Debt Recorded

| Item | Notes |
|------|-------|
| Eloquent model stubs are placeholders only | No migrations, fillable, casts, or relationships — exist for PHPStan type resolution only |
| Queue tests use `Queue::fake()` | Dispatch mechanism is proven; live Redis worker execution is not tested yet |
| `User` model uses integer PK | Must be converted to `char(26)` ULID before `company_memberships` migration |

---

## [Milestone 1] — Platform Foundation — 2026-06-25

### Added

**Laravel Application (`backend/`)**
- Laravel 13.17 project created in `backend/`
- PHP 8.3, Composer 2.x
- `backend/.env` — configured for PostgreSQL + Redis (queue, cache, session drivers)
- `backend/.env.example` — documented template for new environments
- `backend/pint.json` — Laravel preset with `simplified_null_return`, `blank_line_before_statement`, `new_with_parentheses`
- `backend/phpstan.neon` — Larastan at level 8; paths: `app/`

**Queue Topology (`backend/config/queue.php`)**
- Five named queue connections: `high`, `ai`, `default` (Redis), `observations`, `maintenance`
- `ai` queue has elevated `retry_after` (300s) to accommodate long AI calls
- Batching and failed job tables point to PostgreSQL (not SQLite)

**Domain Folder Structure**
- `app/Domain/Company/`
- `app/Domain/Catalog/`
- `app/Domain/BusinessBrain/`
- `app/Domain/Opportunity/`
- `app/Domain/Decision/`
- `app/Domain/Recommendation/`
- `app/Domain/Campaign/`
- `app/Domain/Shared/`
- `app/Application/`
- `app/Infrastructure/`
- `app/Presentation/`

**Core AI Contracts and Abstractions**
- `app/AI/Contracts/AiProvider.php` — single `complete(Prompt): AiResponse` method; the only interface external code touches
- `app/AI/AiResponse.php` — readonly value object: `content`, `model`, `inputTokens`, `outputTokens`
- `app/AI/Prompts/Prompt.php` — abstract base: `system()`, `user()`, `schema()`, `temperature()`, `maxTokens()`, `version()`, `name()`
- `app/AI/Testing/FakeAiProvider.php` — test double: `queueResponse()`, `queueFixture()`, `complete()`, `assertPromptSent()`, `assertNothingSent()`, `sentCount()`
- `tests/Fixtures/AI/` — directory for JSON fixtures consumed by `FakeAiProvider::queueFixture()`

**Domain Service Contracts**
- `app/Services/Analyst/Contracts/Analyst.php` — marker interface; only Analysts may call `AiProvider`
- `app/Services/Observatory/Connectors/Contracts/Connector.php` — `supports(Integration): bool`, `sync(Integration): Observation`
- `app/Services/Opportunity/Detectors/Contracts/OpportunityDetector.php` — `appliesTo(): string[]`, `detect(BusinessBrain): Collection`
- `app/Services/Content/Contracts/ContentGenerator.php` — `channel(): string`, `generate(Campaign): ContentAsset`

**Domain Value Objects**
- `app/Domain/BusinessBrain/BusinessBrain.php` — readonly value object assembled by `BusinessBrainService::for(Company)`; never persisted

**Eloquent Model Stubs** (structure only; no migrations, fillable, or relationships yet)
- `app/Models/Company.php` — with `SoftDeletes`
- `app/Models/DigitalTwin.php`
- `app/Models/Catalog.php`
- `app/Models/Integration.php`
- `app/Models/Observation.php`
- `app/Models/Campaign.php` — with `SoftDeletes`
- `app/Models/ContentAsset.php` — with `SoftDeletes`

**Bootstrap Tests (25 tests, all passing)**
- `tests/Feature/ApplicationBootTest.php` — Laravel boots, container resolves core bindings, environment is `testing`
- `tests/Feature/DatabaseConnectionTest.php` — DB connection established, migrations table exists, users table exists
- `tests/Feature/QueueDispatchTest.php` — jobs dispatched to queues, all five Atlas queues configured
- `tests/Feature/RedisConnectionTest.php` — Redis ping + set/get (skipped when Redis not in test env)
- `tests/Unit/AI/FakeAiProviderTest.php` — queueResponse, ordering, empty-queue exception, assertPromptSent, assertNothingSent, chaining
- `tests/Unit/AI/PromptTest.php` — defaults, version override, name, system/user return strings

**Infrastructure**
- `infrastructure/supervisor/atlas-worker.conf` — Supervisor config for all five queue workers

**CI/CD**
- `.github/workflows/ci.yml` — GitHub Actions: PostgreSQL 16 + Redis 7 services, Pint → PHPStan → PHPUnit on push/PR to `main`/`develop`

**Packages Installed**
- `laravel/sanctum` ^4.3 — API token authentication (used in Phase 2)
- `larastan/larastan` ^3.10 — PHPStan extension for Laravel

### Changed

- `app/Models/User.php` — untouched; uses default Laravel integer PK (will be migrated to ULID in Phase 2)

---

## [Milestone 0] — Specification Phase — 2026-06-25

All foundational specification documents written and committed. No application code.

**Documents created:**
- `specs/core/domain-model.md` — 18 entities with fields, relationships, lifecycle, Laravel notes
- `specs/product/mvp-workflow.md` — 13-step MVP workflow with acceptance criteria
- `docs/technical/Architecture.md` — module structure, event chain, queue topology
- `docs/technical/Database.md` — data classification, multi-tenancy, indexing, retention
- `docs/technical/AI.md` — provider abstraction, 6 MVP analysts, prompt versioning, FakeAiProvider pattern
- `docs/technical/DigitalTwin.md` — definition, purpose, competitive moat
- `docs/technical/DecisionEngine.md` — opportunity scoring formula, explainability, decision lifecycle
- `FOUNDING_PRINCIPLES.md` — 10 engineering principles with self-tests
- `ROADMAP.md` — 8-phase product roadmap with goals, deliverables, success criteria
- `docs/product/PRD.md` — product requirements document
- `docs/vision/FoundersBible.md` — founder vision, design partners, first use cases
- `README.md` — updated to reflect Atlas as autonomous marketing operating system
