# Milestone 15 — Business Discovery Onboarding
## Implementation Plan

**Status:** Plan — not yet implemented
**Author:** Claude Sonnet 5
**Date:** 2026-07-13
**Prerequisite:** Milestone 11 (Marketing Presence), Milestone 12 (Instagram Observation + Content Intelligence), Milestone 13 Phase 1 (Marketing Health MVP) complete. Milestone 14 (Google Business Intelligence) **designed but not implemented** — this plan's Phase 2 depends on at least Milestone 14's `google_business.*` Fact-key design being implemented first (or run in parallel; see Phase 2's note).
**Specification:** `docs/specs/Business-Discovery-Onboarding.md` — authoritative domain spec for this milestone. Read it first; this document sequences its implementation and does not restate its reasoning.

---

## 1. What We Are Building

A redesigned six-step onboarding wizard (Company Information → Business Goals → Marketing Assets → Asset Details → Atlas Discovery → Recommendations) that separates declaring a business's marketing footprint (fast, no waiting, no credentials) from discovering what's true about it (a new, resilient, multi-connector `DiscoveryOrchestrator` that runs after onboarding completes, not interleaved with it). Replaces today's single website-crawl-blocks-everything flow and its single-`Integration`-scoped status polling with a stage-based (Discover → Analyze → Understand → Recommend) progress model that generalizes to however many connectors a company actually declared.

---

## 2. What We Are NOT Building

Restating `docs/specs/Business-Discovery-Onboarding.md` §8's boundaries at the implementation level:

- **No in-app OAuth for Instagram/Facebook/LinkedIn/Google Business during onboarding.** All three remain "declared, connect later from Settings."
- **No re-discovery / re-scan feature.** `DiscoveryRun` is created once, at onboarding completion, in this milestone.
- **No Opportunity Engine, Decision Engine, or Marketing Health scoring changes.** This milestone changes when/how observation begins, not what happens to its results.
- **No granular percentage-based progress bar.** Four coarse, honest stages only.
- **No restriction on post-onboarding Marketing Presence editing.** Unchanged.
- **No code is written as part of the task that produced this plan.** This document and the spec are the complete deliverable of that task. Implementation begins in a future session against this plan.

---

## 3. Dependencies

- `docs/specs/Business-Discovery-Onboarding.md` — domain model, UX flow, orchestration design, event flow, error handling
- `docs/specs/Google-Business-Intelligence.md` — the `google_business.*` Fact-key design this plan's `GoogleBusinessPublicConnector` writes into; **this plan does not require Milestone 14's `GoogleBusinessConnector` (OAuth) to be built first**, only its Fact-key shape to be the target — the public connector can ship its own Fact-writing path (§Phase 2) even before the OAuth connector exists, as long as both ultimately write compatible keys
- `docs/specs/Marketing-Intelligence.md` — the `Connector`/`ObservationAnalyst`/`AnalystRegistry` pattern every new piece here reuses
- `specs/core/domain-model.md` — event-naming conventions, tenancy conventions
- `specs/core/marketing-presence.md` — `MarketingChannel`/`MarketingPresenceService` existing behavior this plan extends (`link()`, `declare()`, `hasChannelEquivalent()`)
- Existing code: `App\Http\Controllers\OnboardingController` (being redesigned, not replaced wholesale — its `createCompany()`/company-membership logic is reused), `App\Jobs\SyncIntegration`, `App\Jobs\ProcessObservation`, `App\Services\Observatory\ObservationService`, `App\Listeners\DispatchObservationProcessing`, `App\Listeners\TriggerOpportunityDetection`, `App\Http\Controllers\Api\OnboardingStatusController` (being replaced by a multi-connector-aware equivalent), `resources/js/Pages/Onboarding/Index.vue` and `Status.vue` (being redesigned)

---

## 4. Implementation Sequence

### Phase 1 — Domain Changes and Business Goals

**Migrations**

- `marketing_channels` gains `integration_id` (nullable, `char(26)`, FK to `integrations`, `nullOnDelete()`) — a single `ALTER TABLE` migration.
- `MarketingChannel::scopeConnected()` updated to `(whereNotNull('channel_id')->orWhereNotNull('integration_id'))->where('is_connected', true)`.
- `MarketingPresenceService::linkIntegration(MarketingChannel $channel, Integration $integration): MarketingChannel` — new method, mirrors `link()`'s exact shape (tenant-match guard via the existing `ChannelBelongsToDifferentCompanyException`, event dispatch) but sets `integration_id` instead of `channel_id`.

**Business Goals**

- `App\Services\Analyst\OnboardingGoalsAnalyst implements ObservationAnalyst` (`app/Services/Analyst/OnboardingGoalsAnalyst.php`) — `supports()` checks `source_type === 'manual' && source_identifier === 'onboarding_goals'`. Deterministic mapping to `business.primary_goal`, `business.target_audience`, `business.growth_priority` Facts, mirroring `InstagramAnalyst`'s exact structure (a `fact()` helper omitting null/empty values).
- Register in `AppServiceProvider`'s `AnalystRegistry` binding — one more array entry.
- `OnboardingController::saveBusinessGoals(Request $request)` (new action) — validates the three fields (§2.3 of the spec), calls `ObservationService::record()` directly with `source_type: 'manual'`, `source_identifier: 'onboarding_goals'`.

**Tests:** `OnboardingGoalsAnalystTest` (mirroring `InstagramAnalystTest`'s profile-Fact-mapping tests), `MarketingPresenceServiceTest` additions for `linkIntegration()`, a migration-level test confirming `scopeConnected()`'s new OR-condition behaves correctly for both linkage paths.

---

### Phase 2 — `GoogleBusinessPublicConnector` (Places API, no OAuth)

Per spec §4.2.1 — a genuinely new connector, distinct from Milestone 14's OAuth-gated `GoogleBusinessConnector`, and this plan's most significant *new capability* (as opposed to orchestration/UX work).

- `App\Services\Observatory\Connectors\GoogleBusiness\GoogleBusinessPublicFetcher` — calls the Google Places API (API-key auth, no user OAuth) with a business name or Maps URL, returns name/address/category/hours/photos/rating average/review count.
- `GoogleBusinessPublicConnector implements Connector` — `supports()` checks `$integration->type === 'google_business_public'` (a new `integrations.type` enum value, added via the standard two-migration pattern). Structurally near-identical to `GoogleBusinessConnector`'s planned shape (Milestone 14 plan Phase 1) but with a different fetcher and no reviews-list endpoint (Places API's review data is more limited than the full Business Profile reviews endpoint — capture what's available, degrade gracefully for what isn't, per Milestone 14's own Q&A-availability precedent).
- `GoogleBusinessAnalyst` (Milestone 14's planned analyst) gains a second `supports()` branch for this connector's Observation source type, writing the **same** `google_business.*` Fact keys at a **lower confidence** than the OAuth connector would (spec §4.2.1) — if Milestone 14's analyst doesn't exist yet when this phase starts, build the minimal subset of it needed here and let Milestone 14's implementation extend it, rather than duplicating a second analyst class.
- Requires a Google Cloud API key (Places API enabled) — `config('google_business.places_api_key')`, `env('GOOGLE_PLACES_API_KEY')`.

**Tests:** Guzzle `MockHandler`-based fetcher/connector tests mirroring `InstagramProfileFetcherTest`/`InstagramConnectorTest`'s structure, including the "business name has no unambiguous match" and "Places API returns zero results" cases (real, expected failure modes for a name-only lookup that a URL-only lookup wouldn't have — worth testing explicitly, not assumed away).

---

### Phase 3 — Discovery Orchestration

- `discovery_runs` and `discovery_connector_attempts` tables (spec §4.3, §7) — new migrations, `char(26)` ULID PKs, FKs with `cascadeOnDelete()`.
- `App\Models\DiscoveryRun`, `App\Models\DiscoveryConnectorAttempt` — standard `HasUlids`/`BelongsToCompany` models.
- `App\Domain\Onboarding\AssetFieldSchema` value object + `App\Services\Onboarding\AssetFieldSchemaRegistry` (spec §5.4) — one entry per `MarketingChannelType`, declaring its identifying fields and `canAutoDiscover`/`connectorType`.
- `App\Services\Onboarding\DiscoveryOrchestrator::start(Company $company): DiscoveryRun` — creates the run, iterates declared `MarketingChannel`s, creates a `DiscoveryConnectorAttempt` + dispatches the matching sync job for every auto-discoverable one (per the registry), leaves every other declared channel untouched (`is_connected: false`, no attempt row).
- New listeners (spec §5.3): `App\Listeners\UpdateDiscoveryConnectorAttempt` (on `IntegrationSyncCompleted`, and a failure-path equivalent — check whether `IntegrationSyncFailed` needs to be introduced or whether `SyncIntegration`'s existing exception handling already provides an equivalent signal to listen to; if not, add a minimal `IntegrationSyncFailed` event mirroring `IntegrationSyncCompleted`'s shape) and `App\Listeners\AdvanceDiscoveryRunStage` (on `ObservationRecorded`, `ObservationProcessed`, `KnowledgeSynthesized`, and a new-or-existing "recommendation created" signal — confirm `RecommendationCreated` fires from `RecommendationService::create()` and is available to listen to, per `specs/core/domain-model.md`'s event table).
- `App\Jobs\SyncGoogleBusinessPublic` — a thin job mirroring `SyncIntegration`'s shape but scoped to the `google_business_public` connector type (or, simpler: confirm whether `SyncIntegration` is already generic enough via `ConnectorRegistry::resolve()` to handle this new Integration type without a new job class at all — likely yes, since `SyncIntegration` already dispatches based on the resolved `Connector`, not a hardcoded type; verify this at implementation time before creating a redundant job class).

**Tests:** `DiscoveryOrchestratorTest` (creates the right attempts for a mixed bag of auto-discoverable and non-discoverable declared assets — the single most important test in this milestone, since it's the crux of objective 6/7), `AdvanceDiscoveryRunStageTest` (each of the four stage transitions, plus the `completed_with_errors` all-failed case), `UpdateDiscoveryConnectorAttemptTest` (success and failure paths), and a full integration test running a realistic multi-asset onboarding through to a `Recommendation` with one connector deliberately failing, proving objective 7's resilience requirement end-to-end, not just unit-by-unit.

---

### Phase 4 — Wizard UI Redesign

- `App\Http\Controllers\OnboardingController` — redesigned step routing (six steps instead of four), reusing `createCompany()` largely unchanged, adding `saveBusinessGoals()` (Phase 1), `saveMarketingAssets()` (persists the step-3 checklist as a set of intended asset types, not yet full `MarketingChannel` rows — or persists partial rows immediately and fills in details on step 4; implementation-time decision, either is compatible with this spec), `saveAssetDetails()` (persists full `MarketingChannel` rows via `MarketingPresenceService::declare()`, then calls `DiscoveryOrchestrator::start()`).
- `resources/js/Pages/Onboarding/Index.vue` — six-step wizard, with the Marketing Assets step (3) and Asset Details step (4) driven by the `AssetFieldSchemaRegistry` (exposed to the frontend the same way `MARKETING_CHANNEL_TYPES`/`marketingChannelTypeLabel` already are, per `resources/js/lib/marketingChannelTypes.ts`) rather than hardcoded per-type template branches — the concrete implementation of objective 9's extensibility requirement.
- New `resources/js/Pages/Onboarding/Discovery.vue` (replacing `Status.vue`) — the four-stage progress UI (spec §2.6, §4.4), backed by a redesigned status endpoint (below).
- `App\Http\Controllers\Api\OnboardingStatusController` — redesigned to aggregate across a company's `DiscoveryRun` + all its `DiscoveryConnectorAttempt` rows (today's version is scoped to "the latest `Integration`" only, a real limitation this milestone must fix, not preserve) — returns `stage`, per-attempt summaries (asset name, status), and the same terminal-state fields today's endpoint already returns (`recommendation_count`, `first_recommendation_id`) so the redirect-to-recommendation behavior is preserved exactly.

**Tests:** Feature tests for each new/changed controller action (mirroring the existing `OnboardingControllerTest`'s structure), Vitest specs for the redesigned wizard steps and the new `Discovery.vue` (mirroring `MarketingPresence/Index.spec.ts`'s conventions), and an update to any existing onboarding end-to-end test that assumed the old four-step/website-first flow.

---

## 5. Risks

| Risk | Mitigation |
|---|---|
| **This is the largest single-milestone scope in the project so far** (new connector, new orchestration layer, new domain tables, full wizard UI redesign, a redesigned status endpoint). | The phase breakdown above is designed so Phases 1–3 (domain changes, the new connector, orchestration) can ship and be fully tested *before* Phase 4's UI redesign begins — the backend can be correct and verified in isolation first, the same "backend proven before frontend built" sequencing this project has used for every prior milestone. If time-constrained, Phase 4 could even ship as an incremental UI change against the *existing* four-step wizard's routes first, with the six-step redesign following once Phases 1–3 are stable. |
| **Google Places API is a genuinely different Google product from the Business Profile API** (different auth model, different terms of service, different rate limits/pricing) — a real integration risk, not a paperwork detail. | Flagged explicitly in the spec (§4.2.1) as a *new* connector, not a variant of Milestone 14's designed one. The implementing session should obtain a real Places API key and test against at least one real business listing before considering Phase 2 done — the same "don't trust the mocked test suite alone" lesson already learned from every prior real-API integration in this project. |
| **`DiscoveryRun.stage` computation could drift out of sync with reality** if a new event source is added later (e.g. a future connector) without updating `AdvanceDiscoveryRunStage`. | The listener's stage-computation logic should be written as declarative conditions over current state (per spec §4.4's precise definitions), not incrementally mutated by each event — recomputing the full stage from scratch on every relevant event, rather than assuming each event always moves the stage exactly one step forward, avoids this class of bug entirely. |
| **The "one asset per channel for beta" UI constraint (spec §2.4) could be mistaken for a new domain-level restriction** by a future contributor who doesn't read the spec closely, potentially leading someone to (wrongly) add a database uniqueness constraint that breaks post-onboarding multi-account flexibility. | Called out explicitly in the spec with the exact reasoning; the implementing session should add a code comment at the UI-layer enforcement point (not the service/model layer) making the same distinction, so it's visible without needing to consult this document. |
| **Existing onboarding tests, and any manual QA scripts, assume the current four-step/website-first flow.** | Phase 4 explicitly includes updating them, not just adding new ones — regressions here would be silent (old tests passing against deleted routes) rather than loud, so this needs deliberate attention, not an assumption that "new tests passing" is sufficient. |

---

## 6. Acceptance Criteria

- A company can complete onboarding (steps 1–4) having declared only an Instagram account and no website, and reach step 5 without error — proving the "at least one asset, not specifically a website" loosening (spec §2.4) actually works, not just that it's designed.
- No connector runs (no `Observation` is created, no third-party HTTP request is made) before step 4 is submitted — verified with a test that asserts zero `Observation`/`DiscoveryConnectorAttempt` rows exist after steps 1–3 alone.
- A company declaring Website + Instagram + Google Business Profile ends up with exactly one `DiscoveryConnectorAttempt` for Website, one for Google Business Profile (via `GoogleBusinessPublicConnector`), and **zero** for Instagram (correctly `is_connected: false`, no attempt row) — the concrete proof of spec §4.2's table.
- If the declared website is unreachable but Google Business Profile lookup succeeds, the company still receives at least one Recommendation, and `DiscoveryRun.stage` reaches `completed` (not `completed_with_errors`, since one connector did succeed) — the concrete proof of objective 7.
- If every attempted connector fails, `DiscoveryRun.stage` reaches `completed_with_errors` and the UI shows a clear, non-dead-end next step.
- Business Goals answers are queryable as real Facts (`business.primary_goal` etc.) immediately after step 2, before step 4 is ever reached.
- `MarketingChannel::scopeConnected()` correctly reflects `is_connected: true` for both a `Channel`-linked and an `Integration`-linked asset, with a dedicated test for each path.
- No existing test in `OnboardingControllerTest`, `MarketingPresenceServiceTest`, `InstagramConnectorTest`/`InstagramAnalystTest`, or any `MarketingHealthScorer` test file regresses.
- `php artisan test`, `./vendor/bin/phpstan analyse --memory-limit=1G`, `./vendor/bin/pint --test`, `npx vue-tsc --noEmit`, `npx vitest run`, `npm run build` all pass clean; every new migration verified against a real local PostgreSQL instance (up/rollback/up), not just sqlite, per this project's now-established discipline.
- Tenant isolation: two companies' `DiscoveryRun`/`DiscoveryConnectorAttempt` rows never cross-contaminate, verified the explicit two-company way every other feature in this codebase already is.

---

## 7. What This Milestone Does Not Prove

- That a business owner actually prefers this six-step flow over today's four-step one — that's a real UX hypothesis this design makes testable (via Phase 4's shipped UI) but does not itself validate; there is no production deployment or real user cohort to measure against yet (`docs/plans/Version-1.0-Roadmap.md`'s Stage A gate).
- That the Google Places API's public data is accurate or fresh enough to be worth showing a business owner immediately during onboarding, before they've verified anything themselves — a real product-quality question the implementing session should sanity-check against a handful of real business listings, not assume from documentation.
- That `DiscoveryRun`'s shape is the right foundation for a future re-discovery feature (§9 of the spec) — it's designed to not preclude one, not designed *for* one; the first real re-discovery use case is the actual test of that claim.
