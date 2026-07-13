# Milestone 14 — Google Business Intelligence
## Implementation Plan

**Status:** Plan — not yet implemented
**Author:** Claude Sonnet 5
**Date:** 2026-07-13
**Prerequisite:** Milestone 11 (Marketing Presence), Milestone 12 (Instagram Observation + Content Intelligence), Milestone 13 Phase 1 (Marketing Health MVP) complete.
**Specification:** `docs/specs/Google-Business-Intelligence.md` — authoritative domain spec for this milestone. Read it first; this document sequences its implementation and does not restate its reasoning.

---

## 1. What We Are Building

Google Business Profile as Atlas's second real-world Marketing Source: a company connects their Google Business Profile (beta scope — manually-obtained access token, no in-app OAuth), Atlas observes profile/hours/categories/photos and the review list, derives deterministic Facts, and those Facts flow into the existing Business Brain, Marketing Health (via the already-shipped `PresenceCoverageScorer` immediately, and via a new proposed `ReputationScorer` in a later phase of this same milestone), and two new Opportunity types that fit the existing Campaign-generation pipeline without inventing a new kind of Recommendation.

At the end of this milestone, a company with a real Google Business Profile has its rating, review count, and rating trend visible to Atlas, contributing to a richer picture of the business than website + Instagram alone provide — particularly valuable for the local/regional verticals (comic auctions, car dealerships) this product already targets.

---

## 2. What We Are NOT Building

Restating `docs/specs/Google-Business-Intelligence.md` §7's boundaries at the implementation level:

- **No publishing to Google Business Profile.** No posts, offers, or profile edits of any kind.
- **No review replies.** `owner_reply_rate` is observed, never acted on.
- **No in-app OAuth.** Beta scope is a manually-pasted access token, exactly like Instagram Phase 1.
- **No AI-based review content analysis.** `GoogleBusinessAnalyst` is deterministic, like `InstagramAnalyst`, not AI-calling, like `WebsiteAnalyst`.
- **No Q&A writing**, and no assumption that Q&A *reading* is even available (§3.3 of the spec) — the connector must degrade gracefully.
- **No other Google product** (Search Console, GA4, Ads).
- **No sixth/seventh Decision Engine guard, no change to `OpportunityScorer`'s base formula, no change to any existing `OpportunityDetector`.** New detectors are additive only, per every prior milestone's discipline.
- **No code is written as part of the task that produced this plan.** This document and the spec are the complete deliverable of that task. Implementation begins in a future session against this plan.

---

## 3. Dependencies

- `docs/specs/Google-Business-Intelligence.md` — domain model, observable data, Fact keys, Marketing Health contribution, Opportunity types, migration strategy
- `docs/specs/Marketing-Intelligence.md` — the `Connector`/`ObservationAnalyst`/`AnalystRegistry` pattern this milestone reuses exactly (`InstagramConnector`/`InstagramAnalyst` are the direct reference implementations)
- `docs/specs/Marketing-Health.md` — `MarketingHealthScorer` contract and the source-agnostic discipline `PresenceCoverageScorer` already satisfies (Phase 3 of this plan is the concrete proof)
- `specs/core/domain-model.md` — `BusinessBrain`, tenancy conventions, event-naming conventions
- `specs/core/opportunity-engine.md` — `OpportunityDetector` contract, deduplication/cooldown rules
- `specs/core/marketing-presence.md` §12 — the existing (generic, not Instagram-specific) `MarketingChannel`-to-`Integration` linking logic this milestone's connection (§2.3 of the spec) relies on unchanged
- Existing code: `App\Services\Observatory\Connectors\Instagram\*` (direct structural reference), `App\Services\Analyst\InstagramAnalyst` (direct structural reference), `App\Services\MarketingHealth\Scorers\PresenceCoverageScorer`, `App\Services\Opportunity\Detectors\ReEngagementDetector` (company-level detector reference), `App\Services\Decision\DecisionEngine` (`COOLDOWN_DAYS`, opportunity-type-to-campaign-type `match`)

---

## 4. Implementation Sequence

### Phase 1 — Connector and Observation Capture

**Migrations**

- Update the base `create_observations_table` migration to include `'google_business'`, `'google_business_reviews'` in `source_type`'s enum (fresh databases), plus a new Postgres-only constraint-rewrite migration (`<timestamp>_add_google_business_source_types_to_observations.php`), mirroring `2026_07_12_000100_add_social_content_source_type_to_observations.php` exactly (drop constraint, re-add with the expanded value list; `down()` deletes rows of the new type(s) first, then reverts the constraint).
- Same two-migration pattern for `integrations.type` gaining `'google_business'`, mirroring `2026_07_11_000100_add_instagram_type_to_integrations.php`.

**Classes** (`app/Services/Observatory/Connectors/GoogleBusiness/`)

- `GoogleBusinessProfileFetcher` — mirrors `InstagramProfileFetcher`'s constructor shape (`baseUrl`, `requestTimeout`, `connectTimeout`, optional injected Guzzle `Client`). Calls the Business Information API for profile/hours/categories/photos and (best-effort, per spec §3.3) Q&A.
- `GoogleBusinessProfileData` — mirrors `InstagramProfileData`; `toArray()`/`fromArray()` shape matching the Fact table in spec §4.2.
- `GoogleBusinessReviewFetcher` — mirrors `InstagramMediaFetcher`. Calls the reviews endpoint, maps each entry to `GoogleBusinessReviewData` (spec §3.3).
- `GoogleBusinessConnector implements Connector` — `supports()` checks `$integration->type === 'google_business'`; `sync()` returns two `ConnectorResult`s (profile, reviews), mirroring `InstagramConnector::sync()`'s two-result shape exactly.
- Register in `ConnectorServiceProvider`, alongside the existing `InstagramConnector` registration — same file, same pattern, one more array entry.

**Config:** `config/google_business.php` — `base_url`, `request_timeout`, `connect_timeout`, `review_limit` (mirroring `config/instagram.php`'s exact structure and comment style).

**Tests:** Guzzle `MockHandler`-based unit tests for both fetchers (mirroring `InstagramProfileFetcherTest`/`InstagramMediaFetcherTest`), a `GoogleBusinessConnectorTest` (mirroring `InstagramConnectorTest`, including the missing-Q&A-scope case and the empty-reviews case).

---

### Phase 2 — Analyst and Fact Derivation

- `GoogleBusinessAnalyst implements ObservationAnalyst` (`app/Services/Analyst/GoogleBusinessAnalyst.php`) — `supports()` matches `'google_business'` and `'google_business_reviews'`; `analyze()` dispatches to `analyzeProfile()`/`analyzeReviews()`, mirroring `InstagramAnalyst`'s exact dispatch pattern. Produces every Fact key in spec §4.2, including the deliberate `has_qanda_access` presence/absence distinction.
- Register in `AppServiceProvider`'s `AnalystRegistry` binding, alongside `WebsiteAnalyst`/`InstagramAnalyst` — one more array entry, no other change.
- `rating_trend`'s older-half/newer-half comparison logic directly mirrors `InstagramAnalyst::engagementTrendFact()`'s existing implementation (Milestone 12 Phase 2) — reuse that method's structure, don't re-derive it from scratch.

**Tests:** `GoogleBusinessAnalystTest` (profile Facts, mirroring `InstagramAnalystTest`) and a `GoogleBusinessReviewAnalystTest` (rating aggregation, trend computation, the malformed-payload and empty-reviews cases, mirroring `InstagramContentAnalystTest`'s structure closely — that test file is the most direct template, since reviews and posts are structurally similar: a list of dated items to aggregate). A `GoogleBusinessBusinessBrainIntegrationTest` mirroring both Milestone 12 Business Brain integration tests, proving zero `BusinessBrainService`/`ProcessObservation` changes are needed.

---

### Phase 3 — Marketing Presence Linkage and Existing Marketing Health Contribution

- No new code for `PresenceCoverageScorer` — verify via a **test only** (`PresenceCoverageScorerGoogleBusinessTest` or an addition to the existing scorer's test file) that a declared-and-linked `MarketingChannel(type: google_business_profile)` is included in its weighted ratio exactly like any other channel type, proving spec §5.1's "zero code change" claim rather than merely asserting it.
- Verify (again, test-only) that `MarketingPresenceService`'s existing linking logic (`specs/core/marketing-presence.md` §12) correctly links a `google_business_profile` `MarketingChannel` to the new `google_business` `Integration`/`Channel` pairing this phase introduces — this is the first real exercise of that generic linking logic for a channel type other than Instagram/Facebook, worth confirming explicitly rather than assuming.

---

### Phase 4 — New Opportunity Types

- Add `'review_milestone'`, `'reputation_risk'` to `opportunities.type`'s enum (base migration + Postgres constraint-rewrite migration, same pattern as every prior enum extension in this codebase).
- Add two new `campaigns.campaign_type` enum values (exact names decided at implementation time — spec §9 suggests `social_proof`/`reputation_response`) via the same two-migration pattern, plus one `match` arm each in `DecisionEngine::evaluate()`'s opportunity-type-to-campaign-type mapping and one entry each in its `COOLDOWN_DAYS` map (suggested starting points: `social_proof` → 30 days, matching the "don't repeat a milestone campaign too often" intent; `reputation_response` → 14 days, matching `re_engagement`'s existing cooldown).
- `ReviewMilestoneDetector implements OpportunityDetector` (`app/Services/Opportunity/Detectors/ReviewMilestoneDetector.php`) — reads `google_business.rating_average`/`.rating_count`, fires on configurable milestone thresholds (config, not code, per every other detector's constant-tuning precedent).
- `ReputationRiskDetector implements OpportunityDetector` — reads `google_business.rating_trend`, fires on a configurable decline threshold.
- Register both in `OpportunityEngine`'s detector collection (constructor injection list) — no interface change, no existing detector touched.
- **New content-generation prompt work required, out of pure-observation scope but necessary for these Opportunity types to produce real campaigns:** `ContentGenerationAnalyst`/`CampaignPreparationAnalyst` need prompt variants aware of the `social_proof`/`reputation_response` campaign types (a new `App\AI\Prompts\Campaign\...` or similar, following the existing per-campaign-type prompt pattern). This is real, non-trivial work and should be scoped as its own sub-phase with its own test pass, not folded silently into "add two detectors."

**Tests:** `ReviewMilestoneDetectorTest`, `ReputationRiskDetectorTest` (mirroring `ReEngagementDetectorTest`'s structure), `DecisionEngineTest` additions confirming the new campaign types flow through the existing five guards unchanged, and content-generation tests for the new prompt variants.

---

### Phase 5 — Proposed `ReputationScorer` (Marketing Health's Eighth Dimension)

This phase is explicitly **optional relative to Phases 1–4** — Google Business Intelligence delivers real value (Facts, Business Brain enrichment, Presence Coverage contribution, two new Opportunity types) without it. Implement only after Phases 1–4 are stable, or defer to a distinct follow-up milestone if scope needs to be cut.

- `ReputationScorer implements MarketingHealthScorer` (`app/Services/MarketingHealth/Scorers/ReputationScorer.php`), per spec §5.2's formula sketch. Register in `MarketingHealthServiceProvider`'s scorer array — one more entry, no other `MarketingHealthService`/`MarketingHealthRegistry` change (the registry already iterates "however many scorers exist").
- Add `'reputation'` to `marketing_health_scores.dimension`'s enum (base migration edit is not applicable here since that table was created fresh in Milestone 13 — this needs a genuine ALTER migration, since real company data will already exist in that table by the time this phase ships).
- Extend `resources/js/Pages/App/MarketingHealth.vue`'s `DIMENSION_LABELS`/`DIMENSION_ORDER` with the new dimension — a two-line Vue change, no structural change to that page.

**Tests:** `ReputationScorerTest` (mirroring the seven existing scorer test files' structure exactly — full-evidence, partial-evidence, N/A cases), `MarketingHealthServiceTest` additions confirming the composite formula (spec `Marketing-Health.md` §4.2) correctly incorporates an eighth dimension with no formula change.

---

## 5. Risks

| Risk | Mitigation |
|---|---|
| **Google Business Profile API access is genuinely harder to obtain than Instagram's.** Getting a real, working access token for a real business requires Google Cloud project setup and (for some endpoints) Google's own manual approval process for elevated API access. This could make even beta-scope manual-token testing slow to set up for a real design-partner business. | Flagged explicitly in the spec (§2.2) as a beta-scope constraint, not hidden. The implementing session should budget real time for obtaining working credentials before writing the first line of connector code, the same lesson already learned (per this project's own `docs/STATUS.md` history) from every "unverified against a real X App" disclaimer on Meta/WordPress work — do not assume the mocked-HTTP test suite passing means the real API integration works until it's been exercised against one real, working credential. |
| **Q&A API access may not exist at all for this integration.** Google has restricted this surface for most developers. | Spec §3.3 already designs for graceful degradation (an omitted field, not a false empty result). Phase 1's connector tests must include the "Q&A scope not granted" case as a first-class scenario, not an afterthought. |
| **New `campaign_type` enum values require real content-generation prompt work**, which is a meaningfully larger scope than "add a Fact key" — the temptation is to under-scope Phase 4 as "just two detectors." | Explicitly called out in Phase 4 above as its own sub-phase with its own tests. If time-constrained, ship Phases 1–3 (observation + Marketing Health contribution) alone as a first release and defer Phase 4 entirely — the milestone still delivers real value without new Opportunity types, exactly as Milestone 13 shipped its MVP (Phase 1) without touching the Opportunity/Decision Engine at all. |
| **Reviews data includes personally-identifiable reviewer names.** Atlas already handles company-scoped data carefully (tenancy isolation, encrypted credentials), but reviewer PII from a third party is a new category of data this codebase hasn't stored before. | Worth a deliberate decision at implementation time on retention (does reviewer PII get pruned on the same Observation raw-payload retention schedule as everything else, per `specs/core/domain-model.md`'s existing 30/90/180-day precedent, or does it need its own, shorter policy?) — flagged here so it isn't decided implicitly by whatever the default happens to be. |
| **`ReputationScorer` (Phase 5) requires an ALTER migration on a table that will already have real production data by the time this phase ships**, unlike Phases 1–4's fresh-table or fresh-enum-value additions. | Called out explicitly in Phase 5 above. Standard Postgres `ALTER TYPE ... ADD VALUE` (or the constraint-rewrite pattern already used elsewhere) handles this safely; the risk is forgetting that this table (unlike a brand-new one) has real rows, not that the migration mechanism itself is novel. |

---

## 6. Acceptance Criteria

- A company with a connected Google Business Profile integration produces both `google_business` and `google_business_reviews` Observations on sync, each processed into the correct, distinct Fact set per spec §4.2.
- `has_qanda_access` correctly distinguishes "never checked" (Fact absent) from "checked, unavailable" (`false`) — verified with a dedicated test, not just visual inspection.
- An empty review list produces rating/trend Facts gracefully omitted (not a zero-average, not an error) — mirroring Instagram Content Intelligence's empty-posts handling exactly.
- `PresenceCoverageScorer` includes a linked Google Business Profile channel with zero code change — proven by test, not assumed.
- `ReviewMilestoneDetector`/`ReputationRiskDetector` respect the existing deduplication/cooldown guards and produce real, approvable Campaigns end-to-end (if Phase 4 ships) through the unchanged five-guard Decision Engine.
- If Phase 5 ships: `ReputationScorer` integrates into the composite score formula with zero changes to `MarketingHealthService::compositeFor()`.
- No existing test in `InstagramConnectorTest`, `InstagramAnalystTest`, any of the seven existing `MarketingHealthScorer` test files, or any existing `OpportunityDetector` test file regresses.
- `php artisan test`, `./vendor/bin/phpstan analyse --memory-limit=1G`, `./vendor/bin/pint --test`, `npx vue-tsc --noEmit`, `npx vitest run`, `npm run build` all pass clean, and (per this project's now-established discipline) every new migration is verified against a real local PostgreSQL instance, not just sqlite.
- Tenant isolation: two companies' Google Business data never cross-contaminate, verified the same explicit two-company way every other feature in this codebase already is.

---

## 7. What This Milestone Does Not Prove

- That the seven-Fact-key set chosen here (§4.2 of the spec) is the right set for real Google Business Profile data shapes — the API's actual response shapes should be verified against a real account before or during Phase 1, not assumed correct from documentation alone.
- That `review_milestone`/`reputation_risk` campaigns actually perform well or feel right to a real business owner — that requires real usage data this platform doesn't have yet (no production deployment, per `docs/plans/Version-1.0-Roadmap.md`'s Stage A gate).
- That the proposed `ReputationScorer` formula (§5.2 of the spec) is correctly weighted — like every Marketing Health dimension's constants, it's configuration to be tuned from real evidence later, not a claim of correctness now.
