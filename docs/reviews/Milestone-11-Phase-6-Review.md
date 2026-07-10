# Milestone 11 Phase 6 — Opportunity and Channel Selection Integration — Review

**Date:** 2026-07-09
**Scope:** Phase 6 only, per [Milestone-11-Marketing-Presence.md](../plans/Milestone-11-Marketing-Presence.md). No Opportunity detection changes, no publishers, no OAuth, no Settings UI, no external publishing, no analytics ingestion, no onboarding changes.
**Tests:** 826 total (824 passing, 2 Redis skipped) — 17 new
**PHPStan:** Level 8 — 0 errors · **Pint:** Clean · **Frontend build:** succeeds (no frontend files touched this phase)

---

## What shipped

### `App\Services\Decision\MarketingChannelSelector` (new)

The single place a company's declared Marketing Presence influences which `Channel` ids become executable. `select(Company, Collection $affinityMatched, Collection $activeChannels, string $campaignType): MarketingChannelSelection`:

1. Loads the company's `MarketingChannel` rows once (`withoutGlobalScopes()->where('company_id', ...)`, the same explicit tenant-scoping pattern already used elsewhere in `DecisionEngine` for `Channel`/`Campaign`/`Recommendation`/`CatalogItem`), and keys the linked ones by `channel_id`.
2. Walks the existing type-affinity candidate set (`$affinityMatched`, produced exactly as before Phase 6) and **excludes** any candidate whose linked `MarketingChannel.status` is `inactive` or `planned` — recording *which* channel and *why* (two distinct reasons, since "stopped using it" and "haven't started yet" are different facts worth keeping distinguishable in logs and results).
3. **Bypasses** that exclusion entirely — reverting to the full `$activeChannels` set, exactly the pre-Phase-6 fallback — if excluding would leave zero candidates. A company whose only affinity-matched channels are inactive/planned-linked behaves exactly as it did before this selector existed; no new failure mode was introduced.
4. **Prefers** `importance: primary`-linked candidates over the rest, narrowing to just those when at least one exists (mirroring the existing "narrow to the best match, else keep the wider set" style already used for the type-affinity step itself).
5. Reports declared, non-inactive `MarketingChannel` rows with **no** `channel_id` link as `draftOnlyChannels` (display names only) — real business context, never executable, never mistaken for something `Decision.channel_ids` could contain.
6. Logs one structured `Log::info('DecisionEngine: marketing-presence channel selection.', [...])` entry per call — see "Logging" below.

### `App\Services\Decision\MarketingChannelSelection` (new)

A small `readonly` value object: `executableChannelIds` (`list<string>`), `draftOnlyChannels` (`list<string>`), `excludedChannels` (`list<array{name: string, reason: string}>`). This is the "channel mix output" the task asks for — three distinguishable categories, computed once, with no `Decision` or `CampaignBlueprint` schema change.

### `DecisionEngine` (modified)

- Injected `MarketingChannelSelector` alongside the existing `OpportunityRepository`/`DecisionService` dependencies.
- `resolveChannelIds()` (returned plucked ids) is now `resolveAffinityChannels()` (returns the `Channel` collection itself, unchanged type-affinity logic, verbatim) — a pure extraction, not a behavior change. `evaluate()` calls it, then hands the result straight to `MarketingChannelSelector::select()` alongside the full `$activeChannels` set.
- `DecisionContext::channelIds` is now populated from `$selection->executableChannelIds` rather than the affinity result directly — the only place a `MarketingChannel` gets a say in `Decision.channel_ids`.
- `DecisionContext` gained a new, `null`-defaulting property, `channelSelection: ?MarketingChannelSelection`, so the full three-way breakdown is available to `DecisionService::commit()` (and anything built on it later) without a `Decision`/Blueprint schema change — the exact "prefer existing metadata/context fields" instruction, applied to the one place in this pipeline where a transient (non-persisted) context object already exists for exactly this purpose.

### BusinessBrain usage — verified, not assumed

The task asked to confirm the Opportunity/Decision path *actually consumes* Marketing Presence data, not merely that a property exists on `BusinessBrain`. It does now: every `DecisionEngine::evaluate()` call that reaches channel selection queries the company's `MarketingChannel` rows (via the new selector) and lets them change which `Channel` ids are preferred, excluded, or reported. This is a **direct, minimal, explicit integration** — a fresh company-scoped query inside `MarketingChannelSelector`, not a read from `BusinessBrain->marketingPresence`. That summary is deliberately a synthesized, display-name-only value object (Phase 5) with no `channel_id` keying — it cannot drive a deterministic, id-level selection decision, and was never meant to. The synthesized summary remains available on `BusinessBrain` for prompt-facing context (unchanged this phase); this phase's selection logic needed structured, id-addressable data, which only a direct query can provide.

### Domain separation — preserved

- No `Channel` row is ever created by this phase. `MarketingChannelSelector` only reads `Channel`/`MarketingChannel` rows that already exist.
- A `MarketingChannel` is never treated as publishable: `executableChannelIds` is built exclusively from `Channel` collections (`$affinityMatched`/`$activeChannels`, both `Collection<Channel>`), never from `MarketingChannel` rows — this is structurally guaranteed by the types involved, not just a runtime check.

---

## Channel mix rules — implemented exactly as specified

| Rule | Implementation |
|---|---|
| Prefer active primary MarketingChannels | Primary-linked candidates narrow the eligible set when any exist, after status filtering |
| Then consider active secondary/experimental channels | Included in the eligible set whenever no primary-linked candidate exists (both `secondary` and `experimental` importance are treated the same — the task names three channel buckets, not four) |
| Exclude inactive channels unless documented reason | Inactive-linked channels are excluded and logged with reason `"linked marketing channel is inactive"`; the one documented exception is the empty-set bypass, itself logged (`bypassed_exclusion_to_avoid_empty_selection`) |
| Planned channels may be suggested as future opportunities, but must not enter executable channel_ids | Planned-linked channels are excluded from `executableChannelIds` with reason `"linked marketing channel is planned, not yet active"`, but remain visible in `excludedChannels` rather than being silently dropped — available for a future opportunity type to reference, without this phase creating one |
| A MarketingChannel without a linked Channel row must never enter executable channel_ids | Structurally impossible — `executableChannelIds` is built only from `Channel` rows |
| Declared-but-unlinked channels may still appear as recommended draft/prepared content targets | Reported in `draftOnlyChannels` (display names), excluding `status: inactive` ones |

---

## Logging

One structured `Log::info` call per `MarketingChannelSelector::select()` invocation, matching this codebase's existing `"<ClassName>: <description>."` + context-array convention exactly (`DecisionEngine`'s own pre-existing guard logs use the identical shape):

```php
Log::info('DecisionEngine: marketing-presence channel selection.', [
    'company_id' => ...,
    'campaign_type' => ...,
    'considered_channel_ids' => ...,      // channels considered
    'narrowed_to_primary' => ...,         // channels preferred (bool: did primary-importance win)
    'excluded' => ...,                    // channels excluded and why — list of {name, reason}
    'bypassed_exclusion_to_avoid_empty_selection' => ...,
    'executable_channel_ids' => ...,      // executable vs. draft-only split
    'draft_only_channels' => ...,
]);
```

---

## Fallback behavior

- **No `MarketingChannel` rows declared at all:** `$linkedByChannelId` is empty, so every affinity-matched candidate is treated as neutral (kept, not preferred, not excluded) — behavior is byte-for-byte identical to the pre-Phase-6 type-affinity-only selection. Verified by `test_no_marketing_presence_preserves_existing_behavior`.
- **`MarketingChannel` rows exist but none are linked:** same neutral treatment for `executableChannelIds` (nothing to exclude or prefer), while `draftOnlyChannels` still surfaces the declared channels as recommendable content targets — useful output without ever producing an invalid executable id.

---

## Tests (17 new)

| File | Covers |
|---|---|
| `tests/Feature/Decision/MarketingChannelSelectorTest.php` (12 tests) | Neutral treatment of an unlinked `Channel`; primary preferred over secondary; inactive excluded (with reason); planned excluded (with reason); exclusion bypassed when it would empty the set; declared-unlinked channel reported as draft-only; inactive-unlinked channel *not* reported as draft-only; a `MarketingChannel` never enters `executableChannelIds` even when its type has a `Channel` equivalent; tenant isolation (a foreign company's linked `MarketingChannel` never influences this company's selection) |
| `tests/Feature/Decision/DecisionEngineTest.php` (5 new, end-to-end through `evaluate()`) | Primary-linked channel preferred over secondary when a `Decision` is actually committed; inactive-linked channel excluded from the committed `Decision.channel_ids`; planned-linked channel never becomes executable; declared-unlinked channel never enters `channel_ids`; a foreign company's `Channel`/`MarketingChannel` can never be selected; the no-Marketing-Presence case preserves the exact pre-Phase-6 result |

All 20 pre-existing `DecisionEngineTest`/`DecisionPipelineTest` tests continue to pass **completely unmodified** — no guard-condition test, no `makeBrain()`/`makeChannel()` helper signature (`makeChannel()` gained an optional `$overrides` parameter with a default, so every existing zero-argument call site is untouched), confirming no regression to existing Opportunity/Decision behavior.

No test in this phase touches `OpportunityEngine`, any `OpportunityDetector`, publishing, Settings UI, or onboarding.

---

## Deviations from the plan (and why)

1. **`MarketingChannel` rows are queried directly inside `MarketingChannelSelector`, not read off `BusinessBrain`.** The plan's Phase 6 text says to "build the map of `channel_id → MarketingChannel`... from the `BusinessBrain` passed into `evaluate()`, not a fresh query" — but `BusinessBrain->marketingPresence` (Phase 5) is a synthesized, display-name-only `MarketingPresenceSummary`, deliberately not a `channel_id`-keyed collection of raw rows. That design was itself a deliberate override of an earlier draft of this same plan/spec (documented in Phase 5's review and `specs/core/marketing-presence.md` §8's "superseded design note"). A fresh, company-scoped `MarketingChannel::withoutGlobalScopes()->where('company_id', ...)` query — the identical pattern already used for `Channel`/`Campaign`/`Recommendation`/`CatalogItem` in this same class — is the correct, necessary way to get id-addressable data for a deterministic selection decision; reading it off the brain was never actually possible once Phase 5 shipped as designed.
2. **Planned-status exclusion was added** beyond the plan document's Phase 6 text (which only mentions excluding `inactive`-linked channels). The live task instruction for this phase explicitly adds it: *"Planned channels may be suggested as future opportunities, but must not enter executable channel_ids."* Treated as authoritative over the plan's narrower text, consistent with how every earlier phase in this milestone treated a more specific live instruction as superseding an earlier plan sketch.
3. **`channelSelection` was added to `DecisionContext`, not to `Decision` or `CampaignBlueprint`.** The task explicitly discourages a Blueprint schema change and asks to "prefer existing metadata/context fields." `Decision` has no generic metadata column (`channel_ids`/`rationale`/`expected_impact` are all schema-locked or AI-owned — mixing engine-computed data into an AI-validated `rationale`/`expected_impact` blob would conflate two different authors of the same field). `DecisionContext` is exactly the kind of "existing context field" the instruction points at: a transient value object that already flows from `DecisionEngine` into `DecisionService::commit()` for exactly this purpose, with no persistence implications and no schema risk.

---

## Quality gates

```
php artisan test                    826 tests, 824 passing, 2 Redis-skipped, 0 failures
phpstan analyse (level 8)           0 errors
pint --test                         clean
npm run build                       succeeds (no frontend files touched this phase)
```

`npm run test` (Vitest) was not run as a gate — no frontend file was touched in this phase.

---

## What Phase 6 does not include (confirmed)

- No `OpportunityDetector` or `OpportunityEngine::scan()` changes — detection remains entirely channel-unaware, exactly as before
- No new publisher, no OAuth, no external publishing, no analytics ingestion
- No Settings UI changes
- No onboarding changes
- No `Decision`/`CampaignBlueprint` schema change or migration
- No new event — channel selection happens inside the existing `OpportunityDetected` → `TriggerDecisionEvaluation` → `CommitDecision` → `DecisionEngine::evaluate()` chain

---

## Next step

Phase 7+ (Recommendation UI, remaining tests) is specified in the plan but **not started**. Per instruction, this session stops here.
