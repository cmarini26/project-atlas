# Milestone 13 — Marketing Health Engine
## Implementation Plan

**Status:** Plan — not yet implemented
**Author:** Claude Sonnet 5
**Date:** 2026-07-12
**Prerequisite:** Milestone 11 (Marketing Presence) and Milestone 12 (Instagram Observation + Content Intelligence, both phases) complete.
**Specification:** `docs/specs/Marketing-Health.md` — authoritative domain spec for this milestone. Read it first; this document sequences its implementation and does not restate its reasoning.

---

## 1. What We Are Building

A deterministic Marketing Health subsystem, sitting between the Business Brain and the Opportunity Engine, that scores a company's marketing across seven dimensions (Website Health, Social Activity, Campaign Consistency, Brand Consistency, Content Diversity, CTA Strength, Marketing Presence Coverage), computes a confidence-weighted composite, and feeds the result into three existing pipelines — Opportunity detection, Decision Engine prioritization, and Recommendation rationale — without modifying any of their existing contracts.

Concretely, this milestone adds:

- Two new tables (`marketing_health_scores`, `marketing_health_snapshots`) and their models.
- Seven `MarketingHealthScorer` implementations, one per dimension, plus the registry/service that runs them and persists results.
- A new `LowMarketingHealthDetector` (`OpportunityDetector`) and a Marketing-Health-derived entry composed into the existing `typeModifiers` map `OpportunityScorer` already consumes.
- A Marketing-Health-aware tiebreaker consulted by `DecisionEngine` among already-guard-passed candidates (never a new guard).
- `BusinessBrain` gains a `marketingHealth` field, read by `RationaleGenerationAnalyst` as additional context.
- A new "Marketing Health" settings-adjacent page plus a Dashboard summary card (read-only; no editing UI — there is nothing for a user to configure, only to observe).
- A scheduled daily recompute job, alongside recompute-on-`ProcessObservation`.

At the end of this milestone, every company with any observed marketing activity has a live, explainable, evidence-backed health score, visible in the UI, and Atlas's opportunity detection and recommendation rationale both get measurably better at explaining *why now* by citing that score — without a single existing detector, the base opportunity scoring formula, or the Decision Engine's guard conditions changing.

---

## 2. What We Are NOT Building

Restating `docs/specs/Marketing-Health.md` §11's boundaries at the implementation level:

- **No AI-based scoring.** Every `MarketingHealthScorer` is pure PHP arithmetic over stored Facts/Knowledge/Campaigns — no `AiProvider` call anywhere in this milestone's code.
- **No new connectors.** Google Business Profile, LinkedIn, Facebook, Search Console, and GA4 are not touched. The architecture is built so they *will* plug in automatically once they exist (spec §7) — none of them exist after this milestone either.
- **No sixth Decision Engine guard.** `DecisionEngine::evaluate()`'s five existing guards (`app/Services/Decision/DecisionEngine.php`) are not modified, and Marketing Health cannot cause a candidate that would otherwise become a Decision to be rejected.
- **No change to `Decision.confidence_score` computation.** It continues to pass through from `Opportunity.confidence_score` unchanged.
- **No change to any existing `OpportunityDetector`** (`FeaturedItemDetector`, `UrgencyDetector`, `NewArrivalDetector`, `ReEngagementDetector`) or to the `OpportunityDetector` interface.
- **No change to `OpportunityScorer`'s base formula** (`relevance × 0.30 + timing × 0.25 + confidence × 0.25 + urgency × 0.20`) or its signature beyond composing into the existing `$typeModifiers` map it already accepts.
- **No backfilled trend history.** `MarketingHealthSnapshot` rows start accumulating from the first recompute after this ships.
- **No editing UI.** The new Marketing Health page is entirely read-only — there are no user-settable inputs to this subsystem.
- **No code is written as part of the task that produced this plan.** This document and the spec are the complete deliverable of that task. Implementation begins in a future session against this plan.

---

## 3. Dependencies

- `docs/specs/Marketing-Health.md` — domain model, dimension definitions, scoring formulas, integration points, evidence model
- `specs/core/domain-model.md` — `BusinessBrain`, `Fact`/`Knowledge` conventions, tenancy (`HasUlids`, `BelongsToCompany`, ULID PKs), event-naming conventions
- `specs/core/opportunity-engine.md` — `OpportunityDetector` contract, `OpportunityCandidate` shape, deduplication/cooldown rules (all unchanged, only referenced)
- `docs/specs/Marketing-Intelligence.md` — the `instagram.*` Fact-key conventions Social Activity reads, and the source-agnostic pattern (`ObservationAnalyst` + `AnalystRegistry`) this milestone's own architecture leans on for future-connector extensibility
- Existing code: `App\Services\Opportunity\OpportunityEngine`, `OpportunityScorer`, `App\Services\Decision\DecisionEngine`, `MarketingChannelSelector`, `App\Services\Recommendation\RecommendationService`, `App\Services\Analyst\RationaleGenerationAnalyst`, `App\Services\Brain\BusinessBrainService`, `App\Services\Learning\WeightCalibrator`, `App\Models\MarketingChannel`

---

## 4. Implementation Sequence

### Phase 1 — Domain Model

**Migrations**

`database/migrations/<timestamp>_create_marketing_health_scores_table.php`

```php
Schema::create('marketing_health_scores', function (Blueprint $table): void {
    $table->char('id', 26)->primary();
    $table->char('company_id', 26)->index();
    $table->enum('dimension', [
        'website', 'social_activity', 'campaign_consistency', 'brand_consistency',
        'content_diversity', 'cta_strength', 'presence_coverage',
    ]);
    $table->unsignedTinyInteger('score');
    $table->unsignedTinyInteger('confidence');
    $table->json('evidence'); // list<MarketingHealthEvidence>, denormalized — see spec §5.2
    $table->timestamp('computed_at');
    $table->boolean('is_current')->default(true);
    $table->char('superseded_by_id', 26)->nullable();
    $table->timestamps();

    $table->index(['company_id', 'dimension', 'is_current']); // primary query path, mirrors facts' (company_id, key, is_current)
    $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
    $table->foreign('superseded_by_id')->references('id')->on('marketing_health_scores')->nullOnDelete();
});
```

`database/migrations/<timestamp>_create_marketing_health_snapshots_table.php`

```php
Schema::create('marketing_health_snapshots', function (Blueprint $table): void {
    $table->char('id', 26)->primary();
    $table->char('company_id', 26)->index();
    $table->unsignedTinyInteger('composite_score')->nullable(); // null when every dimension is N/A
    $table->json('dimension_scores'); // {dimension: {score, confidence}} at computation time
    $table->timestamp('computed_at');
    $table->timestamp('created_at'); // append-only — no updated_at, mirrors Observation's no-soft-delete rationale

    $table->index(['company_id', 'computed_at']); // trend-query path
    $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
});
```

**Models:** `App\Models\MarketingHealthScore` (casts `evidence` to `array`, `HasUlids`, `BelongsToCompany`, a `scopeCurrent()` mirroring `Fact::scopeCurrent()`), `App\Models\MarketingHealthSnapshot` (casts `dimension_scores` to `array`, `HasUlids`, `BelongsToCompany`, no update-related scopes since rows are immutable).

**Config:** `config/marketing_health.php` — per-dimension tunables (decay curves, target cadences, ceilings), mirroring `config/instagram.php`'s existing structure and comment style.

---

### Phase 2 — Scorer Contracts and Dimension Implementations

**Contracts** (`App\Services\MarketingHealth\Contracts\`):

```php
interface MarketingHealthScorer
{
    public function dimension(): string;
    public function score(Company $company, BusinessBrain $brain): ?MarketingHealthScoreResult;
}
```

`MarketingHealthScoreResult` and `MarketingHealthEvidence` as readonly value objects (`App\Domain\MarketingHealth\ValueObjects\`), per spec §4.1/§5.2.

**Seven scorer classes** (`App\Services\MarketingHealth\Scorers\`), one per dimension per spec §3's table:

- `WebsiteHealthScorer` — reads `business.*` Facts and the company's most recent `crawl`-type `Observation`s (recency decay + core-Fact presence + trailing crawl success rate).
- `SocialActivityScorer` — reads `instagram.posting_cadence`/`instagram.content_distribution` against a configured target cadence; written per spec §7's discipline (a documented, explicit list of Fact-key prefixes to check, currently `['instagram']`, so a future platform only appends a string here).
- `CampaignConsistencyScorer` — reads `marketing.days_since_last_campaign` Fact (falling back to `BusinessBrain.recentCampaigns`, mirroring `ReEngagementDetector`'s own existing fallback pattern) against a configured decay ceiling.
- `BrandConsistencyScorer` — reads `Company.brand` (voice/tone) against `ContentAsset.metadata` tone fields across the last N assets.
- `ContentDiversityScorer` — reads `ContentAsset.type` distribution and `instagram.media_mix`, scored via an evenness measure.
- `CtaStrengthScorer` — reads `instagram.cta_usage` and `Campaign.call_to_action` presence.
- `PresenceCoverageScorer` — reads `MarketingChannel` rows (status/importance/objective), reusing `MarketingChannelCapabilityResolver`'s existing query patterns where applicable rather than re-deriving them.

Each scorer is independently unit-testable against a hand-built `BusinessBrain` fixture — no scorer depends on another scorer's output.

---

### Phase 3 — MarketingHealthService and Registry

`App\Services\MarketingHealth\MarketingHealthRegistry` — a plain array-of-scorers holder, mirroring `AnalystRegistry`'s and `ChannelRendererRegistry`'s existing `for()`/iteration pattern (there is no "resolve one" here — every scorer always runs, so this registry's job is simpler: hold the list, let the service iterate it).

`App\Services\MarketingHealth\MarketingHealthService`:
- `recompute(Company $company): MarketingHealthSnapshot` — runs every registered scorer, persists each `MarketingHealthScore` (superseding the prior current row per dimension, exactly like `FactService::storeExtracted()`), computes the confidence-weighted composite (spec §4.2), persists one `MarketingHealthSnapshot`.
- `currentFor(Company $company): Collection<MarketingHealthScore>` — the read path `OpportunityEngine`/`DecisionEngine`/`RationaleGenerationAnalyst` all use.

**Trigger wiring:**
- `ProcessObservation` gains one additional call, `$marketingHealthService->recompute($company)`, alongside its existing `KnowledgeService::synthesizeForCompany()` call — same job, same transaction boundary, no new event needed.
- New scheduled job `App\Jobs\RecomputeMarketingHealth` (daily, per active company), for date-decay dimensions that need to move without a new Observation arriving — registered in the scheduler alongside the existing `DetectOpportunities`/integration-resync jobs.

---

### Phase 4 — Opportunity Engine Integration

- New `LowMarketingHealthDetector implements OpportunityDetector` (`App\Services\Opportunity\Detectors\`) — reads `MarketingHealthService::currentFor()`, fires a new `health_gap` opportunity type when a dimension has been below a configured threshold for a configured duration. Registered into the existing detector collection exactly like the other four detectors are (constructor injection list — no interface change).
- `OpportunityEngine::scan()` gains one additional read (`MarketingHealthService::currentFor($company)`) used to derive a small modifier merged into the `$typeModifiers` array already passed to `OpportunityScorer::score()` — composing into the existing map `WeightCalibrator` populates, not a second competing parameter.
- New `Opportunity.type` enum value: `health_gap`. New `Decision.campaign_type` mapping entry for it in `DecisionEngine::evaluate()`'s existing `match` (the campaign-type-per-opportunity-type table) — this single line is the only touch to `DecisionEngine.php` this phase makes.

---

### Phase 5 — Decision Engine Tiebreaking

- New `App\Services\Decision\MarketingHealthTiebreaker` (naming deliberately distinct from "guard" — see spec §6.2 on why this is not a sixth guard), injected into `DecisionEngine` alongside `MarketingChannelSelector`, consulted only when multiple candidates have passed all five existing guards in the same evaluation pass, to prefer the one whose campaign type would most improve the company's currently-weakest relevant dimension.
- No change to guard order, guard count, or the loop's early-return-on-first-pass structure beyond this tiebreak read.

---

### Phase 6 — BusinessBrain and Rationale Integration

- `BusinessBrain` (`App\Domain\BusinessBrain\BusinessBrain`) gains a new constructor parameter, `?MarketingHealthSnapshot $marketingHealth`, nullable and defaulted, so every existing call site that constructs a `BusinessBrain` without it keeps compiling (test fixtures across Milestones 11/12 construct this object directly — confirmed this is the established convention already tolerated for optional fields like `marketingPresence`).
- `BusinessBrainService::assemble()` populates it via `MarketingHealthService::currentFor()`.
- `RationaleGenerationAnalyst::analyze()`'s prompt-building step gains the health context as additional structured input — no new prompt version scheme beyond the existing `promptVersion()` bump convention every prompt class already follows when its input shape changes.

---

### Phase 7 — UI (read-only)

- New Inertia page, `App/Settings/MarketingHealth/Index.vue` (or a Dashboard-adjacent route — exact placement decided at implementation time, not fixed here), fed by a new `MarketingHealthController::index()` serializing the current snapshot + dimension scores + evidence, following `MarketingPresenceController::index()`'s existing serialization pattern.
- Dashboard gains a compact summary card (reusing `SummaryCard`'s existing `icon`/`accent` props, shipped in the recent visual-direction-refresh work) showing the composite score or the "Not enough data yet" empty state.
- Trend chart is **design only** in this milestone (spec §9) — the `MarketingHealthSnapshot` time-series query is straightforward once rows exist, but no charting library integration is scoped here.

---

### Phase 8 — Tests

- Unit tests per scorer (7 classes × several cases each: full evidence, partial evidence, no evidence → N/A), following the existing `InstagramContentAnalystTest`-style fixture-driven pattern.
- `MarketingHealthServiceTest` — recompute persists correct supersession, composite formula matches spec §4.2 exactly (including the all-N/A → null composite case).
- `LowMarketingHealthDetectorTest` — fires only when threshold/duration conditions are met, respects existing dedup/cooldown guards unchanged.
- `OpportunityEngineTest` additions — confirms existing detectors' candidates are scored identically with and without Marketing Health's modifier contribution absent (regression safety).
- `DecisionEngineTest` additions — confirms the tiebreaker never rejects a candidate that would otherwise have been chosen (only reorders among ties).
- `RationaleGenerationAnalystTest` additions — confirms `BusinessBrain.marketingHealth` reaching the prompt does not break the four-key rationale structural requirement.
- `MarketingHealthControllerTest` — prop shape, tenant isolation (two companies, no cross-contamination — same pattern as every other controller test this session already established).
- Vitest specs for the new Vue page/card, following `MarketingPresence/Index.spec.ts`'s existing conventions.

---

## 5. File Structure After Milestone 13

```
app/
  Domain/
    MarketingHealth/
      ValueObjects/
        MarketingHealthScoreResult.php
        MarketingHealthEvidence.php
  Models/
    MarketingHealthScore.php
    MarketingHealthSnapshot.php
  Services/
    MarketingHealth/
      Contracts/
        MarketingHealthScorer.php
      Scorers/
        WebsiteHealthScorer.php
        SocialActivityScorer.php
        CampaignConsistencyScorer.php
        BrandConsistencyScorer.php
        ContentDiversityScorer.php
        CtaStrengthScorer.php
        PresenceCoverageScorer.php
      MarketingHealthRegistry.php
      MarketingHealthService.php
    Decision/
      MarketingHealthTiebreaker.php
    Opportunity/
      Detectors/
        LowMarketingHealthDetector.php
  Jobs/
    RecomputeMarketingHealth.php
  Http/
    Controllers/
      App/
        MarketingHealthController.php
database/
  migrations/
    <timestamp>_create_marketing_health_scores_table.php
    <timestamp>_create_marketing_health_snapshots_table.php
config/
  marketing_health.php
resources/
  js/
    Pages/
      App/
        Settings/
          MarketingHealth/
            Index.vue
tests/
  Unit/MarketingHealth/
    (one test file per scorer)
  Feature/
    MarketingHealth/
      MarketingHealthServiceTest.php
      LowMarketingHealthDetectorTest.php
    App/
      MarketingHealthControllerTest.php
docs/
  specs/
    Marketing-Health.md          (this milestone's spec — already written)
  plans/
    Milestone-13-Marketing-Health-Plan.md   (this document)
```

---

## 6. Acceptance Criteria

- A company with a crawled website, a connected Instagram account with recent posts, at least one Campaign, and declared Marketing Presence channels produces a non-null composite score and all seven dimension scores (or explicit, correct N/A for any genuinely unobserved dimension).
- A brand-new company with zero Observations produces an entirely N/A state (composite null, every dimension null) — never a 0.
- Every dimension score's `evidence` array, when inspected, cites real, existing Fact/Knowledge/Campaign/Observation ids — no evidence entry is synthesized prose without a traceable source.
- `LowMarketingHealthDetector` fires a real `health_gap` Opportunity, scored through the unchanged `OpportunityScorer`, and is subject to the same deduplication/cooldown rules every other opportunity type already respects.
- No existing test in `OpportunityEngineTest`, `DecisionEngineTest`, `OpportunityScorerTest`, or any of the four existing detector test files regresses.
- `php artisan test`, `./vendor/bin/phpstan analyse --memory-limit=1G`, `./vendor/bin/pint --test`, `npx vue-tsc --noEmit`, `npx vitest run`, and `npm run build` all pass clean.
- Tenant isolation: two companies' Marketing Health scores never cross-contaminate, verified the same way every other company-scoped feature in this codebase already is (explicit two-company test).

---

## 7. Open Questions for the Implementing Session

- Exact decay-curve shapes (linear vs. exponential) for Website Health recency and Campaign Consistency gap-based scoring — spec §3 sketches the inputs; the implementing session should pick concrete, testable functions and document the choice in `config/marketing_health.php`'s comments, per that file's own established documentation style.
- Exact placement of the new "Marketing Health" page in navigation (its own top-level nav item vs. nested under Marketing Presence) — a UX decision better made with the shipped visual-direction-refresh navigation grouping (Understand/Act/Measure) in front of the implementer, not guessed here.
- Whether `LowMarketingHealthDetector`'s threshold/duration should be per-company configurable (via `Company.settings`) or global config-only for Version 1 — the spec doesn't require per-company tuning; the implementing session should default to global config unless a concrete need surfaces during Phase 4.

---

## 8. What This Milestone Does Not Prove

Restating the discipline established in Milestone 11's own plan: this milestone proves Marketing Health can be *computed, stored, and consumed* by the existing loop without breaking it. It does not prove:

- That the seven dimensions, weights, or decay curves chosen here actually correlate with real business outcomes — that requires real customer data over time, which does not exist yet (the platform has no production deployment, per `docs/plans/Version-1.0-Roadmap.md`'s own Stage A gate).
- That a future connector (Google Business, LinkedIn, Facebook, Search Console, GA4) will in fact require *zero* Marketing Health code changes to contribute — spec §7's discipline is designed to make this true, but the first real future connector is the actual test of that claim, not this milestone.
- That surfacing a health score to a business owner changes their behavior in a way that improves outcomes — that's a product hypothesis this milestone makes measurable (via the UI), not one it validates.
