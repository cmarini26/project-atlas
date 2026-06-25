# Engineering Status

This is the live engineering dashboard for Project Atlas. Update it after every sprint, milestone, or significant decision. It is the first document an engineer should read to understand where the project stands today.

---

## Project Health

| Dimension         | Status | Notes |
|-------------------|--------|-------|
| Specifications    | ✅ Complete | Domain model, architecture, database, AI, and MVP workflow all defined |
| Implementation    | ⬜ Not started | No application code exists yet |
| Tests             | ⬜ Not started | No test suite yet |
| CI/CD             | ⬜ Not started | No pipeline configured |
| Design partner    | 🟡 Informal | CBB Auctions engaged as design partner; formal agreement TBD |
| Infrastructure    | ⬜ Not provisioned | No staging or production environment |

**Overall:** Pre-implementation. The specification phase is complete and thorough. The project is well-positioned to begin Phase 1. No blockers.

---

## Current Milestone

**Milestone 1 — Platform Foundation**
Corresponds to [Phase 1 of ROADMAP.md](../ROADMAP.md).

Stand up a working Laravel application with multi-tenant architecture, database migrations for all core entities, queue infrastructure, and the base abstractions that every subsequent phase depends on.

**Target completion:** TBD
**Owner:** TBD

---

## Current Sprint

**Sprint 1 — Application Scaffolding**

| # | Task | Status |
|---|------|--------|
| 1 | Create Laravel application | ⬜ |
| 2 | Configure PostgreSQL + Redis | ⬜ |
| 3 | Users table + auth scaffolding (Sanctum) | ⬜ |
| 4 | Companies, catalogs, digital_twins, company_memberships tables | ⬜ |
| 5 | CompanyScope global scope on all tenant models | ⬜ |
| 6 | CompanyService::create() with DB transaction + auto-provisioning | ⬜ |
| 7 | AiProvider interface + FakeAiProvider | ⬜ |
| 8 | Base Prompt, Analyst, Connector abstract classes | ⬜ |
| 9 | Five queues configured + Supervisor stubs | ⬜ |
| 10 | CI pipeline: migrations run, tests pass | ⬜ |

---

## Completed Milestones

### Milestone 0 — Specification Phase ✅
*Completed: 2026-06-25*

All foundational documents written, reviewed, and committed. The codebase is ready to receive implementation.

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

1. **Start the application.** Create the Laravel project, configure the database, and run the first migration. The goal of Sprint 1 is a working app that can create a company.

2. **Prove the tenancy model.** `CompanyScope` must be working before any feature is built on top of it. Tenant isolation is not something to retrofit.

3. **Wire up the test foundation.** `FakeAiProvider` must be in place before any Analyst is written. The test pattern is: queue a fixture response, run the service, assert on domain objects — not on AI calls.

4. **Establish the base abstractions.** `AiProvider`, `Prompt`, `Analyst`, and `Connector` interfaces exist as contracts. Concrete implementations come in later phases; the contracts come in Sprint 1.

---

## Technical Debt

*None recorded yet — no implementation code exists.*

This section tracks shortcuts taken, deferred decisions, and known code quality issues. Update it honestly. Debt that isn't named doesn't get paid.

---

## Open Questions

| Question | Context | Priority |
|----------|---------|----------|
| Frontend: Inertia.js + Vue 3 or API-first SPA? | CLAUDE.md lists both as options. Inertia is faster to start; API-first allows a separate frontend later. Decision needed before Phase 5 UI work begins. | Medium |
| AI provider for initial development: Anthropic or OpenAI? | Both providers are spec'd. Anthropic (Claude) is preferred per architecture; OpenAI is a fallback. The `FakeAiProvider` abstracts this in tests. Pick one for production before Phase 3. | High |
| Hosting and deployment target? | No infrastructure is provisioned. Options: Laravel Forge + DigitalOcean, Laravel Vapor (serverless), bare VPS. Decision affects queue worker configuration. | High |
| CBB Auctions inventory format? | Does CBB have an RSS feed, a structured API, or HTML only? Determines whether Phase 2 uses `WebsiteCrawlConnector` or `RssFeedConnector` as the primary data source. | High |
| JavaScript-rendered inventory pages? | Some dealership and auction sites render inventory via JS. If `WebsiteCrawlConnector` uses simple HTTP, it won't see this content. May require a headless browser connector. | Medium |
| Image handling for catalog items? | `catalog_items.media` stores URLs. Are images crawled and re-hosted in Atlas's object storage, or do they link to the source? Affects Phase 5 content generation (AI needs accessible images). | Medium |

---

## Recent Decisions

| Decision | Rationale | Date |
|----------|-----------|------|
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

- Drafted and committed all 9 specification and foundation documents (Milestone 0)
- Established the canonical domain model with 18 entities, complete field definitions, and Laravel implementation notes
- Defined the full 13-step MVP workflow with per-step acceptance criteria — the implementation checklist is ready
- Wrote the engineering constitution (`FOUNDING_PRINCIPLES.md`) with 10 principles and one-line self-tests
- Published the 8-phase product roadmap with goals, deliverables, and success criteria per phase
- Documented the AI layer: provider abstraction, 6 MVP analysts, prompt versioning strategy, `FakeAiProvider` testing pattern

---

## Next Tasks

The following are the first concrete engineering tasks. They map to Sprint 1 and the Phase 1 implementation checklist in `specs/product/mvp-workflow.md`.

1. `laravel new atlas --pest` — initialize the application
2. Configure `.env` for PostgreSQL and Redis; confirm connections
3. Install `laravel/sanctum` for API authentication
4. Create migrations for: `users`, `companies`, `company_memberships`, `catalogs`, `digital_twins`
5. Create Eloquent models with `HasUlids`, correct casts, and `$fillable`
6. Implement `CompanyScope` and apply it to all tenant models
7. Write `CompanyService::create()` — wraps company + catalog + digital_twin + membership in one DB transaction
8. Create `AiProvider` interface in `app/AI/Contracts/`
9. Create `FakeAiProvider` in `app/AI/Testing/`
10. Create abstract `Prompt` base class in `app/AI/Prompts/`
11. Create `Analyst` interface in `app/Services/Analyst/Contracts/`
12. Create `Connector` interface in `app/Services/Observatory/Connectors/Contracts/`
13. Configure five queues in `config/queue.php`
14. Write a feature test: user registers → creates company → Catalog, DigitalTwin, and CompanyMembership all exist
15. Set up GitHub Actions CI: `php artisan test`, `php artisan migrate --force` on pull requests

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

**2026-06-25** — Milestone 0 complete. Entering Phase 1 (Platform Foundation). No code exists.

*Update this document at the end of every sprint and whenever a significant decision is made or risk changes.*
