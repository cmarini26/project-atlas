# Milestone 11 Phase 5 — Business Brain Integration — Review

**Date:** 2026-07-09
**Scope:** Phase 5 only, per [Milestone-11-Marketing-Presence.md](../plans/Milestone-11-Marketing-Presence.md). No Opportunity Engine, Decision Engine, publishing, or onboarding changes.
**Tests:** 809 total (807 passing, 2 Redis skipped) — 25 new
**PHPStan:** Level 8 — 0 errors · **Pint:** Clean · **Frontend build:** succeeds (no frontend files touched this phase) · **Vitest:** unaffected, still green

---

## What shipped

### `App\Domain\BusinessBrain\MarketingPresenceSummary` (new)

A small `readonly` value object — `primaryChannels`, `secondaryChannels`, `inactiveChannels`, `primaryObjectives` (all `list<string>`), and a composed `summary` string. This, not a `Collection<MarketingChannel>`, is what `BusinessBrain` now carries.

### `App\Services\Brain\MarketingPresenceSynthesizer` (new)

The only place that reads `MarketingChannel` rows to describe a company's marketing strategy. `synthesize(string $companyId): MarketingPresenceSummary`:

- Buckets by **status first, then importance**: any channel with `status: inactive` goes into `inactiveChannels` regardless of its `importance` — a channel the business stopped using is described as inactive, not as a demoted primary channel. Of the remaining channels, `importance: primary` channels become `primaryChannels`; `secondary` and `experimental` are folded together into `secondaryChannels` (the task calls for exactly three channel buckets, not four).
- `primaryObjectives` is the deduplicated union of `objective` values across the primary bucket, falling back to every non-inactive channel if none are marked primary (so a company that hasn't carefully set importance still gets a non-empty objectives list).
- `summary` is one sentence per non-empty bucket, e.g. *"Primary marketing channels: Instagram, Email. Secondary marketing channels: Facebook. No longer active on: X. Primary marketing objectives: awareness, trust."* A company with nothing declared gets a fixed sentence: *"No marketing channels have been declared yet."*
- **Deterministic string composition, not an AI call.** Bucketing a handful of enum-valued rows and joining names is not a task that benefits from a probabilistic model (Founding Principle 1), and the existing `BusinessBrainService::assemble()` is a pure aggregation step — `activeFacts`/`activeKnowledge` are themselves already AI-synthesized upstream (by earlier pipeline stages), but assembly itself has never made an AI call, and this keeps it that way.

### `BusinessBrain` (modified)

Gained a 9th constructor parameter: `public ?MarketingPresenceSummary $marketingPresence = null`. Nullable with a default so the ~10 existing test files across `tests/Feature/Opportunity/`, `tests/Feature/Decision/`, and `tests/Feature/Campaign/` that construct `new BusinessBrain(...)` directly (with named arguments, omitting this new field) continue to compile and pass completely unmodified — no Opportunity Engine, Decision Engine, or Campaign test file needed to change for this addition. `BusinessBrainService::assemble()` always populates it for real production use.

### `BusinessBrainService` (modified)

Constructor now injects `MarketingPresenceSynthesizer`; `assemble()` calls `$this->marketingPresence->synthesize($company->id)` and passes the result to the `BusinessBrain` constructor. No change to the memoization/TTL/invalidation mechanism itself.

### Event wiring (`AppServiceProvider::boot()`)

```php
Event::listen(MarketingPresenceUpdated::class, function (MarketingPresenceUpdated $event): void {
    BusinessBrainService::invalidate($event->marketingChannel->company_id);
});
```

Registered immediately after the existing `FactExtracted`/`KnowledgeSynthesized` listeners, in the exact same style (inline closure, not a dedicated Listener class) — `MarketingPresenceUpdated` already carries the full `MarketingChannel` model (shipped inert in Phase 2), so the company id comes off `$event->marketingChannel->company_id`, the same pattern the two existing listeners already use (`$event->fact->company_id`, `$event->knowledge->company_id`).

**No synchronous rebuild, no queue.** `BusinessBrainService::invalidate()` only clears an in-process memo entry (`unset()` on a static array) — it does not reassemble anything. The next `BusinessBrainService::for($company)` call lazily rebuilds, including a fresh synthesis pass. No Job was introduced, and none was needed: the memo is per-process, so a queued invalidation running in a separate worker wouldn't even reach the same memo — the existing `FactExtracted`/`KnowledgeSynthesized` listeners already establish this is the correct, sufficient pattern.

### Prompt inheritance

No prompt template file was modified. `CampaignPreparationPrompt`/`RationaleGenerationPrompt` (and every other `Analyst`) already receive the full `BusinessBrain` object through the existing flow; `$brain->marketingPresence->summary` is now available on it without any further plumbing. Whether and how to fold that summary into generated prompt text is left for whichever future session actually needs it — adding it now would mean editing prompt classes that sit immediately adjacent to the Opportunity/Decision Engine pipeline, which this phase's boundaries explicitly leave alone, and the task's own instruction says not to modify prompt templates "unless required" — it isn't, for the data path to exist.

---

## Tenant isolation

- `MarketingPresenceSynthesizer::synthesize()` always scopes its `MarketingChannel` query by the passed `companyId` (`withoutGlobalScopes()->where('company_id', $companyId)`), matching every other Business-Brain-adjacent repository (`FactRepository`, `KnowledgeRepository`).
- The event listener invalidates only the memo entry keyed by `$event->marketingChannel->company_id` — a `MarketingPresenceUpdated` event for company B never touches company A's memoized brain, verified the same way the existing `FactExtracted`/`KnowledgeSynthesized` cross-company tests already do.

---

## Tests (25 new)

| File | Covers |
|---|---|
| `tests/Feature/Brain/MarketingPresenceSynthesizerTest.php` (new, 12 tests) | Empty case; primary/secondary/experimental bucketing; inactive-overrides-importance bucketing; planned/occasional treated as active; objectives from primary channels, falling back to active channels, deduplicated; the composed summary sentence mentions every populated bucket; tenant isolation (a second company's channels never leak into the first's summary) |
| `tests/Feature/Brain/BusinessBrainServiceTest.php` (3 new) | `BusinessBrain::marketingPresence` is populated and non-null; the empty-company case renders the fixed "no channels declared" sentence; the summary only ever exposes strings (`assertContainsOnly('string', ...)`) — never a `MarketingChannel` instance — directly verifying the "no raw rows" boundary; full bucketing end-to-end through the real service (not just the synthesizer in isolation) |
| `tests/Feature/Brain/BusinessBrainCacheTest.php` (3 new) | `MarketingPresenceUpdated` invalidates the memo, mirroring `test_fact_extracted_event_invalidates_memo` exactly; an event scoped to a different company does **not** invalidate this company's memo; after invalidation, the next `for()` call reassembles a fresh (non-stale) `marketingPresence` summary reflecting a channel declared after the first call |

All pre-existing Brain tests (19) continue to pass completely unmodified, confirming no regression to existing Business Brain behavior.

No test in this phase touches `OpportunityEngine`, `DecisionEngine`, any publishing class, or the onboarding controller.

---

## Deviations from the plan/spec (and why)

1. **Synthesized `MarketingPresenceSummary`, not a raw `Collection<MarketingChannel>`.** Both `docs/plans/Milestone-11-Marketing-Presence.md`'s Phase 5 section and `specs/core/marketing-presence.md` §8 specify an unfiltered `Collection<int, MarketingChannel>` property, reasoning that the Opportunity Engine needs to *see* inactive channels to deliberately exclude them. The live task instruction for this phase explicitly overrode that: *"Do NOT expose raw MarketingChannel rows directly to prompts. Instead synthesize..."* Treated as authoritative over the plan/spec's rough sketch, consistent with how Phase 2 and Phase 3 each treated a more specific live instruction as superseding an earlier illustrative design. `specs/core/marketing-presence.md` §8 has been updated to describe the synthesized shape actually implemented, with an explicit "superseded design note" explaining the change and preserving the original reasoning for why unfiltered access mattered (a future `channel_gap`-style detector can still read `inactiveChannels` — just as display names, not full rows with metadata/IDs).
2. **`marketingPresence` is nullable with a `null` default**, not a required 9th constructor argument. This is what let all ten pre-existing test files that construct `BusinessBrain` directly (Opportunity, Decision, and Campaign test suites) continue to compile and pass with zero changes — adding a required parameter would have forced edits across files this phase's boundaries explicitly say not to touch (Opportunity Engine, Decision Engine). `BusinessBrainService::assemble()` — the only production path — always populates it.
3. **No prompt template changed.** The plan's Phase 5 section suggests folding a marketing-presence summary into "wherever `BusinessBrain` fields are serialized into prompt context today," scoped loosely to "if purely internal to the prompt." The task's own instruction for this phase is more direct: "Do not modify prompt templates directly unless required." It isn't required — the summary is already reachable via `$brain->marketingPresence` through the exact same object every `Analyst` already receives. Editing `CampaignPreparationPrompt`/`RationaleGenerationPrompt` was judged out of scope: those files sit immediately adjacent to the Opportunity/Decision Engine content this phase must not touch, and the task's phrasing ("automatically inherit... through the existing BusinessBrain flow") is satisfied by the data being present on the object, not by every consumer already reading it.

---

## Quality gates

```
php artisan test           809 tests, 807 passing, 2 Redis-skipped, 0 failures
phpstan analyse (level 8)  0 errors
pint --test                clean
npm run build              succeeds (no frontend files touched this phase)
vitest run                 18 tests, all passing (unaffected by this phase)
```

---

## What Phase 5 does not include (confirmed)

- No `OpportunityEngine`/detector changes — none currently reads `$brain->marketingPresence`, and none was added
- No `DecisionEngine` changes
- No publishing changes
- No onboarding changes
- No prompt template text changes — `CampaignPreparationPrompt`, `RationaleGenerationPrompt`, and every `Content/*Prompt` class are untouched
- No queued job or new event beyond wiring the already-existing `MarketingPresenceUpdated`
- No raw `MarketingChannel` row ever reaches `BusinessBrain` or a prompt

---

## Next step

Phase 6+ (channel-selection/Decision Engine integration, Recommendation UI, remaining tests) are specified in the plan but **not started**. Per instruction, this session stops here.
