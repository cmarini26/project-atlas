# Learning Engine — Design Specification

**Version:** 1.0  
**Status:** Approved — authoritative specification for Phase 8 (Learning)  
**Depends on:** `specs/core/domain-model.md`, `specs/core/analytics-engine.md`, `specs/core/campaign-blueprint.md`  
**See also:** `docs/technical/AI.md`, `docs/technical/Architecture.md`

When this document conflicts with others, this document wins for anything related to learning record application, BusinessBrain mutation, scoring weight calibration, content preference accumulation, and rollback. Update the others.

---

## Milestone Scope

The Learning Engine is implemented in **Phase 8** of the roadmap. It activates after Phase 7 (Analytics) has produced `Learning` records with `applied_at = null`.

Phase 8 does **not** implement:

- Cross-company pattern aggregation — future phase (requires consent framework and anonymisation)
- Real-time or streaming learning — batch processing only
- Automated content publishing based on learned preferences — humans always approve
- Removing or hard-deleting any Learning, Fact, or Knowledge record — all history is permanent
- ML-trained models — all calibration uses rule-based heuristics against accumulated signals

The output of Phase 8 is:

```
Learning records (applied_at = null)
→ ApplyLearnings job (scheduled; company-scoped batch)
→ LearningApplication record created (tracks what changed and why)
→ New Facts (superseding old) | Knowledge entries updated
→ CompanyScoringWeights new version created (if weights changed)
→ Learning.applied_at = now()
→ BusinessBrain assembles updated state on next request
```

---

## Core Invariants

These rules govern every part of the Learning Engine. Any implementation that violates them is incorrect.

1. **Learning records are immutable.** `Learning` rows are never updated after creation. `applied_at` is set once, by `ApplyLearnings`, and never changed again.

2. **Applying a learning creates new state; it never mutates history.** Applying a signal that changes a Fact creates a new Fact row and supersedes the old one. The old Fact row is never modified.

3. **All applied learnings must be explainable.** Every `LearningApplication` record stores a human-readable `effects` description. A user must be able to understand what changed and why by reading the database.

4. **All applied learnings must be reversible.** Rolling back creates new compensating records (new Fact versions, new Knowledge entries, new weight version). It never deletes rows.

5. **Learning must never reduce confidence without supporting evidence.** A single negative signal cannot decrease a score, weight, or Knowledge confidence. At least two corroborating signals of the same type are required before any downward adjustment.

6. **Learning is always company-scoped.** No query in the Learning Engine reads or writes across `company_id` boundaries. No signal from Company A influences Company B in any way.

---

## 1. Learning Domain Model

### 1.1 Learning (existing — review)

The `Learning` model was created in Phase 7. Phase 8 reads from it; it does not extend its schema.

**Table:** `learnings`

| Column       | Type      | Notes |
|--------------|-----------|-------|
| id           | char(26)  | ULID PK |
| company_id   | char(26)  | FK companies.id |
| signal       | varchar   | Signal type (see §1.5) |
| source_type  | varchar   | `execution_result`, `approval`, `rejection`, `approval_edit` |
| source_id    | char(26)  | Polymorphic ID of the source record |
| payload      | json      | Signal-specific data (see §1.5) |
| applied_at   | timestamp | Nullable; set by `ApplyLearnings` when processed |
| created_at   | timestamp | Immutable |

**Constraints:** Unique `(company_id, source_id, signal)` — prevents duplicate signals from the same source. No `updated_at` column (immutable).

### 1.2 LearningApplication

Tracks each application of a `Learning` record to the BusinessBrain. Provides the audit trail and rollback mechanism.

**Table:** `learning_applications`

| Column           | Type      | Notes |
|------------------|-----------|-------|
| id               | char(26)  | ULID PK |
| company_id       | char(26)  | FK companies.id |
| learning_id      | char(26)  | FK learnings.id |
| applied_at       | timestamp | When this application occurred |
| effects          | json      | List of effect descriptors (see §1.3) |
| rolled_back_at   | timestamp | Nullable; set when rolled back |
| rollback_reason  | text      | Nullable; human-readable reason for rollback |
| created_at       | timestamp | |

No `updated_at` — the row is append-only after creation. `rolled_back_at` and `rollback_reason` are the only fields set post-creation, and only once.

**Indexes:** `(company_id, learning_id)` unique; `(company_id, applied_at)`; `(rolled_back_at)` partial for finding active applications.

**Eloquent model:** `App\Models\LearningApplication`  
Use `HasUlids`, `BelongsToCompany`. Cast `effects` to `array`.

### 1.3 Effect Descriptor Shape

The `effects` JSON on `LearningApplication` is a list of effect descriptors. Each descriptor records exactly what entity was created or changed:

```json
[
    {
        "type": "fact_created",
        "entity_type": "Fact",
        "entity_id": "01HZ...",
        "key": "channel_performance.email.affinity",
        "previous_entity_id": "01HY...",
        "description": "Email channel affinity updated from 'neutral' to 'strong' based on 3 outperformance signals."
    },
    {
        "type": "knowledge_updated",
        "entity_type": "Knowledge",
        "entity_id": "01HX...",
        "key": "channel.email.preferred",
        "description": "Knowledge updated: email outperforms other channels for this company."
    },
    {
        "type": "weight_version_created",
        "entity_type": "CompanyScoringWeights",
        "entity_id": "01HW...",
        "version": 3,
        "description": "Scoring weights updated: relevance +2% for featured_item after 2 exceeded outcomes."
    }
]
```

Effect types:

| Type | Meaning |
|------|---------|
| `fact_created` | A new Fact was written (supersedes `previous_entity_id` if set) |
| `knowledge_created` | A new Knowledge entry was created |
| `knowledge_updated` | An existing Knowledge entry was updated (new row, old deactivated) |
| `weight_version_created` | A new `CompanyScoringWeights` version was created |
| `preference_updated` | A content preference Knowledge entry was updated |

### 1.4 CompanyScoringWeights

Stores versioned, per-company adjustments to the Opportunity scoring formula. The global default weights are defined in code (`OpportunityScorer`); this table stores only deviations or explicitly calibrated versions.

**Table:** `company_scoring_weights`

| Column        | Type      | Notes |
|---------------|-----------|-------|
| id            | char(26)  | ULID PK |
| company_id    | char(26)  | FK companies.id |
| weights       | json      | See §6.2 |
| version       | int       | Monotonically increasing per company; starts at 1 |
| is_current    | boolean   | Only one row per company is current at a time |
| learning_id   | char(26)  | Nullable FK learnings.id; which Learning produced this version |
| created_at    | timestamp | |

No `updated_at`. New versions are always new rows. The old row's `is_current` is set to `false` when a new version is created.

**Indexes:** `(company_id, is_current)` partial unique; `(company_id, version)` unique.

**Eloquent model:** `App\Models\CompanyScoringWeights`  
Use `HasUlids`, `BelongsToCompany`. Cast `weights` to `array`. No `UPDATED_AT`.

### 1.5 Signal Types and Payload Schema

All signal types emitted by Phase 7 and Phase 5 are documented here with their expected `payload` shape. Phase 8 reads these payloads to determine what effects to produce.

**From Phase 7 — Analytics:**

| Signal | Payload keys | Evidence threshold | Adjusts |
|--------|--------------|--------------------|---------|
| `channel_outperformed` | `channel_type`, `engagement_rate`, `vs_campaign_average`, `campaign_type` | 2+ signals | Channel affinity Knowledge, scoring affinity for channel |
| `channel_underperformed` | `channel_type`, `engagement_rate`, `vs_campaign_average` | 2+ signals | Channel affinity Knowledge |
| `campaign_type_succeeded` | `campaign_type`, `performance_rating`, `composite_score` | 1 signal (upward only) | campaign_type weight modifier |
| `campaign_type_underperformed` | `campaign_type`, `consecutive_count` | 2+ signals | campaign_type weight modifier |
| `email_deliverability_issue` | `bounces_hard`, `spam_complaint_rate`, `campaign_id` | 1 signal (safety) | Knowledge: list health flag; owner notification |
| `high_unsubscribe_rate` | `unsubscribe_rate`, `delivered`, `campaign_id` | 1 signal (safety) | Knowledge: audience fatigue flag; owner notification |
| `content_angle_engaged` | `angle`, `channel_type`, `performance_rating`, `campaign_type` | 2+ signals | Content angle preference Knowledge |
| `optimal_timing_signal` | `hour_of_day`, `day_of_week`, `open_rate`, `channel_type` | 4+ signals | Send-time preference Knowledge |

**From Phase 5 — Approval Workflow:**

| Signal | Payload keys | Evidence threshold | Adjusts |
|--------|--------------|--------------------|---------|
| `recommendation_approved` | `campaign_type`, `channel_type`, `opportunity_type`, `confidence_score` | 1 signal (upward only) | Opportunity type approval rate Knowledge |
| `recommendation_rejected` | `campaign_type`, `channel_type`, `opportunity_type`, `notes` | 2+ signals | Opportunity type Knowledge; channel affinity |
| `recommendation_edited_and_approved` | `channel_type`, `campaign_type`, `edits` | 2+ signals | Content preference Knowledge (see §8) |

---

## 2. Learning Lifecycle

A `Learning` record passes through exactly three states:

```
[created, applied_at = null]
        │
        ▼ ApplyLearnings job runs
[applied, applied_at = timestamp]
        │
        ▼ optional — admin-initiated rollback only
[applied, LearningApplication.rolled_back_at = timestamp]
```

Learning records themselves never change state beyond `applied_at` being set. Rolling back does not change the `Learning` record — it creates compensating records and marks the `LearningApplication` as rolled back.

### 2.1 Creation

`Learning` records are created by:

1. `LearningService::recordFromMetrics()` — called by `CampaignKpiService` when a final `CampaignKpiSnapshot` is created (Phase 7)
2. `ApprovalService::approve()` — on every Recommendation approval (Phase 5)
3. `ApprovalService::reject()` — on every Recommendation rejection (Phase 5)
4. `ApprovalService::editAndApprove()` — on edited approvals with `Approval.edits` non-empty (Phase 5)

All creation is idempotent: `createIfAbsent()` checks `(company_id, source_id, signal)` before inserting.

### 2.2 Application

`ApplyLearnings` job reads all unapplied `Learning` records for a company and processes them in priority order (see §4). For each Learning:

1. Evaluate evidence threshold — does sufficient corroborating evidence exist? (See §4.2)
2. If threshold not met: skip — mark as pending, do not apply
3. If threshold met: apply effects (see §7)
4. Create `LearningApplication` record with full `effects` descriptor
5. Set `Learning.applied_at = now()`

If two signals conflict (see §5), the conflict resolution rules determine which wins before effects are applied.

### 2.3 Post-Application

After `applied_at` is set:

- The `Learning` record is never read again by `ApplyLearnings`
- The `LearningApplication` record is permanent and auditable
- Effects are live: the next `BusinessBrainService::for($company)` call reflects the updated Facts and Knowledge

### 2.4 Deferred Application

Some signals do not meet their evidence threshold when first processed. These are not applied and are not marked with `applied_at`. They remain unapplied and are re-evaluated on each subsequent run of `ApplyLearnings`. When the threshold is eventually met across multiple runs, the batch of corroborating signals is applied together.

---

## 3. ApplyLearnings Job

### 3.1 Job Definition

**Class:** `App\Jobs\ApplyLearnings`  
**Queue:** `ai` (requires careful reasoning; not time-critical but must not saturate default queue)  
**Schedule:** Daily at 02:00 UTC (low-traffic window; outside publishing hours)  
**Scope:** One job invocation per company — the scheduler dispatches one `ApplyLearnings` job per active company  

```php
class ApplyLearnings implements ShouldQueue, ShouldBeUnique
{
    public function __construct(public readonly string $companyId)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return $this->companyId;
    }

    public function handle(LearningEngine $engine): void
    {
        $engine->applyForCompany($this->companyId);
    }
}
```

`ShouldBeUnique` prevents stacking — if a company's previous run is still in the queue, the duplicate dispatch is silently dropped.

### 3.2 LearningEngine Service

The `ApplyLearnings` job delegates all logic to `LearningEngine`:

**Class:** `App\Services\Learning\LearningEngine`

```php
class LearningEngine
{
    public function applyForCompany(string $companyId): void
    {
        $unapplied = Learning::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('applied_at')
            ->orderBy('created_at')
            ->get();

        if ($unapplied->isEmpty()) {
            return;
        }

        $prioritised = $this->prioritise($unapplied);

        foreach ($prioritised as $batch) {
            $this->processBatch($companyId, $batch);
        }
    }
}
```

### 3.3 Scheduling

`routes/console.php`:

```php
Schedule::call(function () {
    Company::withoutGlobalScopes()
        ->whereHas('digitalTwin', fn ($q) => $q->where('status', 'active'))
        ->each(fn (Company $company) => ApplyLearnings::dispatch($company->id));
})->dailyAt('02:00');
```

Only companies with an **active** Digital Twin are processed. Inactive/initializing companies have no Learning records worth processing.

### 3.4 Idempotency

`ApplyLearnings` is idempotent:

- It only reads `Learning` records where `applied_at IS NULL`
- Once `applied_at` is set, the Learning is never re-processed
- `LearningApplication` has a unique constraint on `(company_id, learning_id)` — attempting to apply the same Learning twice fails at the database level
- Running the job twice for the same company on the same day is safe; the second run processes nothing

### 3.5 Failure Handling

If `applyForCompany()` throws for a specific company:

- The exception is logged with `company_id` as context
- The job retries (3 attempts, 60/300/900s backoff)
- Failed application does not leave `applied_at` set — the Learning remains unapplied and will be re-processed the next day
- Other companies' jobs are not affected (each company has its own job)

---

## 4. Learning Prioritization

Not all signals are equal. The Learning Engine processes signals in priority order within each company.

### 4.1 Priority Tiers

**Tier 1 — Safety signals** (processed first; evidence threshold = 1)

These signals indicate audience damage, deliverability risk, or legal exposure. They are acted on immediately regardless of whether corroborating signals exist.

| Signal | Reason |
|--------|--------|
| `email_deliverability_issue` | Hard bounces and spam complaints harm sender reputation; immediate action required |
| `high_unsubscribe_rate` | Audience fatigue or list mismatch; CAN-SPAM/GDPR compliance concern |

Safety signals produce:
- A `Knowledge` entry flagging the issue (type: `flag`, key: `email.deliverability.issue` or `email.unsubscribe.elevated`)
- An owner notification (surfaced in Filament; not auto-sent externally)
- No change to scoring weights

**Tier 2 — Performance signals** (processed second; evidence threshold = 2)

These signals reflect campaign outcomes and channel effectiveness. They require at least two corroborating data points before the engine acts.

| Signal | Threshold |
|--------|-----------|
| `channel_outperformed` | 2+ signals of same channel outperforming |
| `channel_underperformed` | 2+ signals of same channel underperforming |
| `campaign_type_succeeded` | 1 signal (upward adjustment only — see §6) |
| `campaign_type_underperformed` | 2+ signals, must be consecutive |
| `recommendation_rejected` | 2+ rejections of same opportunity_type or channel_type |

**Tier 3 — Preference signals** (processed last; evidence threshold = 3)

These signals encode learned style preferences from human editing. They require more evidence because individual edits may reflect a one-time contextual choice rather than a preference.

| Signal | Threshold |
|--------|-----------|
| `recommendation_edited_and_approved` | 3+ edits with a detectable pattern |
| `content_angle_engaged` | 3+ confirmed angle-outcome correlations |
| `optimal_timing_signal` | 4+ send-time observations with above-average rate |

### 4.2 Evidence Counting

Evidence is counted per `(company_id, signal, discriminator)` — where discriminator is the primary grouping key for that signal type (e.g., `channel_type` for channel signals, `campaign_type` for campaign type signals).

```
evidence_count = Learning::withoutGlobalScopes()
    ->where('company_id', $companyId)
    ->where('signal', $signalType)
    ->where("payload->>'discriminator_key'", $discriminatorValue)
    ->where('created_at', '>=', now()->subDays(90))  // rolling 90-day window
    ->count();
```

The 90-day window ensures that stale evidence does not accumulate indefinitely. Evidence older than 90 days does not count toward thresholds.

---

## 5. Conflict Resolution

Two Learning signals conflict when they point in opposite directions for the same `(company_id, signal_category, discriminator)`.

**Example conflict:** Three `channel_outperformed` signals for email (from 60 days ago) and two `channel_underperformed` signals for email (from last week).

### 5.1 Resolution Rules

Rules are evaluated in order. The first matching rule wins.

**Rule 1 — Safety overrides everything.**  
If one signal is Tier 1 (safety) and the other is Tier 2 or 3, the safety signal wins without further evaluation.

**Rule 2 — Recency wins when evidence counts are within 1.**  
If the count on each side differs by 1 or less, the group with the more recent median signal date wins.

```
recent_median = median(created_at) for signals in the last 30 days
older_median  = median(created_at) for signals older than 30 days
→ Recent group wins
```

**Rule 3 — Majority wins when counts differ by 2 or more.**  
If one direction has at least 2 more signals than the other, apply the majority direction and skip the minority.

**Rule 4 — No action on tie.**  
If evidence counts are equal and neither group is more recent, no effect is applied. Both Learning records are left unapplied and re-evaluated in the next daily run.

### 5.2 Conflict Logging

All conflict resolutions are logged at the `Info` level with:
- Both signal groups (count, recency, direction)
- Which rule was applied
- The winning outcome

This log is the audit trail for unexpected scoring behavior.

---

## 6. Confidence Recalibration

### 6.1 Upward Bias Rule

**Learning must never reduce confidence without supporting evidence.**

This is enforced by asymmetric thresholds:
- Any **positive** signal (campaign succeeded, channel outperformed, recommendation approved) can increase weights and confidence scores with a threshold of 1.
- Any **negative** signal (campaign underperformed, channel underperformed, recommendation rejected) requires a threshold of 2+ before decreasing anything.

A single bad campaign does not make Atlas pessimistic. It takes consistent underperformance to shift the engine's view.

### 6.2 Weight Adjustment Bounds

Each application of `CompanyScoringWeights` is constrained by hard limits:

| Constraint | Value |
|------------|-------|
| Maximum adjustment per application | ±5% per weight component |
| Maximum total deviation from global defaults | ±20% per weight component |
| Minimum weight value | 0.05 (5%) — no component can reach zero |
| Maximum weight value | 0.60 (60%) — no component dominates completely |
| Sum of weights | Always 1.00 — adjustments are renormalized after applying |

**Example:** Global defaults are `{relevance: 0.30, timing: 0.25, confidence: 0.25, urgency: 0.20}`. After three `featured_item` successes, the engine may increase `relevance` to `0.35` for this company (a +5% adjustment, within bounds). The other weights are renormalized proportionally.

**`CompanyScoringWeights.weights` JSON shape:**

```json
{
    "relevance":    0.32,
    "timing":       0.24,
    "confidence":   0.24,
    "urgency":      0.20,
    "type_modifiers": {
        "featured_item":   1.10,
        "urgency_promotion": 0.95,
        "re_engagement":   1.00,
        "seasonal":        1.00
    }
}
```

`type_modifiers` are multipliers applied to the composite score after weighting. A value of `1.10` means the composite score for `featured_item` opportunities is multiplied by 1.10 before being ranked against other opportunity types. Bounds: `0.50` to `1.50`.

### 6.3 Cooling Period

After a weight adjustment is applied, a **14-day cooling period** applies to the same signal category. Within the cooling period:

- New signals of the same type are collected and counted toward future adjustments
- No further weight change is applied until the cooling period expires

This prevents rapid oscillation between opposing signals. The cooling period is tracked via `LearningApplication.applied_at` — the engine checks whether a `LearningApplication` for the same signal category exists within the last 14 days before applying.

---

## 7. BusinessBrain Mutation Rules

The BusinessBrain is a value object assembled on demand by `BusinessBrainService::for($company)`. It reads from:
1. Current `Fact` records (`is_current = true`)
2. Active `Knowledge` entries (`is_active = true`, `expires_at` not past)
3. Current `CompanyScoringWeights` (`is_current = true`)

Applying a Learning changes the source records. The BusinessBrain reflects changes automatically on the next assembly.

### 7.1 What Can Be Changed

| Source record | Change mechanism | Who creates it |
|---------------|-----------------|----------------|
| `Fact` | New Fact row; old row's `is_current` set to false; `superseded_by_id` set | `LearningEngine` |
| `Knowledge` | New Knowledge row; old row's `is_active` set to false | `LearningEngine` |
| `CompanyScoringWeights` | New row with `is_current = true`; old row's `is_current` set to false | `LearningEngine` |

### 7.2 What Cannot Be Changed

| Item | Why |
|------|-----|
| Historical `Learning` records | Immutable — append-only history |
| `Approval`, `Rejection` records | Source of truth for user decisions — immutable |
| `CampaignKpiSnapshot` records | Point-in-time measurement — never amended |
| `Execution`, `ExecutionMetric` records | Platform-reported; not interpretive |
| Global scoring formula code | Code change, not a Learning |
| Other companies' data | Hard scoping constraint |

### 7.3 Fact Mutation Rules

Facts may be created by `LearningEngine` for the following key namespaces:

| Namespace | Example key | Meaning |
|-----------|-------------|---------|
| `channel_performance.*` | `channel_performance.email.affinity` | Channel engagement history |
| `campaign_type.*` | `campaign_type.featured_item.success_rate` | Outcome history by type |
| `content_preferences.*` | `content_preferences.email.length` | Learned content style |
| `audience.*` | `audience.email.list_health` | Deliverability and engagement signals |
| `timing.*` | `timing.email.preferred_hour` | Optimal send-time signals |

Facts with other keys are not created or modified by `LearningEngine`. If an existing Fact with a key outside these namespaces needs correction, that is a `WebsiteAnalyst` / `FactExtractionAnalyst` concern, not a Learning concern.

### 7.4 Knowledge Mutation Rules

Knowledge entries mutated by `LearningEngine` use a `type = 'learning'` to distinguish them from AI-synthesized Knowledge entries (`type = 'context'`).

```php
Knowledge::withoutGlobalScopes()->updateOrCreate(
    ['company_id' => $companyId, 'key' => 'channel.email.preferred', 'type' => 'learning'],
    [
        'is_active' => true,
        'body'      => 'Email consistently outperforms other channels for this company. Prioritize email in multi-channel campaigns.',
        'expires_at' => now()->addDays(90),  // Learning-derived Knowledge expires; re-evaluated each cycle
    ],
);
```

Learning-derived Knowledge entries have a 90-day expiry. If the signal does not repeat within 90 days, the Knowledge entry expires naturally and is not renewed. This prevents stale learning from permanently biasing the Business Brain.

### 7.5 OpportunityScorer Integration

`OpportunityScorer` must be updated to read `CompanyScoringWeights` for the current company before scoring:

```php
class OpportunityScorer
{
    public function score(OpportunityCandidate $candidate, string $companyId): float
    {
        $weights = $this->weightsFor($companyId);

        $composite = ($candidate->relevanceScore * $weights['relevance'])
            + ($candidate->timingScore    * $weights['timing'])
            + ($candidate->confidenceScore * $weights['confidence'])
            + ($candidate->urgencyScore   * $weights['urgency']);

        $modifier = $weights['type_modifiers'][$candidate->opportunityType] ?? 1.0;

        return min(100, $composite * $modifier);
    }

    private function weightsFor(string $companyId): array
    {
        $row = CompanyScoringWeights::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_current', true)
            ->first();

        return $row?->weights ?? $this->defaultWeights();
    }
}
```

If no `CompanyScoringWeights` row exists (new company, no Learning applied yet), the global defaults are used. The database is never required for default scoring.

---

## 8. Prompt Adaptation Strategy

### 8.1 Principle

The Learning Engine does **not** modify prompt templates. Prompts are versioned code — they are changed by engineers through deliberate prompt engineering, not by runtime learning.

Learning improves prompt outputs by enriching the **context passed to prompts** — specifically, the `BusinessBrain` value object. Better Facts and Knowledge in the BusinessBrain produce better-grounded prompt outputs without any change to the prompt template itself.

This preserves the guarantee from Founding Principle 2: swapping the AI provider or prompt version remains a configuration change, not a learning effect.

### 8.2 Content Preference Propagation

When `recommendation_edited_and_approved` signals accumulate with detectable patterns, `LearningEngine` creates Knowledge entries of type `learning` that describe the company's content preferences:

| Detected pattern | Knowledge body |
|-----------------|----------------|
| User consistently adds price to email subject lines | "Always include product price in email subject lines for this company." |
| User removes hashtags from Instagram posts | "Do not use hashtags in Instagram posts for this company." |
| User shortens body copy by >30% on most edits | "Generate concise email body copy (target: 150 words) for this company." |
| User rewrites CTAs to be more direct | "Use direct, action-oriented CTAs. Avoid passive constructions." |

These Knowledge entries are surfaced in the `BusinessBrain.knowledge` collection when `ContentGenerationAnalyst` and `CampaignPreparationAnalyst` are invoked. The analyst's prompt template includes a section for company-specific preferences pulled from Knowledge.

### 8.3 Edit Pattern Detection

The `LearningEngine` reads `Approval.edits` (stored on `edited_and_approved` Approvals) and applies pattern detection logic to identify systematic preferences:

- **Length preference:** If `edited_word_count / original_word_count < 0.75` on 3+ occasions, flag as "prefers shorter copy."
- **Hashtag preference:** If hashtag count is consistently reduced to 0 on 3+ occasions, flag as "no hashtags."
- **Price inclusion:** If the word matching `\$[0-9]` appears in 3+ edits where it was absent in the original, flag as "include price."

Detection is heuristic and keyword-based. No ML model is used. Patterns are only written as Knowledge entries when threshold evidence exists.

### 8.4 Prompt Version Tracking

`prompt_version` and `prompt_name` are already stored on every AI-produced record (`Fact`, `ContentAsset`, `CampaignKpiSnapshot` via `Campaign`). Phase 8 connects this data to outcomes:

`LearningEngine` can compare `approval_rate` and `performance_rating` grouped by `prompt_version`. This produces `Learning` records of type `prompt_performance` (new signal type, Phase 8 only):

| Signal | Payload |
|--------|---------|
| `prompt_underperformed` | `prompt_name`, `prompt_version`, `sample_size`, `approval_rate`, `avg_performance_rating` |

These signals are read by engineering (surfaced in Filament) — they do not automatically produce BusinessBrain effects. A human engineer reviews them and decides whether a new prompt version is warranted.

---

## 9. Safety Constraints

### 9.1 Company Scoping

Every query in `LearningEngine` and `ApplyLearnings` uses explicit `company_id` filtering with `withoutGlobalScopes()`:

```php
Learning::withoutGlobalScopes()
    ->where('company_id', $this->companyId)
    ->...
```

No `Learning` from Company A is read when processing Company B. No cross-company join is ever performed. This is enforced at both the query level and the service level — `LearningEngine::applyForCompany(string $companyId)` accepts exactly one company and processes only that company's records.

### 9.2 Hard Limits

These constraints cannot be overridden by any signal, regardless of evidence count:

| Constraint | Value | Reason |
|------------|-------|--------|
| Weight floor | 0.05 | No scoring component goes to zero — ensures all evidence types contribute |
| Weight ceiling | 0.60 | No single component dominates the formula |
| Weight sum | 1.00 | Renormalised after every adjustment |
| Type modifier floor | 0.50 | Campaign types are never suppressed below half weight |
| Type modifier ceiling | 1.50 | Campaign types are never more than 50% boosted |
| Maximum weight shift per run | ±5% per component | Prevents runaway single-signal adjustments |
| Cooling period | 14 days per signal category | Prevents oscillation |
| Rolling evidence window | 90 days | Stale data does not accumulate |

### 9.3 No-Auto-Publish Constraint

Learning effects never directly cause content to be published. The chain from Learning to publishing must always pass through a human `Approval`. `LearningEngine` mutates Facts, Knowledge, and scoring weights — it does not create or modify `Execution` records, `Recommendation` records, or `Approval` records.

### 9.4 Notification Requirements

When a Tier 1 (safety) signal is applied, a notification must be surfaced to company owners in the Filament admin UI. The notification describes the detected issue and recommends action. Atlas does not send unsolicited external email/SMS notifications without human initiation.

### 9.5 Immutability Guards

At the Eloquent model level:
- `Learning::UPDATED_AT = null` — enforces append-only by removing the `updated_at` column
- `Learning.applied_at` is set only once — `LearningEngine` checks `applied_at IS NULL` before marking
- `LearningApplication` has no `updated_at` — the row is created once; only `rolled_back_at` and `rollback_reason` may be set post-creation, and only once

---

## 10. Explainability

### 10.1 Every Applied Learning Must Be Readable

The `LearningApplication.effects` JSON is the primary explainability artifact. Every effect descriptor must include:

1. **What changed** — entity type, entity ID, and key name
2. **Why it changed** — a human-readable `description` string
3. **What it replaced** — `previous_entity_id` for superseded Facts; previous `version` for weights

### 10.2 Filament Admin Visibility

The Filament admin panel must show, per company:

**Learning Log view:**
- All `Learning` records, grouped by signal type
- `applied_at` status (pending / applied)
- Link to the source record (Campaign, Recommendation, Approval)

**Applied Effects view:**
- All `LearningApplication` records, sorted by `applied_at` desc
- Expanded `effects` as a readable list: "On [date], email channel affinity was strengthened because email outperformed other channels in 3 of the last 4 campaigns."
- Rollback button (admin only; see §11)

**BusinessBrain Mutations view:**
- Current `CompanyScoringWeights` with the global default for comparison
- Weight history: all versions with the Learning that produced each change
- Learning-derived Knowledge entries (filtered to `type = 'learning'`)
- Pending Learning records not yet applied (count + signal types)

### 10.3 Decision Rationale Traceability

When a `Decision` is committed, its `rationale` explains why this Opportunity was chosen. After Phase 8, the rationale must also reflect when a choice was influenced by accumulated Learning — for example: "Email was selected as the primary channel because Atlas has observed email outperforming other channels in 3 of your last 4 campaigns."

This context is injected by `BusinessBrainService::for($company)` via the Learning-derived Knowledge entries, which `RationaleGenerationAnalyst` reads as part of the `BusinessBrain` context. The Learning engine does not modify the prompt — it populates the context the prompt reads.

---

## 11. Rollback Strategy

### 11.1 When Rollback Is Used

Rollback is an admin-initiated operation, not an automated one. It is used when:

- A Learning was applied based on anomalous data (e.g., a bug in Phase 7 produced incorrect metrics)
- The applied effects produced clearly wrong behavior (e.g., a campaign type was drastically de-scored due to a one-off failure)
- A Safety signal was triggered by a provider error rather than real audience behavior

### 11.2 Rollback Mechanism

Rolling back a `LearningApplication` means undoing its effects by creating compensating records:

1. For each `fact_created` effect: find the new Fact and its predecessor. Set the new Fact's `is_current = false`. Set the predecessor's `is_current = true` and clear `superseded_by_id`. Create a new Fact row recording the rollback.
2. For each `knowledge_created` or `knowledge_updated` effect: find the Knowledge entry created by the Learning. Set `is_active = false`. Restore the previous Knowledge entry (`is_active = true`).
3. For each `weight_version_created` effect: find the `CompanyScoringWeights` version created. Set `is_current = false`. Find the version immediately prior and set `is_current = true`.
4. Set `LearningApplication.rolled_back_at = now()` and `rollback_reason` to the admin's provided reason.
5. Set `Learning.applied_at = null` — the Learning is eligible to be re-evaluated in the next daily run.

**Nothing is deleted.** The rolled-back `LearningApplication` remains as a permanent record. The new compensating records create a complete audit trail.

### 11.3 Rollback Constraints

- Rolling back a Tier 1 (safety) signal requires a written `rollback_reason` and is logged at `Warning` level.
- A rolled-back Learning is re-evaluated in the next daily run. If sufficient corroborating evidence still exists, it may be re-applied. The engineer initiating the rollback should also investigate and correct the underlying cause.
- Cascading rollbacks (a Learning that was used to justify another Learning) are not supported in Phase 8. Rolling back Learning A does not automatically roll back Learning B that depended on A's effects. This complexity is deferred to a future phase.

---

## 12. Versioning

### 12.1 Weight Versioning

`CompanyScoringWeights` rows are versioned per company. The `version` column is an integer that increments monotonically:

- Version 0 is implicit — the global defaults defined in code
- Version 1 is the first row created by `LearningEngine` for a company
- Each subsequent application that changes weights creates a new row and increments the version

The `is_current` flag marks the active version. Only one row per company has `is_current = true` at any time.

### 12.2 BusinessBrain Snapshot

The `BusinessBrain` value object reflects the state of Facts, Knowledge, and weights at the moment of assembly. It is not cached across requests in Phase 8 — each assembly reads the current rows.

A future caching layer (Redis, 5-minute TTL) can be added once the assembly is profiled and known to be a bottleneck. The interface does not change.

### 12.3 Prompt Version Linkage

`Campaign.prompt_version` and `ContentAsset.prompt_version` already track which prompt produced the output. Phase 8 reads these to compute per-version approval rates. When a new prompt version is deployed:

- Old Learnings derived from old-version outputs remain valid
- `prompt_performance` signals that compare across versions are labeled with both `prompt_version` values
- No automatic weight recalibration occurs on prompt version change — only on outcome signals

### 12.4 Learning Record Audit Trail

The full audit trail for any company's BusinessBrain state is:

```
SELECT * FROM learnings WHERE company_id = ? ORDER BY created_at
  → shows every signal ever emitted, what produced it, and whether it was applied

SELECT * FROM learning_applications WHERE company_id = ? ORDER BY applied_at
  → shows every effect ever applied, what changed, and whether it was rolled back

SELECT * FROM company_scoring_weights WHERE company_id = ? ORDER BY version
  → shows weight history; join to learning_applications for attribution

SELECT * FROM facts WHERE company_id = ? AND key LIKE 'channel_performance%' ORDER BY created_at
  → shows Fact history in learning-owned namespaces

SELECT * FROM knowledge_entries WHERE company_id = ? AND type = 'learning' ORDER BY created_at
  → shows Learning-derived Knowledge history
```

---

## 13. Acceptance Criteria

All criteria are verifiable by automated tests. No test calls a real AI provider or platform API. All tests use `FakeAnalyticsProvider` and existing test doubles.

### Learning Application

- [ ] `ApplyLearnings` job reads only `Learning` records where `applied_at IS NULL`
- [ ] After a Learning is applied, `applied_at` is set and the record is not processed again in subsequent runs
- [ ] Two invocations of `ApplyLearnings` for the same company on the same day are idempotent (second run processes nothing)
- [ ] `LearningApplication` is created for every applied Learning, with a non-empty `effects` array
- [ ] `LearningApplication` unique constraint on `(company_id, learning_id)` prevents double-application at the database level

### Evidence Thresholds

- [ ] A single `channel_underperformed` signal does not produce a weight change
- [ ] Two `channel_underperformed` signals for the same channel type produce a weight change
- [ ] A single `campaign_type_succeeded` signal produces an upward weight modifier adjustment
- [ ] A single `email_deliverability_issue` signal (Tier 1) is applied immediately without waiting for corroboration

### Conflict Resolution

- [ ] When 3 `channel_outperformed` (email) signals exist and 1 `channel_underperformed` (email) signal exists, the majority wins and channel affinity is strengthened
- [ ] When 2 opposing signals exist with no recency difference, no effect is applied and both signals remain unapplied
- [ ] A Tier 1 safety signal is never overridden by a Tier 2 performance signal

### Weight Calibration

- [ ] No weight adjustment exceeds ±5% in a single `ApplyLearnings` run
- [ ] No weight component falls below 0.05 or rises above 0.60
- [ ] Weight components always sum to 1.00 after renormalization
- [ ] Type modifiers remain within the `0.50–1.50` range
- [ ] `OpportunityScorer` reads `CompanyScoringWeights` for the company before scoring; falls back to global defaults when no row exists

### Cooling Period

- [ ] A second weight adjustment for the same signal category within 14 days is deferred, not applied
- [ ] After 14 days, the same signal category is eligible for a further adjustment

### BusinessBrain Mutation

- [ ] A new `Fact` supersedes its predecessor: old fact's `is_current` becomes false, `superseded_by_id` is set
- [ ] A new Learning-derived `Knowledge` entry deactivates its predecessor (`is_active = false`)
- [ ] `CompanyScoringWeights` version increments with each weight change; previous version's `is_current = false`
- [ ] `BusinessBrainService::for($company)` returns updated Facts and Knowledge after `ApplyLearnings` runs

### Company Scoping

- [ ] Learning records for Company A are never read when processing Company B
- [ ] `OpportunityScorer` reads weights scoped to the correct `company_id`
- [ ] No cross-company join exists anywhere in the Learning Engine

### Rollback

- [ ] Rolling back a `LearningApplication` sets `rolled_back_at` and restores the predecessor Fact/Knowledge/Weight versions
- [ ] The rolled-back Learning has `applied_at` set back to null and is re-evaluated in the next daily run
- [ ] No records are deleted during rollback; all changes create new rows or update `is_current`/`is_active` flags

### Explainability

- [ ] Every `effects` entry has a non-empty `description` field
- [ ] `LearningApplication.effects` correctly identifies `previous_entity_id` for superseded Facts
- [ ] Filament admin can list `LearningApplication` records for a company with their effects

### Prompt Adaptation

- [ ] After 3 `recommendation_edited_and_approved` signals with detectable hashtag-removal pattern, a Knowledge entry with `type = 'learning'` and body containing "no hashtags" is created
- [ ] `ContentGenerationAnalyst` prompt context includes Learning-derived Knowledge when assembling the BusinessBrain
- [ ] No prompt template file is modified by `LearningEngine` — only BusinessBrain context changes

---

## 14. Future Extensibility

### 14.1 Cross-Company Aggregate Learning

Once Atlas serves enough companies (suggested threshold: 50 active companies with ≥3 completed campaigns each), an aggregate pattern layer can be added:

- Anonymized, consent-gated signals from multiple companies inform **default** scoring weights for new companies
- Cross-company patterns never override per-company learning — they only influence the global defaults
- Implementation: a separate `AggregateSignal` table (not `Learning` — different scoping model) processed by a separate `ApplyAggregateSignals` job
- This is explicitly out of scope for Phase 8 and requires a consent and anonymisation framework

### 14.2 ML-Trained Scoring

The rule-based weight adjustments in Phase 8 are a deterministic heuristic. As the dataset grows, a future `ScoringCalibrationService` could train a simple regression model on `{opportunity_scores, campaign_type, channel_type} → performance_rating` to produce optimal weights per company. The `CompanyScoringWeights` schema and `OpportunityScorer.weightsFor()` interface are already designed to accept ML-produced weights without structural changes.

### 14.3 Preference Cascade to Brief

In Phase 8, content preferences (Knowledge entries) reach the AI via the BusinessBrain context. A future refinement would pre-populate the `CampaignPreparationPrompt` with a dedicated "Company Style Guide" section auto-generated from accumulated preferences — a personalized system prompt addition produced from Knowledge entries with type `learning`. This requires prompt engineering work, not structural changes.

### 14.4 User-Initiated Learning Override

A future "Teach Atlas" UI would allow company owners to explicitly add or override Learning signals — for example: "Always use email for urgency campaigns" or "Never post on Sundays." These explicit overrides would be stored as `Learning` records with `source_type = 'user_override'` and `applied_at` set immediately (they bypass the evidence threshold). Phase 8 does not build this UI but the data model supports it.

### 14.5 Prompt Performance Dashboard

Phase 8 produces `prompt_performance` Learning signals but surfaces them only to engineering via Filament. A future phase adds a prompt performance dashboard: approval rates and campaign performance ratings grouped by `prompt_version`, with a diff view for prompt changes. This informs when to invest in prompt updates.

### 14.6 Real-Time Learning Path

The current daily batch architecture is sufficient for the signal volumes expected in Phase 8. A future real-time path — where high-priority Tier 1 signals are processed within minutes rather than the next daily run — would require:

- A new event: `SafetySignalDetected` dispatched by `LearningService` when a Tier 1 signal is created
- A dedicated high-priority `ApplyImmediateLearning` job on the `high` queue
- No structural model changes — the same `LearningApplication` and mutation rules apply

The safety constraint (immutability, company scoping) applies identically regardless of whether processing is batch or real-time.
