# Milestone 9 Review — Learning Engine Implementation

**Completed:** 2026-06-26
**Tests:** 449 total | 447 passing | 2 skipped (Redis)
**PHPStan:** Level 8 — 0 errors
**Pint:** Clean

---

## What Was Built

### Core Learning Machinery

The Learning Engine processes company-scoped `Learning` records in a daily batch (`ApplyLearnings` job, 02:00 daily). For each company, the engine:

1. Loads all unapplied Learning records
2. Sorts them by tier priority (Tier 1 safety first, Tier 2 performance, Tier 3 preference)
3. Resolves conflicts between opposing signals (using 4 ordered rules)
4. For each winner: checks evidence threshold against a 90-day rolling window
5. If threshold met: mutates Facts + Knowledge, optionally calibrates scoring weights
6. Creates an immutable `LearningApplication` with human-readable `effects` descriptors
7. Sets `Learning.applied_at = now()` atomically in a DB transaction

### Signal Tiers and Thresholds

| Tier | Signals | Threshold |
|------|---------|-----------|
| 1 (Safety) | `email_deliverability_issue`, `high_unsubscribe_rate` | 1 signal |
| 2 (Performance) | `channel_outperformed`, `channel_underperformed`, `campaign_type_succeeded`, `campaign_type_underperformed`, `optimal_timing_signal` | 2 signals |
| 3 (Preference) | `content_angle_engaged`, `recommendation_approved`, `recommendation_rejected`, `recommendation_edited_and_approved` | 3 signals |

### Conflict Resolution — 4 Rules (in order)

1. **Safety override** — Tier 1 signal beats any non-Tier-1 opposing signal
2. **Majority** — if one direction has ≥ 2 more signals than the opposing direction, it wins
3. **Recency** — if the latest signal on one side is ≥ 30 days newer than the opposing side's latest, it wins
4. **Tie** — leave both directions unapplied; re-evaluated in the next daily run

### BusinessBrain Mutations

Every applied Learning may produce:
- **Fact mutations** — new `Fact` rows with previous superseded (`is_current = false`, `superseded_by_id` set). Covers channel affinity, email health, optimal timing, campaign preferences.
- **Knowledge mutations** — new `Knowledge` entries with `type = 'learning'`, 90-day TTL, previous entry deactivated. Covers all 11 signal types.
- **Weight calibrations** — new versioned `CompanyScoringWeights` row; `type_modifier` ±0.05 per calibration; bounded [0.50, 1.50]; 14-day cooling period prevents rapid oscillation.

### OpportunityScorer Integration

`OpportunityScorer::score()` now accepts an optional `?array $typeModifiers` parameter. `OpportunityEngine` loads the current `CompanyScoringWeights` for the company and passes the modifiers to the scorer. The composite score is multiplied by the modifier for the candidate's type. All existing callers and tests remain unaffected (optional parameter, default `null` = baseline behavior).

### Approval Signals Wired

`ApprovalService` now creates `Learning` records on all three approval actions:
- `approve()` → `recommendation_approved` signal
- `reject()` → `recommendation_rejected` signal
- `editAndApprove()` (new method) → `recommendation_edited_and_approved` signal + `EditPatternDetector` runs to extract length/hashtag/price patterns from the diff

Duplicate-safe: checked via `source_id + signal` existence before creation.

### Rollback

`LearningRollbackService::rollback(LearningApplication, reason)`:
- Reverses Fact mutations: deactivates new Fact, restores previous Fact if stored
- Reverses Knowledge mutations: deactivates new Knowledge, restores previous if stored
- Reverses Weight calibrations: retires new CompanyScoringWeights row, restores previous if stored
- Sets `LearningApplication.rolled_back_at` and `rollback_reason`
- Resets `Learning.applied_at = null` — the Learning re-enters the queue
- Double-rollback throws `RuntimeException`
- All operations in a single DB transaction

---

## Architectural Decisions and Rationale

### No unique constraint on `(company_id, learning_id)` in `learning_applications`

The spec suggested this for idempotency, but it would prevent re-application after rollback (since `LearningApplication` rows are never deleted). Idempotency is instead guaranteed by the `applied_at IS NULL` filter on the Learning records — a signal that's already applied won't re-enter the processing loop unless explicitly rolled back.

### PHP-side evidence filtering (not SQL JSON extraction)

`EvidenceEvaluator::count()` loads Learning records into PHP and filters the discriminator in-memory rather than using `JSON_EXTRACT` or `->>'key'` syntax. This avoids SQLite/PostgreSQL dialect differences while keeping the code readable. The expected volume per signal type per company is small enough that this is not a performance concern.

### Cooling period via `CompanyScoringWeights.created_at`

The 14-day cooling period for weight calibration is enforced by checking when the current `CompanyScoringWeights` row was created. This means any weight adjustment resets the clock for the entire company. It's a simplification of per-signal-category cooling (which would require richer metadata), and is intentionally conservative.

### `recommendation_approved` as Tier 3, not Tier 2

Approval signals are user preference signals — they require 3 corroborating instances before they influence the BusinessBrain. This prevents premature tuning from one-off approvals.

### `EditPatternDetector` is heuristic-only

All pattern detection (length, hashtag, price) is keyword-based regex. No ML. No training data. The detector produces signals that accumulate over time and eventually cross the Tier 3 threshold, at which point a `recommendation_edited_and_approved` Knowledge entry is written to the BusinessBrain.

---

## What Was Not Built (Out of Scope)

Per the implementation constraints:

- **Cross-company learning** — no signal from Company A influences Company B
- **ML-based scoring** — no trained models; only deterministic weight adjustments
- **Real-time learning** — batch-only, daily at 02:00
- **User-facing "Teach Atlas" UI** — no user-visible learning configuration
- **Automatic publishing** — no publishing triggered by learning
- **Prompt template mutation at runtime** — prompt templates are static
- **Deleting historical records** — rollback creates compensating records, never deletes

---

## Test Coverage Summary

| File | Tests | Focus |
|------|-------|-------|
| `SignalTierTest` | 7 | Signal classification, threshold values, prioritisation order |
| `EvidenceEvaluatorTest` | 8 | Discriminator extraction per signal, 90-day window, company scoping |
| `ConflictResolverTest` | 6 | Majority rule, tie, recency rule, discriminator scoping |
| `FactMutatorTest` | 7 | Fact creation, supersession, missing-key edge cases |
| `KnowledgeMutatorTest` | 8 | Knowledge creation, supersession, 90-day expiry, all signal types |
| `WeightCalibratorTest` | 8 | Increase/decrease, bounds, cooling period, version monotonicity |
| `LearningRollbackServiceTest` | 5 | Fact/Knowledge/Weight rollback, double-rollback guard, re-queue |
| `LearningEngineTest` | 10 | Full pipeline: idempotency, tier thresholds, conflict losers, ties, company scoping |
| `EditPatternDetectorTest` | 8 | Length, hashtag, price pattern detection |
| `ApprovalServiceLearningTest` | 7 | Approval/rejection/edit signals, idempotency, status updates |
| `OpportunityScorerWeightTest` | 7 | Modifier application, bounds, type scoping, backward compat |
| **Total (new)** | **81** | |
| **Total (full suite)** | **449** | 447 passing, 2 skipped (Redis) |

---

## Acceptance Criteria Coverage

All safety invariants from `specs/core/learning-engine.md`:

- ✅ Learning records immutable — `applied_at` set once; rollback is a compensating action
- ✅ Applying learning creates new state — Fact and Knowledge mutations are append-only
- ✅ All applied learnings explainable — `LearningApplication.effects` contains human-readable descriptors
- ✅ Learning never reduces confidence without evidence — negative adjustments require Tier 2 threshold (2+ signals)
- ✅ Learning always company-scoped — all queries and services filter by `company_id`

---

## Known Gaps (Future Work)

- **Filament rollback action modal** — the rollback UI is wired into the infolist as a visible effects list, but an admin-initiated rollback action button was not added to this milestone. Rollback via `LearningRollbackService` can be triggered programmatically.
- **Prompt version tracking** — `prompt_underperformed` Knowledge entries (from prompt version approval rates) are not yet implemented. Engineering visibility into prompt performance exists via analytics, but no Learning-derived Knowledge entries are created from prompt approval rates.
- **Per-signal-category cooling period** — the current implementation applies a company-level 14-day cooling window. A future enhancement could track per-signal-category last-calibrated timestamps.
