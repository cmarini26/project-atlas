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
| Specifications    | ✅ Complete | Domain model, architecture, database, AI, MVP workflow, analytics engine, and learning engine all defined. **New:** `specs/core/marketing-presence.md` — Milestone 11 domain spec, approved, not yet implemented. |
| Implementation    | ✅ Customer dashboard complete | All 10 milestones delivered. Full customer-facing Vue 3 + Inertia.js dashboard live. Milestone 11 (Marketing Presence) is spec'd and planned; implementation has not started. |
| Tests             | ✅ Strong | 667 tests (665 passing, 2 Redis skipped) + 13 Vitest tests; PHPStan level 8 — 0 errors; Pint clean. Unchanged by this update — spec/plan only, no code. |
| CI/CD             | 🟡 Active | GitHub Actions running on push to main; `pdo_sqlite` extension fix applied — awaiting confirmation CI is green |
| Design partner    | 🟡 Informal | CBB Auctions engaged as design partner; formal agreement TBD |
| Infrastructure    | ⬜ Not provisioned | No staging or production environment |

**Overall:** Milestone 10 complete + onboarding pipeline fixed (Phase 1–9) + P0 product-polish tier shipped + P1 Customer Trust & Navigation slice shipped (approval confirmation dialog, safety-tested company switcher, persistent layout + `Link` sweep, toast primitive — see [P1-Customer-Trust-Navigation-Review.md](reviews/P1-Customer-Trust-Navigation-Review.md)) + Channel Publishing Reality audit complete (see [Channel-Publishing-Reality-Audit.md](reviews/Channel-Publishing-Reality-Audit.md)) + **Milestone 11 (Marketing Presence) specified and planned, not yet implemented** (see [marketing-presence.md](../specs/core/marketing-presence.md) and [Milestone-11-Marketing-Presence.md](plans/Milestone-11-Marketing-Presence.md)). Marketing Presence introduces a `MarketingChannel` domain entity so Atlas can know where a business markets today (Instagram, print, events, and everything else the business owner would name) as business context, independent of whether Atlas can technically publish to or measure that channel — directly closing the gap the Channel Publishing Reality Audit identified, without claiming any new publishing capability exists. The spec defines the entity, lifecycle (Declared → Connected → Publishing enabled → Analytics enabled), and every integration point (Business Brain, Opportunity/Decision Engine channel selection, Campaign Blueprint, the existing capability-badge system); the plan sequences it into 8 phases (domain model → service layer → onboarding → Settings UI → Business Brain → Opportunity Engine → Recommendation UI → tests). No code has been written for this milestone. Remaining P1 items (email notifications, Sentry, AI usage persistence, icon/Button/FormField primitives, a first real channel publisher) and all P2 items are tracked in [Product-Polish-Audit.md](reviews/Product-Polish-Audit.md). 667 tests (665 passing, 2 Redis skipped) + 13 Vitest tests. PHPStan level 8 — 0 errors. Pint clean.

---

## Current Milestone

**Private Beta Readiness Audit ✅ Complete**
*Completed: 2026-06-27*

CTO-style operational audit across 40 areas. Beta Readiness Score: 31/100. Go/No-Go: NO-GO. 7 critical blockers identified. Full 4-week remediation sprint plan written.

See:
- [Beta-Readiness-Audit.md](reviews/Beta-Readiness-Audit.md) — 40-area audit with severity, effort, and blocks-beta assessment for every finding
- [Private-Beta-Plan.md](plans/Private-Beta-Plan.md) — week-by-week sprint plan to safely onboard first 10 paying customers

**Critical blockers (must resolve before any paying customer is onboarded):**
1. `ResolveCurrentCompany` middleware not verified / may not exist — multi-tenancy enforcement gap
2. No production server provisioned
3. Email delivery uses log driver only (no Postmark, no Mailgun)
4. No monitoring or alerting (only health endpoints exist)
5. No database backups configured
6. No domain configured (APP_URL is localhost)
7. No privacy policy or terms of service

**What is working well:**
- Full AI pipeline operational (Anthropic + SSRF protection)
- Domain model correct and well-tested (579/581 tests, PHPStan level 8)
- Customer dashboard complete (all 16 pages, Tier 1 & 2 polish done)
- Learning Engine implemented
- Filament admin panel with superadmin gate
- End-to-end smoke test passing

**Beta Readiness Score: 31 / 100** (strong foundation, infrastructure entirely absent)

**Previous milestone:**

**Landing Page Design & Content Specification ✅ Complete**
*Completed: 2026-06-27*

Full landing page spec written for the Atlas marketing site. 24 sections covering hero through footer, mobile layout, animation, accessibility, CTA strategy, and copy principles. No code written — this is a design and content specification document.

See:
- [Landing-Page.md](marketing/Landing-Page.md) — complete landing page specification

**Deliverables:**
- Strategic foundation and four core messages
- 16 content sections with full copy direction and layout guidance
- Recommendation showcase mockup with specific, plausible CBB Auctions content
- Industry cards for comic book auction houses and exotic car dealers
- Mobile layout specification for every section
- Animation recommendations with explicit timing and easing values
- WCAG 2.1 AA accessibility requirements throughout
- CTA strategy with placement logic and A/B test variants
- Copy principles: what Atlas avoids and what it sounds like

**Previous milestone:**

**Version 0.2 Polish — Tier 1 & 2 ✅ Complete**
*Completed: 2026-06-27*

All Tier 1 (trust blockers) and Tier 2 (clarity gaps) items from `docs/plans/Version-0.2-Polish.md` implemented. 17 frontend issues resolved across 16 files. All four quality gates pass.

See:
- [Version-0.2-Polish-Tier-1-2-Review.md](reviews/Version-0.2-Polish-Tier-1-2-Review.md) — implementation notes and decisions

**Tier 1 — Trust blockers (all resolved):**
- T1-1: HealthCard + Brain.vue status labels fixed — `active` → "Active" in emerald, not raw gray
- T1-2: Onboarding redirects to first recommendation; 5-min timeout message; polling at 5s intervals
- T1-3: All enum badge values translated — opportunity types, campaign statuses, execution statuses, learning signals, source types
- T1-4: Analytics metric keys translated with human-readable labels and titleCase fallback

**Tier 2 — Clarity gaps (all resolved):**
- T2-1: "Edit & Approve" secondary button added; emits event to open ContentEditor
- T2-2: Explanatory copy added below approval buttons
- T2-3 + T2-4: ScoreBar rewritten — value-based color scale + ARIA progressbar roles
- T2-5: Opportunity expiry shows time remaining with amber (<48h) / rose (<24h) urgency coloring
- T2-6: `<Head>` title tags added to all 16 app pages (title formatter wired in app.ts)
- T2-7: Mobile padding fixed — `px-8` → `px-4 lg:px-8` throughout AppLayout
- T2-8: Already done (Inertia progress bar was wired in app.ts)
- T2-9: Inline error messages added to approval buttons via `onError` callbacks
- T2-10: Form label typography — `text-xs uppercase tracking-widest text-muted` on all form pages
- T2-11: Health score (0–100) + "Healthy"/"Building"/"Learning" label added to HealthCard
- T2-12: Nav label "Brain" → "Business Brain"
- T2-13: Rationale body text → `text-base leading-relaxed`
- T2-14: Onboarding timeout message shown after 5 min with suggestions

**Quality gates:**

| Gate | Result |
|------|--------|
| PHPUnit (581 tests) | 579 passing, 2 Redis skipped |
| PHPStan level 8 | 0 errors |
| Laravel Pint | Clean |
| Frontend build | 129 modules, 0 errors |

**Previous milestone:**

**Product Validation Sprint ✅ Complete**
*Completed: 2026-06-27*

Full customer experience review. 24 issues across 20 review areas. See [Product-Validation-Review.md](reviews/Product-Validation-Review.md) and [Version-0.2-Polish.md](plans/Version-0.2-Polish.md).

**Previous milestone:**

**Version 0.2 Planning ✅ Complete**
*Completed: 2026-06-27*

9-milestone roadmap written covering all production-readiness and real-provider work. See [Version-0.2-Roadmap.md](plans/Version-0.2-Roadmap.md) for full details.

**Planned milestones:**

| Milestone | Goal | Status |
|-----------|------|--------|
| M11 — Production Infrastructure | Forge + DigitalOcean, PostgreSQL RLS, zero-downtime deploys | ⬜ |
| M12 — Error Reporting | Flare or Sentry; job failure alerts; exception triage runbook | ⬜ |
| M13 — Telemetry & Monitoring | Laravel Pulse; uptime monitoring; scheduled job heartbeats | ⬜ |
| M14 — Demo Environment | Seeded `mountain-city-comics`; nightly reset; read-only guard | ⬜ |
| M15 — Onboarding Improvements | Email verification; progress persistence; welcome email; error recovery | ⬜ |
| M16 — Real Email Publishing | `PostmarkEmailProvider`; channel credential UI; sandbox mode | ⬜ |
| M17 — Real Social Publishing | Meta OAuth; `MetaPublisher`; image upload; content policy handling | ⬜ |
| M18 — Real Analytics Integrations | `MetaAnalyticsProvider`; Postmark pull; real learning signals | ⬜ |
| M19 — Customer Feedback Tooling | In-app NPS; `Feedback` model; weekly digest; Filament review panel | ⬜ |

**Previous milestone:**

**Milestone 10 — Customer Dashboard & UX ✅ Complete**
*Completed: 2026-06-28*

Full customer-facing dashboard built across 10 phases. See [Milestone-10-Review.md](reviews/Milestone-10-Review.md) for full details.

**Quality gates (M10):**

| Gate | Result |
|------|--------|
| PHPUnit (581 tests) | 579 passing, 2 Redis skipped |
| PHPStan level 8 | 0 errors |
| Laravel Pint | Clean |
| Frontend build | 129 modules, 0 errors |

---

---

## Completed Milestones

### Milestone 10 — Customer Dashboard & UX ✅
*Completed: 2026-06-28*

Full customer-facing Inertia.js + Vue 3 + TypeScript dashboard. 10 implementation phases. 581 tests. See [Milestone-10-Review.md](reviews/Milestone-10-Review.md).

### Milestone 9.5 — Version 0.1 Stabilization Sprint ✅
*Completed: 2026-06-27*

All 5 production-blocking gaps resolved. Two systemic pipeline defects fixed. See [Milestone-9.5-Review.md](reviews/Milestone-9.5-Review.md).

### Milestone 8.5 — Learning Engine Specification ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| `specs/core/learning-engine.md` | Full Phase 8 implementation blueprint — 14 sections covering every design decision for the Learning Engine |
| Learning domain model | `Learning` (existing, Phase 7); `LearningApplication` (new — tracks applied effects + rollback); `CompanyScoringWeights` (new — versioned per-company scoring weights) |
| Learning lifecycle | Created → Unapplied → Applied → (optional Rollback); `applied_at` set once and never changed |
| `ApplyLearnings` job design | `ShouldBeUnique`; company-scoped; scheduled daily at 02:00 UTC; delegates to `LearningEngine` service |
| Learning prioritization | Tier 1 (safety: immediate), Tier 2 (performance: 2+ signals), Tier 3 (preference: 3+ signals); 90-day rolling evidence window |
| Conflict resolution | 4-rule ordered resolution: safety override → recency → majority → no-action tie |
| Confidence recalibration | Upward bias: 1 positive signal sufficient; 2+ negative signals required for downward adjustment; ±5% max per run; 14-day cooling |
| `CompanyScoringWeights` design | Versioned rows; `is_current` flag; floor 0.05, ceiling 0.60, sum always 1.00; `type_modifiers` (0.50–1.50) |
| BusinessBrain mutation rules | Fact supersession (new row, old `is_current = false`); Knowledge `type = 'learning'` with 90-day expiry; weight versioning; `OpportunityScorer` integration pattern |
| Prompt adaptation strategy | Indirect: learning enriches BusinessBrain context, never modifies prompt templates; edit-pattern detection (length, hashtags, price, CTAs) |
| Safety constraints | Hard limits table; company scoping enforcement pattern; no-auto-publish; notification requirements for Tier 1 signals |
| Explainability | `LearningApplication.effects` descriptor shape; Filament admin views (Learning Log, Applied Effects, BusinessBrain Mutations) |
| Rollback strategy | Compensating records only — no deletes; `rolled_back_at` + `rollback_reason`; Learning `applied_at` reset to null for re-evaluation |
| Versioning | Weight version history; Knowledge supersession; prompt version linkage; full audit trail via SQL queries documented |
| 47 acceptance criteria | All verifiable by automated tests; no live API or provider calls |
| Future extensibility | Cross-company aggregation; ML-trained scoring; preference cascade to brief; user-initiated overrides; real-time Tier 1 path |
| `ROADMAP.md` updated | Phase 8 now references `specs/core/learning-engine.md`; deliverables expanded with concrete models, jobs, and safety invariants |

### Milestone 8 — Analytics Engine ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| 4 migrations | `execution_metrics`, `campaign_kpi_snapshots`, `metric_retrieval_logs`, `learnings` |
| `ExecutionMetric` model | Per-execution platform metrics; normalised keys; `raw` payload; retrieval window tracking |
| `CampaignKpiSnapshot` model | Campaign-level KPI rollup; `actual_kpis`, `performance_rating`, `snapshot_type`; immutable (`UPDATED_AT = null`) |
| `MetricRetrievalLog` model | Append-only audit log per pull attempt; immutable |
| `Learning` model | Idempotent signal records; `applied_at = null` until Learning Engine runs |
| `AnalyticsProvider` interface | `pull`, `normalize`, `isWindowClosed`, `pollingDelayHours`, `repollingIntervalHours`, `supports` |
| `AnalyticsProviderRegistry` | First-match registry; `register()`, `for()`, `all()` |
| `FakeAnalyticsProvider` | Queue/assert test double; `queueMetrics()`, `queueFailure()`, `assertPulled()`, `assertNotPulled()`, `setWindowClosed()` |
| `LogAnalyticsProvider` | No-op provider for blog/landing page channels |
| `WebhookEvent` VO | Immutable: `providerType`, `platformMessageId`, `eventType`, `occurredAt` |
| `AnalyticsWebhookHandler` interface | `verify()`, `parse()`, `supports()` |
| `WebhookHandlerRegistry` | First-match registry for webhook handlers |
| `PostmarkWebhookHandler` | HMAC-SHA256 verification; maps RecordType → Open/Click/Bounce/Delivery/SpamComplaint |
| `AnalyticsServiceProvider` | Registers all analytics singletons; boots providers and handlers |
| `ScheduleMetricRetrieval` listener | `ExecutionCompleted` → delayed `RetrieveExecutionMetrics` dispatch |
| `RetrieveExecutionMetrics` job | Self-rescheduling pull polling; `updateOrCreate` ExecutionMetric; `snapshotIfReady` on window close |
| `PruneRawMetrics` job | Monthly maintenance; nulls `raw` on records older than 1 year |
| `AnalyticsWebhookController` | `POST /api/analytics/webhooks/{provider}`; HMAC verified; dispatches `ProcessAnalyticsWebhookEvent` |
| `ProcessAnalyticsWebhookEvent` job | Idempotent counter merging by `platform_id`; silent no-op if not found |
| `CampaignKpiService` | `aggregate()`, `snapshotIfReady()`, `ratePerformance()`, `bestChannel()`; interim/final snapshot lifecycle |
| `RecommendationKpiService` | Approval/rejection/edit rates; median time-to-decision (driver-aware SQL); 30-day trend |
| `DecisionEffectivenessService` | Accuracy rate by detector, by campaign type; score-band correlation |
| `LearningService` | `recordFromMetrics()` with 8 signal types; idempotency guard; `applied_at = null` |
| Filament panels | Campaign performance infolist; ExecutionMetric sub-panel; Company approval rate |
| `api.php` routes | `POST /api/analytics/webhooks/{provider}` registered via `bootstrap/app.php` |
| 97 new tests | 16 test files; all use `FakeAnalyticsProvider`; no live API calls; 365 total (363 passing) |
| PHPStan level 8 | 0 errors |

### Milestone 7.5 — Analytics Engine Specification ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| `specs/core/analytics-engine.md` | Full Phase 7 implementation blueprint: domain model, event ingestion, webhook interface, attribution, metrics by channel, campaign KPIs, recommendation KPIs, decision effectiveness metrics, BusinessBrain feedback loop, learning inputs, provider abstraction, data retention, privacy considerations, acceptance criteria, future extensibility |
| `ROADMAP.md` updated | Phase 7 now references `analytics-engine.md` as authoritative spec; Major Deliverables expanded with concrete models, services, and jobs |

### Milestone 7 — EmailPublisher ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| `EmailPayload` VO | Readonly: `subject`, `fromName`, `fromEmail`, `body`, `previewText`; `fromPlatformPayload()` throws `MalformedPayloadException` if subject is empty |
| `EmailProvider` interface | `send(EmailPayload, ChannelCredentials): string`, `ping(ChannelCredentials): PingResult`, `supports(string): bool` |
| `EmailProviderRegistry` | Resolves `EmailProvider` by `provider_type` string; throws `UnknownEmailProviderException` (non-retryable); `register()`, `for()`, `all()` |
| `UnknownEmailProviderException` | Extends `PublishingException`; non-retryable; `userMessage()` directs user to contact support |
| `LogEmailProvider` | Sends to `publishing` log channel; returns `'log-email-{ulid}'`; supports only `'log'` provider type |
| `FakeEmailProvider` | Queue/assertion test double; `queueMessageId()`, `queueFailure()`, `assertSent()`, `assertNotSent()`, `sentItems()` |
| `EmailRenderer` | Implements `ChannelRenderer`; reads `metadata.subject_line` → fallback `title` → throws; packs `subject/from_name/from_email/body/preview_text` into `PlatformPayload`; supports only `'email'` channel type |
| `EmailPublisher` | Implements `ChannelPublisher`; resolves credentials → renders → creates `EmailPayload` → picks provider from registry → sends; `ping()` delegates to provider; supports only `'email'` |
| `PublisherServiceProvider` updated | `EmailRenderer` registered first (priority over `GenericRenderer`); `EmailPublisher` registered first (priority over `LogChannelPublisher`) |
| 29 new tests | `EmailRendererTest` (6), `EmailProviderRegistryTest` (6), `LogEmailProviderTest` (6), `EmailPublisherTest` (12, including full `PublishContent` job integration) |
| PHPStan level 8 | 0 errors |

### Milestone 6.5 — Publishing Hardening ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| `ChannelRendererRegistry` | Mirrors `ChannelPublisherRegistry`; `register()`, `for()`, `all()`; throws `UnknownChannelException` |
| `GenericRenderer` | `supports()` returns true for all channel types; wraps asset body/title/media/metadata into `PlatformPayload` |
| `FakeChannelRenderer` | Test double; `assertRendered()`, `assertNotRendered()`, `renderedItems()` |
| `LogChannelPublisher` updated | Now injects `ChannelRendererRegistry`; calls `render()` before logging payload |
| `PublisherServiceProvider` updated | Registers both `ChannelRendererRegistry` and `ChannelPublisherRegistry` as singletons; boots `GenericRenderer` |
| `CredentialsExpiredException` | New non-retryable exception; `userMessage()` instructs reconnection |
| `ChannelCredentialsRepository` updated | Three-stage validation: not found/revoked → `CredentialsNotFoundException`; expired → `CredentialsExpiredException`; error → `AuthenticationException` |
| Blueprint validation hardened | 8 new checks: `tone.voice`, `tone.modifier`, `tone.avoid`, `landing_page`, `success_metrics.*` (4 fields), channel_strategy count and field completeness |
| `CampaignPublished` bug fixed | Event no longer fires when all executions fail; campaign marked `cancelled` without event |
| `docs/technical/Tenancy.md` | Documents CompanyScope, required middleware pattern, production-readiness requirement |
| 28 new tests | `RendererIntegrationTest` (5), `ChannelCredentialsRepositoryTest` (9), `CampaignPreparationServiceTest` (14 new) |
| PHPStan level 8 | 0 errors |

### Milestone 6 — Publishing Infrastructure ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| 3 migrations | `channel_credentials`, `executions`, `execution_attempts` |
| `ChannelCredentials` model | `BelongsToCompany`, `HasUlids`; encrypted JSON credentials; `isExpired()` |
| `Execution` model | `BelongsToCompany`, `HasUlids`; status lifecycle; `isSettled()`; `attemptLogs()` HasMany |
| `ExecutionAttempt` model | `HasUlids`; append-only audit log; no `updated_at` |
| `ExecutionResult` VO | Readonly: `platformId`, `url`, `publishedAt`, `metadata` |
| `PlatformPayload` VO | Readonly: `channelType`, `data` |
| `PingResult` VO | Readonly: `reachable`, `error` |
| `PublishingException` hierarchy | Base + 8 subclasses; `isRetryable()` + `userMessage()` |
| `ChannelPublisher` interface | `publish()`, `supports()`, `ping()` |
| `ChannelRenderer` interface | `render()`, `supports()` |
| `SupportsRollback` interface | Opt-in; `rollback(): bool` |
| `ChannelPublisherRegistry` | Resolves publisher by `supports(channelType)` |
| `ChannelCredentialsRepository` | `for(companyId, channelType)` → throws `CredentialsNotFoundException` |
| `FakeChannelPublisher` | Queue-based test double; `assertPublished()`, `assertNotPublished()` |
| `LogChannelPublisher` | Writes to `publishing` log channel; supports all 8 channel types; no API calls |
| `ExecutionService` | `queueForCampaign`, `markCompleted`, `markFailed`, `logAttempt`, `checkCampaignCompletion` |
| `RollbackService` | Iterates completed Executions; dispatches rollback if `SupportsRollback`; reports unrollable |
| `PublishCampaign` job | `high` queue; creates Executions; dispatches immediate `PublishContent` jobs |
| `PublishContent` job | `high` queue; 4 tries; 60/300/900s backoff; non-retryable → `fail()`; retryable → re-throw |
| `PublishScheduledContent` job | `maintenance` queue; every 5 min; dispatches due Executions |
| `CheckChannelHealth` job | `maintenance` queue; every 30 min; pings all active credentials |
| 3 events | `ExecutionCompleted`, `ExecutionFailed`, `CampaignPublished` |
| `TriggerCampaignPublishing` listener | `RecommendationApproved → PublishCampaign` |
| `PublisherServiceProvider` | Singleton registry; registers `LogChannelPublisher` for all 8 channel types |
| Filament `ExecutionResource` | Read-only; status badge; attempts; last_error; company/campaign/channel columns |
| `publishing` log channel | `storage/logs/publishing.log`; separate from `laravel.log` |
| Campaign status `published` | Added to campaign status enum |
| 47 new tests | All passing; no live API calls; `FakeChannelPublisher` throughout |
| PHPStan level 8 | 0 errors |

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
| `IntegrationService` | `create(Company, type, config)` — provisions Integration, sets `name`, `status: active`, `next_run_at: +7 days`; callers own dispatch |
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

See `docs/plans/Version-0.1-Architecture-Audit.md` for the full pre-customer-dashboard readiness checklist.

**Immediate priorities (blocking for production):**
1. Implement `AnthropicProvider` — the AI pipeline does not function without it
2. Add Filament superadmin gate — all company data is currently exposed to any registered user
3. SSRF protection on `WebPageCrawler` — user-supplied URLs must be validated to public IPs before outbound requests
4. Add health check endpoint (`GET /api/health`)
5. Confirm PostgreSQL RLS rollout plan

---

## Technical Debt

| Item | Introduced | Notes |
|------|------------|-------|
| No real AI provider implemented | 2026-06-26 | `AnthropicProvider.php` does not exist. `FakeAiProvider` is used in all environments. Atlas cannot run the observation → fact → campaign pipeline in production. |
| `BusinessBrainService` has no caching | 2026-06-26 | Spec requires 5-minute Redis TTL per company. Currently assembles fresh on every call. Will degrade at moderate scale. |
| `EvidenceEvaluator` PHP-side filtering | 2026-06-26 | Loads all Learning records for a company+signal then filters discriminator in PHP. Correct for cross-DB compat in tests; inefficient at production scale. Replace with SQL JSON extraction on PostgreSQL. |
| No PostgreSQL RLS | 2026-06-25 | `docs/technical/Database.md` specifies RLS as defense-in-depth. Not yet applied to any table. Required before production. |
| Queue tests use `Queue::fake()` — no live Redis execution | 2026-06-25 | Dispatch mechanism is tested; real Redis worker execution is not. Add integration test or smoke test before production. |
| Spec/code column drift | 2026-06-26 | `learning-engine.md` spec uses `payload`; implementation uses `value`. Spec defines `LearningApplication.applied_at`; implementation uses `created_at`. Update spec or add migration. |
| `ApplyLearnings` on `ai` queue instead of `maintenance` | 2026-06-26 | Architecture.md assigns this job to `maintenance`. Implementation uses `ai`. Align with spec. |

---

## Open Questions

| Question | Context | Priority |
|----------|---------|----------|
| Frontend: Inertia.js + Vue 3 or API-first SPA? | CLAUDE.md lists both. Decision needed before customer dashboard work begins. | High |
| AI provider: Anthropic or OpenAI? | Anthropic preferred per Architecture.md. Implement before any production run. | Critical |
| Hosting and deployment target? | No infrastructure provisioned. Options: Laravel Forge + DigitalOcean, Vapor, bare VPS. Decision affects queue worker config and environment secrets strategy. | High |
| CBB Auctions inventory format? | RSS feed, structured API, or HTML-only? Determines which Connector is primary. | High |
| JavaScript-rendered inventory pages? | WebsiteCrawlConnector uses simple HTTP. Headless browser connector may be required. | Medium |
| Image handling for catalog items? | `catalog_items.media` stores URLs. Re-host vs. link-to-source decision needed before content generation goes live. | Medium |

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

## Next Tasks (Post-M9.5)

All production-blocking items resolved. Remaining pre-production items:

1. `BusinessBrainService` Redis caching — 5-min TTL per `company_id`; required before the brain is queried at any scale
2. Rate limiting on `/api/analytics/webhooks/{provider}` — required before analytics webhooks are exposed publicly
3. Spec/code drift — `Learning.value` vs spec `payload`; update spec to match implementation
4. `ApplyLearnings` queue alignment — change from `ai` to `maintenance` per Architecture.md
5. First production environment provisioning (Forge + DigitalOcean or Vapor)

---

## Recently Completed

- **Milestone 9.5 — Version 0.1 Stabilization Sprint** — All 5 production blockers resolved: `AnthropicProvider`, Filament superadmin gate, SSRF protection, health endpoints, E2E smoke test. Two systemic pipeline defects fixed (job dispatch silencing, duplicate event listeners). 519 tests (517 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. Pint clean. See [Milestone-9.5-Review.md](reviews/Milestone-9.5-Review.md).

- **Version 0.1 Architecture Audit** — `docs/plans/Version-0.1-Architecture-Audit.md` written. 15 audit areas reviewed. 5 critical/production-blocking items identified. 5 customer-dashboard-blocking items identified. 12 recommended refactors prioritized.

- **Milestone 9 — Learning Engine** — Full Learning Engine implemented and verified. 449 tests (447 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. Pint clean. See [Milestone-9-Review.md](reviews/Milestone-9-Review.md).

- **Milestone 8.5 — Learning Engine Specification** — `specs/core/learning-engine.md` written. 14 sections: domain model, learning lifecycle, `ApplyLearnings` job design, 3-tier prioritization, 4-rule conflict resolution, confidence recalibration, BusinessBrain mutation rules, prompt adaptation, safety constraints, explainability, rollback, versioning, 47 acceptance criteria, and future extensibility.

- **Milestone 8 — Analytics Engine** — Full analytics pipeline implemented. Pull polling + webhook ingestion; `CampaignKpiSnapshot` (interim/final); `RecommendationKpiService`; `DecisionEffectivenessService`; `LearningService` with 8 signal types; Filament panels. 97 new tests (365 total, 363 passing). PHPStan level 8 — 0 errors. See [Milestone-8-Review.md](reviews/Milestone-8-Review.md).

- **Milestone 7.5 — Analytics Engine Specification** — `specs/core/analytics-engine.md` written. Covers domain model (`ExecutionMetric`, `CampaignKpiSnapshot`, `MetricRetrievalLog`), pull polling + webhook push ingestion, `AnalyticsProvider` interface and registry, normalised metric keys, campaign KPIs, recommendation KPIs, decision effectiveness metrics, BusinessBrain feedback loop, learning inputs, privacy constraints, acceptance criteria, and future extensibility. `ROADMAP.md` Phase 7 updated with concrete deliverables.

- **Milestone 7 — EmailPublisher** — First real channel publisher shipped. `EmailProvider` interface + `EmailProviderRegistry` + `LogEmailProvider` + `FakeEmailProvider` + `EmailRenderer` + `EmailPublisher` all wired into M6 infrastructure. 29 new tests (268 total, 266 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. See [Milestone-7-Review.md](reviews/Milestone-7-Review.md).

- **Milestone 6.5 — Publishing Hardening** — Renderer layer integrated, credential validation hardened, blueprint validation expanded, `CampaignPublished` event bug fixed. 28 new tests (239 total, 237 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. See [Milestone-6.5-Review.md](reviews/Milestone-6.5-Review.md).

- **Milestone 6 — Publishing Infrastructure** — Full pipeline implemented: `RecommendationApproved → PublishCampaign → PublishContent × n → LogChannelPublisher → Execution completed → CampaignPublished`. 47 new tests (211 total, 209 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. See [Milestone-6-Review.md](reviews/Milestone-6-Review.md).

- **Milestone 5 — Campaign Engine** — Full Campaign Preparation + Content Generation + Approval Workflow implemented. `CampaignBlueprint` VO, `CampaignPreparationAnalyst`, `CampaignPreparationService`, 5 `ContentGenerationPrompt` variants, `ContentGenerationAnalyst`, `ContentGenerationService`, `RecommendationService`, `ApprovalService` (approve + reject with full status transitions). Jobs: `PrepareCampaign` (full), `GenerateContent`, `CreateRecommendation`. Events: `CampaignAssetsReady`, `RecommendationCreated`, `RecommendationApproved`, `RecommendationRejected`. Filament admin panel with 6 resources (Company, Opportunity, Decision, Campaign, ContentAsset, Recommendation) + approve/reject actions. 35 new tests (164 total, 162 passing, 2 Redis skipped). PHPStan level 8 — 0 errors.
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

## Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Auction/dealer sites render inventory via JavaScript, blocking simple HTTP crawl | High | High | Spike a headless browser connector (Puppeteer or Playwright via Node sidecar) before Phase 2 goes live |
| AI provider rate limits during parallel processing (crawl → extract → synthesize) | Medium | Medium | All AI jobs run on dedicated `ai` queue; implement per-provider rate limiting in `AnthropicProvider` |
| No real AI provider — all AI paths use `FakeAiProvider` | High | Critical | Implement `AnthropicProvider` before any customer data is processed |
| SSRF in `WebPageCrawler` — user URLs not validated to public IPs | High | Critical | Add IP range validation before outbound Guzzle requests |
| Filament panel has no superadmin gate | High | Critical | Add `canAccess()` policy or `authMiddleware` before Filament is accessible in production |
| CBB Auctions engagement becomes informal, reducing design partner feedback | Low | Medium | Formalize the design partner relationship; schedule regular demos |
| Scope creep into CRM, billing, or ads integrations before core loop is proven | Low | High | ROADMAP.md exclusions list is authoritative; defer any out-of-scope request explicitly |

---

## Last Updated

**2026-06-29** — P0 onboarding pipeline fix complete (Phase 4). Critical `body_text`/`bodyText` key mismatch in `WebsiteAnalyst` fixed — all real crawls now produce facts. AI provider binding updated: `AnthropicProvider` used when `ANTHROPIC_API_KEY` is set in local env; `LocalAiProvider` only when no key. `OnboardingStatusController` adds `crawl_succeeded` and `ai_failed` fields. Status page shows dedicated "AI analysis encountered an error" card distinct from crawl failure. All test payloads updated from `bodyText` to `body_text`. `SettingsControllerTest::test_sync_integration_dispatches_job` fixed with `Bus::fake()`. 603 tests (601 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. See [P0-New-Customer-Onboarding-Fix.md](reviews/P0-New-Customer-Onboarding-Fix.md).

**2026-06-28** — P0 onboarding pipeline fix complete (Phase 3). AI pipeline now runs end-to-end in local development: `LocalAiProvider` returns deterministic stubs in `local` env; default blog channel seeded on onboarding; `.env.example` defaults to `QUEUE_CONNECTION=sync`; pipeline logging added at every stage; status page shows "queue worker needed" card when facts stall > 90s. Full crawl → facts → recommendation pipeline test added (`OnboardingPipelineTest`). 603 tests (601 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. See [P0-New-Customer-Onboarding-Fix.md](reviews/P0-New-Customer-Onboarding-Fix.md).

**2026-06-28** — P0 onboarding pipeline fix Phase 1 + Phase 2. Website crawl now runs synchronously on form submit (`dispatchSync`) — no queue worker needed for first sync. `connect_timeout` bug fixed in `WebPageCrawler`; `max_pages` default changed to 1 for fast local onboarding. Integration error state exposed on the status API (`integration_status`, `sync_started`). Status page shows clear failure UI when crawl fails. See [P0-New-Customer-Onboarding-Fix.md](reviews/P0-New-Customer-Onboarding-Fix.md).

**2026-06-27** — Landing Page Design & Content Specification complete. Full marketing spec for Atlas: hero through footer, 16 content sections, recommendation showcase mockup, industry cards, mobile layout, animation spec, accessibility requirements, CTA strategy, and copy principles. See `docs/marketing/Landing-Page.md`.

*Update this document at the end of every sprint and whenever a significant decision is made or risk changes.*
