# Marketing Health — Design Specification

**Milestone:** 13
**Status:** Design only — no code exists yet
**Read first:** `specs/core/domain-model.md`, `docs/specs/Marketing-Intelligence.md`, `docs/STATUS.md`, `docs/plans/Version-1.0-Roadmap.md`
**Companion:** `docs/plans/Milestone-13-Marketing-Health-Plan.md` — sequences this spec into implementation phases; read this document first.

---

## 1. What Marketing Health Is

Marketing Health is a **deterministic scoring subsystem** that sits between the Business Brain and the Opportunity Engine. It answers a question Atlas cannot currently answer at all: *"Overall, how healthy is this business's marketing, right now, and in which specific ways?"*

Today, Atlas accumulates Facts (`business.*`, `catalog.*`, `instagram.*`, `marketing.*`, …) and detects Opportunities from them, but nothing looks across all of a company's accumulated evidence and synthesizes a single, structured, trustworthy answer to "how are they doing overall." The Opportunity Engine's detectors each look at a narrow slice (a catalog item, a countdown, a days-since-last-campaign gap) — none of them assess the business's marketing holistically. Marketing Health fills that gap, and having filled it, feeds back into detection, prioritization, and rationale so Atlas's recommendations get sharper as it knows more.

**Marketing Health is not a new Fact source.** It reads Facts and Knowledge that already exist (from the website crawl, Instagram Content Intelligence, Marketing Presence declarations, campaign history) and produces a new, synthesized layer on top — exactly the same relationship `KnowledgeService::synthesizeForCompany()` already has to raw Facts, one level up.

**Marketing Health is not AI-scored, in Version 1.** Every dimension score is computed by a deterministic formula over stored evidence — the same design choice already made for `InstagramAnalyst` (Milestone 12) and `MarketingPresenceSynthesizer` (Milestone 11): when the input is already structured, scoring it is arithmetic, not inference. This keeps scores explainable (every point traces to a specific Fact), reproducible (same evidence → same score, always), and cheap to compute on every Digital Twin refresh without an AI provider round-trip or its associated cost/latency/failure modes. AI-assisted health narrative (turning a score into prose) is an explicit future-phase idea (§12), never a scoring input.

---

## 2. Domain Model

### 2.1 New entities

**`MarketingHealthScore`** — one row per company, per dimension, per computation. Mirrors the Fact/Knowledge supersession pattern already used everywhere else in this codebase (`is_current`, never deleted, new row supersedes old).

| Column | Type | Notes |
|---|---|---|
| `id` | ulid | PK |
| `company_id` | ulid | FK |
| `dimension` | enum | see §3 — one of the seven dimension keys |
| `score` | tinyint | 0–100 |
| `confidence` | tinyint | 0–100; how much evidence backed this score (see §5) |
| `evidence` | json | ordered list of `MarketingHealthEvidence`-shaped entries (see §5.2) — denormalized onto the score row so historical scores remain self-explanatory even after the underlying Facts are pruned |
| `computed_at` | timestamp | when this score was computed |
| `is_current` | boolean | exactly one current row per `(company_id, dimension)` |
| `superseded_by_id` | ulid | FK to `marketing_health_scores`, nullable |
| `created_at` / `updated_at` | timestamp | |

**`MarketingHealthSnapshot`** — one row per company, per computation run, holding the composite score and a pointer to the dimension scores that produced it. This is what "Overall Marketing Health" and "trend over time" (§9) are built from — a `MarketingHealthScore` row only ever represents the *current* state of one dimension, but a `MarketingHealthSnapshot` is an immutable point-in-time record, which is what a trend chart needs.

| Column | Type | Notes |
|---|---|---|
| `id` | ulid | PK |
| `company_id` | ulid | FK |
| `composite_score` | tinyint | 0–100 — see §4 for the weighting formula |
| `dimension_scores` | json | `{dimension: {score, confidence}}` snapshot at computation time (denormalized, same reasoning as `MarketingHealthScore.evidence`) |
| `computed_at` | timestamp | |
| `created_at` | timestamp | snapshots are never updated or superseded — append-only, which is exactly what a trend line needs |

Two tables, not one, for the same reason `Opportunity` and `Decision` are two tables and not one: a dimension score is a *current value with history via supersession* (like a Fact); a snapshot is an *immutable point-in-time record* (like nothing else in the domain model today, which is precisely why trend-over-time has nowhere to live without it).

### 2.2 Relationship to existing entities

```
Company
  ├── Fact (1:N) ──────────────┐
  ├── Knowledge (1:N) ─────────┤── read by MarketingHealthService, never written to
  ├── MarketingChannel (1:N) ──┘
  ├── MarketingHealthScore (1:N, one current per dimension)
  ├── MarketingHealthSnapshot (1:N, append-only)
  ├── Opportunity (1:N) ── OpportunityEngine reads current MarketingHealthScores
  └── Decision (1:N) ──── DecisionEngine reads current MarketingHealthScores
```

Marketing Health sits *beside* Knowledge, not inside it — see `docs/plans/Milestone-13-Marketing-Health-Plan.md` §"Why not just Knowledge entries" for the rejected alternative and why a first-class table wins here despite Knowledge being the closer conceptual sibling.

### 2.3 Fact namespace

Any *new* Facts Marketing Health itself produces (as opposed to scores it reads) live under `marketing_health.*`, consistent with the existing per-domain namespacing (`instagram.*`, `catalog.*`, `channel_performance.*`). In Version 1, Marketing Health is expected to be a pure *reader* of Facts/Knowledge and a *writer* only of `MarketingHealthScore`/`MarketingHealthSnapshot` rows — it does not need to also write Facts back, because `OpportunityDetector`s and the Decision Engine will read `MarketingHealthScore` directly (§6), not go looking for a Fact. The namespace is reserved here in case a future phase needs a scalar health value available to something that only knows how to read Facts (e.g. a prompt builder that serializes `BusinessBrain.activeFacts` generically).

---

## 3. The Seven Health Dimensions

Each dimension is scored independently, 0–100, by a dedicated deterministic scorer reading specific, named evidence. "N/A — insufficient evidence" (not zero) is a valid outcome for any dimension when nothing has ever been observed for it — a company with no crawled website has no Website Health score, not a Website Health score of 0. This distinction matters enormously for §4 (composite weighting) and §7 (recommendation rationale): a business that hasn't connected Instagram is not being told its social marketing is *bad*, it's being told Atlas *doesn't know yet* — a different, honest, and actionable message.

| Dimension | Enum key | Primary evidence source | Formula sketch |
|---|---|---|---|
| **Website Health** | `website` | Website crawl Facts (`business.*`), `Observation` freshness (`source_type: 'crawl'`) | Recency of last successful crawl (decay curve) + presence of core Facts (`business.name`, `.description`, `.industry`) + crawl success rate over trailing N attempts |
| **Social Activity** | `social_activity` | Instagram content Facts (`instagram.posting_cadence`, `.content_distribution`) — Milestone 12 Phase 2 | Posting cadence vs. a configurable target cadence (e.g. 2×/week), scaled 0–100; N/A if no `social_content` Observation has ever existed |
| **Campaign Consistency** | `campaign_consistency` | `Campaign` history (`recentCampaigns` on `BusinessBrain`), `marketing.days_since_last_campaign` Fact | Gap-based decay: full score at ≤7 days since last campaign, decaying to 0 by a configurable ceiling (e.g. 60 days) |
| **Brand Consistency** | `brand_consistency` | `Company.brand` JSON (voice/tone/colors), `ContentAsset.metadata` tone fields across recent assets | Agreement between declared brand voice/tone and the tone actually used in generated content over the last N assets — a same-vs-different comparison, not sentiment analysis |
| **Content Diversity** | `content_diversity` | `ContentAsset.type` distribution across recent Campaigns, `instagram.media_mix` | Shannon-style evenness measure over the type/media-type distribution — a business posting only `IMAGE` every time scores lower than one mixing `IMAGE`/`VIDEO`/`CAROUSEL_ALBUM`, independent of volume |
| **CTA Strength** | `cta_strength` | `instagram.cta_usage`, presence of `call_to_action` on `Campaign` rows | Percentage of recent content with a detectable, non-generic call-to-action |
| **Marketing Presence Coverage** | `presence_coverage` | `MarketingChannel` rows (Milestone 11) — status/importance/objective | Ratio of declared `active` channels with at least one fulfilled `objective`, weighted by `importance` (`primary` counted more than `secondary`/`experimental`) |

Each dimension's exact formula constants (decay curves, target cadences, ceilings) are configuration, not code — `config/marketing_health.php`, mirroring `config/instagram.php`'s existing per-domain config file pattern — so they can be tuned per learnings without a deploy touching scoring logic itself.

### 3.1 Why these seven, and only these, for Version 1

Every one of the seven reads evidence that **already exists in the codebase today** (confirmed against `specs/core/domain-model.md`, `docs/specs/Marketing-Intelligence.md`, and the Milestone 11/12 shipped work) — no dimension in this list is waiting on a connector that doesn't exist yet. That is a deliberate constraint, not an oversight: a health dimension with no real evidence source would either be permanently N/A (useless) or would tempt a placeholder/AI-guessed value (exactly what §1 rules out for Version 1). Dimensions that *do* require future connectors (SEO/Search visibility, paid media efficiency, email list health, review/reputation signals) are listed as future dimensions in §12, explicitly deferred, not designed here.

---

## 4. Scoring Model

### 4.1 Dimension scores

Each `MarketingHealthScorer` (one class per dimension, see §6.1) implements a common contract:

```php
interface MarketingHealthScorer
{
    public function dimension(): string;

    /** Null when there is not enough evidence to score this dimension at all. */
    public function score(Company $company, BusinessBrain $brain): ?MarketingHealthScoreResult;
}

readonly class MarketingHealthScoreResult
{
    public function __construct(
        public int $score,           // 0-100
        public int $confidence,      // 0-100 — see §5.1
        public array $evidence,      // list<MarketingHealthEvidence> — see §5.2
    ) {}
}
```

This is intentionally the same shape as `OpportunityDetector::detect()` (returns candidates, or none) and `MarketingPresenceSynthesizer` (deterministic, BusinessBrain-driven, no AI) — a new engineer who already understands either of those already understands this.

### 4.2 Composite score

The overall Marketing Health score is a **confidence-weighted average**, not a simple mean — a dimension Atlas barely has evidence for should not drag the composite down (or up) as hard as one it has deep evidence for:

```
composite = Σ(dimension.score × dimension.confidence) / Σ(dimension.confidence)
```

computed only over dimensions that are not N/A. If every dimension is N/A (a brand-new company with nothing observed yet), the composite itself is N/A — there is no health score to show, and the UI (§9) must render that as "Not enough data yet," never as a 0.

This is a genuinely different formula from the Opportunity Engine's fixed-weight composite (`relevance × 0.30 + timing × 0.25 + confidence × 0.25 + urgency × 0.20`, `OpportunityScorer.php:31`) on purpose: Opportunity scoring weights four *always-present* components of a single candidate, while Marketing Health weights a *variable subset* of seven independent dimensions where absence is common and meaningful. Reusing the fixed-weight approach here would silently either overweight N/A-as-zero or require inventing a placeholder score for a dimension with no evidence — both wrong per §1 and §3.

### 4.3 Recomputation cadence

Recomputed on the same trigger `KnowledgeService::synthesizeForCompany()` already runs on — after `ProcessObservation` completes (new Fact available) — plus a scheduled daily recompute per company (so Campaign Consistency's day-based decay moves even without a new Observation arriving), mirroring the existing `DigitalTwin` staleness/health-score-recompute precedent already described in `specs/core/domain-model.md`'s `DigitalTwin.health_score` note ("recomputed by a scheduled job … not a DB-computed column").

---

## 5. Evidence Model

### 5.1 Confidence, per dimension

Confidence is not a subjective quality judgment — it's a deterministic function of **how much evidence backs the score**, matching the existing `Fact.confidence`/`Knowledge.confidence` convention (0–100, tinyint). For example, Website Health's confidence scales with how many of its three inputs (recency, core-Fact presence, crawl success rate) are actually available; Social Activity's confidence scales with how many posts were fetched (a `posting_cadence` computed from 20 posts is more trustworthy than one computed from 2 — recall `InstagramAnalyst::postingCadenceFact()` already requires ≥2 posts and a ≥1-day span before it produces a fact at all, `app/Services/Analyst/InstagramAnalyst.php`).

### 5.2 Evidence entries

Every dimension score carries an ordered list of the specific evidence that produced it — this is what "Supporting evidence" in the UI (§9) renders, and what makes every score auditable back to a real Fact or Knowledge row, never an opaque number:

```php
readonly class MarketingHealthEvidence
{
    public function __construct(
        public string $label,        // "Last crawled 3 days ago"
        public string $sourceType,   // 'fact' | 'knowledge' | 'observation' | 'campaign'
        public ?string $sourceId,    // the Fact/Knowledge/Observation/Campaign id, when one exists
        public mixed $value,         // the raw value that drove this evidence entry
    ) {}
}
```

Stored denormalized on both `MarketingHealthScore.evidence` and folded into `MarketingHealthSnapshot.dimension_scores` (§2.1) specifically so a score computed today remains fully explainable even after its source Fact is pruned by the existing 30/90/180-day Observation/raw-payload retention jobs (`specs/core/domain-model.md`'s `Observation` notes) — the evidence *summary* outlives the raw payload, the same tradeoff `Knowledge.structured` already makes relative to the Facts that produced it.

---

## 6. Integration: How Marketing Health Influences the Rest of the Loop

All three integration points below were confirmed against the actual current implementation (not assumed from spec docs alone) — file:line references are to the code as of Milestone 12 Phase 2.

### 6.1 Opportunity detection

**Mechanism:** a new, optional `MarketingHealthContext` passed alongside `BusinessBrain` into `OpportunityEngine::scan()`, used two ways:

1. **A new detector, not a new detector capability.** `LowMarketingHealthDetector implements OpportunityDetector` — a new detector class, following the exact existing contract (`app/Services/Opportunity/Detectors/Contracts/OpportunityDetector.php`), not a modification to the interface or to any existing detector. It fires a new opportunity type, `health_gap` (e.g. "Campaign Consistency has been below 40 for 21 days"), scored through the same `OpportunityScorer` every other candidate goes through. This is the lowest-risk integration point — it adds, it does not touch existing detectors.
2. **A post-scoring modifier, using the existing `typeModifiers` hook.** `OpportunityScorer::score()` already accepts an optional `$typeModifiers` array (`app/Services/Opportunity/OpportunityScorer.php:20,34`) applied as `composite = round(raw * modifier)` — currently populated only by `WeightCalibrator` from LearningEngine. Marketing Health composes into the *same* modifier map (not a second, competing multiplier) — e.g. a `featured_item` opportunity for a company with strong Content Diversity and CTA Strength gets a small positive nudge, reflecting "this business executes well, so back this recommendation with more confidence" — without rewriting `OpportunityScorer` or any detector's fixed component scores (per the research: "no detector performs component-level modifications" today, and this design deliberately keeps it that way).

**What this does not do:** no existing detector (`FeaturedItemDetector`, `UrgencyDetector`, `NewArrivalDetector`, `ReEngagementDetector`) is modified. No change to the `OpportunityDetector` interface. No change to the 0.30/0.25/0.25/0.20 base formula.

### 6.2 Decision Engine prioritization

**Mechanism:** follows the exact precedent `MarketingChannelSelector` already establishes — an injected service returning a value object the engine consumes, not inline logic in `DecisionEngine::evaluate()`. A new `MarketingHealthGuard` (or, more precisely, a *tiebreaker*, not a sixth guard condition — see below) is consulted once a candidate has passed all five existing guards (`app/Services/Decision/DecisionEngine.php:38-115`), to prefer, among multiple equally-viable candidates, the one whose Decision would most improve the company's weakest current dimension.

This is deliberately **not** a sixth guard condition (a candidate is never blocked from becoming a Decision because of Marketing Health) — it only affects ordering among already-eligible candidates, which matches how `DecisionEngine::evaluate()` already returns the first candidate to pass all guards from an already-priority-ordered `openForCompany()` query (`OpportunityRepository`). Marketing Health participates in that existing ordering, it doesn't add a new rejection path — rejecting a real, valid opportunity because of an unrelated health dimension would be a confusing, hard-to-explain UX regression, not an improvement.

**Confidence score:** `DecisionService::commit()` currently carries `Decision.confidence_score` straight through from `Opportunity.confidence_score` with no adjustment (confirmed: no existing hook for Decision-level confidence recalculation). This spec does not change that in Version 1 — Marketing Health influences *which* opportunity gets picked (§6.1, §6.2 above), not the confidence number stamped on the resulting Decision. Revisiting this is an explicit future-phase idea (§12), not silently bundled in here.

### 6.3 Recommendation rationale

**Mechanism:** Marketing Health scores are added to the `BusinessBrain` context passed into `RationaleGenerationAnalyst` (`app/Services/Analyst/RationaleGenerationAnalyst.php`) as additional structured input — the analyst already receives the full `BusinessBrain`, so this is an additive context field, not a new AI call or a new pipeline stage. A strong or weak dimension relevant to the campaign type becomes citable evidence inside the existing `why_now`/`why_this`/`why_channel`/`why_works` structure the AI already produces (e.g. `why_now` citing "Campaign Consistency has dropped to 35/100 — no campaign in 21 days" as part of the timing justification), rather than a new fifth rationale field. `DecisionService::validateRationale()`'s existing structural requirement (all four keys present and non-empty) is unchanged.

**What this does not do:** Marketing Health does not write directly into `Decision.rationale` — it's context available to the analyst that generates that rationale, same as every other `BusinessBrain` field already is. No new Knowledge entries of `type: 'marketing_health'` are needed for this to work in Version 1 (the analyst reads scores directly), keeping this integration point as simple as it can be.

---

## 7. Source-Agnostic Architecture

Every `MarketingHealthScorer` reads only `Company`/`BusinessBrain`/`Fact`/`Knowledge`/`Campaign`/`ContentAsset` — domain-model entities that are already source-agnostic by construction (a Fact doesn't know or care whether `WebsiteAnalyst` or `InstagramAnalyst` produced it; `BusinessBrainService::assemble()` already pulls Facts by `company_id` alone, confirmed in Milestone 12's own Business Brain integration tests). No scorer imports a connector-specific class (`InstagramConnector`, a future `GoogleBusinessConnector`, etc.) or a connector-specific Fact key by name where a dimension-neutral alternative exists.

Concretely, this means when a future connector ships (Google Business Profile, LinkedIn, Facebook Page insights, Search Console, GA4 — all explicitly named in this milestone's brief, all explicitly out of scope to build here), it contributes to Marketing Health **automatically and without a Marketing Health code change**, provided it follows the two conventions every connector already follows:

1. It produces `Fact`/`Knowledge` rows through the existing `ObservationAnalyst`/`Analyst` + `AnalystRegistry` pipeline (Milestone 12's own `InstagramAnalyst` extension is the reference example — a second `source_type` added to one analyst, zero changes to `ProcessObservation` or `AnalystRegistry`).
2. Those Facts use a namespace a scorer already reads generically. **Social Activity is the concrete proof this works today**: its scorer should be written against a dimension-neutral question ("has this company posted content on any social channel recently, and how consistently") answered today only by `instagram.posting_cadence`/`instagram.content_distribution`, but written so that a future `linkedin.posting_cadence` or `facebook.posting_cadence` Fact — produced by a connector that doesn't exist yet, following the same key-suffix convention — extends the same dimension without the scorer's formula changing, only its evidence-gathering query widening from one Fact-key prefix to a small, explicit list of them.

This is the one deliberate design discipline this spec asks of every dimension scorer: **query by a documented Fact-key convention, never by "is Instagram connected."** Practically, this shows up as each scorer's evidence-gathering step being a small, explicit, documented list of Fact-key prefixes (e.g. Social Activity's list starts as `['instagram']`, and grows by literally appending a string when a second platform ships — no branching logic, no new class).

---

## 8. Sequence Diagrams

### 8.1 Computation (triggered by ProcessObservation, mirrors KnowledgeService's own trigger)

```
Observation ──▶ ProcessObservation ──▶ AnalystRegistry::resolve() ──▶ Analyst::analyze()
                       │
                       ├──▶ FactService::storeExtracted()
                       ├──▶ KnowledgeService::synthesizeForCompany()   (existing, unchanged)
                       └──▶ MarketingHealthService::recompute($company)   (new)
                                  │
                                  ├──▶ for each MarketingHealthScorer:
                                  │        scorer->score($company, $brain)
                                  │        ──▶ MarketingHealthScore::create() (supersedes prior current row)
                                  │
                                  └──▶ MarketingHealthSnapshot::create()  (composite + denormalized dimension scores)
```

### 8.2 Scheduled daily recompute (for date-decay dimensions that move without a new Observation)

```
Scheduler (daily) ──▶ RecomputeMarketingHealth job ──▶ per active Company:
                              BusinessBrainService::for($company)
                              ──▶ MarketingHealthService::recompute($company)   (same path as 8.1)
```

### 8.3 Opportunity detection consuming Marketing Health

```
DetectOpportunities job ──▶ OpportunityEngine::scan($company, $brain)
        │
        ├──▶ MarketingHealthService::currentFor($company)   (new: fetch current dimension scores)
        │
        ├──▶ existing detectors (unchanged) ──▶ candidates
        ├──▶ LowMarketingHealthDetector (new) ──▶ candidates       (reads health scores directly)
        ├──▶ OpportunityDetectionAnalyst (unchanged) ──▶ AI candidates
        │
        └──▶ for each candidate:
                 typeModifiers = CompanyScoringWeights::current()->typeModifiers()
                                 merged with MarketingHealthService's derived modifier   (new, composes into same map)
                 OpportunityScorer::score($candidate, $typeModifiers)   (unchanged formula)
                 ──▶ persist Opportunity if composite ≥ 30
```

### 8.4 Decision Engine and rationale consuming Marketing Health

```
DecisionEngine::evaluate($company, $brain)
        │
        ├──▶ existing 5 guards (unchanged)
        ├──▶ MarketingChannelSelector::select()   (unchanged)
        ├──▶ MarketingHealthService::currentFor($company)   (new: tiebreak among eligible candidates)
        │
        └──▶ DecisionService::commit($context)
                     │
                     └──▶ RationaleGenerationAnalyst::analyze($opportunity, $decision, $brain)
                                  brain now additionally carries MarketingHealthScores  (new field on BusinessBrain)
                                  ──▶ why_now / why_this / why_channel / why_works   (unchanged structure)
```

---

## 9. UI Design (design only — no implementation in this milestone)

A new "Marketing Health" page, one level below the main navigation's existing Understand section (alongside Marketing Presence and Business Brain), plus a compact summary card surfaced on the Dashboard overview.

**Overall score:** a single 0–100 number with a qualitative label band (`Needs attention` <40, `Developing` 40–69, `Strong` ≥70 — exact cutoffs are configuration, not this spec's concern), rendered only when the composite is not N/A; an explicit "Not enough data yet — connect more sources" empty state otherwise, consistent with how Marketing Presence's own empty state already reads ("Atlas still works with zero declared channels, it simply has less business context").

**Individual category scores:** the seven dimensions as a card grid (mirroring the Dashboard's existing `SummaryCard`-with-`accent`-color pattern shipped in the recent visual-direction-refresh work), each showing its score, confidence, and an N/A state independently — a company might be `Strong` on Website Health and simultaneously N/A on Social Activity, and the UI must make that legible at a glance, not average it away silently.

**Supporting evidence:** clicking into a dimension expands its `MarketingHealthEvidence` list (§5.2) as a plain, readable list — "Last crawled 3 days ago," "Posting 2.1×/week over the last 20 posts," "3 of 4 declared active channels have a fulfilled objective" — every line traceable to a real Fact/Knowledge/Campaign, matching the transparency principle already established for Recommendation rationale (`specs/core/domain-model.md`'s emphasis on `Decision.rationale` being structurally required, never optional prose).

**Trends over time (design only):** a per-dimension and composite line chart backed by `MarketingHealthSnapshot` rows (append-only, so this is a straightforward time-series query — no synthetic backfill needed once snapshots start accumulating). Design note for a future phase, not built in Version 1: the chart's first real data point is the first snapshot computed *after* this milestone ships; there is no retroactive history, and the UI must say so rather than showing a misleading flat line before that date.

---

## 10. Migration Strategy

Two new tables (`marketing_health_scores`, `marketing_health_snapshots`), both:
- `char(26)` ULID primary keys, `company_id` FK with `cascadeOnDelete()`, per `specs/core/domain-model.md`'s established convention.
- No new columns on any existing table — Marketing Health is purely additive, reading existing `facts`/`knowledge_entries`/`campaigns`/`content_assets`/`marketing_channels` and writing only its own two new tables.
- No enum value additions needed on `observations.source_type` or `integrations.type` — Marketing Health is not a connector; it has no `Observation` shape of its own. (Contrast with Milestone 12 Phase 2, which genuinely needed a new `source_type`.)
- Backfill: none possible or expected — a company's Marketing Health history begins the day this ships, exactly as `MarketingHealthSnapshot`'s trend-over-time design (§9) already accounts for.

Full column-level migration DDL is deferred to `docs/plans/Milestone-13-Marketing-Health-Plan.md` (implementation-level detail; this spec fixes the shape, the plan fixes the exact migration file).

---

## 11. Explicitly Out of Scope for Version 1

- **AI-based scoring of any dimension.** Every score is arithmetic over stored evidence, per §1.
- **New connectors** (Google Business Profile, LinkedIn, Facebook, Search Console, GA4). This spec designs the architecture so they *plug in* automatically (§7) — it does not build any of them.
- **A sixth Decision Engine guard condition that can block a Decision.** Marketing Health only reorders among already-eligible candidates (§6.2).
- **Decision-level confidence-score recalculation.** `Decision.confidence_score` continues to pass through from `Opportunity.confidence_score` unchanged (§6.2).
- **Backfilled historical trend data.** Trends start accumulating from first computation after this ships (§9, §10).
- **A public/shareable Marketing Health report** (e.g. a PDF export, an agency-facing multi-client view). Not named in this milestone's brief; would be a Stage C+ concern per `docs/plans/Version-1.0-Roadmap.md`'s Sofia-persona notes.
- **Any change to existing `OpportunityDetector` implementations, the `OpportunityDetector` interface, or the 0.30/0.25/0.25/0.20 base composite formula.**

---

## 12. Future Phases (not designed here)

- AI-generated narrative summaries of a dimension's evidence (e.g. turning "posting 0.3×/week" into a coaching-toned paragraph) — a presentation-layer enhancement over deterministic scores, not a scoring change; would need its own prompt-versioning treatment per `specs/core/domain-model.md`'s AI-output conventions.
- Additional dimensions once their evidence source ships: SEO/Search Visibility (needs Search Console), Paid Media Efficiency (needs an ads connector — explicitly deferred platform-wide per the Version 1.0 roadmap), Email List Health (needs real email delivery data, itself a Stage B roadmap item), Review/Reputation Signal (needs a reviews connector).
- Decision-level confidence-score adjustment informed by Marketing Health (§6.2's deferred item).
- Cross-company benchmarking ("your Campaign Consistency is above average for comic auction houses") — must never leak one company's underlying data to another, per the Version 1.0 roadmap's explicit Stage D caution on cross-company learning aggregation; a real future idea, not a casual add-on.
