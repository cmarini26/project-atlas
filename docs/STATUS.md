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

**Overall:** Milestone 2 complete. Discovery & Knowledge Platform shipped: multi-tenancy, BFS website crawler, connector framework, observation pipeline, and 40 passing tests. Ready for Milestone 3 (Fact Extraction & Knowledge Synthesis).

---

## Current Milestone

**Milestone 3 — Fact Extraction & Knowledge Synthesis**
Corresponds to [Phase 1 of ROADMAP.md](../ROADMAP.md) (continued).

Process raw Observations into structured Facts using AI analysts. Synthesise Facts into a live BusinessBrain for each Company.

**Target completion:** TBD
**Owner:** TBD

---

## Completed Milestones

### Milestone 2 — Discovery & Knowledge Platform ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| ULID PKs throughout | All domain tables use `char(26)` ULID PKs; users, personal_access_tokens patched for compatibility |
| Multi-tenancy foundation | `CompanyScope` global scope; `BelongsToCompany` trait; scoping is no-op when no company bound (safe in CLI/tests) |
| Domain migrations | `companies`, `company_memberships`, `catalogs`, `digital_twins`, `integrations`, `observations` — all with ULID PKs and FKs |
| Eloquent models | Full implementations: `Company`, `CompanyMembership`, `Catalog`, `DigitalTwin`, `Integration`, `Observation` — with fillable, casts, relationships, and `HasUlids` |
| `CompanyService` | Single DB transaction creates Company + Catalog (inventory) + DigitalTwin (initializing) + owner CompanyMembership |
| Connector framework | `Connector` interface, `ConnectorRegistry`, `ConnectorResult` value object, `UnsupportedIntegrationException` |
| `WebPageCrawler` | BFS crawler using Guzzle + DOMDocument; max 20 pages / depth 3; strips nav/footer/scripts; 5,000-char body cap |
| `WebsiteConnector` | Maps crawled `WebPageData` → `ConnectorResult`; supports `website_crawl` integration type |
| `ConnectorServiceProvider` | Registers `WebsiteConnector` in `ConnectorRegistry` as a singleton |
| Observation pipeline | `ObservationService`, `SyncIntegration` job, `ProcessObservation` stub job, `ObservationRecorded` event, `DispatchObservationProcessing` listener |
| Event wiring | `ObservationRecorded → DispatchObservationProcessing` registered in `AppServiceProvider` |
| Feature tests | 15 new tests: company creation, tenant isolation, connector registry, observation service, queue dispatch — 40 total, all passing |
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

1. **Implement Fact extraction.** `ProcessObservation` job is currently a stub. Wire it to an AI Analyst that extracts structured Facts from raw observation payloads.

2. **Create `Fact` model and migration.** Facts are the atomic unit of Knowledge — extracted from Observations and stored as typed key-value pairs with source attribution.

3. **Implement `BusinessBrainService::for(Company)`.** Assemble a live `BusinessBrain` value object from a company's current Facts.

4. **Write Analyst for website observations.** A `WebsiteAnalyst` that reads `WebPageData` payloads and extracts business facts (name, products, services, USPs, contact info, geography).

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

- **Milestone 2** — Discovery & Knowledge Platform: multi-tenancy, BFS website crawler, connector framework, observation pipeline; 40 tests passing; PHPStan level 8 clean
- **Milestone 1 hardening** — PHPStan raised to level 8 (0 errors); stack versions documented; technical debt items recorded; CHANGELOG updated
- **Milestone 1** — Laravel 13 / PHP 8.3 application scaffolded with full tooling chain (Pint, PHPStan, PHPUnit, GitHub Actions)
- Core domain contracts: `AiProvider`, `Analyst`, `Connector`, `OpportunityDetector`, `ContentGenerator`
- Abstract `Prompt`, `AiResponse`, `FakeAiProvider`, `BusinessBrain` value object
- 25 bootstrap tests → 40 feature tests; Supervisor config for all five queues

---

## Next Tasks

1. Implement `Fact` model and migration — source_type, source_id, key, value, confidence, extracted_at
2. Wire `ProcessObservation` job to `WebsiteAnalyst` — extract Facts from crawled page payloads
3. Implement `WebsiteAnalyst` — uses AI to extract business facts from `WebPageData` body text
4. Implement `BusinessBrainService::for(Company)` — assemble live `BusinessBrain` from current Facts
5. Write tests: fact creation, analyst dispatch, BusinessBrain assembly

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

**2026-06-26** — Milestone 2 complete. Discovery & Knowledge Platform shipped. 40 tests passing, PHPStan level 8 clean, Pint clean. Ready for Milestone 3 (Fact Extraction & Knowledge Synthesis).

*Update this document at the end of every sprint and whenever a significant decision is made or risk changes.*
