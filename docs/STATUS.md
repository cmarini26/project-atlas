# Engineering Status

This is the live engineering dashboard for Project Atlas. Update it after every sprint, milestone, or significant decision. It is the first document an engineer should read to understand where the project stands today.

---

## Stack

| Component | Version |
|-----------|---------|
| PHP | 8.3+ |
| Laravel | 13.x |
| PHPStan / Larastan | level 8 (0 errors) |
| Laravel Pint | Laravel preset |
| PostgreSQL | 16 (CI); local install for dev |
| Redis | 7 (CI); local install for dev |

---

## Project Health

| Dimension         | Status | Notes |
|-------------------|--------|-------|
| Specifications    | ✅ Complete | Domain model, architecture, database, AI, and MVP workflow all defined |
| Implementation    | 🟡 In progress | Milestone 2 complete: multi-tenancy, connector framework, website crawler, observation pipeline all in place |
| Tests             | 🟡 Partial | 40 tests passing (2 Redis skipped); discovery feature tests cover company creation, tenant isolation, connector registry, observation service, and queue dispatch |
| CI/CD             | 🟡 Defined | GitHub Actions workflow written; not yet triggered (no PR opened against remote) |
| Design partner    | 🟡 Informal | CBB Auctions engaged as design partner; formal agreement TBD |
| Infrastructure    | ⬜ Not provisioned | No staging or production environment |

**Overall:** Milestone 4 complete. Opportunity & Decision Engine fully implemented and tested. 127 tests passing (2 Redis skipped). PHPStan level 8 clean. Full pipeline: `BusinessBrain → Opportunity Detection → Scoring → Deduplication → Decision Selection → Rationale Generation → DecisionCommitted Event`.

---

## Current Milestone

**Milestone 5 — Campaign Preparation & Content Generation**
Corresponds to [Phase 5 of ROADMAP.md](../ROADMAP.md).

After a Decision is committed, prepare a Campaign: generate a Campaign Blueprint, produce ContentAssets per channel, create a Recommendation for user approval.

**Status:** Campaign Blueprint spec complete (`specs/core/campaign-blueprint.md`). Implementation ready to begin.  
**Owner:** TBD

---

## Completed Milestones

### Milestone 4 — Opportunity & Decision Engine ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| 6 new migrations | `catalog_items`, `channels`, `opportunities`, `decisions`, `campaigns`, `recommendations` |
| `CatalogItem` model | `BelongsToCompany`, `HasUlids`, `SoftDeletes`, datetime casts; `isActive()` |
| `Channel` model | `HasUlids` only; nullable `company_id` (system channels) |
| `Campaign` model | Full implementation; `campaign_type`, `completed_at` for Guard 3 |
| `Recommendation` model | Minimal; `campaign_type` for Guard 2 |
| `Opportunity` model | Full: polymorphic subject, score fields, lifecycle methods |
| `Decision` model | Full: JSON casts for `channel_ids`, `rationale`, `expected_impact` |
| `OpportunityCandidate` VO | Readonly; all 4 score fields + `aiDetected` |
| `OpportunityScorer` | Composite formula; min-30 threshold; AI confidence cap at 75 |
| 4 rule-based detectors | `FeaturedItemDetector`, `UrgencyDetector`, `NewArrivalDetector`, `ReEngagementDetector` |
| `OpportunityDetectionAnalyst` | AI-assisted supplemental detection; never bypasses scoring/deduplication |
| `OpportunityEngine::scan()` | Orchestrates detectors → AI → dedup → score → persist → fire events |
| `DecisionContext` VO | Immutable: `Opportunity`, `BusinessBrain`, `campaignType`, `channelIds` |
| `DecisionEngine::evaluate()` | 5 guard conditions; score-ordered selection; channel affinity resolution |
| `DecisionService::commit()` | Validates 5 rationale keys + 4 `expected_impact` sub-keys; persists; fires event |
| `RationaleGenerationAnalyst` | AI rationale generation; temperature 0.4; versioned prompt |
| `RationaleGenerationFailedException` | Hard failure when rationale is incomplete |
| 4 jobs | `DetectOpportunities` (default), `CommitDecision` (ai, `ShouldBeUnique`), `ExpireOpportunities` (maintenance), `PrepareCampaign` stub (ai) |
| 2 events | `OpportunityDetected`, `DecisionCommitted` |
| 3 listeners | `TriggerOpportunityDetection`, `TriggerDecisionEvaluation`, `DispatchCampaignPreparation` |
| Morph map | `catalog_item`, `catalog`, `company` registered in `AppServiceProvider` |
| `BusinessBrainService` | `featuredItems` and `recentCampaigns` now populated from DB |
| 2 AI fixtures | `opportunity-detection.json`, `rationale-generation.json` |
| 44 new tests | All M4 components tested with `FakeAiProvider`; no live AI; 127 total passing |
| PHPStan level 8 | 0 errors |

### Milestone 3 — Fact Extraction & Knowledge Synthesis ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| `Fact` model + migration | `facts` table; ULID PK; `is_current` versioning; `(company_id, key, is_current)` index |
| `Knowledge` model + migration | `knowledge_entries` table; `active()` scope with `expires_at` handling |
| `FactData` value object | Readonly VO: key, value, dataType, confidence — decouples analyst from Eloquent |
| `FactRepository` + `KnowledgeRepository` | Encapsulated Eloquent queries with `withoutGlobalScopes()` |
| `FactExtractionPrompt` | Versioned prompt (v1.0); structured JSON schema; temperature 0.1 |
| `StructuredResponseParser` | Parses AI JSON; strips markdown fences; throws on invalid response |
| `WebsiteAnalyst` | Implements `Analyst`; calls `AiProvider`; returns `Collection<FactData>`; short-circuits on empty payload |
| `FactService` | `storeExtracted()`: persists Facts; supersedes existing current facts; fires `FactExtracted` |
| `KnowledgeService` | `synthesizeForCompany()`: groups facts by domain; upserts Knowledge; activates DigitalTwin; fires events |
| `BusinessBrainService` | `for(Company): BusinessBrain`; assembles from current Facts, active Knowledge, recent Observations |
| Real `ProcessObservation` | Full pipeline: analyze → store facts → synthesize knowledge → mark processed; marks failed on error |
| 4 domain events | `FactExtracted`, `KnowledgeSynthesized`, `ObservationProcessed`, `DigitalTwinActivated` |
| Company model | Added `facts()` and `knowledge()` `hasMany` relationships |
| `AiProvider` binding | Bound to `FakeAiProvider` in `testing` environment |
| AI fixture | `tests/Fixtures/AI/website-facts.json` |
| 35 new tests | 7 test classes covering all new services, AI layer, and end-to-end pipeline — 83 total (81 passing) |
| PHPStan level 8 | 0 errors |

### Milestone 2 — Discovery & Knowledge Platform ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| ULID PKs throughout | All domain tables use `char(26)` ULID PKs; users, personal_access_tokens patched for compatibility |
| Multi-tenancy foundation | `CompanyScope` global scope; `BelongsToCompany` trait; scoping is no-op when no company bound (safe in CLI/tests) |
| Domain migrations | `companies`, `company_memberships`, `catalogs`, `digital_twins`, `integrations`, `observations` — all with ULID PKs and FKs |
| Eloquent models | Full implementations: `Company`, `CompanyMembership`, `Catalog`, `DigitalTwin`, `Integration`, `Observation` — with fillable, casts, relationships, and `HasUlids` |
| `CompanyService` | Single DB transaction creates Company + Catalog (type: `mixed`) + DigitalTwin (initializing) + owner CompanyMembership |
| Connector framework | `Connector` interface, `ConnectorRegistry`, `ConnectorResult` value object, `UnsupportedIntegrationException` |
| `WebPageCrawler` | BFS crawler using Guzzle + DOMDocument; max 20 pages / depth 3; strips nav/footer/scripts; 5,000-char body cap |
| `WebsiteConnector` | Maps crawled `WebPageData` → `ConnectorResult`; supports `website_crawl` integration type |
| `ConnectorServiceProvider` | Registers `WebsiteConnector` in `ConnectorRegistry` as a singleton |
| Observation pipeline | `ObservationService`, `SyncIntegration` job, `ProcessObservation` stub job, `ObservationRecorded` event, `DispatchObservationProcessing` listener |
| Event wiring | `ObservationRecorded → DispatchObservationProcessing` registered in `AppServiceProvider` |
| `IntegrationService` | `create(Company, type, config)` — provisions Integration, sets `name`, `status: active`, `next_run_at: +7 days`, dispatches `SyncIntegration` immediately |
| `SyncIntegration` uniqueness | Implements `ShouldBeUnique`; `uniqueId()` keyed on `integration->id` — prevents duplicate syncs in queue |
| Feature tests | 20 new tests: company creation, tenant isolation, connector registry, observation service, queue dispatch, integration service — 48 total, 46 passing (2 Redis skipped) |
| PHPStan level 8 | 0 errors; full generic annotations on all Eloquent relationships |

### Milestone 1 — Platform Foundation ✅
*Completed: 2026-06-25 | Hardened: 2026-06-25*

**Delivered:**

| Item | Description |
|------|-------------|
| Laravel 13.x / PHP 8.3 application | Installed in `backend/`; PostgreSQL + Redis configured; app boots cleanly |
| `.env` configuration | PostgreSQL, Redis, mail (log driver), storage (local + S3 stubs) |
| Queue topology | Five named queues in `config/queue.php`: `high`, `ai`, `default`, `observations`, `maintenance` |
| Supervisor stubs | `infrastructure/supervisor/atlas-worker.conf` — one worker group per queue |
| Laravel Pint | `pint.json` with Laravel preset; all files passing |
| PHPStan / Larastan | `phpstan.neon` at **level 8**; 0 errors |
| GitHub Actions CI | `.github/workflows/ci.yml` — Pint + PHPStan + PHPUnit on push/PR to `main`/`develop` |
| Domain folder structure | `app/Domain/{Company,Catalog,BusinessBrain,Opportunity,Decision,Recommendation,Campaign,Shared}/`, `app/Application/`, `app/Infrastructure/`, `app/Presentation/` |
| Core contracts | `AiProvider`, `Analyst`, `Connector`, `OpportunityDetector`, `ContentGenerator` interfaces |
| Abstract base classes | `Prompt` with `system()`, `user()`, `schema()`, `temperature()`, `maxTokens()`, `version()`, `name()` |
| Value objects | `AiResponse` readonly class; `BusinessBrain` readonly value object |
| FakeAiProvider | `queueResponse()`, `queueFixture()`, `assertPromptSent()`, `assertNothingSent()` |
| Eloquent model stubs | 7 structural placeholders for entities referenced by contracts — **not yet implemented domain persistence** |
| Bootstrap tests | 25 tests: Laravel boots, DB connection, queue dispatch, AI contracts, Prompt — all passing |
| Sanctum installed | Authentication package ready for Milestone 2 scaffolding |

### Milestone 0 — Specification Phase ✅
*Completed: 2026-06-25*

All foundational documents written, reviewed, and committed.

**Delivered:**

| Document | Description |
|----------|-------------|
| `specs/core/domain-model.md` | 18 entities — fields, relationships, lifecycle states, Laravel notes |
| `docs/technical/Architecture.md` | Module structure, layered architecture, event chain, queue topology, Connector and Analyst patterns |
| `docs/technical/Database.md` | Data classification, multi-tenancy strategy, indexing, retention, backup |
| `docs/technical/AI.md` | Provider abstraction, 6 MVP analysts, prompt design, testing strategy |
| `docs/technical/DigitalTwin.md` | Definition, purpose, core objects, competitive moat |
| `docs/technical/DecisionEngine.md` | Opportunity scoring, explainability, decision lifecycle |
| `specs/product/mvp-workflow.md` | 13-step MVP workflow with acceptance criteria and implementation checklist |
| `FOUNDING_PRINCIPLES.md` | 10 engineering principles with self-tests |
| `ROADMAP.md` | 8-phase product roadmap |
| `docs/product/PRD.md` | Updated with Digital Twin lifecycle and decision lifecycle |
| `docs/vision/FoundersBible.md` | Updated with CBB Auctions as primary design partner |

---

## Current Objectives

1. **Bind real `AiProvider` for production.** `AppServiceProvider` currently binds `FakeAiProvider` in testing only. A real `AnthropicProvider` (or `OpenAiProvider`) must be implemented and bound before `ProcessObservation` can run in production.

2. **Implement `Opportunity` model and migration.** Spec defined in `domain-model.md`. Polymorphic `subject` (CatalogItem, Catalog, Company).

3. **Implement `OpportunityDetector` contract.** Rule-based detectors run first; AI analyst supplements for non-obvious opportunities.

4. **Implement `Decision` model and migration.** One Decision per Opportunity; required `rationale` JSON (`why_now`, `why_this`, `why_channel`, `why_works`); enforced in `DecisionService`.

---

## Technical Debt

| Item | Introduced | Notes |
|------|------------|-------|
| `Campaign` and `ContentAsset` are still stubs | 2026-06-26 | These models exist solely for PHPStan to resolve types in contracts. They have no migrations and no implemented domain persistence. Required for Milestone 4+. |
| Queue tests use `Queue::fake()` — no live worker execution | 2026-06-25 | `QueueDispatchTest` proves the dispatch mechanism and queue configuration, but does not prove that a real Redis worker picks up and executes a job. Add an integration test or smoke test for real Redis queue processing before Phase 2 is considered complete. |
| Queue tests use `Queue::fake` only | 2026-06-25 | Current queue tests verify dispatch/configuration but do not prove Redis worker execution. Add an integration test or smoke test for real Redis queue processing before Phase 2 is considered complete. |
| `User` model uses integer PK | 2026-06-25 | Laravel default. Must be migrated to `char(26)` ULID before `company_memberships` (or any table with a `user_id` FK) is created. First task in Milestone 2. |

---

## Open Questions

| Question | Context | Priority |
|----------|---------|----------|
| Frontend: Inertia.js + Vue 3 or API-first SPA? | CLAUDE.md lists both as options. Inertia is faster to start; API-first allows a separate frontend later. Decision needed before Phase 5 UI work begins. | Medium |
| AI provider for initial development: Anthropic or OpenAI? | Both providers are spec'd. Anthropic (Claude) is preferred per architecture; OpenAI is a fallback. The `FakeAiProvider` abstracts this in tests. Pick one for production before Phase 3. | High |
| Hosting and deployment target? | No infrastructure is provisioned. Options: Laravel Forge + DigitalOcean, Laravel Vapor (serverless), bare VPS. Decision affects queue worker configuration. | High |
| CBB Auctions inventory format? | Does CBB have an RSS feed, a structured API, or HTML only? Determines whether Phase 2 uses `WebsiteCrawlConnector` or `RssFeedConnector` as the primary data source. | High |
| JavaScript-rendered inventory pages? | Some dealership and auction sites render inventory via JS. If `WebsiteCrawlConnector` uses simple HTTP, it won't see this content. May require a headless browser connector. | Medium |
| Image handling for catalog items? | `catalog_items.media` stores URLs. Are images crawled and re-hosted in Atlas's object storage, or do they link to the source? Affects Phase 5 content generation. | Medium |

---

## Recent Decisions

| Decision | Rationale | Date |
|----------|-----------|------|
| PHPStan raised to level 8 | Level 8 passed with 0 errors on current codebase; no reason to defer — stricter analysis catches more issues earlier | 2026-06-25 |
| Laravel 13.x chosen | Current stable release; PHP 8.3+; compatible with Larastan 3.x and PHPStan level 8 | 2026-06-25 |
| Sanctum over Passport for auth | Sanctum is lighter and sufficient for token-based API auth; Passport adds OAuth complexity not needed in MVP | 2026-06-25 |
| Stub models for interface type safety | Interfaces reference Eloquent models that don't yet have migrations; stubs allow PHPStan to pass without deferring type checking | 2026-06-25 |
| PostgreSQL over MySQL | Required for `pgvector` (future embeddings) and Row-Level Security as defense-in-depth | 2026-06-25 |
| ULIDs over UUIDs | Sortable, URL-safe, reduces B-tree index fragmentation vs. random UUIDs | 2026-06-25 |
| Business Brain is a value object, not a DB row | It's a query projection assembled on demand — persisting it would create a stale cache problem | 2026-06-25 |
| Opportunity detection is hybrid | Rule-based detectors (fast, deterministic) run first; AI analyst supplements for non-obvious opportunities | 2026-06-25 |
| Anthropic uses tool-use for structured output | Anthropic has no JSON mode; tool-use with `tool_choice: forced` achieves equivalent structured output | 2026-06-25 |
| Shared schema multi-tenancy | Schema-per-tenant is operationally expensive at this scale; shared schema + `CompanyScope` + RLS is sufficient | 2026-06-25 |
| `char(26)` for ULID columns | ULIDs are always exactly 26 chars; `char` avoids variable-length overhead and preserves lexicographic sort | 2026-06-25 |
| CBB Auctions as primary design partner | Comic book auctions and exotic cars share the dynamic-inventory pattern. CBB is more willing to engage early. | 2026-06-25 |

---

## Recently Completed

- **Milestone 5 — Campaign Blueprint spec** — `specs/core/campaign-blueprint.md` written; covers Blueprint definition, relationship to Decision, all 10 required fields with validation rules, versioning and immutability, `CampaignPreparationAnalyst` AI contract, `BlueprintGenerationFailedException`, full Blueprint→Asset→Renderer pipeline, `ChannelRenderer` interface contract, acceptance criteria, and future extensibility
- **Milestone 4 — Decision Engine spec** — `specs/core/decision-engine.md` written; covers Decision definition, lifecycle, statuses, types, inputs, all five guard conditions, selection algorithm, required rationale fields, `RationaleGenerationAnalyst` contract, Campaign pipeline handoff (M5), M4 implementation list, explicit out-of-scope list, acceptance criteria, and extensibility
- **Milestone 4 — Opportunity Engine spec** — `specs/core/opportunity-engine.md` written and CTO approved; covers Opportunity lifecycle, types, scoring formula, evidence chains, expiration, deduplication, `OpportunityDetector` interface, rule-based vs. AI-assisted detectors, implementation scope
- **Milestone 3 + cleanup** — Fact extraction, knowledge synthesis, BusinessBrain assembly; `Observation.facts()` + `last_enriched_at` fix; 83 tests (81 passing); PHPStan level 8 clean
- **Milestone 2 + cleanup** — `IntegrationService::create()`, `SyncIntegration` uniqueness guard, catalog type fix; 48 tests (46 passing); PHPStan level 8 clean
- **Milestone 1 hardening** — PHPStan raised to level 8 (0 errors); stack versions documented; technical debt items recorded; CHANGELOG updated
- **Milestone 1** — Laravel 13 / PHP 8.3 application scaffolded with full tooling chain (Pint, PHPStan, PHPUnit, GitHub Actions)
- Core domain contracts: `AiProvider`, `Analyst`, `Connector`, `OpportunityDetector`, `ContentGenerator`
- Abstract `Prompt`, `AiResponse`, `FakeAiProvider`, `BusinessBrain` value object
- 25 bootstrap tests → 40 feature tests; Supervisor config for all five queues

---

## Next Tasks (Milestone 5)

Spec: `specs/core/campaign-blueprint.md` — authoritative; read before implementing any M5 component.

1. **`AnthropicProvider`** — implement real `AiProvider`; required before any production AI calls; bind in `AppServiceProvider` for non-test environments
2. **Campaign Blueprint migrations** — add `blueprint` JSON column, `blueprint_version`, `prompt_version`, `expected_asset_count`, `generated_asset_count`, `campaign_type` to `campaigns` table; add `content_assets` table
3. **`CampaignBlueprint` value object** — readonly PHP class with all 10 Blueprint fields
4. **`CampaignPreparationPrompt`** — version `1.0`, temperature `0.5`; structured JSON schema; per the Blueprint spec AI generation contract
5. **`CampaignPreparationAnalyst`** — implements `Analyst`; generates Blueprint from Decision + BusinessBrain; marks all AI-generated
6. **`CampaignPreparationService`** — validates Blueprint against all 10 field rules; throws `BlueprintGenerationFailedException` on failure; persists Blueprint on Campaign; dispatches `GenerateContent` per channel
7. **`GenerateContent` job** — `ai` queue; receives Campaign + Channel; calls `ContentGenerationAnalyst`; creates `ContentAsset`
8. **`ContentGenerationAnalyst`** — channel-aware; uses Blueprint + channel strategy entry; dispatches channel-specific prompt variant
9. **`ContentAsset` model + migration** — full implementation with `body`, `title`, `media`, `metadata` (per-channel schema), `status`
10. **`RecommendationService::create()`** — assembles `Recommendation` from Decision + Blueprint + ContentAssets; fires `RecommendationCreated`; sends in-app notification
11. **Approval UI** — Vue 3 + Inertia.js (or API-first); Recommendation card with rationale display, content preview per channel, approve/edit/reject controls
12. **`CampaignAssetsReady` event** — fired when `generated_asset_count == expected_asset_count`; triggers `CreateRecommendation`

---

## Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Auction/dealer sites render inventory via JavaScript, blocking simple HTTP crawl | High | High | Spike a headless browser connector (Puppeteer or Playwright via Node sidecar) early in Phase 2 |
| AI provider rate limits during parallel processing (crawl → extract → synthesize) | Medium | Medium | All AI jobs run on dedicated `ai` queue; implement per-provider rate limiting in `AnthropicProvider` |
| Frontend framework decision delayed, blocking Phase 5 UI | Medium | Medium | Decide Inertia vs. API-first before Phase 3 ends; Phase 5 UI work cannot start without it |
| CBB Auctions engagement becomes informal, reducing design partner feedback | Low | Medium | Formalize the design partner relationship; schedule regular demos starting Phase 3 |
| Scope creep into CRM, billing, or ads integrations before core loop is proven | Low | High | ROADMAP.md exclusions list is authoritative; defer any out-of-scope request explicitly |

---

## Last Updated

**2026-06-26** — Milestone 5 Campaign Blueprint spec complete. `specs/core/campaign-blueprint.md` is the authoritative implementation spec for the full Campaign Preparation pipeline: Blueprint generation, ContentAsset creation per channel, `ChannelRenderer` interface contract, and Recommendation assembly. Milestone 5 implementation ready to begin.

*Update this document at the end of every sprint and whenever a significant decision is made or risk changes.*
