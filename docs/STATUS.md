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
| Implementation    | 🟡 In progress | Laravel application scaffolded; core abstractions in place; Eloquent models are structural placeholders only |
| Tests             | 🟡 Partial | 25 bootstrap tests passing; queue tests use `Queue::fake()` (no live Redis worker execution yet); feature tests for company creation pending Milestone 2 |
| CI/CD             | 🟡 Defined | GitHub Actions workflow written; not yet triggered (no PR opened against remote) |
| Design partner    | 🟡 Informal | CBB Auctions engaged as design partner; formal agreement TBD |
| Infrastructure    | ⬜ Not provisioned | No staging or production environment |

**Overall:** Milestone 1 hardening complete. Laravel 13 / PHP 8.3 / PHPStan level 8 all green. Ready for Milestone 2 (domain migrations, models, CompanyScope, company creation flow).

---

## Current Milestone

**Milestone 2 — Domain Models & Company Creation**
Corresponds to [Phase 1 of ROADMAP.md](../ROADMAP.md) (continued).

Create the full Eloquent model layer, database migrations, CompanyScope tenancy enforcement, and CompanyService with auto-provisioning. This is the prerequisite for every domain operation.

**Target completion:** TBD
**Owner:** TBD

---

## Completed Milestones

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

1. **Convert `User` model primary key to ULID.** The Laravel default integer PK on `users` must be replaced with `char(26)` ULID before building `company_memberships` or any tenant association. If the `users` table uses an integer FK, the entire tenancy graph is wrong.

2. **Create migrations for all Phase 1 entities.** `users` (ULID PK), `companies`, `company_memberships`, `catalogs`, `digital_twins` — schemas defined in `specs/core/domain-model.md`. Run against PostgreSQL only.

3. **Implement CompanyScope.** Global scope on all tenant models. Tenancy must be correct before any feature is built on it.

4. **Implement `CompanyService::create()`.** One DB transaction wraps company creation, catalog provisioning, digital twin initialization, and owner membership assignment.

5. **Publish Sanctum.** Run `php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"` and wire up API authentication.

6. **Write the company creation feature test.** User registers → creates company → asserts Catalog, DigitalTwin, and CompanyMembership all exist, are correctly linked, and use ULID PKs.

---

## Technical Debt

| Item | Introduced | Notes |
|------|------------|-------|
| Eloquent model stubs are placeholders only | 2026-06-25 | `Company`, `DigitalTwin`, `Catalog`, `Integration`, `Observation`, `Campaign`, `ContentAsset` have no migrations, no `$fillable`, no casts, no relationships, and do not represent implemented domain persistence. They exist solely so PHPStan can resolve the types referenced in contracts. Must be fully implemented in Milestone 2. |
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

- **Milestone 1 hardening** — PHPStan raised to level 8 (0 errors); stack versions documented; technical debt items recorded; CHANGELOG updated
- **Milestone 1** — Laravel 13 / PHP 8.3 application scaffolded with full tooling chain (Pint, PHPStan, PHPUnit, GitHub Actions)
- Core domain contracts created: `AiProvider`, `Analyst`, `Connector`, `OpportunityDetector`, `ContentGenerator`
- Abstract `Prompt` base class and `AiResponse` value object
- `FakeAiProvider` with full assertion API — ready for Phase 3+ Analyst tests
- `BusinessBrain` value object in `app/Domain/BusinessBrain/`
- Domain folder structure: `app/Domain/`, `app/Application/`, `app/Infrastructure/`, `app/Presentation/`, `app/AI/`, `app/Services/`
- 25 bootstrap tests passing; Supervisor worker config for all five queues

---

## Next Tasks

1. **Convert `User` PK to ULID** — update existing migration or create a replacement; add `HasUlids` to `User` model
2. Run `php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"` — publish Sanctum config and migration
3. Create migration: `companies` — per schema in `specs/core/domain-model.md`
4. Create migration: `company_memberships`
5. Create migration: `catalogs`
6. Create migration: `digital_twins`
7. Flesh out Eloquent models: add `HasUlids`, `$fillable`, casts, relationships — aligned with domain model spec
8. Create `CompanyScope` global scope and apply to all tenant models
9. Create `CompanyService::create()` — DB transaction wrapping company + catalog + digital_twin + membership
10. Write feature test: user registers → creates company → Catalog, DigitalTwin, CompanyMembership all exist with correct ULID PKs

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

**2026-06-25** — Milestone 1 hardening complete. PHPStan raised to level 8. Stack versions documented (Laravel 13.x / PHP 8.3+). Three technical debt items recorded. Ready for Milestone 2.

*Update this document at the end of every sprint and whenever a significant decision is made or risk changes.*
