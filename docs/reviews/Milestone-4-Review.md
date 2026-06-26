# Milestone 4 CTO Review — Opportunity & Decision Engine

**Date:** 2026-06-26
**Milestone:** Milestone 4 — Opportunity & Decision Engine
**Reviewer:** Prepared for CTO review

---

## Milestone Summary

### What Was Implemented

Milestone 4 built the autonomy layer of the Atlas loop — the complete path from BusinessBrain through multi-detector opportunity discovery, composite scoring, AI-assisted detection, guard-validated decision commitment, and AI-generated rationale. The system now produces structured `Decision` records with machine-readable rationale ready for Milestone 5's Recommendation Engine.

Specifically delivered:

- **6 new migrations** — `opportunities`, `decisions`, `recommendations`, `campaigns`, `channels`, and `channel_assignments` tables.
- **6 new models** — `Opportunity`, `Decision`, `Recommendation`, `Campaign`, `Channel`, `ChannelAssignment`; all with `HasUlids` and appropriate `BelongsToCompany` traits.
- **`OpportunityDetector` interface + 4 rule-based detectors** — `FeaturedItemDetector`, `UrgencyDetector`, `NewArrivalDetector`, `ReEngagementDetector`; each sets all 4 score components.
- **`OpportunityCandidate` value object** — typed, readonly data carrier from detector to scorer; avoids DB writes at detection time.
- **`OpportunityScorer`** — applies composite formula `(relevance × 0.30) + (timing × 0.25) + (confidence × 0.25) + (urgency × 0.20)`; enforces minimum threshold of 30; caps AI-detected candidate confidence at 75.
- **`OpportunityRepository`** — `openForCompany()`, `findDuplicate()`, `findOpenForCompany()` — always uses `withoutGlobalScopes()`.
- **`OpportunityEngine::scan()`** — orchestrates all detectors → AI analyst → dedup → score → persist → `OpportunityDetected` event per opportunity.
- **`OpportunityDetectionPrompt` + `OpportunityDetectionAnalyst`** — extends the rule-based detectors with AI-discovered candidates; marks all as `aiDetected: true`; validates required fields; clamps scores 0–100; does NOT write to DB, create Decisions, or bypass scoring.
- **`DecisionContext` value object** — typed transfer object from `DecisionEngine` to `DecisionService`.
- **`DecisionRepository`** — `openForCompany()`, `findByOpportunity()`.
- **`RationaleGenerationPrompt` + `RationaleGenerationAnalyst`** — generates structured `why_now`, `why_this`, `why_channel`, `why_works`, and `expected_impact` rationale; all fields required.
- **`RationaleGenerationFailedException`** — thrown when any rationale key is missing or empty; prevents partial Decision persistence.
- **`DecisionService::commit()`** — validates all 5 rationale keys + 4 `expected_impact` sub-keys; persists Decision; calls `opportunity->select()`; fires `DecisionCommitted`.
- **`DecisionEngine::evaluate()`** — five guard conditions enforced in order: channel availability (G5), minimum composite score (G1), duplicate recommendation (G2), campaign cooldown (G3), catalog availability (G4); dismisses Opportunity on G4 failure; selects highest-scoring open Opportunity.
- **4 jobs** — `DetectOpportunities` (default queue), `CommitDecision` (`ShouldBeUnique` per company, ai queue), `ExpireOpportunities` (maintenance), `PrepareCampaign` (ai queue stub).
- **3 listeners** — `TriggerOpportunityDetection`, `TriggerDecisionEvaluation`, `DispatchCampaignPreparation`.
- **`AppServiceProvider` updated** — morph map + full event wiring for the Opportunity→Decision chain.
- **`BusinessBrainService` updated** — populates `featuredItems` and `recentCampaigns`.
- **`Company` model updated** — `opportunities()` and `decisions()` `hasMany` relationships.
- **47 new tests** — 10 test classes; all use `FakeAiProvider` fixtures; 0 live AI calls.
- **PHPStan level 8** — 0 errors. Pint — clean.

### Stop Boundary Respected

Milestone 4 stopped at Decision commitment. Not implemented:

- Recommendation Engine (human-facing presentation layer)
- Campaign Engine or campaign content generation
- Marketing assets or channel-specific renderers
- Any publishing or external API calls
- Analytics or learning loop

---

## Database

### New Migrations

| File | Table | Purpose |
|------|-------|---------|
| `2026_06_26_002000_create_opportunities_table.php` | `opportunities` | Detected growth opportunities with composite scoring |
| `2026_06_26_002100_create_channels_table.php` | `channels` | Marketing channels (email, Instagram, etc.) |
| `2026_06_26_002200_create_decisions_table.php` | `decisions` | Committed AI decisions with structured rationale |
| `2026_06_26_002300_create_recommendations_table.php` | `recommendations` | Human-facing recommendation layer (stub, M5) |
| `2026_06_26_002400_create_campaigns_table.php` | `campaigns` | Campaign records tracking execution lifecycle |
| `2026_06_26_002500_create_channel_assignments_table.php` | `channel_assignments` | Pivot: decision → channel |

### `opportunities` Table

| Column | Type | Notes |
|--------|------|-------|
| id | char(26) | ULID PK |
| company_id | char(26) | FK → companies |
| subject_type | string | nullable morphable type (`catalog_item`, `catalog`, `company`) |
| subject_id | char(26) | nullable morphable id |
| type | enum | `featured_item`, `urgency`, `new_arrival`, `re_engagement`, `seasonal` |
| title | string | Short opportunity label |
| description | text | Human-readable explanation |
| relevance_score | tinyint | 0–100 |
| timing_score | tinyint | 0–100 |
| confidence_score | tinyint | 0–100; capped at 75 for AI-detected |
| urgency_score | tinyint | 0–100 |
| composite_score | tinyint | Weighted formula result |
| status | enum | `open`, `selected`, `dismissed`, `expired` |
| ai_detected | boolean | true when discovered by `OpportunityDetectionAnalyst` |
| detected_at | timestamp | When first detected |
| expires_at | timestamp | nullable; used by `ExpireOpportunities` job |
| timestamps | — | standard |

**Indexes:** `(company_id, status)`, `(subject_type, subject_id)`.

### `decisions` Table

| Column | Type | Notes |
|--------|------|-------|
| id | char(26) | ULID PK |
| company_id | char(26) | FK → companies |
| opportunity_id | char(26) | FK → opportunities |
| campaign_type | enum | `featured_item`, `urgency_promotion`, `re_engagement`, `seasonal` |
| channel_ids | json | Ordered array of selected channel IDs |
| rationale | json | `{why_now, why_this, why_channel, why_works, expected_impact}` |
| status | enum | `pending`, `approved`, `rejected`, `cancelled` |
| decided_at | timestamp | nullable |
| timestamps | — | standard |

---

## Domain

### Models Created

| Model | File | Key Notes |
|-------|------|-----------|
| `Opportunity` | `app/Models/Opportunity.php` | `BelongsToCompany`, `HasUlids`; morphable `subject()`; `open()`, `selected()` scopes |
| `Decision` | `app/Models/Decision.php` | `BelongsToCompany`, `HasUlids`; `rationale` cast as `array`; `channel_ids` cast as `array` |
| `Recommendation` | `app/Models/Recommendation.php` | `BelongsToCompany`, `HasUlids`; M5 stub |
| `Campaign` | `app/Models/Campaign.php` | `BelongsToCompany`, `HasUlids`; `completed_at` datetime cast |
| `Channel` | `app/Models/Channel.php` | `HasUlids` only (no `BelongsToCompany`); `company_id` nullable (null = system template) |
| `ChannelAssignment` | `app/Models/ChannelAssignment.php` | `HasUlids`; pivot between Decision and Channel |

### Opportunity Engine

| Class | File | Responsibility |
|-------|------|----------------|
| `OpportunityDetector` (interface) | `app/Services/Opportunity/Contracts/OpportunityDetector.php` | `detect(Company, BusinessBrain): Collection<OpportunityCandidate>` |
| `OpportunityCandidate` | `app/Services/Opportunity/OpportunityCandidate.php` | Readonly VO; no DB access |
| `FeaturedItemDetector` | `app/Services/Opportunity/Detectors/` | Items never promoted or promoted > 30 days ago |
| `UrgencyDetector` | `app/Services/Opportunity/Detectors/` | Items expiring within 48 hours |
| `NewArrivalDetector` | `app/Services/Opportunity/Detectors/` | Items created within 24 hours |
| `ReEngagementDetector` | `app/Services/Opportunity/Detectors/` | Gap ≥ 14 days since last campaign |
| `OpportunityScorer` | `app/Services/Opportunity/OpportunityScorer.php` | Composite formula, threshold (30), AI confidence cap (75) |
| `OpportunityRepository` | `app/Services/Opportunity/OpportunityRepository.php` | `openForCompany()`, `findDuplicate()`, `findOpenForCompany()` |
| `OpportunityEngine` | `app/Services/Opportunity/OpportunityEngine.php` | Full scan pipeline: detectors → AI analyst → dedup → score → persist → events |

### AI Layer

| Class | File | Purpose |
|-------|------|---------|
| `OpportunityDetectionPrompt` | `app/AI/Prompts/OpportunityDetectionPrompt.php` | Version `1.0`; temperature 0.3; schema-driven JSON output |
| `OpportunityDetectionAnalyst` | `app/Services/Analyst/OpportunityDetectionAnalyst.php` | Supplements rule-based detection; marks `aiDetected: true` |
| `RationaleGenerationPrompt` | `app/AI/Prompts/RationaleGenerationPrompt.php` | Version `1.0`; temperature 0.4; 5-key + 4-sub-key structured output |
| `RationaleGenerationAnalyst` | `app/Services/Analyst/RationaleGenerationAnalyst.php` | Generates rationale array; validation is `DecisionService`'s responsibility |

### Decision Engine

| Class | File | Responsibility |
|-------|------|----------------|
| `DecisionContext` | `app/Services/Decision/DecisionContext.php` | Readonly VO: `opportunity`, `brain`, `campaignType`, `channelIds` |
| `DecisionRepository` | `app/Services/Decision/DecisionRepository.php` | `openForCompany()`, `findByOpportunity()` |
| `DecisionService` | `app/Services/Decision/DecisionService.php` | Validates rationale, persists Decision, fires `DecisionCommitted` |
| `DecisionEngine` | `app/Services/Decision/DecisionEngine.php` | 5-guard evaluation loop; opportunity→campaign_type mapping via `match` |
| `RationaleGenerationFailedException` | `app/Services/Decision/Exceptions/` | Thrown when rationale incomplete; prevents partial persistence |

### Events Created

| Event | Fired When |
|-------|------------|
| `OpportunityDetected` | After each Opportunity is persisted by `OpportunityEngine` |
| `DecisionCommitted` | After `DecisionService` persists a Decision |

### Listeners Created

| Listener | Handles | Action |
|----------|---------|--------|
| `TriggerOpportunityDetection` | `DigitalTwinActivated` | Dispatches `DetectOpportunities` job |
| `TriggerDecisionEvaluation` | `OpportunityDetected` | Dispatches `CommitDecision` job |
| `DispatchCampaignPreparation` | `DecisionCommitted` | Dispatches `PrepareCampaign` job |

### Jobs Created

| Job | Queue | Notes |
|-----|-------|-------|
| `DetectOpportunities` | `default` | Assembles BusinessBrain, calls `OpportunityEngine::scan()` |
| `CommitDecision` | `ai` | `ShouldBeUnique` per company ID; 3 tries, 60s backoff |
| `ExpireOpportunities` | `maintenance` | Bulk-expires open opportunities past `expires_at` |
| `PrepareCampaign` | `ai` | M4 no-op stub; implemented in Milestone 5 |

---

## Decision Guard Conditions

Guards applied in `DecisionEngine::evaluate()`, in order:

| # | Guard | Outcome on Fail |
|---|-------|-----------------|
| G5 | No active channels for company | Return null (entire evaluation aborted) |
| G1 | Composite score < 30 | Skip opportunity, continue loop |
| G2 | Pending/viewed Recommendation of same `campaign_type` exists | Skip opportunity, continue loop |
| G3 | Completed Campaign of same `campaign_type` within cooldown window | Skip opportunity, continue loop |
| G4 | Subject is `catalog_item` and item status is not `active` | **Dismiss** opportunity (status → `dismissed`), continue loop |

**Cooldown days by campaign_type:**

| Campaign Type | Cooldown |
|---------------|----------|
| `urgency_promotion` | 3 days |
| `featured_item` | 14 days |
| `re_engagement` | 14 days |
| `seasonal` | 365 days |

---

## Testing

### New Test Classes

| File | Tests | Covers |
|------|-------|--------|
| `tests/Unit/Opportunity/OpportunityScorerTest.php` | 5 | Formula correctness, threshold, AI confidence cap |
| `tests/Feature/Opportunity/FeaturedItemDetectorTest.php` | 6 | Unpromoted items, stale promotions, active filter, re-promotion |
| `tests/Feature/Opportunity/UrgencyDetectorTest.php` | 5 | Expiry window, score validity, non-expiring items |
| `tests/Feature/Opportunity/ReEngagementDetectorTest.php` | 5 | Gap from fact, gap from campaigns, fallback to 999 days |
| `tests/Feature/Opportunity/OpportunityEngineTest.php` | 4 | Persistence, deduplication, events, AI flag |
| `tests/Feature/Opportunity/OpportunityExpiryTest.php` | 3 | Bulk expiry, threshold boundary, already-expired items |
| `tests/Feature/Opportunity/OpportunityDetectionAnalystTest.php` | 6 | Fixture parsing, field validation, score clamping, `aiDetected` flag |
| `tests/Feature/Decision/DecisionEngineTest.php` | 7 | All 5 guards, happy path, highest-score selection |
| `tests/Feature/Decision/RationaleGenerationAnalystTest.php` | 2 | Fixture parsing, key presence |
| `tests/Feature/Decision/DecisionPipelineTest.php` | 2 | End-to-end: opportunity → decision, missing rationale throws |

**Fixtures:**
- `tests/Fixtures/AI/opportunity-detection.json` — seasonal opportunity with valid score fields.
- `tests/Fixtures/AI/rationale-generation.json` — full 5-key rationale with all `expected_impact` sub-keys.

### Test Count

| Status | Count |
|--------|-------|
| Passing | 127 |
| Skipped | 2 (Redis — `CommitDecision` uniqueness; cache lock) |
| Failing | 0 |
| Total | 129 |

---

## Technical Debt

| Item | Notes |
|------|-------|
| `AiProvider` has no production implementation | `AppServiceProvider` binds `FakeAiProvider` in `testing` only. An `AnthropicProvider` must be bound in production before any AI analyst runs. |
| `PrepareCampaign` is a no-op stub | Logs a debug message and returns. Campaign content generation is Milestone 5. |
| `NewArrivalDetector` hardcodes 24-hour window | Detection threshold should be configurable per business or vertical. Deferred to M5+. |
| `ReEngagementDetector` uses a single global gap threshold | Per-channel or per-vertical cooldown logic not yet implemented. |
| `Channel` records must be manually seeded | There is no channel discovery or automatic provisioning. Operators must create Channel records before the Decision Engine can run. G5 returns null if no channels exist. |
| `DecisionEngine` uses a single `match` for type mapping | All 5 opportunity types are covered. If new types are added, the `match` will throw `UnhandledMatchError` at runtime. |
| `ShouldBeUnique` skips are silent | If `CommitDecision` is already queued for a company, the second dispatch is silently dropped. No alerting or logging on skip. |

---

## Non-obvious Implementation Decisions

### PHPStan and larastan compatibility

Two non-obvious fixes required for PHPStan level 8 compliance:

1. **`CatalogItem` casts as property, not method.** larastan 3.10.0 does not reliably infer `Carbon` types from the `protected function casts(): array` method form. Changed to `protected $casts = [...]` property form so `promoted_at`, `expires_at`, `featured_at`, and `sold_at` are correctly typed as `Carbon` throughout the service layer.

2. **`Company::brand` is `string|null` to PHPStan.** The `brand` column stores JSON but PHPStan sees the raw DB type. `RationaleGenerationPrompt` extracts via `json_decode` rather than calling `is_array()` directly on the model attribute, preventing the always-false branch error.

3. **PHP heredoc does not support `??` or keyed array access inside `{...}` interpolation.** `OpportunityDetectionPrompt` and `RationaleGenerationPrompt` both extract all interpolated variables to local variables before opening the heredoc.

### Deduplication granularity

The dedup check in `OpportunityEngine` is scoped to `(company_id, type, subject_id)`. An item cannot have two open `featured_item` opportunities simultaneously. Subject-less opportunities (e.g., `re_engagement`) dedup on `(company_id, type)` with `subject_id IS NULL`.

### Composite score minimum enforced at scorer, not detector

Detectors do not know the threshold. `OpportunityScorer` applies the 30-point minimum. This keeps detectors simple and allows the threshold to change without touching detector logic.

---

## Specification Compliance

### Domain Model

| Requirement | Status | Notes |
|-------------|--------|-------|
| `Opportunity` with 4 score components + composite | ✅ Compliant | All columns implemented |
| Composite formula: `(rel×0.30)+(tim×0.25)+(conf×0.25)+(urg×0.20)` | ✅ Compliant | Enforced in `OpportunityScorer` |
| Minimum composite score threshold: 30 | ✅ Compliant | Below-threshold candidates not persisted |
| AI-detected confidence capped at 75 | ✅ Compliant | Applied in `OpportunityScorer` |
| `OpportunityDetector` interface: returns `Collection<OpportunityCandidate>`, no DB writes | ✅ Compliant | All detectors are read-only |
| `OpportunityDetectionAnalyst`: supplements detectors, does not bypass scoring | ✅ Compliant | Returns `Collection<OpportunityCandidate>` |
| Morph map registered: `catalog_item`, `catalog`, `company` | ✅ Compliant | Registered in `AppServiceProvider::boot()` |
| `Decision` rationale: 5 required keys | ✅ Compliant | Validated in `DecisionService::validateRationale()` |
| `expected_impact`: 4 required sub-keys | ✅ Compliant | Validated in same method |
| `RationaleGenerationFailedException` thrown on incomplete rationale | ✅ Compliant | Decision not persisted on throw |
| `CommitDecision` is `ShouldBeUnique` per company | ✅ Compliant | `uniqueId()` returns `company->id` |
| G4 dismisses Opportunity when catalog item not active | ✅ Compliant | Status set to `dismissed`; loop continues |
| Human approval required before external publishing | ✅ Compliant | `Decision::status = 'pending'`; no publishing in M4 |
| `Company hasMany Opportunity` | ✅ Compliant | Relationship added |
| `Company hasMany Decision` | ✅ Compliant | Relationship added |

### AI.md

| Requirement | Status | Notes |
|-------------|--------|-------|
| Only Analysts call `AiProvider` | ✅ Compliant | `OpportunityDetectionAnalyst` and `RationaleGenerationAnalyst` are the only callers |
| Prompts are versioned | ✅ Compliant | Both prompts return `'1.0'` from `version()` |
| Structured JSON outputs | ✅ Compliant | Both prompts define `schema()` |
| `FakeAiProvider` used in all tests | ✅ Compliant | No live AI calls in any test |

---

## Ready for Milestone 5?

**YES.**

The full Observe → Understand → Decide pipeline is now end-to-end and tested. A `Decision` with validated AI rationale is the handoff point. Milestone 5 (Recommendation Engine) can build directly on:

- `Decision::status = 'pending'` — the pending queue that M5 will convert into human-facing Recommendations.
- `Decision::rationale` — structured JSON ready for display.
- `DecisionCommitted` event — the trigger that M5 listens to for Recommendation creation.
- `PrepareCampaign` stub — already wired; M5 implements the body.

**Prerequisites for Milestone 5:**

1. **Real `AiProvider` implementation** — still required for any production run.
2. **`Recommendation` model** — table and model exist (M4 stub); M5 implements the service layer and content.
3. **Channel records** — must exist in DB before `DecisionEngine::evaluate()` can proceed past G5.
4. **Campaign content generation** — `PrepareCampaign::handle()` needs real implementation.
