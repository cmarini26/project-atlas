# Changelog

All notable changes to Project Atlas are documented here. Entries are organized by milestone, then by commit.

Format: each entry identifies what changed, which files/paths are affected, and why the change was made.

---

## [Milestone 1] — Platform Foundation — 2026-06-25

### Added

**Laravel Application (`backend/`)**
- Laravel 13.17 project created in `backend/`
- PHP 8.3, Composer 2.x
- `backend/.env` — configured for PostgreSQL + Redis (queue, cache, session drivers)
- `backend/.env.example` — documented template for new environments
- `backend/pint.json` — Laravel preset with `simplified_null_return`, `blank_line_before_statement`, `new_with_parentheses`
- `backend/phpstan.neon` — Larastan at level 6; paths: `app/`

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
