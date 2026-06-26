# Decision Engine — Design Specification

**Version:** 1.0  
**Status:** Approved — authoritative specification for Milestone 4  
**Depends on:** `specs/core/domain-model.md`, `specs/core/opportunity-engine.md`, `specs/product/mvp-workflow.md`

When this document conflicts with others, this document wins for anything related to the Decision Engine, guard conditions, rationale generation, and the `CommitDecision` job. Update the others.

---

## Milestone 4 Implementation Scope

Milestone 4 implements the Decision Engine.

The output of Milestone 4 is:

```
Opportunity selected
→ Decision created
→ rationale generated
→ DecisionCommitted event fired
```

Milestone 4 must not implement:

- Campaign preparation or `PrepareCampaign` behavior beyond a no-op stub
- Campaign Engine logic
- Marketing Assets or ContentAssets
- Channel renderers
- Any publishing integrations (Facebook, Instagram, Email, SMS, LinkedIn, Google Ads, Meta Ads, Blog, Landing Pages)
- Recommendation assembly or UI
- Approval workflow
- Analytics
- Learning records

Campaign creation begins in Milestone 5.

---

## 1. What Is a Decision?

A **Decision** is Atlas's committed choice to act on a specific Opportunity.

It is the moment the system stops evaluating and starts preparing. Before a Decision, Atlas has a ranked list of Opportunities. After a Decision, Atlas has a specific plan — a campaign type, a channel selection, and a rationale — that it will carry forward.

A Decision answers five questions, in full, before it can exist:

| Question | Field |
|----------|-------|
| Why is now the right time? | `rationale.why_now` |
| Why this campaign, for this subject? | `rationale.why_this` |
| Why these channels? | `rationale.why_channel` |
| Why do we expect this to work? | `rationale.why_works` |
| What outcome do we expect? | `rationale.expected_impact` |

**A Decision without complete rationale must not exist in the database.** This is a hard invariant, enforced in `DecisionService` before any write.

### Decisions Are Irrevocable

Once committed, a Decision cannot be undone. If the user rejects the resulting Recommendation, the rejection is recorded as a Learning — but the Decision record itself is never deleted. The record of what Atlas decided and why is part of the audit trail.

### One Decision Per Opportunity

Every `Opportunity` may produce at most one `Decision`. The `decisions.opportunity_id` column has a unique constraint. An Opportunity in `selected` status cannot be re-selected.

---

## 2. How Decisions Differ from Opportunities

| Dimension | Opportunity | Decision |
|-----------|-------------|----------|
| Created by | `OpportunityEngine::scan()` | `DecisionEngine::evaluate()` |
| Represents | A detected marketing moment | A committed plan of action |
| Multiple allowed | Yes — many open at once | No — one per Opportunity; one pending per company |
| Contains rationale | No — only evidence description | Yes — five required fields |
| Contains channel selection | No | Yes — `channel_ids` array |
| Contains campaign type | No — only opportunity type | Yes — `campaign_type` enum |
| Expiration | Yes — `expires_at` | No — Decisions do not expire |
| Created by AI | Partly (AI-assisted detectors) | Rationale is AI-generated; selection is deterministic |
| Reversible | Can be dismissed or expired | Not reversible; only rejected by user |
| Triggers downstream | `CommitDecision` job | `PrepareCampaign` job (Milestone 5) |

The Opportunity Engine asks: *should we act?*  
The Decision Engine asks: *on what, how, and why?*

---

## 3. Decision Lifecycle

```
[Opportunity selected]
         ↓
      pending          ← Decision committed; Campaign preparation begins
         ↓
    recommended        ← Recommendation surfaced to user (Milestone 5)
         ↓
  ┌──────┴──────┐
approved      rejected
  ↓               ↓
executed      (Learning recorded; next cycle re-evaluates)
```

**Milestone 4 boundary:** Decisions reach `pending` status and stay there. The `recommended` → `approved` / `rejected` path is Milestone 5+.

### Transition Rules

| Transition | Who triggers it | Conditions |
|-----------|-----------------|------------|
| Created → `pending` | `DecisionService::commit()` | All five rationale fields present and non-empty |
| `pending` → `recommended` | `RecommendationService` (M5) | Recommendation record created |
| `recommended` → `approved` | `ApprovalService` (M5) | User approves |
| `recommended` → `rejected` | `ApprovalService` (M5) | User rejects |
| `approved` → `executed` | Execution pipeline (M6) | All executions completed |
| `pending` / `recommended` → `cancelled` | System | Opportunity expired or catalog item removed post-decision |

---

## 4. Decision Statuses

| Status | Meaning | Set by |
|--------|---------|--------|
| `pending` | Decision committed; Recommendation being prepared | `DecisionService::commit()` |
| `recommended` | Recommendation surfaced to the user | `RecommendationService` (M5) |
| `approved` | User approved; Campaign is executing | `ApprovalService` (M5) |
| `rejected` | User rejected; Learning recorded | `ApprovalService` (M5) |
| `executed` | Campaign fully completed | Execution pipeline (M6) |
| `cancelled` | Cancelled before or during presentation | System (guard violation post-commit, or user cancels) |

In Milestone 4: only `pending` is written. No other status transitions are implemented.

---

## 5. Decision Types (`campaign_type`)

The `campaign_type` is derived from the selected Opportunity's `type`. It determines the angle of the resulting campaign and the prompt templates used by the Campaign Engine (Milestone 5).

| Opportunity type | Maps to `campaign_type` | Campaign angle |
|-----------------|------------------------|----------------|
| `featured_item` | `featured_item` | Spotlight a specific catalog item |
| `urgency` | `urgency_promotion` | Drive action before a hard deadline |
| `new_arrival` | `featured_item` | Introduce a new item to the audience |
| `re_engagement` | `re_engagement` | Re-establish audience contact |
| `seasonal` | `seasonal` | Leverage a calendar moment |
| `milestone` | `re_engagement` | Celebrate a business milestone |

The `campaign_type` is stored on the Decision and used by:
- The cooldown guard (no same `campaign_type` completed recently)
- The duplicate recommendation guard (no open Recommendation of same `campaign_type`)
- The Campaign Engine prompt routing (Milestone 5)

---

## 6. Decision Inputs

`DecisionEngine::evaluate()` receives the following inputs. All are required.

### 6a. Selected Opportunity

The Opportunity chosen from the ranked open queue. The Decision Engine iterates candidates in descending `composite_score` order, applying guard conditions to each until one passes.

What the Decision Engine reads from the Opportunity:
- `type` → maps to `campaign_type`
- `subject_type` + `subject_id` → the entity the campaign is about
- `description` → passed to `RationaleGenerationAnalyst` as evidence context
- `composite_score`, `relevance_score`, `timing_score`, `confidence_score`, `urgency_score` → inform rationale and confidence
- `expires_at` → verified to be in the future before committing

### 6b. BusinessBrain

The `BusinessBrain` value object assembled by `BusinessBrainService::for($company)`. The Decision Engine reads:

- `activeFacts` — used by `RationaleGenerationAnalyst` to ground the rationale in observed reality
- `activeKnowledge` — used to enrich rationale with synthesized insights
- `recentCampaigns` — used for cooldown guard evaluation
- `catalog` — used to confirm catalog availability
- `company` — used for company context (industry, brand voice) in rationale generation

The Decision Engine does not query the database directly. Everything it needs is in the `BusinessBrain` or resolved via repositories before the engine runs.

### 6c. Score Components

All four score components from the selected Opportunity are passed to `RationaleGenerationAnalyst`. They inform the confidence score on the Decision and the `expected_impact` field:

- High `urgency_score` → rationale emphasises time pressure
- High `confidence_score` → higher Decision `confidence_score`; rationale is more assertive
- Low `confidence_score` → rationale acknowledges uncertainty; `expected_impact` range is wider
- High `relevance_score` → rationale is more specific to the business

### 6d. Guard Conditions

Evaluated per candidate before any rationale is generated. See Section 7.

### 6e. Company Context

From `BusinessBrain.company`:
- `industry` — used to calibrate rationale tone and terminology
- `brand.voice` / `brand.tone` — used by `RationaleGenerationAnalyst` to match brand language
- `settings` — any company-level preferences that affect channel selection

---

## 7. Guard Conditions

Guard conditions are evaluated in order, from cheapest to most expensive. A candidate that fails any guard is skipped; the engine moves to the next-highest-scoring Opportunity.

If no candidate passes all guards, no Decision is committed. The `CommitDecision` job exits cleanly and logs the outcome. The next scan cycle will detect a fresh set of Opportunities and try again.

### Guard 1 — Minimum Score Guard

**Condition:** `opportunity.composite_score >= 30`

**Why:** Opportunities below 30 represent weak signals — insufficient evidence, poor timing, and low relevance simultaneously. Committing a Decision on a low-quality signal wastes the user's attention.

**Implementation:** `OpportunityScorer` already enforces this at detection time — Opportunities below 30 are not persisted. This guard is a safety check in case the threshold is ever lowered or a stale Opportunity is encountered.

**On failure:** Skip candidate silently. If all candidates are below 30, log and exit.

---

### Guard 2 — Duplicate Recommendation Guard

**Condition:** No Recommendation with `status` in (`pending`, `viewed`) exists for this company with the same `campaign_type` as the candidate would produce.

**Why:** The user already has an unreviewed Recommendation of this type. Adding another of the same type before they respond to the first creates confusion and erodes trust. Respect the user's attention.

**Query (Milestone 4 — stub check):**

```sql
SELECT COUNT(*) FROM recommendations
WHERE company_id = ?
  AND campaign_type = ?
  AND status IN ('pending', 'viewed')
```

In Milestone 4, this table exists but will always be empty (Recommendation creation is M5). The guard should still execute correctly — an empty `recommendations` table means the guard always passes.

**On failure:** Skip candidate. Try the next opportunity with a different `campaign_type`.

---

### Guard 3 — Campaign Cooldown Guard

**Condition:** No Campaign with the same `campaign_type` has `status = 'completed'` with `completed_at` within the cooldown window for this type.

**Default cooldown windows:**

| `campaign_type` | Cooldown |
|----------------|----------|
| `urgency_promotion` | 3 days |
| `featured_item` | 14 days |
| `re_engagement` | 14 days |
| `seasonal` | Until next occurrence |

**Why:** Running the same campaign type too soon repeats the message before the audience has had time to act on or forget the previous one. A second urgency campaign 2 days after the first trains the audience to ignore the urgency signal.

**Query:**

```sql
SELECT COUNT(*) FROM campaigns
WHERE company_id = ?
  AND campaign_type = ?
  AND status = 'completed'
  AND completed_at >= NOW() - INTERVAL ? DAY
```

In Milestone 4, `campaigns` exists but will have no `completed` rows. The guard always passes in M4.

**On failure:** Skip candidate. Try the next opportunity with a different `campaign_type` or one outside its cooldown window.

---

### Guard 4 — Catalog Availability Guard

**Condition:** If the Opportunity has `subject_type = 'CatalogItem'`, the referenced CatalogItem must currently have `status = 'active'`.

**Why:** The world may have changed between when the Opportunity was detected and when the Decision Engine runs. An item that was active during detection may have been sold, expired, or archived by the time the Decision is committed. A campaign for a sold item is harmful to brand trust.

**Query:**

```sql
SELECT status FROM catalog_items
WHERE id = ?
  AND company_id = ?
```

This is the only direct database query the Decision Engine makes. All other data comes from the `BusinessBrain`.

**On failure:** Dismiss the Opportunity (`status = 'dismissed'`). Do not skip — this Opportunity is permanently invalidated. Continue to the next candidate.

**For company-level Opportunities** (`subject_type = 'Company'` or `subject_type = 'Catalog'`): this guard does not apply. The guard is skipped for re_engagement and company-level seasonal opportunities.

---

### Guard 5 — Channel Availability Guard

**Condition:** At least one Channel exists for the company with `is_active = true`.

**Why:** A Decision commits to a channel selection. If no channels are configured or active, the resulting campaign has nowhere to go. Committing a Decision without viable channels produces a dead end in the pipeline.

**Implementation:** The Decision Engine resolves `channel_ids` during evaluation. If the company has no active Channels, the engine cannot form a valid Decision and exits.

**In Milestone 4:** The `channels` table is queried. If no channels exist, the Decision Engine logs the reason and exits cleanly. This is not an error state — it is a configuration gap that the user needs to address. In tests, at least one Channel record should be seeded for the test company.

**On failure:** Do not attempt other candidates. No Decision can be committed if there are no channels. Exit and log.

---

## 8. Decision Scoring and Selection Rules

The Decision Engine is not a scoring engine — scoring belongs to `OpportunityScorer`, which runs at detection time. By the time `DecisionEngine::evaluate()` runs, every candidate already has a `composite_score`.

The Decision Engine's selection rule is simple:

> **Select the highest-scoring open Opportunity that passes all guard conditions.**

### Selection Algorithm

```
1. Load all open Opportunities for the company
   ORDER BY composite_score DESC, detected_at ASC

2. For each candidate:
   a. Guard 1: minimum score check (composite_score >= 30)
   b. Guard 2: duplicate recommendation check
   c. Guard 3: campaign cooldown check
   d. Guard 4: catalog availability check (if subject_type = CatalogItem)
   e. If all guards pass → SELECT this candidate and proceed
   f. If any guard fails → SKIP (or DISMISS for Guard 4)

3. If no candidate selected → exit cleanly; log reason; schedule retry
```

### Why Score-Ordered Selection

Ordering by score ensures Atlas always acts on its strongest signal first. A business with an urgency opportunity (composite 89) and a featured_item opportunity (composite 70) correctly prioritises the urgent action.

### Tie-Breaking

When two Opportunities share an identical composite score:

1. Type hierarchy: `urgency` > `new_arrival` > `featured_item` > `re_engagement` > `seasonal` > `milestone`
2. If still tied: earlier `detected_at` wins

### No Randomisation

The Decision Engine is deterministic. Given the same set of Opportunities and the same guard condition state, it always selects the same candidate. This makes the system auditable and debuggable.

---

## 9. Channel Selection

Channel selection is part of Decision commitment, not a separate step.

The Decision Engine resolves `channel_ids` based on:

1. **Active company channels:** Query `channels` where `company_id = ? AND is_active = true`
2. **Opportunity type affinity:** Some opportunity types favour certain channels:
   - `urgency_promotion` → email and social (immediacy)
   - `featured_item` → social and blog/landing page (visual, storytelling)
   - `re_engagement` → email (direct reach)
3. **Fallback:** If no type-affinity rule matches, select all active channels

In Milestone 4: channel selection logic may be minimal — the primary concern is that `channel_ids` is a non-empty array on the committed Decision. The full channel-affinity rules are Milestone 5+ refinement.

---

## 10. Required Rationale Fields

Every Decision must include a complete rationale. Incomplete rationale is not a warning — it is a hard failure. `RationaleGenerationFailedException` is thrown and the Decision is not persisted.

### `why_now`

**Purpose:** Explains the timing signal. Why is this the right moment — not yesterday, not next week?

**Content:** References the specific condition that makes now urgent or timely. For urgency opportunities, cites the deadline. For re_engagement, cites the gap duration. For new arrivals, cites the novelty window.

**Bad example:** "Now is a good time to promote."  
**Good example:** "12 of your active auctions close within 48 hours, including 3 golden-age keys above $2,000. This promotion window expires tomorrow."

---

### `why_this`

**Purpose:** Explains why this specific campaign — this item, this angle, this campaign type — is the right choice from all available options.

**Content:** References the subject entity (if any) and the evidence that makes it the strongest candidate. For featured_item, explains why this item over other active items. For re_engagement, explains why the gap matters.

**Bad example:** "This item is in your catalog."  
**Good example:** "The 1967 Ferrari 275 GTB has been in inventory for 45 days with no promotion. At $485,000, it's your highest-value vehicle — featuring it maximises attention on your marquee piece."

---

### `why_channel`

**Purpose:** Explains why these specific channels were selected for this campaign.

**Content:** References the match between channel characteristics and campaign goals. Visual campaigns favour Instagram/social. Urgency campaigns favour email. Long-form storytelling favours blog or email.

**Bad example:** "These are your active channels."  
**Good example:** "Instagram leads with the visual impact the car deserves. Email reaches collectors directly with a private-viewing invitation. Together they cover both discovery and conversion."

---

### `why_works`

**Purpose:** Explains the mechanism of action — why this campaign is expected to produce results for this business.

**Content:** References either historical performance patterns, industry norms, or logical reasoning grounded in the BusinessBrain. Should be specific, not generic.

**Bad example:** "Marketing campaigns typically produce results."  
**Good example:** "Featured vehicle campaigns in premium inventory businesses generate 2–4× higher engagement than generic inventory posts. Your audience expects marquee pieces — this matches that expectation."

---

### `expected_impact`

**Purpose:** Provides a structured estimate of the campaign's outcome. Used on the Recommendation card to give the user a basis for their approval decision.

**Required structure:**

```json
{
    "summary": "string — one sentence summary of expected outcome",
    "reach_estimate": "string — estimated audience reach",
    "engagement_signal": "string — expected engagement behaviour",
    "confidence_basis": "string — what the estimate is grounded in"
}
```

All four keys are required. The values may be ranges or qualitative descriptions when precise data is unavailable — but they must not be empty.

**Bad example:**
```json
{"summary": "Good results expected."}
```

**Good example:**
```json
{
    "summary": "Strong engagement expected given the vehicle's rarity and price point.",
    "reach_estimate": "~600–1,000 accounts reached on Instagram per post",
    "engagement_signal": "Luxury vehicle posts typically see 3–5% engagement rate vs. 1% average",
    "confidence_basis": "No prior campaign history for this vehicle; estimate based on inventory value and industry benchmarks"
}
```

### Validation Rule

`DecisionService::commit()` validates the rationale before any database write:

```php
$required = ['why_now', 'why_this', 'why_channel', 'why_works', 'expected_impact'];
foreach ($required as $key) {
    if (empty($rationale[$key])) {
        throw new RationaleGenerationFailedException("Missing required rationale key: {$key}");
    }
}

$requiredImpact = ['summary', 'reach_estimate', 'engagement_signal', 'confidence_basis'];
foreach ($requiredImpact as $key) {
    if (empty($rationale['expected_impact'][$key])) {
        throw new RationaleGenerationFailedException("Missing required expected_impact key: {$key}");
    }
}
```

This validation runs on the raw AI response after parsing. It does not run again at the Eloquent model level — the service layer is the enforcement point.

---

## 11. RationaleGenerationAnalyst Contract

`RationaleGenerationAnalyst` is the only AI-calling component in the Decision Engine pipeline. It is an `Analyst` implementation — no other class in the Decision Engine may call `AiProvider` directly.

### Inputs

```php
public function analyze(
    Opportunity $opportunity,
    array $partialDecision,   // campaign_type, channel_ids (not yet persisted)
    BusinessBrain $brain,
): array
```

The partial Decision is an array (not an Eloquent model) because it has not been persisted yet. The analyst must not trigger persistence side-effects.

### What the Analyst Receives in Context

The prompt passed to the AI provider includes:

- **Company identity:** name, industry, brand voice, brand tone
- **Opportunity:** type, title, description (the evidence summary from the detector), all four score components
- **Campaign type and channels selected**
- **Active Facts:** key-value pairs from `BusinessBrain.activeFacts`
- **Active Knowledge:** synthesised insights from `BusinessBrain.activeKnowledge`
- **Subject entity** (if `subject_type = 'CatalogItem'`): title, status, price, expires_at, promoted_at

The analyst does not receive the full list of other open Opportunities — only the selected one.

### Output

The analyst returns a raw `array` parsed from the AI response JSON. It is the caller's responsibility to validate the structure:

```php
[
    'why_now'         => string,
    'why_this'        => string,
    'why_channel'     => string,
    'why_works'       => string,
    'expected_impact' => [
        'summary'           => string,
        'reach_estimate'    => string,
        'engagement_signal' => string,
        'confidence_basis'  => string,
    ],
]
```

### Prompt Design

- **Version:** `1.0` (must be stored on the Decision record as `prompt_version`)
- **Temperature:** `0.4` — some creativity is appropriate for rationale language; lower than content generation but higher than fact extraction
- **Output format:** Structured JSON via AI provider tool-use (Anthropic) or JSON mode (OpenAI)
- **Tone constraint:** The prompt must instruct the AI to write in the company's brand voice, using brand tone as a modifier. Rationale is user-facing — it must be legible and credible, not technical.
- **Grounding instruction:** The prompt must explicitly instruct the AI to ground every claim in the provided Facts or Knowledge. It must not invent performance statistics or make claims that cannot be traced to the BusinessBrain context.

### Failure Handling

If the AI provider is unavailable, the job retries up to 3× with exponential backoff. After 3 failures, the job fails and is sent to the failed job queue. An alert is logged. No Decision is committed.

If the AI returns malformed JSON, `StructuredResponseParser` throws. The job fails. No retry for schema failures — this indicates a prompt issue, not a transient error.

If the AI returns valid JSON but with missing or empty keys, `RationaleGenerationFailedException` is thrown by `DecisionService`. The job fails. The Opportunity is not marked `selected`. It remains `open` and will be re-evaluated on the next cycle.

---

## 12. How Decisions Become Campaigns (Milestone 5)

This section is a summary of what happens after Milestone 4. It is included to clarify the full pipeline.

After `DecisionCommitted` fires, the `DispatchCampaignPreparation` listener dispatches `PrepareCampaign`.

In Milestone 4, `PrepareCampaign` is a no-op stub — it is wired and dispatched but does nothing. Its implementation is Milestone 5.

In Milestone 5:

```
DecisionCommitted
    ↓
PrepareCampaign (ai queue)
    ↓
CampaignPreparationAnalyst
    → creates Campaign (status: draft)
    → dispatches GenerateContent per channel
    ↓
GenerateContent (ai queue, one per channel)
    ↓
ContentGenerationAnalyst
    → creates ContentAsset per channel (status: draft)
    ↓
All assets created → CreateRecommendation
    ↓
RecommendationService
    → creates Recommendation (status: pending)
    → updates Decision (status: recommended)
    → fires RecommendationCreated
    → sends in-app notification
```

**Decision fields that drive the Campaign Engine:**

| Decision field | How used in Milestone 5 |
|---------------|-------------------------|
| `campaign_type` | Selects prompt template for `CampaignPreparationAnalyst` |
| `channel_ids` | One `GenerateContent` job per channel |
| `rationale.why_this` | Informs `Campaign.strategy` |
| `rationale.why_works` | Informs `Campaign.positioning` |
| `rationale.expected_impact` | Sets the performance baseline for Measurement |
| `opportunity.subject_type/id` | The CatalogItem (or Company) the Campaign is about |
| `confidence_score` | Displayed on the Recommendation card |

---

## 13. What Milestone 4 Implements

Milestone 4 delivers a fully working Decision Engine. This is the complete implementation list.

### Database / Models

- `opportunities` table + migration + `Opportunity` model (full implementation including polymorphic subject)
- `decisions` table + migration + `Decision` model
- `catalog_items` table + migration + `CatalogItem` model (minimal — fields required for detection and guard 4 only)
- `campaigns` table + migration + `Campaign` model (minimal — `status` and `campaign_type` for guard 3 only)
- `recommendations` table + migration + `Recommendation` model (minimal — `status` and `campaign_type` for guard 2 only)
- `channels` table + migration + `Channel` model (required for guard 5 and channel selection)

### Services

- `OpportunityEngine::scan()` — runs detectors, scores, deduplicates, persists
- `OpportunityScorer::score()` — composite formula; minimum threshold enforcement
- Four rule-based detectors: `FeaturedItemDetector`, `UrgencyDetector`, `NewArrivalDetector`, `ReEngagementDetector`
- `OpportunityDetectionAnalyst` (AI-assisted; non-fatal on failure)
- `DecisionEngine::evaluate()` — ordered selection with all five guards
- `DecisionService::commit()` — rationale validation; Decision persistence; Opportunity update
- `RationaleGenerationAnalyst` — AI-powered rationale generation
- `RationaleGenerationPrompt` — versioned prompt; temperature 0.4
- `StructuredResponseParser` — already implemented in M3; reused

### Jobs

- `DetectOpportunities` — runs `OpportunityEngine::scan()`; on `default` queue
- `CommitDecision` — `ShouldBeUnique` per company; on `ai` queue; runs `DecisionEngine::evaluate()`
- `ExpireOpportunities` — nightly maintenance; transitions stale `open` Opportunities to `expired`; on `maintenance` queue
- `PrepareCampaign` — **no-op stub only**; wired to `DecisionCommitted` listener; dispatched but does nothing

### Events and Listeners

- `OpportunityDetected` — fired per persisted Opportunity
- `DecisionCommitted` — fired after Decision persisted; carries Decision record
- Listener: `TriggerDecisionEvaluation` → dispatches `CommitDecision` on `OpportunityDetected`
- Listener: `DispatchCampaignPreparation` → dispatches `PrepareCampaign` stub on `DecisionCommitted`
- Listener: `TriggerOpportunityDetection` → dispatches `DetectOpportunities` on `DigitalTwinActivated`

### Exceptions

- `RationaleGenerationFailedException` — thrown when any required rationale key is missing or empty

---

## 14. What Milestone 4 Must Not Implement

Even if scaffolding is tempting, the following must not be implemented in Milestone 4:

| Item | Where it belongs |
|------|-----------------|
| `CampaignPreparationAnalyst` or real `PrepareCampaign` logic | Milestone 5 |
| `ContentGenerationAnalyst` or `GenerateContent` job | Milestone 5 |
| `ContentAsset` model, migration, or any content fields | Milestone 5 |
| `RecommendationService::create()` | Milestone 5 |
| Recommendation assembly or rationale display formatting | Milestone 5 |
| In-app notifications | Milestone 5 |
| `ApprovalService` or `ApprovalController` | Milestone 5 |
| `Execution` model or records | Milestone 6 |
| `Learning` records | Milestone 5 |
| Any publishing integration | Milestone 6 |
| Analytics or performance tracking | Milestone 7 |

The `Campaign`, `Recommendation`, and `CatalogItem` models introduced in M4 are intentionally minimal. They must not grow beyond what is strictly necessary for the guard conditions and deduplication logic defined in this spec.

---

## 15. Acceptance Criteria

These criteria define "done" for the Decision Engine in Milestone 4. All are verifiable by automated tests.

### Opportunity Detection

- [ ] `DetectOpportunities` job dispatched after `DigitalTwinActivated` fires
- [ ] At least one `opportunities` row created for a company with a populated `BusinessBrain`
- [ ] All four score components persisted on the Opportunity row
- [ ] `composite_score` matches `(relevance × 0.30) + (timing × 0.25) + (confidence × 0.25) + (urgency × 0.20)`
- [ ] No Opportunity with `composite_score < 30` persisted
- [ ] No duplicate `(type, subject_type, subject_id)` in `open` status for the same company
- [ ] `OpportunityDetected` fired for each persisted Opportunity
- [ ] `CommitDecision` job dispatched after detection completes
- [ ] AI detector failure does not block rule-based candidates

### Guard Conditions

- [ ] Guard 1 (minimum score): Opportunity with `composite_score < 30` is skipped; test verifies no Decision created
- [ ] Guard 2 (duplicate recommendation): Existing `pending` Recommendation with same `campaign_type` blocks Decision; test verifies no second Decision created
- [ ] Guard 3 (cooldown): Completed Campaign of same `campaign_type` within cooldown window blocks Decision; test verifies no Decision created
- [ ] Guard 4 (catalog availability): CatalogItem not in `active` status causes Opportunity to be `dismissed`; test verifies Opportunity status = `dismissed`
- [ ] Guard 5 (channel availability): No active Channels causes Decision Engine to exit without Decision; test verifies no Decision created and job exits cleanly
- [ ] When all guards pass: Decision is committed with `status: pending`

### Decision Commitment

- [ ] `CommitDecision` is `ShouldBeUnique` per company — concurrent jobs do not produce duplicate Decisions
- [ ] `decisions` row created with `status: pending` and `decided_at` set
- [ ] `opportunities` row updated to `status: selected`
- [ ] `DecisionCommitted` event fired with the Decision record
- [ ] `PrepareCampaign` stub job dispatched
- [ ] Decision `confidence_score` reflects the Opportunity's confidence component

### Rationale

- [ ] All five rationale keys present and non-empty on the persisted Decision: `why_now`, `why_this`, `why_channel`, `why_works`, `expected_impact`
- [ ] `expected_impact` is a valid JSON object with `summary`, `reach_estimate`, `engagement_signal`, `confidence_basis`
- [ ] `RationaleGenerationFailedException` thrown when any key is missing — Decision not persisted
- [ ] `prompt_version` stored on the Decision record
- [ ] `FakeAiProvider` used in all tests — no real AI provider calls

### Failure Paths

- [ ] No Opportunity passing guards → job exits cleanly; no Decision; no exception thrown
- [ ] AI unavailable → job retried up to 3×; after 3 failures, job fails; no Decision committed
- [ ] Malformed AI response → `StructuredResponseParser` throws; job fails; Opportunity stays `open`
- [ ] Rationale missing required keys → `RationaleGenerationFailedException`; Opportunity stays `open`; no `selected` status set

### Expiry

- [ ] `ExpireOpportunities` transitions `open` Opportunities past `expires_at` to `expired`
- [ ] The job is idempotent — running twice does not change already-`expired` rows
- [ ] A `selected` Opportunity is never expired by the maintenance job

### Tests

- [ ] All detectors have unit tests with a stubbed `BusinessBrain` (no database required)
- [ ] `OpportunityScorer` unit tests cover each score component and the composite formula
- [ ] `DecisionEngine` feature tests for each guard condition, including the pass case
- [ ] Deduplication tested: second scan does not create a duplicate `open` Opportunity
- [ ] Cooldown enforcement tested: recent completed Campaign blocks the Decision
- [ ] `RationaleGenerationAnalyst` tested using `FakeAiProvider` fixture
- [ ] End-to-end feature test: `DigitalTwinActivated` → Opportunity detected → Decision committed → `DecisionCommitted` event fired

---

## 16. Future Extensibility

The Decision Engine is designed to grow without structural changes to the core selection loop.

### Additional Guard Conditions

New guards are added as methods on `DecisionEngine` and inserted into the evaluation sequence. Each guard receives the candidate Opportunity and the `BusinessBrain`. No changes to the selection algorithm or the `CommitDecision` job are required.

### Per-Company Scoring Weights (Phase 8)

The composite formula currently uses global weights. In Phase 8, `Learning` records from approval and rejection history will calibrate per-company weights. `OpportunityScorer` accepts weight overrides:

```php
$scorer->score($candidate, $brain, weights: $company->scoringWeights());
```

The default weights are the M4 formula. Company-level calibration is Phase 8.

### Channel Affinity Learning (Phase 8)

In Phase 8, the Decision Engine will use accumulated Learning to refine channel selection — deprioritising channels that have been repeatedly rejected, and favouring channels with high historical engagement for this company. The channel selection logic in `DecisionEngine::resolveChannels()` is the extension point.

### Multiple Decisions Per Cycle (Future)

Currently, the Decision Engine commits one Decision per cycle — the highest-scoring candidate that passes all guards. In a future phase, the engine may queue multiple Decisions for different `campaign_type`s in a single cycle, giving the user a ranked Recommendation inbox rather than a single item. The current implementation does not prevent this; `DecisionEngine::evaluate()` would loop and commit multiple Decisions rather than returning after the first pass.

### Vertical Calibration (Ongoing)

The scoring weight defaults, cooldown windows, and channel affinity rules are seeded with generic values. Vertical-specific calibration (CBB Auctions vs. exotic dealers vs. restaurants) is applied through per-company settings and eventually through vertical knowledge packs. No changes to the engine interface are required — only the configuration it receives.

### Human-Initiated Decisions (Future)

Users may eventually initiate a Decision manually ("I want to promote this item now"). A `ManualDecision` flow would bypass the Opportunity detection pipeline and enter the lifecycle at the Decision commitment step, with the user providing the campaign type and subject. The rationale generation path would be the same. This is not in scope for MVP.
