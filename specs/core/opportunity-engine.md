# Opportunity Engine — Design Specification

**Version:** 1.0  
**Status:** Approved — authoritative specification for Milestone 4  
**Depends on:** `specs/core/domain-model.md`, `specs/product/mvp-workflow.md`

When this document conflicts with others, this document wins for anything related to the Opportunity Engine, OpportunityDetectors, OpportunityScorer, and DecisionEngine. Update the others.

---

## 1. What Is an Opportunity?

An **Opportunity** is a specific, time-sensitive marketing moment that Atlas has identified for a company — a condition under which a campaign would be timely, relevant, and likely to perform.

An Opportunity is not a suggestion. It is not an idea. It is a structured claim with evidence, a score, and an expiration. It says: *given what Atlas knows about this business right now, this specific action is worth taking*.

Every Opportunity answers four questions before it can exist:

| Question | Maps to |
|----------|---------|
| Is this action relevant to the business? | `relevance_score` |
| Is now the right time? | `timing_score` |
| How reliable is this assessment? | `confidence_score` |
| How urgent is the action? | `urgency_score` |

An Opportunity without evidence from the BusinessBrain cannot be created. An Opportunity without a positive composite score is not persisted. An Opportunity that is already open for the same subject cannot be duplicated.

### Opportunities Are Not Content

Atlas does not create a post. Atlas does not write a caption. An Opportunity is the detection stage — it says *this matters* and *why*. The Decision Engine decides *how to act*. The Campaign Engine decides *what to prepare*. These are distinct jobs.

### Opportunities Are Company-Scoped

Every Opportunity belongs to a Company. There is no global or cross-company opportunity detection. Each company's BusinessBrain produces its own Opportunities, scored against its own context.

---

## 2. Opportunity Lifecycle

```
[detected] open → selected → [Campaign created]
              ↓
          dismissed
              or
           expired
```

| Status | Meaning | Who sets it |
|--------|---------|-------------|
| `open` | Detected, scored, and available for the Decision Engine | `OpportunityEngine::scan()` |
| `selected` | Decision Engine has committed to this Opportunity | `DecisionEngine::evaluate()` |
| `dismissed` | Skipped — either manually by a user or by guard condition logic | `DecisionEngine` or user action |
| `expired` | The opportunity window has passed without a Decision | Scheduled expiry job |

### Transition Rules

- `open → selected`: Only the Decision Engine may make this transition. It is irreversible.
- `open → dismissed`: The Decision Engine may dismiss an Opportunity that fails guard conditions without selecting another. Users may dismiss manually in a future phase.
- `open → expired`: A scheduled job checks `opportunities.expires_at` nightly. Any `open` Opportunity past its `expires_at` is transitioned to `expired`.
- `selected → *`: Once selected, an Opportunity may not change status. Its fate is tied to the Decision that claimed it.

### What Happens When No Decision Is Committed

The `CommitDecision` job runs after opportunities are detected. If no Opportunity passes all guard conditions, the job exits cleanly and logs the reason. The Opportunity stays `open`. The next sync cycle re-scores and re-evaluates.

---

## 3. Opportunity Types

Atlas MVP supports six types. Each type has a defined trigger condition, a natural subject, and a default scoring profile.

### `featured_item`

**Trigger:** An active CatalogItem has not been the subject of any Campaign in the last N days (default: 14 days for general inventory, 45 days for high-value items).

**Subject:** A specific `CatalogItem`

**Evidence required:**
- `catalog.active_item_count` Fact > 0
- No Campaign with `subject_id = item.id` completed within the cooldown window

**Scoring profile:** High relevance, moderate timing, moderate confidence, low urgency (no hard deadline).

**CBB Auctions example:** A golden-age key issue that has been in inventory for 6 weeks with no promotion.

**Exotic dealer example:** A Ferrari 275 GTB in inventory for 45 days, never promoted.

---

### `urgency`

**Trigger:** A CatalogItem has a hard deadline (`expires_at`) within 48 hours, or a catalog-level count of items expiring soon exceeds a threshold.

**Subject:** A specific `CatalogItem` or the `Catalog` (when multiple items share the urgency window).

**Evidence required:**
- `catalog.ending_within_48h_count` Fact > 0, or `CatalogItem.expires_at` within 48 hours
- Item is `active` status

**Scoring profile:** High relevance, very high timing, high confidence, very high urgency.

**CBB Auctions example:** 12 auctions closing within 48 hours. Highest-scoring opportunity type for auction businesses.

**Exotic dealer example:** Not typically applicable (no auction model), unless a lease or reservation deadline applies.

---

### `new_arrival`

**Trigger:** A CatalogItem was created or added to the catalog within the last 48 hours and has not yet been promoted.

**Subject:** A specific `CatalogItem`

**Evidence required:**
- `CatalogItem.created_at` within 48 hours
- No Campaign with `subject_id = item.id`

**Scoring profile:** High relevance, high timing (new arrivals have a natural novelty window), moderate confidence, moderate urgency.

**CBB Auctions example:** A new high-grade key added to the store.

**Exotic dealer example:** A 1967 Ferrari 275 GTB just listed.

---

### `re_engagement`

**Trigger:** The company has not published any Campaign in the last N days (default: 14 days).

**Subject:** The `Company` itself (no specific CatalogItem required)

**Evidence required:**
- `marketing.days_since_last_campaign` Fact > 14, or no Campaign with `status: completed` in the last 14 days
- At least one active CatalogItem exists in the catalog

**Scoring profile:** Moderate relevance, high timing (the gap is the problem), moderate confidence, moderate urgency.

**CBB Auctions example:** No campaigns sent in 3 weeks — audience engagement at risk.

**Exotic dealer example:** No social posts or emails in 2 weeks — inventory awareness dropping.

---

### `seasonal`

**Trigger:** An upcoming date, holiday, or calendar event aligns with the business's vertical or inventory.

**Subject:** The `Company` or `Catalog`

**Evidence required:**
- A registered seasonal event within the detection window (e.g., within 14 days)
- Company `industry` or catalog `type` matches the event profile

**Scoring profile:** Variable — depends on how closely the business aligns with the seasonal moment.

**MVP note:** Seasonal detection in Milestone 4 is rule-based using a static calendar of relevant events (New Year, Valentine's Day, Free Comic Book Day, car show season, etc.). AI-assisted seasonal detection is a future enhancement.

---

### `milestone`

**Trigger:** A notable company milestone — N months in business, Nth sale, first anniversary, inventory count milestone.

**Subject:** The `Company`

**Evidence required:**
- Fact or Knowledge entry indicating a milestone threshold has been crossed
- Company `created_at` or catalog statistics

**Scoring profile:** Low urgency (milestones are not time-critical), high relevance (human-interest content), low confidence (harder to verify from crawl data alone).

**MVP note:** `milestone` detection is not implemented in Milestone 4. It is defined here for completeness. The `type` enum includes it for future use.

---

## 4. Opportunity Priorities

Opportunity types are not ranked by a static priority list. Priority is determined dynamically by the composite score at detection time. However, each type has a **natural scoring profile** that tends to produce higher or lower composite scores.

### Natural Scoring Hierarchy (Typical)

```
urgency          → composite ~85–98   (hard deadline, time-critical)
new_arrival      → composite ~65–85   (novelty window, moderate urgency)
featured_item    → composite ~55–80   (strong relevance, low urgency)
re_engagement    → composite ~50–70   (timing pressure, moderate confidence)
seasonal         → composite ~45–65   (context-dependent)
milestone        → composite ~30–55   (low urgency drags composite down)
```

These are tendencies, not ceilings. A featured_item for a $485,000 Ferrari in a dealer with no campaigns for 60 days will score higher than a re_engagement for a company that posted yesterday.

### Tie-breaking

When two Opportunities have identical composite scores (rare but possible):

1. `urgency` > `new_arrival` > `featured_item` > `re_engagement` > `seasonal` > `milestone`
2. If still tied: earlier `detected_at` wins (older opportunity gets first consideration)

---

## 5. Opportunity Confidence Scoring

The composite score is computed from four components:

```
composite = (relevance × 0.30) + (timing × 0.25) + (confidence × 0.25) + (urgency × 0.20)
```

All scores are integers 0–100. All four components are required. The `OpportunityScorer` computes and validates them.

### Component Definitions

**Relevance (0–100):** How well does this opportunity align with the business's core identity and goals?

- 90–100: Direct alignment with the business model (auctions ending for an auction house)
- 70–89: Strong alignment (featured item for a dealership)
- 50–69: Moderate alignment (seasonal post for a business with loose seasonal ties)
- Below 50: Weak alignment — consider whether to surface this opportunity at all

**Timing (0–100):** How well-timed is this action relative to the current moment?

- 90–100: Hard deadline within 24–48 hours
- 70–89: Strong timing window (last 3–7 days, or new arrival in first 48h)
- 50–69: Moderate timing pressure (no campaigns in 14+ days)
- Below 50: No particular timing pressure

**Confidence (0–100):** How reliable is the evidence that supports this opportunity?

- 90–100: Directly observed Facts with high confidence, from multiple sources
- 70–89: Observed Facts with moderate-to-high confidence
- 50–69: Inferred from limited facts or single source
- Below 50: Low evidence quality — AI-detected with limited support

**Urgency (0–100):** How quickly must this action be taken before the window closes?

- 90–100: Hard, immovable deadline (auction close, event date)
- 70–89: Strong urgency, soft deadline (freshness decay, competitive pressure)
- 50–69: Moderate urgency (engagement gap growing)
- Below 50: Low urgency — opportunity is open-ended

### Minimum Threshold

Any Opportunity with `composite_score < 30` is not persisted. It is logged and discarded. This threshold prevents noise from reaching the Decision Engine.

### Score Storage

All four component scores and the composite are persisted on the `opportunities` row. This allows the Decision Engine and future Learning systems to understand *why* an opportunity scored as it did — not just what the final number was.

---

## 6. Opportunity Evidence

Every Opportunity must reference the Facts and Knowledge that support it. This is the evidence chain that makes Atlas explainable.

### Evidence Fields

The `opportunities` table carries an implicit evidence chain through:

- `subject_type` + `subject_id` — the specific entity being acted on
- `type` — which detection rule fired
- `description` — human-readable summary of why this opportunity exists
- `detected_at` — when the evidence was current

### Evidence Sources for Each Type

| Opportunity Type | Primary Evidence |
|-----------------|-----------------|
| `featured_item` | `catalog.active_item_count` Fact; CatalogItem `promoted_at` / Campaign history |
| `urgency` | `catalog.ending_within_48h_count` Fact; CatalogItem `expires_at` |
| `new_arrival` | CatalogItem `created_at`; absence of prior campaigns |
| `re_engagement` | `marketing.days_since_last_campaign` Fact; absence of recent Campaigns |
| `seasonal` | Registered calendar event; Company `industry` alignment |
| `milestone` | Company `created_at`; catalog statistics facts |

### Connecting Opportunities to Facts and Knowledge

Detectors receive the full `BusinessBrain` — which includes `activeFacts` and `activeKnowledge`. The detector inspects specific fact keys and knowledge subjects to determine whether the trigger condition is met.

**Detectors should not query the database directly.** Everything they need is in the `BusinessBrain`. If a detector needs data not present in the `BusinessBrain`, that data should be added to `BusinessBrainService::for()`.

The only exception: CatalogItem lookup by ID (when verifying a specific item's campaign history). This query goes to the database directly and should be encapsulated in a dedicated repository method.

### Evidence Chain for the Rationale

When the Decision Engine later generates a rationale, it receives the Opportunity record including its `description` (the human-readable evidence summary written by the detector). The `RationaleGenerationAnalyst` uses this description as the foundation for `why_now` and `why_this`.

**The evidence chain is:**
```
Facts → Knowledge → Opportunity description → Decision rationale
```

Each link must be traceable. The description written by the detector is the handoff point.

---

## 7. Opportunity Expiration

Every Opportunity should expire. An Opportunity that never expires creates decision fatigue — the Decision Engine keeps encountering stale signals.

### Expiry Rules by Type

| Type | Default `expires_at` | Rationale |
|------|---------------------|-----------|
| `urgency` | Item's `expires_at` + 2 hours | Grace period after the deadline |
| `new_arrival` | `detected_at` + 72 hours | Novelty window closes fast |
| `featured_item` | `detected_at` + 14 days | Item will be re-evaluated at next sync |
| `re_engagement` | `detected_at` + 7 days | If not acted on in a week, re-detect |
| `seasonal` | Event date + 1 day | After the event, the opportunity is gone |
| `milestone` | `detected_at` + 30 days | Milestones have a broader celebration window |

### Expiry Job

A scheduled job (`ExpireOpportunities`) runs nightly on the `maintenance` queue. It queries:

```sql
UPDATE opportunities
SET status = 'expired'
WHERE status = 'open'
AND expires_at < NOW()
```

This job is idempotent and safe to run multiple times.

### Expiry and Re-detection

Expiring an Opportunity does not prevent the same opportunity from being detected again on the next sync. If the underlying condition still holds (e.g., the item still hasn't been promoted), the detector will fire again and a new Opportunity is created with fresh scores and a fresh expiry.

This is by design. Each sync cycle is a fresh assessment of the current state. The history of expired Opportunities is data for the Learning system — not a filter on future detection.

---

## 8. Opportunity Deduplication

The deduplication rule is:

> **A new Opportunity is not persisted if an existing Opportunity with the same `(type, subject_type, subject_id)` is currently `open` or `selected`.**

This prevents the Opportunity Engine from stacking identical opportunities across sync cycles.

### Deduplication Logic

```php
$exists = Opportunity::withoutGlobalScopes()
    ->where('company_id', $company->id)
    ->where('type', $candidate->type)
    ->where('subject_type', $candidate->subjectType)
    ->where('subject_id', $candidate->subjectId)
    ->whereIn('status', ['open', 'selected'])
    ->exists();

if ($exists) {
    // Skip — do not persist
}
```

### Company-Level Deduplication

For opportunities whose `subject_type` is `Company` (re_engagement, milestone), `subject_id` is the company's ID. The deduplication rule still applies: only one open `re_engagement` opportunity per company at a time.

### After Selection

Once an Opportunity is `selected`, a new detection run will skip it (status is `selected`, not `open` — still blocked by the rule above). Once the Decision produces a Campaign and the Campaign completes, the Opportunity's cooldown window governs when re-detection can produce a new open opportunity of the same type for the same subject.

### Cooldown Windows

In addition to deduplication of *open* opportunities, the Decision Engine applies a cooldown check: no Opportunity of the same `campaign_type` whose Decision produced a Campaign with `status: completed` within N days.

| Campaign Type | Cooldown |
|---------------|----------|
| `urgency_promotion` | 3 days (urgency is event-driven; events recur) |
| `featured_item` | 14 days |
| `new_arrival` | 7 days |
| `re_engagement` | 14 days |
| `seasonal` | Until the next occurrence of the seasonal event |

Cooldown is checked in `DecisionEngine::evaluate()`, not in `OpportunityEngine::scan()`. The Opportunity is created; the Decision Engine decides whether to act on it now.

---

## 9. OpportunityDetector Interface

```php
namespace App\Services\Opportunity\Detectors\Contracts;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Company;
use Illuminate\Support\Collection;

interface OpportunityDetector
{
    /**
     * The opportunity types this detector can produce.
     * Used by OpportunityEngine to route detectors by type.
     *
     * @return string[]
     */
    public function appliesTo(): array;

    /**
     * Inspect the BusinessBrain and return opportunity candidates.
     * Must not perform database writes.
     * Must not call AiProvider (use OpportunityDetectionAnalyst for AI detection).
     *
     * @return Collection<int, OpportunityCandidate>
     */
    public function detect(Company $company, BusinessBrain $brain): Collection;
}
```

### OpportunityCandidate Value Object

```php
namespace App\Services\Opportunity;

readonly class OpportunityCandidate
{
    public function __construct(
        public string $type,           // opportunity type enum value
        public ?string $subjectType,   // 'CatalogItem', 'Catalog', 'Company', or null
        public ?string $subjectId,     // ULID of the subject, or null
        public string $title,          // short label
        public string $description,    // evidence summary — used for rationale generation
        public ?string $expiresAt,     // ISO 8601 datetime string, or null
    ) {}
}
```

### Detector Contract Rules

1. Detectors **must not** write to the database. They produce candidates; `OpportunityEngine` persists them after scoring and deduplication.
2. Detectors **must not** call `AiProvider`. AI-detected candidates come from `OpportunityDetectionAnalyst`.
3. Detectors **must** return an empty `Collection` when no candidate is found. Never return null.
4. Detectors **must not** throw on sparse data. If required facts are missing, return empty. Log at `debug` level if useful.
5. A single detector may return multiple candidates (e.g., `UrgencyDetector` may return one candidate per ending-soon item).
6. Detectors receive the `BusinessBrain` as their primary data source. Database queries are allowed only to look up specific CatalogItems by ID — and must go through the repository layer.

---

## 10. Rule-Based Detectors vs. AI-Assisted Detectors

Atlas uses a hybrid detection strategy: rule-based detectors run first, then AI supplements.

### Rule-Based Detectors

Rule-based detectors are deterministic. Given the same BusinessBrain, they always produce the same candidates. They are fast, cheap, and auditable.

**MVP rule-based detectors:**

| Class | Type | Trigger |
|-------|------|---------|
| `FeaturedItemDetector` | `featured_item` | Active CatalogItem, no Campaign in last 14–45 days |
| `UrgencyDetector` | `urgency` | `expires_at` within 48 hours, or `catalog.ending_within_48h_count` Fact > 0 |
| `NewArrivalDetector` | `new_arrival` | CatalogItem `created_at` within 48 hours, no prior Campaign |
| `ReEngagementDetector` | `re_engagement` | No completed Campaign in last 14 days, catalog is non-empty |

**Implementation location:** `app/Services/Opportunity/Detectors/`

### AI-Assisted Detection

`OpportunityDetectionAnalyst` uses AI to identify opportunities that rule-based detectors cannot see — emerging patterns, contextual opportunities, and vertical-specific signals that require interpretation.

**When AI detection runs:** After all rule-based detectors have run and candidates have been collected. The AI analyst receives the full BusinessBrain and the list of already-detected candidate types (to avoid duplicating what rules already found).

**What AI detection can identify:**
- Seasonal alignment that isn't in the static calendar
- Brand voice or tone signals that suggest an audience-building opportunity
- Catalog composition patterns (e.g., "unusually high proportion of high-grade silver-age keys" for a comic dealer) that indicate a themed campaign opportunity
- Cross-business patterns learned from Knowledge entries (Phase 8+)

**What AI detection cannot do:**
- Override scores computed by `OpportunityScorer`
- Bypass deduplication
- Create Opportunities without evidence in the BusinessBrain
- Generate a Decision or Campaign

**AI detector output:** A `Collection<OpportunityCandidate>` — identical in structure to rule-based output. The pipeline treats all candidates uniformly after detection.

**Failure handling:** If `OpportunityDetectionAnalyst` throws, rule-based candidates still proceed. AI failure is logged at `warning` level. The engine does not halt.

### Scoring Differences: Rule-Based vs. AI-Detected

AI-detected candidates receive a lower default `confidence_score` cap of 75. The AI cannot observe the business as directly as the rule-based detectors, which operate on verified Facts. This cap is applied by `OpportunityScorer`, not by the analyst.

Rule-based detectors may produce `confidence_score` up to 100 when the supporting Facts have high confidence.

---

## 11. How Opportunities Become Decisions

The path from Opportunity to Decision runs through `DecisionEngine::evaluate()`, which executes as part of the `CommitDecision` job.

### Selection Algorithm

```
1. Load all open Opportunities for the company, ordered by composite_score DESC
2. For each candidate (highest score first):
   a. Apply guard conditions (see below)
   b. If all guards pass: select this Opportunity → proceed to rationale
   c. If any guard fails: mark candidate as dismissed (or skip and try next)
3. If no candidate passes: exit cleanly; schedule retry
```

### Guard Conditions

An Opportunity must pass all three guards before a Decision can be committed:

**Guard 1 — No duplicate open Recommendation:**
No existing Recommendation with `status` in (`pending`, `viewed`) for the same `campaign_type` as this Opportunity would produce.

*Rationale:* The user already has an unreviewed Recommendation. Don't add another of the same type. Respect the user's attention.

**Guard 2 — Cooldown window:**
No Campaign with the same `campaign_type` has `status: completed` within the cooldown window for this type (see Section 8).

*Rationale:* Don't repeat the same campaign type too soon. Allow time for the previous campaign to have effect.

**Guard 3 — Catalog availability:**
If the Opportunity requires a specific CatalogItem (`subject_type = 'CatalogItem'`), that item must still be `active`. If the item was sold, expired, or archived since detection, the Opportunity is dismissed.

*Rationale:* The world has moved. The detected opportunity no longer applies. Dismiss it and re-evaluate.

### Rationale Generation

Once an Opportunity is selected and guards pass, `RationaleGenerationAnalyst` is called before the Decision is persisted. It receives:

- The selected Opportunity (including `description` and all score components)
- The partial Decision (type and channel assignments)
- The full BusinessBrain

It produces a structured rationale with all five required fields:

```json
{
    "why_now": "...",
    "why_this": "...",
    "why_channel": "...",
    "why_works": "...",
    "expected_impact": {
        "summary": "...",
        "reach_estimate": "...",
        "engagement_signal": "..."
    }
}
```

If any of the five keys are missing or empty, `RationaleGenerationFailedException` is thrown. The Decision is not persisted. The job fails. This is not retried automatically — it indicates a prompt or schema issue requiring developer attention.

### Decision Persisted

After rationale validation, a `Decision` row is written with `status: "pending"`. The Opportunity's `status` is set to `"selected"`. `DecisionCommitted` is fired. The `DispatchCampaignPreparation` listener dispatches `PrepareCampaign`.

**A Decision record without all four rationale keys must not exist in the database.** This invariant is enforced by `DecisionService` validation, not by a database constraint. Tests must verify this.

---

## 12. How Decisions Become Campaigns

This section is a brief summary; the Campaign Engine will have its own spec in a future milestone. It is included here to close the loop.

```
Decision (pending)
    ↓
PrepareCampaign job (ai queue)
    ↓
CampaignPreparationAnalyst
    ↓
Campaign (draft) + GenerateContent jobs per channel
    ↓
ContentAssets (draft, one per channel)
    ↓
Recommendation (pending)
    ↓
User sees Recommendation in UI
```

### Decision Fields That Drive the Campaign

| Decision Field | How Used by Campaign Engine |
|----------------|-----------------------------|
| `campaign_type` | Determines the campaign angle and AI prompt template |
| `channel_ids` | One `GenerateContent` job dispatched per channel |
| `rationale.why_this` | Informs the campaign `strategy` |
| `rationale.why_works` | Informs `positioning` |
| `rationale.expected_impact` | Sets the performance baseline for future Learning |
| `opportunity.subject_type/id` | The CatalogItem (or Company) the campaign is about |

### Campaign Scope for Milestone 4

Milestone 4 produces the Decision and transitions it to `status: "pending"`. It fires `DecisionCommitted`. The Campaign Engine (`PrepareCampaign`, `GenerateContent`, `Recommendation`) is Milestone 5 work.

At the end of Milestone 4:
- At least one `decisions` row exists with `status: "pending"` and all rationale fields populated
- `Opportunity.status = "selected"`
- `DecisionCommitted` event has fired
- No Campaign, ContentAsset, or Recommendation rows exist yet

---

## 13. Acceptance Criteria

These criteria define "done" for Milestone 4. They are verifiable by automated tests.

### Opportunity Detection

- [ ] `DetectOpportunities` job runs after `DigitalTwinActivated` event (via listener)
- [ ] At least one `opportunities` row is created for a company with a populated BusinessBrain
- [ ] All four score components are persisted on the Opportunity row
- [ ] `composite_score` matches the formula: `(relevance × 0.30) + (timing × 0.25) + (confidence × 0.25) + (urgency × 0.20)`
- [ ] No Opportunity with `composite_score < 30` is persisted
- [ ] No duplicate `(type, subject_type, subject_id)` with `status: open` or `selected` is created
- [ ] `OpportunityDetected` event is fired for each persisted Opportunity
- [ ] `CommitDecision` job is dispatched after detection
- [ ] If `OpportunityDetectionAnalyst` throws, rule-based candidates still proceed (AI failure is non-fatal)

### Rule-Based Detectors

- [ ] `FeaturedItemDetector` fires for an active CatalogItem with no Campaign in 14+ days
- [ ] `UrgencyDetector` fires when `expires_at` is within 48 hours
- [ ] `NewArrivalDetector` fires for a CatalogItem created in the last 48 hours
- [ ] `ReEngagementDetector` fires when no Campaign has completed in 14+ days and the catalog is non-empty
- [ ] No detector creates database writes
- [ ] Empty BusinessBrain (no Facts, no Knowledge) produces zero candidates from any rule-based detector

### Decision Engine

- [ ] `CommitDecision` is `ShouldBeUnique` per company (prevents concurrent Decisions)
- [ ] Guard conditions are applied in order before committing
- [ ] No Decision is committed if an open Recommendation of the same `campaign_type` already exists
- [ ] No Decision is committed if the cooldown window has not elapsed for the same `campaign_type`
- [ ] A CatalogItem that was sold or archived after Opportunity detection causes that Opportunity to be dismissed
- [ ] `RationaleGenerationFailedException` is thrown if any of the five rationale keys is missing or empty
- [ ] A Decision row cannot be persisted without all rationale keys present — service-layer validation enforces this
- [ ] `decisions.status = "pending"` after successful commit
- [ ] `opportunities.status = "selected"` after successful commit
- [ ] `DecisionCommitted` event fired
- [ ] `PrepareCampaign` job dispatched (may be a listener stub in Milestone 4)

### Scoring

- [ ] `OpportunityScorer::score()` returns all four component scores and the composite
- [ ] AI-detected candidates are capped at `confidence_score ≤ 75`
- [ ] Tie-breaking follows the defined type hierarchy when composite scores are equal

### Expiry

- [ ] `ExpireOpportunities` job transitions `open` Opportunities past `expires_at` to `expired`
- [ ] The job is idempotent — running it twice does not change already-expired rows
- [ ] A test verifies that an expired Opportunity is not re-expired

### Tests

- [ ] All detectors have unit tests using a stubbed `BusinessBrain` (no DB required)
- [ ] `OpportunityScorer` has unit tests for each score component and the composite formula
- [ ] `DecisionEngine` has feature tests for each guard condition
- [ ] Deduplication is tested: a second scan does not create a duplicate open Opportunity
- [ ] Cooldown enforcement is tested: a recent completed Campaign of the same type blocks the Decision
- [ ] `FakeAiProvider` is used for all AI-touching tests; no test calls a real AI provider

---

## 14. Future Extensibility

The Opportunity Engine is designed to grow without structural changes to the core pipeline.

### Adding a New Detector

1. Create a new class implementing `OpportunityDetector`
2. Return the correct `appliesTo()` type string(s)
3. Register it in `OpportunityEngine` (via constructor injection or the service provider)
4. Write unit tests

No changes to the engine, scorer, or Decision Engine are required.

### Adding a New Opportunity Type

1. Add the new type to the `opportunities.type` enum migration
2. Define its scoring profile and default `expires_at` logic in `OpportunityScorer`
3. Add it to the cooldown window table in `DecisionEngine`
4. Create or update a detector
5. Add it to the `campaign_type` mapping in `DecisionEngine`

### Weighted Scoring Per Company (Phase 8)

The composite formula currently uses fixed weights. In Phase 8, `Learning` records from approval and rejection history will calibrate per-company weights. The `OpportunityScorer` should accept weight overrides:

```php
$scorer->score($candidate, $brain, weights: $company->scoringWeights());
```

The default weights are the MVP formula. Company-level weights are Phase 8.

### Cross-Company Pattern Detection (Phase 8)

`OpportunityDetectionAnalyst` will eventually have access to anonymized cross-company patterns from the Learning system. Detectors and the analyst currently receive only the company's own BusinessBrain. The interface does not need to change — the BusinessBrain can carry aggregate signals in a future `globalContext` field.

### Opportunity Priority Queues

Currently, `CommitDecision` selects one Opportunity per run (the highest-scoring candidate that passes guards). In future phases, the Decision Engine may queue multiple Decisions for different opportunity types in a single cycle — providing the user with a ranked list rather than a single Recommendation. The current design does not prevent this; `DecisionEngine::evaluate()` would simply loop and commit multiple Decisions instead of returning after the first.

### Vertical-Specific Detectors

New verticals (beyond comic books and exotic cars) will add vertical-specific detectors. A restaurant might have a `slow_period` detector (Thursday lunch gap) or a `popular_item` detector. Each is a new class implementing `OpportunityDetector` — no changes to the engine.

### Manual Opportunity Creation (Future)

Users may eventually be able to manually create Opportunities ("I want to promote this item"). A `ManualOpportunity` would bypass the detection pipeline and enter the lifecycle at `open` status with user-supplied evidence. The Decision Engine and Campaign Engine process it identically. This is not in scope for MVP.

---

## 15. Milestone 4 Implementation Scope

This section clarifies exactly what Milestone 4 builds, what it defers, and which supporting tables are permitted to be introduced.

### Required Opportunity Types

| Type | Required in M4 | Notes |
|------|---------------|-------|
| `featured_item` | **Yes** | Requires `CatalogItem` lookup |
| `urgency` | **Yes** | Requires `CatalogItem.expires_at` |
| `new_arrival` | **Yes** | Requires `CatalogItem.created_at` |
| `re_engagement` | **Yes** | Company-scoped; no item lookup required |
| `seasonal` | Optional | Calendar event registry not required in M4; type is defined in the enum for future use |
| `milestone` | Optional | Deferred; type defined in the enum only |

Milestone 4 ships with four working detectors. `seasonal` and `milestone` are named in the type enum and documented in this spec but produce zero candidates in M4.

### Supporting Tables Permitted in Milestone 4

Milestone 4 may introduce minimal models and migrations for the following, to the extent required by Opportunity detection, deduplication, and Decision guard conditions. Nothing beyond that extent should be implemented.

#### `CatalogItem`

**Why needed:** `FeaturedItemDetector`, `UrgencyDetector`, and `NewArrivalDetector` operate on individual catalog items. Deduplication uses `subject_id` to identify which item an open Opportunity refers to. Guard condition 3 checks whether the item is still active before committing a Decision.

**What to implement:**
- Migration and model with the fields necessary for detection: `catalog_id`, `company_id`, `title`, `status` (active/sold/archived/draft), `expires_at` (nullable), `created_at`
- `BelongsToCompany` trait and `HasUlids`
- `active()` local scope
- Relationship from `Catalog hasMany CatalogItem`

**What not to implement:** item content fields, media handling, pricing, schema-driven metadata, storefront rendering, feed ingestion, or any form of CatalogItem CRUD UI.

#### `Campaign`

**Why needed:** Cooldown enforcement in `DecisionEngine::evaluate()` queries for a completed Campaign of the same `campaign_type` within the cooldown window. Without at least a `campaigns` table with `status` and `campaign_type`, cooldown is untestable.

**What to implement:**
- Migration and model with: `company_id`, `campaign_type`, `status` (draft/active/completed/cancelled), `decision_id` (nullable FK), `completed_at` (nullable)
- `BelongsToCompany` trait and `HasUlids`
- No content fields, no channel assignments, no ContentAsset relationship required in M4

**What not to implement:** Campaign preparation logic, content generation, `CampaignPreparationAnalyst`, `GenerateContent` jobs, ContentAssets, channel rendering, or any publishing behavior. The Campaign Engine is Milestone 5.

#### `Recommendation`

**Why needed:** Guard condition 1 in `DecisionEngine::evaluate()` checks for an existing open Recommendation of the same `campaign_type`. Without a `recommendations` table, this guard condition cannot be enforced.

**What to implement:**
- Migration and model with: `company_id`, `decision_id`, `campaign_type`, `status` (pending/viewed/approved/rejected/expired)
- `BelongsToCompany` trait and `HasUlids`

**What not to implement:** Recommendation assembly logic, content previews, approval workflow, `ApprovalService`, `ApprovalController`, in-app notifications, or any UI surface. That is Milestone 5.

### Explicit Out-of-Scope for Milestone 4

The following must not be implemented during Milestone 4, even if the scaffolding is tempting:

- Campaign preparation or `CampaignPreparationAnalyst`
- Content generation or `ContentGenerationAnalyst`
- Marketing Assets (`ContentAsset` model/migration)
- Channel Renderers or channel-specific content
- Publishing or Execution records
- Approval workflow or `ApprovalService`
- Recommendation UI or in-app notifications

Milestone 4 ends when:
- At least one `opportunities` row exists with a composite score, all four component scores, and `status: open`
- At least one `decisions` row exists with `status: pending` and all five rationale fields populated
- `DecisionCommitted` event has fired
- `CommitDecision` job dispatches a stub `PrepareCampaign` job (listener wired, job is a no-op in M4)

---

## Appendix — Scoring Examples

### CBB Auctions — Urgency (12 auctions ending in 48h)

| Component | Score | Reasoning |
|-----------|-------|-----------|
| relevance | 85 | Auctions are the core revenue mechanism |
| timing | 95 | Hard deadline in < 48 hours |
| confidence | 80 | `catalog.ending_within_48h_count` Fact confirmed by crawler |
| urgency | 98 | Immovable deadline — cannot be deferred |
| **composite** | **89** | `(85×0.30) + (95×0.25) + (80×0.25) + (98×0.20) = 25.5 + 23.75 + 20.0 + 19.6` |

### Exotic Car Dealer — Featured Item (Ferrari 275 GTB, 45 days in inventory)

| Component | Score | Reasoning |
|-----------|-------|-----------|
| relevance | 90 | Marquee vehicle, matches brand identity |
| timing | 75 | 45 days is a long gap; timing pressure building |
| confidence | 70 | No prior campaign history for this vehicle; crawl-based estimate |
| urgency | 40 | No hard deadline; vehicle isn't going anywhere tomorrow |
| **composite** | **70** | `(90×0.30) + (75×0.25) + (70×0.25) + (40×0.20) = 27.0 + 18.75 + 17.5 + 8.0` |

### Dealer — Re-Engagement (No campaigns in 21 days)

| Component | Score | Reasoning |
|-----------|-------|-----------|
| relevance | 70 | Any business benefits from staying visible |
| timing | 80 | 21-day gap is significant audience engagement risk |
| confidence | 60 | `marketing.days_since_last_campaign` Fact inferred, not directly observed |
| urgency | 55 | Urgency grows with time but no hard deadline |
| **composite** | **67** | `(70×0.30) + (80×0.25) + (60×0.25) + (55×0.20) = 21.0 + 20.0 + 15.0 + 11.0` |

In this scenario, if both the featured_item and re_engagement opportunities exist, the Ferrari (composite 70) is selected over re_engagement (composite 67) — and the rationale references the vehicle directly.

---

*This document is authoritative for Milestone 4. Changes to scoring formulas, guard conditions, detector contracts, or lifecycle states must be reflected here before implementation begins.*
