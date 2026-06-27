# Milestone 9 — Learning Engine: Implementation Plan

**Milestone:** 9 — Learning Engine  
**Spec:** `specs/core/learning-engine.md` (authoritative — read it before implementing any phase)  
**Status:** Pre-implementation — plan approved, no code written  
**Predecessor:** Milestone 8 (Analytics Engine) ✅, Milestone 8.5 (Learning Engine Specification) ✅  
**Estimated phases:** 10  

---

## 1. Overview

Milestone 9 implements the Learning Engine: the system that consumes `Learning` records produced by Phase 7 (Analytics) and Phase 5 (Approvals), applies them to the BusinessBrain, and makes Atlas measurably smarter over time.

After M9, the Atlas feedback loop is complete:

```
Campaign runs
→ ExecutionMetric produced
→ CampaignKpiSnapshot (final) created
→ LearningService writes Learning records (applied_at = null)

[02:00 UTC daily — M9 adds this]
→ ApplyLearnings job dispatched per active company
→ LearningEngine.applyForCompany()
   → Signals prioritised by tier
   → Evidence evaluated (90-day rolling window)
   → Conflicts resolved
   → Facts superseded / Knowledge updated / Weights versioned
   → LearningApplication created (effects audit trail)
   → Learning.applied_at = now()
→ BusinessBrainService assembles updated state on next Opportunity scan
→ Next Decision reflects what Atlas learned
```

This is not a side feature. Learning is Founding Principle 10 and the reason the Digital Twin is a competitive moat.

---

## 2. Scope

### In Scope

- `learning_applications` migration and `LearningApplication` model
- `company_scoring_weights` migration and `CompanyScoringWeights` model
- `LearningEngine` service (orchestration)
- `ApplyLearnings` job (scheduled, company-scoped, `ShouldBeUnique`)
- `LearningServiceProvider` + daily `routes/console.php` schedule
- `EvidenceEvaluator` service (signal tier lookup, evidence counting, threshold checking)
- `ConflictResolver` service (4-rule ordered resolution)
- `FactMutator` service (Fact supersession per signal type)
- `KnowledgeMutator` service (Knowledge upsert with `type = 'learning'`, 90-day expiry)
- `WeightCalibrator` service (CompanyScoringWeights versioning, bounds enforcement, renormalization)
- `EditPatternDetector` service (heuristic detection of content preference patterns)
- `LearningRollbackService` (admin-initiated compensating record creation)
- `OpportunityScorer` update — reads `CompanyScoringWeights` per company before scoring
- `BusinessBrainService` update — includes `type = 'learning'` Knowledge entries in assembled context
- Approval-side Learning signals audit and wire-up (verify/add `recommendation_approved`, `recommendation_rejected`, `recommendation_edited_and_approved` signals with correct payloads)
- Filament admin visibility: Learning Log, Applied Effects, BusinessBrain Mutations views per company
- Full test suite: ≥50 new tests covering all acceptance criteria from `specs/core/learning-engine.md` §13

### Out of Scope

- Cross-company pattern aggregation — requires separate `AggregateSignal` table and consent framework; future phase
- ML-trained scoring models — `WeightCalibrator` uses rule-based heuristics only
- Real-time learning — daily batch only; no `SafetySignalDetected` event path
- User-facing "Teach Atlas" UI — no `source_type = 'user_override'` flow
- Auto-publishing of any content — Learning never creates or modifies `Execution` records
- Prompt template mutation at runtime — prompts are versioned code; context enrichment only
- Deleting historical `Learning`, `Fact`, `Knowledge`, or `LearningApplication` records — ever
- Cascading rollback — rolling back Learning A does not auto-roll back Learning B; future phase

---

## 3. Dependencies

### Hard Prerequisites (verified before any code is written)

**3.1 — `facts.superseded_by_id` column exists**

The `FactMutator` sets `superseded_by_id` on old Fact rows when a new one is created. Verify this column exists in `facts` table migration. If not, add an addColumn migration in Phase 1.

```bash
grep -r "superseded_by_id" backend/database/migrations/
```

If absent: add `$table->char('superseded_by_id', 26)->nullable()->after('is_current');` to a new migration.

**3.2 — `knowledge_entries.is_active` and `knowledge_entries.type` columns exist**

The `KnowledgeMutator` uses `is_active` and `type` on Knowledge rows. Verify both columns exist. The `type = 'learning'` distinction is essential for the BusinessBrainService update and Filament filter.

```bash
grep -r "is_active\|knowledge_type\|->type" backend/database/migrations/ | grep knowledge
```

If `type` is absent: add `$table->string('type')->default('context')->after('key');` in a new migration.

**3.3 — Approval-side Learning signals are wired**

The Learning Engine must find `recommendation_approved`, `recommendation_rejected`, and `recommendation_edited_and_approved` Learning records to process in Phase 3 (preference signals). Verify `ApprovalService::approve()`, `reject()`, and `editAndApprove()` write these signals with the correct payload shape:

```bash
grep -n "Learning\|recordFromMetrics\|recommendation_approved" backend/app/Services/ApprovalService.php
```

Expected payload shapes:
- `recommendation_approved`: `{campaign_type, channel_type, opportunity_type, confidence_score}`
- `recommendation_rejected`: `{campaign_type, channel_type, opportunity_type, notes}`
- `recommendation_edited_and_approved`: `{channel_type, campaign_type, edits}` where `edits` is the `Approval.edits` JSON

If these signals are absent or have wrong payloads: wire them in Phase 1 before any engine code runs, as test fixtures will depend on them.

**3.4 — `Learning` model has no `UPDATED_AT`**

`Learning::UPDATED_AT = null` must already be set (it was part of M8). Verify that `applied_at` can be set in one step without an `updated_at` conflict.

```bash
grep -n "UPDATED_AT" backend/app/Models/Learning.php
```

### Soft Prerequisites (can be resolved during M9)

**3.5 — `BusinessBrainService::for()` includes Learning-derived Knowledge**

The current `for()` implementation may filter Knowledge by type `context` only. Phase 8 of M9 extends this to also include `type = 'learning'` entries.

**3.6 — `OpportunityScorer` does not yet read `CompanyScoringWeights`**

The scorer currently uses hardcoded weights `{relevance: 0.30, timing: 0.25, confidence: 0.25, urgency: 0.20}`. Phase 6 of M9 adds the DB lookup. The global defaults are the fallback when no per-company row exists.

---

## 4. Implementation Phases

Work phases are ordered by dependency. Phases 1–3 must complete before 4–6. Phases 4–7 can be worked in parallel by separate engineers if needed. Phase 8 requires 5 complete. Phase 9 requires all others. Phase 10 covers the full milestone.

---

### Phase 1 — Migrations, Models, Prerequisite Fixes

**Goal:** All new tables exist; all prerequisite schema gaps are patched; approval-side Learning signals are correctly wired.

**Files to create:**

```
database/migrations/*_create_learning_applications_table.php
database/migrations/*_create_company_scoring_weights_table.php
app/Models/LearningApplication.php
app/Models/CompanyScoringWeights.php
```

**`learning_applications` migration:**

```php
Schema::create('learning_applications', function (Blueprint $table) {
    $table->char('id', 26)->primary();
    $table->char('company_id', 26)->index();
    $table->char('learning_id', 26)->index();
    $table->timestamp('applied_at');
    $table->json('effects');
    $table->timestamp('rolled_back_at')->nullable();
    $table->text('rollback_reason')->nullable();
    $table->timestamp('created_at')->useCurrent();

    $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
    $table->foreign('learning_id')->references('id')->on('learnings')->cascadeOnDelete();
    $table->unique(['company_id', 'learning_id']);
    $table->index(['company_id', 'applied_at']);
});
```

No `updated_at`. The unique `(company_id, learning_id)` constraint is the idempotency guard.

**`company_scoring_weights` migration:**

```php
Schema::create('company_scoring_weights', function (Blueprint $table) {
    $table->char('id', 26)->primary();
    $table->char('company_id', 26)->index();
    $table->json('weights');
    $table->unsignedInteger('version');
    $table->boolean('is_current')->default(false);
    $table->char('learning_id', 26)->nullable();
    $table->timestamp('created_at')->useCurrent();

    $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
    $table->foreign('learning_id')->references('id')->on('learnings')->nullOnDelete();
    $table->unique(['company_id', 'version']);
    $table->index(['company_id', 'is_current']);
});
```

No `updated_at`. `is_current` will be toggled (old → false, new → true) in a single transaction.

**`LearningApplication` model:**

```php
class LearningApplication extends Model
{
    use BelongsToCompany, HasUlids;

    const UPDATED_AT = null;

    protected $fillable = ['company_id', 'learning_id', 'applied_at', 'effects', 'rolled_back_at', 'rollback_reason'];

    protected $casts = [
        'effects'        => 'array',
        'applied_at'     => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function learning(): BelongsTo { ... }
}
```

**`CompanyScoringWeights` model:**

```php
class CompanyScoringWeights extends Model
{
    use BelongsToCompany, HasUlids;

    const UPDATED_AT = null;

    protected $table = 'company_scoring_weights';

    protected $fillable = ['company_id', 'weights', 'version', 'is_current', 'learning_id'];

    protected $casts = ['weights' => 'array', 'is_current' => 'boolean'];

    public function learning(): BelongsTo { ... }
}
```

**Prerequisite migrations (conditional — run only if columns absent):**

```
database/migrations/*_add_superseded_by_id_to_facts_table.php    (if missing)
database/migrations/*_add_type_to_knowledge_entries_table.php     (if missing)
```

**ApprovalService wire-up (if signals are absent):**

If `ApprovalService` does not write the three approval-side Learning signals, add them now. The method calls follow the same `LearningService::createIfAbsent()` idempotency pattern already used in `recordFromMetrics()`. Do not create a separate method in `LearningService` — call `createIfAbsent()` directly with the signal type and payload.

**Exit criteria for Phase 1:**
- `php artisan migrate` runs cleanly with no errors
- `LearningApplication::create([...])` and `CompanyScoringWeights::create([...])` persist and read back correctly
- At least one `recommendation_approved` Learning record exists for a test company in a seeded environment

---

### Phase 2 — LearningEngine Skeleton and ApplyLearnings Job

**Goal:** The job can be dispatched and runs without error on an empty Learning set. The service infrastructure is wired but effects not yet applied.

**Files to create:**

```
app/Providers/LearningServiceProvider.php
app/Services/Learning/LearningEngine.php
app/Jobs/ApplyLearnings.php
```

**Files to modify:**

```
bootstrap/providers.php                  — add LearningServiceProvider
routes/console.php                       — add daily 02:00 schedule
```

**`LearningServiceProvider`:**

Follows the pattern of `AnalyticsServiceProvider`. Registers `LearningEngine` as a singleton. In Phase 2 it's simple:

```php
class LearningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LearningEngine::class, fn ($app) => new LearningEngine(
            $app->make(EvidenceEvaluator::class),
            $app->make(ConflictResolver::class),
            $app->make(FactMutator::class),
            $app->make(KnowledgeMutator::class),
            $app->make(WeightCalibrator::class),
        ));
    }
}
```

The injected services are stubbed in Phase 2 and filled in Phases 3–6.

**`ApplyLearnings` job:**

```php
class ApplyLearnings implements ShouldQueue, ShouldBeUnique
{
    public function __construct(public readonly string $companyId)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string { return $this->companyId; }
    public function tries(): int { return 3; }
    public function backoff(): array { return [60, 300, 900]; }

    public function handle(LearningEngine $engine): void
    {
        $engine->applyForCompany($this->companyId);
    }
}
```

**`LearningEngine` skeleton:**

```php
class LearningEngine
{
    public function __construct(
        private readonly EvidenceEvaluator $evidence,
        private readonly ConflictResolver  $conflicts,
        private readonly FactMutator       $facts,
        private readonly KnowledgeMutator  $knowledge,
        private readonly WeightCalibrator  $weights,
    ) {}

    public function applyForCompany(string $companyId): void
    {
        $unapplied = Learning::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('applied_at')
            ->orderBy('created_at')
            ->get();

        if ($unapplied->isEmpty()) { return; }

        // Phases 3–6 fill these stubs
        foreach ($this->prioritise($unapplied) as $tier => $signals) {
            $resolved = $this->conflicts->resolve($companyId, $signals);
            foreach ($resolved as $learning) {
                $this->applyOne($companyId, $learning, $tier);
            }
        }
    }

    // Returns [1 => Collection, 2 => Collection, 3 => Collection]
    private function prioritise(Collection $learnings): array { ... }

    private function applyOne(string $companyId, Learning $learning, int $tier): void
    {
        if (! $this->evidence->meetsThreshold($companyId, $learning, $tier)) { return; }

        $effects = $this->mutate($companyId, $learning);
        if (empty($effects)) { return; }

        DB::transaction(function () use ($companyId, $learning, $effects) {
            LearningApplication::create([
                'company_id'  => $companyId,
                'learning_id' => $learning->id,
                'applied_at'  => now(),
                'effects'     => $effects,
            ]);
            $learning->withoutGlobalScopes()->where('id', $learning->id)->update(['applied_at' => now()]);
        });
    }

    private function mutate(string $companyId, Learning $learning): array { ... }
}
```

**`routes/console.php` schedule:**

```php
Schedule::call(function () {
    Company::withoutGlobalScopes()
        ->whereHas('digitalTwin', fn ($q) => $q->where('status', 'active'))
        ->each(fn (Company $c) => ApplyLearnings::dispatch($c->id));
})->dailyAt('02:00');
```

**Exit criteria for Phase 2:**
- `php artisan schedule:run` dispatches one `ApplyLearnings` job per active-twin company
- Running `ApplyLearnings` for a company with zero unapplied Learnings returns immediately with no errors
- `ShouldBeUnique` prevents duplicate dispatches for the same company
- PHPStan level 8 — 0 errors on new files

---

### Phase 3 — Evidence Threshold Evaluation

**Goal:** The engine correctly skips Learning signals that haven't met their evidence threshold and applies those that have.

**Files to create:**

```
app/Services/Learning/SignalTier.php
app/Services/Learning/EvidenceEvaluator.php
```

**`SignalTier`:**

Maps signal type strings to their tier (1/2/3). Not an enum — a simple static class.

```php
final class SignalTier
{
    const TIER_1_SAFETY      = 1;
    const TIER_2_PERFORMANCE = 2;
    const TIER_3_PREFERENCE  = 3;

    private static array $map = [
        'email_deliverability_issue'        => self::TIER_1_SAFETY,
        'high_unsubscribe_rate'             => self::TIER_1_SAFETY,
        'channel_outperformed'              => self::TIER_2_PERFORMANCE,
        'channel_underperformed'            => self::TIER_2_PERFORMANCE,
        'campaign_type_succeeded'           => self::TIER_2_PERFORMANCE,
        'campaign_type_underperformed'      => self::TIER_2_PERFORMANCE,
        'recommendation_rejected'           => self::TIER_2_PERFORMANCE,
        'recommendation_edited_and_approved' => self::TIER_3_PREFERENCE,
        'content_angle_engaged'             => self::TIER_3_PREFERENCE,
        'optimal_timing_signal'             => self::TIER_3_PREFERENCE,
        'recommendation_approved'           => self::TIER_2_PERFORMANCE,
    ];

    public static function for(string $signal): int { ... }
    public static function thresholdFor(string $signal): int { ... }
}
```

Threshold mapping (these encode the asymmetric upward-bias rule):

| Signal | Threshold |
|--------|-----------|
| Tier 1 (safety) | 1 |
| `campaign_type_succeeded` | 1 (upward only) |
| `recommendation_approved` | 1 (upward only) |
| `channel_outperformed` | 2 |
| `channel_underperformed` | 2 |
| `campaign_type_underperformed` | 2 |
| `recommendation_rejected` | 2 |
| `recommendation_edited_and_approved` | 3 |
| `content_angle_engaged` | 3 |
| `optimal_timing_signal` | 4 |

**`EvidenceEvaluator`:**

```php
class EvidenceEvaluator
{
    public function meetsThreshold(string $companyId, Learning $learning, int $tier): bool
    {
        $threshold = SignalTier::thresholdFor($learning->signal);
        if ($threshold === 1) { return true; }

        $count = $this->evidenceCount(
            $companyId,
            $learning->signal,
            $this->discriminatorFor($learning),
        );

        return $count >= $threshold;
    }

    public function evidenceCount(string $companyId, string $signal, string $discriminator): int
    {
        return Learning::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('signal', $signal)
            ->whereRaw("payload->>'discriminator' = ?", [$discriminator])
            ->where('created_at', '>=', now()->subDays(90))
            ->count();
    }

    private function discriminatorFor(Learning $learning): string
    {
        return match ($learning->signal) {
            'channel_outperformed', 'channel_underperformed', 'optimal_timing_signal'
                => $learning->payload['channel_type'] ?? '',
            'campaign_type_succeeded', 'campaign_type_underperformed'
                => $learning->payload['campaign_type'] ?? '',
            'recommendation_approved', 'recommendation_rejected'
                => ($learning->payload['campaign_type'] ?? '') . '.' . ($learning->payload['channel_type'] ?? ''),
            'recommendation_edited_and_approved'
                => $learning->payload['channel_type'] ?? '',
            'content_angle_engaged'
                => ($learning->payload['channel_type'] ?? '') . '.' . ($learning->payload['angle'] ?? ''),
            default => '',
        };
    }
}
```

**Key subtlety:** The discriminator value stored in `payload` must be normalized consistently when Learning records are created (in `LearningService::recordFromMetrics()`). If payload shapes vary, the count query will undercount. Verify against the M8 LearningService implementation before trusting evidence counts.

**Exit criteria for Phase 3:**
- A single `channel_underperformed` Learning record does not meet its threshold (count = 1, required = 2)
- Two `channel_underperformed` records for the same channel meet the threshold (count = 2, required = 2)
- A single `email_deliverability_issue` record meets threshold immediately (Tier 1)
- Evidence older than 90 days does not count

---

### Phase 4 — Conflict Resolution

**Goal:** Opposing signals for the same discriminator are correctly resolved before effects are applied.

**Files to create:**

```
app/Services/Learning/ConflictResolver.php
```

**Conflict detection:** Two signals conflict when they represent opposing directions for the same `(signal_category, discriminator)`. Signal categories:

| Category | Signals |
|----------|---------|
| `channel` | `channel_outperformed` vs `channel_underperformed` (same `channel_type`) |
| `campaign_type` | `campaign_type_succeeded` vs `campaign_type_underperformed` (same `campaign_type`) |

Approval-side signals don't conflict with each other (approved vs. rejected are different signal types applied to different effects).

**`ConflictResolver`:**

```php
class ConflictResolver
{
    // Returns subset of $learnings to apply after conflict resolution.
    // Logs all resolution decisions at Info level.
    public function resolve(string $companyId, Collection $learnings): Collection
    {
        $conflicts = $this->detectConflicts($learnings);

        if (empty($conflicts)) { return $learnings; }

        $resolved = collect();
        foreach ($conflicts as $group) {
            $winner = $this->resolveGroup($companyId, $group['positive'], $group['negative']);
            if ($winner !== null) { $resolved = $resolved->merge($winner); }
        }

        $uncontested = $this->uncontested($learnings, $conflicts);

        return $resolved->merge($uncontested);
    }

    private function resolveGroup(string $companyId, Collection $pos, Collection $neg): ?Collection
    {
        // Rule 1: Safety always wins
        // (Safety signals don't conflict with performance signals — they're different categories.
        //  This rule fires when a Tier 1 signal is in one group and Tier 2 in another.)

        // Rule 2: Recency wins when counts within 1
        $posCount = $pos->count();
        $negCount = $neg->count();

        if (abs($posCount - $negCount) <= 1) {
            // Compare recency by median created_at within last 30 days
            return $this->resolveByRecency($pos, $neg);
        }

        // Rule 3: Majority wins when diff >= 2
        if ($posCount > $negCount) { return $pos; }
        if ($negCount > $posCount) { return $neg; }

        // Rule 4: Tie — no action
        Log::info('LearningEngine: conflict tie — no action', [...]);
        return null;
    }
}
```

**Logging:** Every resolution logs `[signal_category, discriminator, pos_count, neg_count, rule_applied, winner]` at `Info` channel. Use the `learning` log channel (add it to `config/logging.php`).

**Exit criteria for Phase 4:**
- 3 `channel_outperformed` + 1 `channel_underperformed` signals → majority rule → positive wins
- 2 `channel_outperformed` + 2 `channel_underperformed` signals, recency equal → tie → no action
- 2 `channel_outperformed` (60 days ago) + 2 `channel_underperformed` (last week) → recency → negative wins
- Log entry exists for every resolution

---

### Phase 5 — Fact and Knowledge Mutation

**Goal:** When a Learning signal passes evidence and conflict checks, it correctly creates new Facts and Knowledge entries and marks old ones inactive.

**Files to create:**

```
app/Services/Learning/FactMutator.php
app/Services/Learning/KnowledgeMutator.php
```

**`FactMutator`:**

Creates a new `Fact` in the learning-owned namespace, supersedes the old one. Returns an effect descriptor array.

```php
class FactMutator
{
    public function apply(string $companyId, Learning $learning): array
    {
        [$key, $value, $dataType] = $this->factSpec($learning);
        if ($key === null) { return []; }

        $existing = Fact::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->where('is_current', true)
            ->first();

        $newFact = Fact::withoutGlobalScopes()->create([
            'company_id'       => $companyId,
            'key'              => $key,
            'value'            => $value,
            'data_type'        => $dataType,
            'confidence'       => 85,
            'is_current'       => true,
            'source'           => 'learning',
            'prompt_name'      => 'LearningEngine',
            'prompt_version'   => '1.0',
        ]);

        if ($existing) {
            $existing->update(['is_current' => false, 'superseded_by_id' => $newFact->id]);
        }

        return [[
            'type'               => 'fact_created',
            'entity_type'        => 'Fact',
            'entity_id'          => $newFact->id,
            'key'                => $key,
            'previous_entity_id' => $existing?->id,
            'description'        => $this->describe($learning, $existing, $newFact),
        ]];
    }
}
```

**Fact namespace → key mapping:**

| Signal | Fact key | Value |
|--------|----------|-------|
| `channel_outperformed` | `channel_performance.{channel_type}.affinity` | `'strong'` |
| `channel_underperformed` | `channel_performance.{channel_type}.affinity` | `'weak'` |
| `campaign_type_succeeded` | `campaign_type.{campaign_type}.success_rate` | `'high'` |
| `campaign_type_underperformed` | `campaign_type.{campaign_type}.success_rate` | `'low'` |
| `email_deliverability_issue` | `audience.email.list_health` | `'compromised'` |
| `high_unsubscribe_rate` | `audience.email.unsubscribe_rate` | `'elevated'` |
| `content_angle_engaged` | `content_preferences.{channel_type}.angle` | payload `angle` |
| `optimal_timing_signal` | `timing.{channel_type}.preferred_hour` | payload `hour_of_day` |
| `recommendation_approved` | `campaign_type.{campaign_type}.approval_rate` | `'high'` |
| `recommendation_rejected` | `campaign_type.{campaign_type}.approval_rate` | `'low'` |
| `recommendation_edited_and_approved` | no direct Fact (Knowledge only) | N/A |

**`KnowledgeMutator`:**

Creates or updates a `Knowledge` entry with `type = 'learning'` and 90-day expiry. Always append-only: deactivates old entry and creates new.

```php
class KnowledgeMutator
{
    public function apply(string $companyId, Learning $learning): array
    {
        [$key, $body] = $this->knowledgeSpec($learning);
        if ($key === null) { return []; }

        $existing = Knowledge::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->where('type', 'learning')
            ->where('is_active', true)
            ->first();

        $newEntry = Knowledge::withoutGlobalScopes()->create([
            'company_id'   => $companyId,
            'key'          => $key,
            'type'         => 'learning',
            'body'         => $body,
            'is_active'    => true,
            'expires_at'   => now()->addDays(90),
            'prompt_name'  => 'LearningEngine',
            'prompt_version' => '1.0',
        ]);

        if ($existing) {
            $existing->update(['is_active' => false]);
        }

        return [[
            'type'        => $existing ? 'knowledge_updated' : 'knowledge_created',
            'entity_type' => 'Knowledge',
            'entity_id'   => $newEntry->id,
            'key'         => $key,
            'description' => $body,
        ]];
    }
}
```

**Knowledge key → body mapping (per signal):**

| Signal | Key | Body template |
|--------|-----|---------------|
| `channel_outperformed` | `channel.{channel_type}.preferred` | `"{channel_type} consistently outperforms other channels for this company. Prioritize {channel_type} in multi-channel campaigns."` |
| `channel_underperformed` | `channel.{channel_type}.underperforming` | `"{channel_type} has consistently underperformed for this company. Reduce reliance on {channel_type} in recommendations."` |
| `campaign_type_succeeded` | `campaign_type.{campaign_type}.performs_well` | `"{campaign_type} campaigns consistently produce strong results for this company."` |
| `campaign_type_underperformed` | `campaign_type.{campaign_type}.underperforms` | `"{campaign_type} campaigns have underperformed consistently. Consider alternative campaign types."` |
| `email_deliverability_issue` | `email.deliverability.issue` | `"Email deliverability issues detected (hard bounces or spam complaints). Email list needs attention before the next campaign."` |
| `high_unsubscribe_rate` | `email.unsubscribe.elevated` | `"Above-average unsubscribe rate detected. Consider reducing email send frequency or revising content strategy."` |
| `content_angle_engaged` | `content.{channel_type}.preferred_angle` | `"{angle} content angle consistently drives engagement on {channel_type} for this company."` |
| `optimal_timing_signal` | `timing.{channel_type}.optimal` | `"Optimal send time for {channel_type}: {day_of_week} at {hour_of_day}:00."` |
| `recommendation_approved` | `approval_rate.{campaign_type}` | `"{campaign_type} recommendations are consistently approved. Continue prioritizing this type."` |
| `recommendation_rejected` | `rejection_pattern.{campaign_type}.{channel_type}` | `"{campaign_type} on {channel_type} is consistently rejected. Reconsider this combination for this company."` |

**Safety signal notifications:** For `email_deliverability_issue` and `high_unsubscribe_rate`, after the Knowledge entry is written, create a Filament `DatabaseNotification` (or equivalent) for the company's owner. Do not send external email/SMS. The notification body mirrors the Knowledge body.

**Exit criteria for Phase 5:**
- `FactMutator::apply()` with a `channel_outperformed` Learning creates a new Fact with `key = 'channel_performance.email.affinity'` and `value = 'strong'`; old Fact has `is_current = false`; `superseded_by_id` is set to the new Fact's ID
- `KnowledgeMutator::apply()` creates a Knowledge entry with `type = 'learning'` and `expires_at` 90 days from now
- Calling `apply()` a second time creates a second new entry and deactivates the first
- Both methods return non-empty effect descriptor arrays
- A Tier 1 signal triggers a Filament notification

---

### Phase 6 — CompanyScoringWeights Versioning and OpportunityScorer Integration

**Goal:** Signals that warrant weight adjustment create new `CompanyScoringWeights` versions with bounds enforcement. `OpportunityScorer` reads per-company weights.

**Files to create:**

```
app/Services/Learning/WeightCalibrator.php
```

**Files to modify:**

```
app/Domain/Opportunity/Services/OpportunityScorer.php
```

**Weight-affecting signals:**

Only `campaign_type_succeeded` and `campaign_type_underperformed` produce weight changes in Phase 8. Channel and approval signals produce Knowledge only. This keeps the weight table simple and prevents oscillation.

| Signal | Effect |
|--------|--------|
| `campaign_type_succeeded` | `type_modifiers[campaign_type]` += 0.05 (capped at 1.50) |
| `campaign_type_underperformed` | `type_modifiers[campaign_type]` -= 0.05 (floored at 0.50) |

The four scoring components (`relevance`, `timing`, `confidence`, `urgency`) are adjusted only in future phases as more signal diversity accumulates. Phase 8 focuses on `type_modifiers` only, avoiding premature component weight divergence.

**`WeightCalibrator`:**

```php
class WeightCalibrator
{
    public function apply(string $companyId, Learning $learning): array
    {
        if (! $this->affectsWeights($learning->signal)) { return []; }
        if ($this->inCoolingPeriod($companyId, $learning->signal)) { return []; }

        $current = CompanyScoringWeights::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_current', true)
            ->first();

        $weights = $current?->weights ?? $this->defaultWeights();
        $weights = $this->applyAdjustment($weights, $learning);
        $nextVersion = ($current?->version ?? 0) + 1;

        $newRow = null;

        DB::transaction(function () use ($companyId, $weights, $nextVersion, $current, $learning, &$newRow) {
            if ($current) {
                $current->update(['is_current' => false]);
            }
            $newRow = CompanyScoringWeights::create([
                'company_id'  => $companyId,
                'weights'     => $weights,
                'version'     => $nextVersion,
                'is_current'  => true,
                'learning_id' => $learning->id,
            ]);
        });

        return [[
            'type'        => 'weight_version_created',
            'entity_type' => 'CompanyScoringWeights',
            'entity_id'   => $newRow->id,
            'version'     => $nextVersion,
            'description' => $this->describe($learning, $weights),
        ]];
    }

    private function applyAdjustment(array $weights, Learning $learning): array
    {
        $type = $learning->payload['campaign_type'] ?? null;
        if ($type === null) { return $weights; }

        $delta = match ($learning->signal) {
            'campaign_type_succeeded'      => +0.05,
            'campaign_type_underperformed' => -0.05,
            default => 0,
        };

        $modifiers = $weights['type_modifiers'] ?? [];
        $current = $modifiers[$type] ?? 1.0;
        $modifiers[$type] = max(0.50, min(1.50, $current + $delta));
        $weights['type_modifiers'] = $modifiers;

        // Renormalize base components (sum to 1.00, floor 0.05, ceiling 0.60)
        $weights = $this->renormalize($weights);

        return $weights;
    }

    private function inCoolingPeriod(string $companyId, string $signal): bool
    {
        return LearningApplication::withoutGlobalScopes()
            ->whereHas('learning', fn ($q) => $q->where('signal', $signal))
            ->where('company_id', $companyId)
            ->where('applied_at', '>=', now()->subDays(14))
            ->whereNull('rolled_back_at')
            ->exists();
    }

    private function defaultWeights(): array
    {
        return [
            'relevance'     => 0.30,
            'timing'        => 0.25,
            'confidence'    => 0.25,
            'urgency'       => 0.20,
            'type_modifiers' => [],
        ];
    }
}
```

**`renormalize()` logic:**

After any base component adjustment (future phases), ensure:
1. Floor each at 0.05
2. Ceiling each at 0.60
3. Sum all four → scale proportionally to exactly 1.00

```php
private function renormalize(array $weights): array
{
    $components = ['relevance', 'timing', 'confidence', 'urgency'];
    foreach ($components as $k) {
        $weights[$k] = max(0.05, min(0.60, $weights[$k] ?? 0.25));
    }
    $sum = array_sum(array_intersect_key($weights, array_flip($components)));
    foreach ($components as $k) {
        $weights[$k] = round($weights[$k] / $sum, 4);
    }
    return $weights;
}
```

**`OpportunityScorer` update:**

```php
// Add to OpportunityScorer:
private function weightsFor(string $companyId): array
{
    $row = CompanyScoringWeights::withoutGlobalScopes()
        ->where('company_id', $companyId)
        ->where('is_current', true)
        ->first();

    return $row?->weights ?? [
        'relevance'     => 0.30,
        'timing'        => 0.25,
        'confidence'    => 0.25,
        'urgency'       => 0.20,
        'type_modifiers' => [],
    ];
}
```

The `score()` method must accept `companyId` as a parameter (or the full `Company` object) to call `weightsFor()`. Audit all callers of `OpportunityScorer::score()` and update signatures. This is a breaking change to the existing method signature.

**Exit criteria for Phase 6:**
- After a `campaign_type_succeeded` Learning is applied, `CompanyScoringWeights` has a new row with version +1 and `type_modifiers.featured_item = 1.05`
- The old row has `is_current = false`
- A second application within 14 days is skipped (cooling period)
- `type_modifiers` never exceeds 1.50 or falls below 0.50
- Base component weights always sum to 1.00
- `OpportunityScorer::score()` returns a different value for a company with custom weights vs. defaults
- `OpportunityScorer` falls back to defaults for a company with no `CompanyScoringWeights` row

---

### Phase 7 — LearningRollbackService

**Goal:** An admin can roll back any `LearningApplication`, which creates compensating records and resets the Learning for re-evaluation.

**Files to create:**

```
app/Services/Learning/LearningRollbackService.php
```

**`LearningRollbackService`:**

```php
class LearningRollbackService
{
    public function rollback(LearningApplication $application, string $reason): void
    {
        if ($application->rolled_back_at !== null) {
            throw new \RuntimeException("LearningApplication {$application->id} has already been rolled back.");
        }

        DB::transaction(function () use ($application, $reason) {
            foreach ($application->effects as $effect) {
                $this->compensate($effect);
            }
            $application->rolled_back_at = now();
            $application->rollback_reason = $reason;
            $application->save();

            // Reset Learning so it can be re-evaluated in the next daily run
            Learning::withoutGlobalScopes()
                ->where('id', $application->learning_id)
                ->update(['applied_at' => null]);
        });

        $isSafety = in_array(
            Learning::withoutGlobalScopes()->find($application->learning_id)?->signal,
            ['email_deliverability_issue', 'high_unsubscribe_rate'],
        );

        if ($isSafety) {
            Log::warning('LearningRollbackService: Tier 1 safety signal rolled back', [
                'learning_application_id' => $application->id,
                'reason' => $reason,
            ]);
        }
    }

    private function compensate(array $effect): void
    {
        match ($effect['type']) {
            'fact_created'          => $this->compensateFact($effect),
            'knowledge_created',
            'knowledge_updated',
            'preference_updated'    => $this->compensateKnowledge($effect),
            'weight_version_created' => $this->compensateWeights($effect),
            default => null,
        };
    }
}
```

**`compensateFact()`:** Find the new Fact by `entity_id`. Set `is_current = false`. If `previous_entity_id` is set, restore the predecessor: `is_current = true`, `superseded_by_id = null`. Never delete rows.

**`compensateKnowledge()`:** Find the Knowledge entry by `entity_id`. Set `is_active = false`. If a predecessor existed (the one that was deactivated when this was created), find it via the `key + type + company_id + previous created_at` and restore `is_active = true`. Store predecessor IDs in the effect descriptor at mutation time to make rollback deterministic.

**Effect descriptor must include `previous_entity_id` for all mutations.** Add this to `FactMutator` and `KnowledgeMutator` in Phase 5 if not already done.

**`compensateWeights()`:** Find `CompanyScoringWeights` with `version = effect['version']` for the company. Set `is_current = false`. Find version `effect['version'] - 1` and set `is_current = true`. If version 1 is rolled back and there is no version 0 row, `CompanyScoringWeights` for this company is now empty — `OpportunityScorer` will fall back to global defaults (correct behavior).

**Exit criteria for Phase 7:**
- Rolling back a `fact_created` effect restores the predecessor Fact to `is_current = true` and sets the new one to `false`; `superseded_by_id` on the predecessor is cleared
- Rolling back a `weight_version_created` effect restores the prior version to `is_current = true`; the rolled-back version is `is_current = false`; nothing is deleted
- `Learning.applied_at` is reset to null after rollback
- Rolling back an already-rolled-back application throws
- Rolling back a Tier 1 signal produces a `Warning` log entry
- All operations run inside a single DB transaction — if any step fails, no state changes are committed

---

### Phase 8 — Prompt Context and BusinessBrain Integration

**Goal:** Learning-derived Knowledge entries flow through the BusinessBrain into the context passed to AI analysts. Edit pattern detection creates preference Knowledge entries.

**Files to create:**

```
app/Services/Learning/EditPatternDetector.php
```

**Files to modify:**

```
app/Services/BusinessBrainService.php
app/Services/Learning/LearningEngine.php    (wire EditPatternDetector)
```

**`BusinessBrainService::for()` update:**

The existing implementation assembles the `BusinessBrain` value object from current Facts and active Knowledge. Verify that the Knowledge query does not filter out `type = 'learning'` entries. If it does, remove the type filter or expand it to include both `'context'` and `'learning'`.

The `BusinessBrain` value object's `knowledge` collection must include all active, non-expired Knowledge entries regardless of type. Analysts that receive the BusinessBrain context can then surface company preferences alongside synthesized context.

**`EditPatternDetector`:**

Processes `recommendation_edited_and_approved` Learning signals to detect systematic content preferences. Called by `LearningEngine` when processing Tier 3 preference signals.

```php
class EditPatternDetector
{
    // Returns array of [key => $key, body => $body] preference entries to write
    public function detect(string $companyId): array
    {
        $editSignals = Learning::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('signal', 'recommendation_edited_and_approved')
            ->where('created_at', '>=', now()->subDays(90))
            ->get();

        if ($editSignals->count() < 3) { return []; }

        $preferences = [];

        if ($this->detectHashtagRemoval($editSignals)) {
            $preferences[] = [
                'key'  => 'content_preferences.instagram.no_hashtags',
                'body' => 'Do not use hashtags in Instagram posts for this company.',
            ];
        }

        if ($this->detectLengthPreference($editSignals)) {
            $preferences[] = [
                'key'  => 'content_preferences.email.length',
                'body' => 'Generate concise email body copy (target: 150 words) for this company.',
            ];
        }

        if ($this->detectPriceInclusion($editSignals)) {
            $preferences[] = [
                'key'  => 'content_preferences.email.include_price',
                'body' => 'Always include product price in email subject lines for this company.',
            ];
        }

        return $preferences;
    }

    private function detectHashtagRemoval(Collection $signals): bool
    {
        $relevant = $signals->filter(fn ($s) => str_contains($s->payload['channel_type'] ?? '', 'instagram'));
        if ($relevant->count() < 3) { return false; }

        $removed = $relevant->filter(function ($s) {
            $before = substr_count($s->payload['edits']['original_body'] ?? '', '#');
            $after  = substr_count($s->payload['edits']['edited_body'] ?? '', '#');
            return $before > 0 && $after === 0;
        });

        return $removed->count() >= 3;
    }

    private function detectLengthPreference(Collection $signals): bool
    {
        $emailSignals = $signals->filter(fn ($s) => ($s->payload['channel_type'] ?? '') === 'email');
        if ($emailSignals->count() < 3) { return false; }

        $shortened = $emailSignals->filter(function ($s) {
            $orig = str_word_count($s->payload['edits']['original_body'] ?? '');
            $edited = str_word_count($s->payload['edits']['edited_body'] ?? '');
            return $orig > 0 && ($edited / $orig) < 0.75;
        });

        return $shortened->count() >= 3;
    }

    private function detectPriceInclusion(Collection $signals): bool
    {
        $emailSubjectSignals = $signals->filter(fn ($s) => ($s->payload['channel_type'] ?? '') === 'email');
        if ($emailSubjectSignals->count() < 3) { return false; }

        $priceAdded = $emailSubjectSignals->filter(function ($s) {
            $originalSubject = $s->payload['edits']['original_subject'] ?? '';
            $editedSubject   = $s->payload['edits']['edited_subject'] ?? '';
            return ! preg_match('/\$\d/', $originalSubject) && preg_match('/\$\d/', $editedSubject);
        });

        return $priceAdded->count() >= 3;
    }
}
```

**`LearningEngine` integration:** When processing Tier 3 `recommendation_edited_and_approved` signals, call `EditPatternDetector::detect($companyId)` and pass each detected preference to `KnowledgeMutator` as a `preference_updated` effect.

**Prompt version tracking:** `LearningEngine::applyForCompany()` computes approval rates grouped by `prompt_version` across the company's recent Recommendations and writes a `prompt_underperformed` Knowledge entry (type: `learning`) if a version's approval rate is below 60%. This is surfaced in Filament only — no automated weight change.

**Exit criteria for Phase 8:**
- `BusinessBrainService::for()` returns a `BusinessBrain` with Learning-derived Knowledge entries in its `knowledge` collection
- After 3 edit signals with detected hashtag removal, `KnowledgeMutator` creates a Knowledge entry with `type = 'learning'` and key `content_preferences.instagram.no_hashtags`
- No prompt template file is modified by `LearningEngine` or `EditPatternDetector`
- `ContentGenerationAnalyst` receives the updated BusinessBrain context (verify by asserting Knowledge count in test)

---

### Phase 9 — Filament Visibility

**Goal:** Admins can inspect the full Learning history per company, understand what Atlas learned and when, and initiate rollbacks.

**Files to create or modify:**

```
app/Filament/Resources/CompanyResource.php              — three new infolist sections
app/Filament/Resources/CompanyResource/Pages/ViewCompany.php  — extend existing
```

**Three sections to add to `CompanyResource` ViewCompany page:**

**Learning Log tab** (per-company):
- Table of all `Learning` records for the company: `signal`, `source_type`, `created_at`, `applied_at` (badge: `pending` / `applied`)
- Grouped by signal tier (Tier 1 / Tier 2 / Tier 3)
- Link to source record (Recommendation, Campaign) if navigable

**Applied Effects tab**:
- Table of all `LearningApplication` records, sorted `applied_at` desc
- Expand each row to show `effects` as a readable list
- Badge: `active` / `rolled back`
- Rollback button on `active` rows (admin role check) — opens a modal asking for `rollback_reason`, then calls `LearningRollbackService::rollback()`

**BusinessBrain Mutations tab**:
- Current `CompanyScoringWeights` displayed as a comparison table: default vs. company-specific, per component
- `type_modifiers` shown as a badge list
- Weight history: all `CompanyScoringWeights` versions, `version`, `created_at`, `learning_id`
- Learning-derived Knowledge entries: filter Knowledge entries where `type = 'learning'`, display key, body, expires_at
- Count of pending (unapplied) Learning records per signal type

**Filament Notification:** For Tier 1 safety signals, use `Filament\Notifications\Notification::make()` to create a persistent notification for the company owner. Attach it to the company's record (store in `database` notification channel).

**Exit criteria for Phase 9:**
- The Learning Log tab is visible on the Company ViewCompany page in Filament
- The Applied Effects tab shows `LearningApplication` records with expanded effects
- The rollback action appears only for admin role; clicking it opens a modal; submitting calls the rollback service
- BusinessBrain Mutations tab shows current weights vs. defaults and Learning-derived Knowledge

---

### Phase 10 — Tests

**Goal:** All 47 acceptance criteria from `specs/core/learning-engine.md` §13 are covered by automated tests. PHPStan level 8 — 0 errors. Pint clean. Total tests ≥ 420.

**Test file map:**

| File | Tests | What they cover |
|------|-------|----------------|
| `tests/Feature/Learning/LearningEngineTest.php` | ~12 | Idempotency (double-run), tier prioritization, full pipeline (end-to-end: unapplied → applied → LearningApplication exists) |
| `tests/Feature/Learning/EvidenceEvaluatorTest.php` | ~8 | Tier 1 always passes; Tier 2 threshold; Tier 3 threshold; 90-day window excludes stale evidence; discriminator matching per signal |
| `tests/Feature/Learning/ConflictResolverTest.php` | ~6 | Rule 1 (safety wins); Rule 2 (recency wins when count within 1); Rule 3 (majority wins); Rule 4 (tie = no-action); conflict logging |
| `tests/Feature/Learning/FactMutatorTest.php` | ~6 | Correct key/value per signal; new Fact `is_current = true`; old Fact `is_current = false`; `superseded_by_id` set; effect descriptor non-empty |
| `tests/Feature/Learning/KnowledgeMutatorTest.php` | ~6 | Knowledge `type = 'learning'`; `expires_at` 90 days; old entry deactivated; body matches template; `previous_entity_id` in effect descriptor |
| `tests/Feature/Learning/WeightCalibratorTest.php` | ~8 | Weight created on success signal; cooling period blocks second adjustment within 14 days; `type_modifier` floor 0.50, ceiling 1.50; base weights sum to 1.00; version increments; prior version `is_current = false`; fallback to defaults when no row |
| `tests/Feature/Learning/LearningRollbackServiceTest.php` | ~7 | Fact restored; Knowledge restored; Weight version restored; `applied_at` reset to null; double-rollback throws; Tier 1 logs Warning; all inside single transaction |
| `tests/Feature/Learning/ApplyLearningsJobTest.php` | ~4 | Dispatches per active-twin company only; `ShouldBeUnique` drops duplicate dispatch; idempotent on second run (nothing applied); failure leaves `applied_at = null` |
| `tests/Feature/Learning/EditPatternDetectorTest.php` | ~5 | Hashtag removal detected after 3+ signals; length reduction detected; price inclusion detected; fewer than 3 signals → no preference written; non-matching patterns ignored |
| `tests/Feature/Learning/OpportunityScorerWeightTest.php` | ~5 | Scorer reads company weights; `type_modifier` applied to composite score; no row → uses defaults; different scores for different companies; score capped at 100 |

**Test helpers:**

All tests that need Learning records should use a `LearningTestCase` base class (similar to `AnalyticsTestCase` from M8) with helpers:

```php
protected function makeApprovalLearning(string $signal, array $payload = []): Learning
{
    return Learning::withoutGlobalScopes()->create([
        'company_id'  => $this->company->id,
        'signal'      => $signal,
        'source_type' => 'approval',
        'source_id'   => Str::ulid()->toString(),
        'payload'     => $payload,
        'applied_at'  => null,
    ]);
}

protected function makeMetricLearning(string $signal, array $payload = []): Learning
{
    // Similar pattern for analytics-sourced signals
}
```

**PHPStan and Pint:**

Run after all phases complete:

```bash
cd backend && ./vendor/bin/pint --dirty
cd backend && ./vendor/bin/phpstan analyse --level=8
cd backend && php artisan test --parallel
```

PHPStan annotations required for any new model with polymorphic relationships or JSON casts. Follow the `ChannelCredentials` PHPDoc pattern established in M8.

---

## 5. Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| **`facts.superseded_by_id` absent from schema** — `FactMutator` would fail silently or cause a DB error | Medium | High | Verify in Phase 1 prerequisite check; add migration if absent before any mutator code runs |
| **Approval-side Learning signals have wrong payload shape** — `EvidenceEvaluator.discriminatorFor()` would return wrong discriminator; evidence counts would be off | Medium | High | Audit `ApprovalService` against spec payload shapes in Phase 1; add integration test that creates an approval and asserts the Learning signal payload matches the expected shape |
| **`CompanyScoringWeights.is_current` race condition** — two concurrent `ApplyLearnings` jobs (possible if `ShouldBeUnique` Redis lock expires before job finishes) could both try to set `is_current = true` | Low | Medium | Wrap all weight version transitions in a DB transaction with a `SELECT ... FOR UPDATE` on the current row; `ShouldBeUnique` prevents the most common case |
| **Renormalization drift** — repeated small weight adjustments + renormalization could cause float rounding drift where weights don't sum exactly to 1.0000 | Medium | Low | Use `round($w, 4)` on each component and verify sum with `abs(array_sum($components) - 1.0) < 0.001` in `WeightCalibrator`; log and skip adjustment if assertion fails |
| **`EditPatternDetector` false positives** — heuristic keyword detection may produce incorrect preferences (e.g., user added a price once but it's not a pattern) | Medium | Medium | Thresholds are already set at 3+; in future, add confidence score to preference Knowledge entries; make them easy to roll back via Filament |
| **BusinessBrainService Knowledge query excludes `type = 'learning'`** — Learning would be applied but never surfaced to analysts | Low | High | Explicit test: assert `BusinessBrain.knowledge` count includes Learning-derived entries after `ApplyLearnings` runs |
| **`OpportunityScorer` signature change breaks callers** — adding `companyId` parameter could break `OpportunityEngine`, `DecisionEngine`, and any test that constructs the scorer | High | High | Search all callers before changing the signature; update all at once; this is the highest-risk mechanical change in M9 |

---

## 6. Acceptance Criteria

All criteria are directly from `specs/core/learning-engine.md` §13. Each must be covered by an automated test.

### Learning Application
- `ApplyLearnings` reads only `Learning` records where `applied_at IS NULL`
- After application, `applied_at` is set and the record is not re-processed
- Two invocations on the same day are idempotent
- `LearningApplication` is created for every applied Learning with non-empty `effects`
- Unique constraint on `(company_id, learning_id)` prevents double-application at DB level

### Evidence Thresholds
- Single `channel_underperformed` → no weight change
- Two `channel_underperformed` (same channel) → weight change
- Single `campaign_type_succeeded` → upward modifier adjustment
- Single `email_deliverability_issue` → applied immediately (Tier 1)

### Conflict Resolution
- 3 `channel_outperformed` + 1 `channel_underperformed` → majority wins → positive applied
- 2 opposing signals, no recency difference → tie → both remain unapplied
- Tier 1 safety signal never overridden by Tier 2 performance signal

### Weight Calibration
- No adjustment exceeds ±5% per run (Phase 8 uses fixed ±0.05 step for type_modifiers)
- No type_modifier below 0.50 or above 1.50
- Base weight components always sum to 1.00 after renormalization
- `OpportunityScorer` reads company weights; falls back to defaults when no row exists

### Cooling Period
- Second weight adjustment for same signal category within 14 days → deferred
- After 14 days → eligible again

### BusinessBrain Mutation
- New Fact supersedes predecessor (`is_current` toggled, `superseded_by_id` set)
- New Learning Knowledge entry deactivates predecessor (`is_active = false`)
- `CompanyScoringWeights` version increments; previous `is_current = false`
- `BusinessBrainService::for()` returns updated state after `ApplyLearnings` runs

### Company Scoping
- Company A Learning records not read when processing Company B
- `OpportunityScorer` reads weights scoped to correct `company_id`
- No cross-company join in any Learning Engine query

### Rollback
- Rolling back restores predecessor Fact/Knowledge/Weight versions
- Rolled-back Learning has `applied_at` reset to null
- No records deleted during rollback
- Double rollback throws

### Explainability
- Every `effects` entry has non-empty `description`
- `effects` correctly identifies `previous_entity_id` for superseded Facts
- Filament can list `LearningApplication` records with effects

### Prompt Adaptation
- 3 `recommendation_edited_and_approved` signals with hashtag removal → Knowledge entry with `type = 'learning'`
- `ContentGenerationAnalyst` receives Learning-derived Knowledge via BusinessBrain context
- No prompt template file modified by `LearningEngine`

---

## 7. Deliverables

### New files

```
database/migrations/*_create_learning_applications_table.php
database/migrations/*_create_company_scoring_weights_table.php
database/migrations/*_add_superseded_by_id_to_facts_table.php      (if needed)
database/migrations/*_add_type_to_knowledge_entries_table.php       (if needed)

app/Models/LearningApplication.php
app/Models/CompanyScoringWeights.php

app/Providers/LearningServiceProvider.php
app/Jobs/ApplyLearnings.php
app/Services/Learning/LearningEngine.php
app/Services/Learning/SignalTier.php
app/Services/Learning/EvidenceEvaluator.php
app/Services/Learning/ConflictResolver.php
app/Services/Learning/FactMutator.php
app/Services/Learning/KnowledgeMutator.php
app/Services/Learning/WeightCalibrator.php
app/Services/Learning/EditPatternDetector.php
app/Services/Learning/LearningRollbackService.php

tests/Feature/Learning/LearningTestCase.php
tests/Feature/Learning/LearningEngineTest.php
tests/Feature/Learning/EvidenceEvaluatorTest.php
tests/Feature/Learning/ConflictResolverTest.php
tests/Feature/Learning/FactMutatorTest.php
tests/Feature/Learning/KnowledgeMutatorTest.php
tests/Feature/Learning/WeightCalibratorTest.php
tests/Feature/Learning/LearningRollbackServiceTest.php
tests/Feature/Learning/ApplyLearningsJobTest.php
tests/Feature/Learning/EditPatternDetectorTest.php
tests/Feature/Learning/OpportunityScorerWeightTest.php
```

### Modified files

```
bootstrap/providers.php                                      — add LearningServiceProvider
routes/console.php                                           — add ApplyLearnings schedule
app/Domain/Opportunity/Services/OpportunityScorer.php        — add weightsFor(), update score() signature
app/Services/BusinessBrainService.php                        — include type='learning' Knowledge
app/Services/ApprovalService.php                             — wire approval-side Learning signals (if not done)
app/Filament/Resources/CompanyResource.php                   — add three Learning visibility tabs
app/Filament/Resources/CompanyResource/Pages/ViewCompany.php — extend as needed
config/logging.php                                           — add 'learning' log channel
```

---

## 8. Milestone Exit Criteria

All of the following must be true before Milestone 9 is considered complete.

**Tests:**
- [ ] ≥ 420 total tests passing (365 from M8 + ≥ 55 new)
- [ ] 0 tests failing
- [ ] 2 Redis-skipped tests remain the only skipped tests

**Static analysis:**
- [ ] PHPStan level 8 — 0 errors

**Code style:**
- [ ] `./vendor/bin/pint --dirty` produces no output (clean)

**Functional verification:**
- [ ] `php artisan migrate` runs cleanly on a fresh database
- [ ] `php artisan schedule:run` dispatches one `ApplyLearnings` job per active-twin company
- [ ] `ApplyLearnings` for a company with 5 unapplied Learning records: at least one `LearningApplication` record created after the job runs, all applied Learning records have `applied_at` set
- [ ] `OpportunityScorer::score()` returns a different value for a company with a `campaign_type_succeeded` Learning applied vs. a fresh company

**Documentation:**
- [ ] `docs/STATUS.md` updated: Milestone 9 added to Completed Milestones table, Current Milestone updated
- [ ] `CHANGELOG.md` updated: Milestone 9 entry at top with all deliverables
- [ ] `docs/reviews/Milestone-9-Review.md` created with spec compliance table, non-obvious technical decisions, issues encountered

**Commit and push:**
- [ ] All changes committed to `main`
- [ ] Branch pushed to `origin/main`
- [ ] GitHub Actions CI passes (Pint + PHPStan + PHPUnit)
