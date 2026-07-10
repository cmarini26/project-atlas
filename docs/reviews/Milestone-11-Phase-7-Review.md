# Milestone 11 Phase 7 — Campaign & Recommendation UI — Review

**Date:** 2026-07-10
**Scope:** Phase 7 only, per [Milestone-11-Marketing-Presence.md](../plans/Milestone-11-Marketing-Presence.md). No publishers, no OAuth, no analytics, no onboarding changes, no app redesign.
**Tests:** 840 total (838 passing, 2 Redis skipped) + 24 Vitest tests (10 new) — 15 new PHP tests
**PHPStan:** Level 8 — 0 errors · **Pint:** Clean · **Frontend build:** succeeds · **Vitest:** all green

---

## What shipped

### Channel mix — a coordinated marketing plan, not isolated content

`App\Services\Recommendation\ChannelMixPresenter` (new) assembles four buckets for a Recommendation's detail page, computed fresh at display time from currently-persisted data:

- **Primary** — executable `Channel` ids (from `Decision.channel_ids`) whose linked `MarketingChannel.importance` is `primary`.
- **Supporting** — every other executable channel: linked-but-`secondary`/`experimental`, or no `MarketingChannel` link at all (neutral).
- **Draft-only** — declared, non-inactive/non-planned `MarketingChannel` rows with **no** linked `Channel` — real business context, never executable, still worth targeting with messaging.
- **Unavailable** — declared `MarketingChannel` rows with `status: inactive` or `planned`, each with a `reason`, excluded from the "not included this time" list if that same channel happens to be executing right now (the rare case where Phase 6's `MarketingChannelSelector` empty-set bypass fired at commit time).

This directly satisfies the task's four-bucket ask and the "campaign as a coordinated plan" framing — `ChannelMixCard.vue` renders it as one compact card on `Recommendations/Show.vue`, positioned right after the header/rationale and before expected impact and content.

**Computed fresh, not replayed.** `MarketingChannelSelection` (Phase 6's `DecisionContext->channelSelection`) is never persisted and is discarded once `DecisionService::commit()` returns — there is no schema-free way to resurrect it later. `ChannelMixPresenter` instead recomputes the picture from what's true *right now*: `Decision.channel_ids` (which real channels this campaign actually executes on — untouched, always accurate) plus a fresh, company-scoped `MarketingChannel` query. This is arguably more correct than replaying stale Phase 6 reasoning: if a channel goes inactive in Settings after the Decision was committed, the Recommendation page should say so.

### Capability labels — the existing four-state vocabulary, extended not replaced

Per `specs/core/marketing-presence.md` §11's mapping table, `resources/js/lib/channelCapability.ts` gained two small, additive functions:

- `resolveChannelCapability(channelType, linkedMarketingChannel?)` — for a real, technical `Channel`: if a `MarketingChannel` links to it, its `supports_publishing` flag decides **Connected** vs. **Draft only**; absent a link, falls back to the existing global, type-only `channelCapability()` lookup exactly as before. Every existing call site that doesn't pass the new second argument behaves identically to before this phase.
- `resolveDeclaredChannelCapability(marketingChannelType)` — for a declared-but-unlinked `MarketingChannel`: **Not configured** if its type has a `Channel` type equivalent (mirrors `MarketingChannelType::hasChannelEquivalent()` — email/instagram/facebook/linkedin/x), else **Coming later**.

`ChannelCapabilityBadge.vue` gained an optional `linkedMarketingChannel` prop threading into the first function — a backward-compatible extension, not a new badge or a fifth label. `ApproveActions.vue`'s per-content-asset confirmation-dialog line (the plan's explicitly named "wherever a channel is displayed" surface) now calls the extended resolver instead of the bare global lookup.

**No new label was invented.** "Executable" (the task's own wording) maps onto the existing **Connected**/**Draft only** labels depending on `supports_publishing`; nothing in this UI ever claims Atlas can publish where `supports_publishing` isn't true.

### Rationale — expanded through existing, honest, deterministic copy

- **"Why these channels were chosen"** — already covered by the existing, AI-authored `why_channel` rationale quadrant (`RationaleCard.vue`, unchanged); no prompt work was needed or done.
- **"Why some declared channels were excluded"** — the new "Unavailable" bucket states the reason plainly and deterministically (`"no longer active"` / `"planned, not started yet"`) — derived directly from `MarketingChannel.status`, no AI call.
- **"Why draft-only channels are still valuable"** — a short, fixed caption above the draft-only list: *"Also part of your marketing presence — Atlas can't prepare content for these yet, but they're valuable context for the campaign's messaging."*

No new AI prompt, no `RationaleGenerationPrompt`/`CampaignPreparationPrompt` change — consistent with this phase's "no publishers/OAuth/analytics" boundary and the same restraint Phase 6 applied to prompt templates.

### Domain-separation guarantees preserved

- `ChannelMixPresenter`'s primary/supporting buckets are built exclusively from `Channel` rows resolved off `Decision.channel_ids` — a `MarketingChannel` is never treated as executable, structurally (the loop iterates `$executableChannels`, a `Collection<Channel>`, never `MarketingChannel`).
- A `MarketingChannel` type with no `Channel` type equivalent (Print, Events, YouTube, TikTok, Google Business Profile, Website, Other) can only ever appear in `draft_only`/`unavailable` — never `primary`/`supporting` — since it can never acquire a `channel_id` in the first place (Phase 1/2 invariant, unchanged).
- No `Channel` row is created anywhere in this phase.

---

## UX — kept lightweight

- One new card (`ChannelMixCard.vue`), styled identically to the page's existing cards (`bg-surface-elevated`, `border`, `rounded-xl`, same heading style as `RationaleCard`/`ImpactCard`) — no new visual language.
- The card renders nothing (`v-if="hasAnything"`) when there's nothing to show, so a company with no declared Marketing Presence sees an unchanged page — Marketing Presence makes the page smarter only when there's something to say.
- `ContentPreview.vue` was deliberately **not** touched — the new Channel Mix card is the single place this page explains channel capability; adding a second, per-asset capability badge there would have duplicated information already shown once, overwhelming the page against the task's own instruction.
- `Campaigns/Show.vue`, `Publishing.vue`, and `Dashboard.vue` were **not** touched, despite the plan document listing them as "the same surfaces touched by the Channel Publishing Reality Audit." The live task's explicit ask was scoped to "Recommendation UI"; extending the badge additively means those pages' existing `ChannelCapabilityBadge` usages are unaffected either way (no `linkedMarketingChannel` prop passed, so they keep behaving exactly as before) — see "Deviations" below.

---

## Tests (15 new PHP, 10 new Vitest)

| File | Covers |
|---|---|
| `tests/Feature/Recommendation/ChannelMixPresenterTest.php` (11 tests) | No-decision/no-channel-ids empty cases; primary vs. supporting bucketing by `importance`; unlinked executable channel treated as neutral (supporting); `supports_publishing` carried through from the link; declared-unlinked-active channel is draft-only; inactive/planned declared channels are unavailable with the correct reason; an inactive-linked channel currently executing (bypass edge case) is never listed as unavailable; tenant isolation |
| `tests/Feature/App/RecommendationControllerTest.php` (3 new) | `show()` includes `channel_mix` with a real linked channel in `supporting`; a declared-unlinked channel appears in `channel_mix.draft_only`; another company's `MarketingChannel` rows never leak into this company's `channel_mix` |
| `resources/js/Components/Recommendations/ChannelMixCard.spec.ts` (6 new) | Empty mix renders nothing; a primary entry renders "Draft only" (never "Can publish") when `supports_publishing` is false; renders "Connected" only when `supports_publishing` is true; falls back to the global type lookup for an unlinked executable channel; draft-only entries render "Not configured"/"Coming later" and never "Can publish"; unavailable entries show their reason with no capability badge |

**Regression:** all 11 pre-existing `RecommendationControllerTest` tests, all 4 `ApproveActions.spec.ts` tests (the exact "regression-style test matching the existing `ApproveActions.spec.ts` pattern" the plan calls for), and the full 826-test PHP baseline plus 18-test Vitest baseline from before this phase all pass **completely unmodified** — no approval-workflow test needed to change, confirming no regression to the approve/reject flow.

---

## Deviations from the plan (and why)

1. **Only `Recommendations/Show.vue` and `ApproveActions.vue` were touched**, not `Campaigns/Show.vue`, `Publishing.vue`, or `Dashboard.vue` as the plan document's Phase 7 section lists. The live task's explicit ask ("1. Recommendation UI... 4. Campaign visualization" — both framed around the Recommendation detail page) and its own boundary ("do not redesign the app") were treated as the operative, narrower scope, consistent with how every earlier phase in this milestone treated a more specific live instruction as superseding the plan's broader sketch. Because the capability-resolution extension is purely additive (an optional prop/parameter), the untouched pages' existing `ChannelCapabilityBadge` usages are unaffected — nothing there needs a companion change to keep working.
2. **Channel mix is recomputed fresh at display time, not persisted from Phase 6's `MarketingChannelSelection`.** No `Decision`/`Recommendation`/`Campaign` migration was added. `DecisionContext->channelSelection` remains exactly what Phase 6 left it: available during the same request/job that commits a Decision, then discarded. Reconstructing the equivalent picture at read-time from `Decision.channel_ids` (immutable, already correct) plus a fresh `MarketingChannel` query needs no schema change and stays honestly current if Settings change after the fact — judged preferable to a stale replay, and required no "absolutely necessary" schema change per the task's own instruction.
3. **`ContentPreview.vue` was left unchanged.** The plan's Phase 7 text lists it implicitly among "wherever a channel is displayed," but adding a second capability badge per content asset — on top of the new aggregate Channel Mix card — would repeat information already shown once on the same page, working against "do not overwhelm the page."

---

## Quality gates

```
php artisan test              840 tests, 838 passing, 2 Redis-skipped, 0 failures
phpstan analyse (level 8)     0 errors
pint --test                   clean
npm run build                 succeeds
vitest run                    24 tests, all passing (10 new)
```

---

## What Phase 7 does not include (confirmed)

- No publisher, no OAuth, no analytics ingestion
- No onboarding changes
- No app redesign — one new card, reusing existing components/styling throughout
- No `Decision`/`Recommendation`/`Campaign`/`CampaignBlueprint` schema change
- No claim, anywhere in code or copy, that Atlas can publish where `supports_publishing` isn't true

---

## Next step

Phase 8 (consolidated test checklist) is specified in the plan but **not started** as a distinct session — its individual items have been covered incrementally by each phase's own tests throughout Milestone 11. Per instruction, this session stops here.
