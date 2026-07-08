# Milestone 11 — Marketing Presence
## Implementation Plan

**Status:** Plan — not yet implemented
**Author:** Claude Fable 5
**Date:** 2026-07-08
**Prerequisite:** Milestone 10 (Customer Dashboard) complete. P0 Product Polish and P1 Customer Trust & Navigation slices shipped. [Channel Publishing Reality Audit](../reviews/Channel-Publishing-Reality-Audit.md) complete.
**Specification:** `specs/core/marketing-presence.md` — authoritative domain spec for this milestone. Read it first; this document sequences its implementation and does not restate its reasoning.

---

## 1. What We Are Building

Marketing Presence — a new first-class Atlas domain concept that lets Atlas understand where a company markets today, independent of whether Atlas can technically publish to or measure that channel.

Concretely, this milestone adds:

- A `MarketingChannel` domain entity, always company-scoped, declared with zero API/OAuth requirement
- A service layer for creating, updating, and reasoning about declared channels
- An onboarding step: "Where do you market today?"
- A Settings section for managing declared channels after onboarding
- `BusinessBrain.marketingPresence`, so every AI prompt can reference the business's full marketing footprint
- Channel-selection preference in the Decision Engine that favors declared `primary`/`active` channels and never silently substitutes an `inactive` one
- Recommendation/Campaign UI updates that clearly distinguish what can actually be published from what is prepared-only or purely informational

At the end of this milestone, a new company can tell Atlas "we post on Instagram weekly, run a monthly print mailer, and occasionally exhibit at conventions" during onboarding — with no credentials, no OAuth, no technical setup — and that context immediately improves campaign strategy and audience description, even though Atlas still cannot publish to Instagram or place a print ad.

---

## 2. What We Are NOT Building

This plan is strictly bounded, restating the boundaries given for this work:

- **No Instagram publishing.** No Graph API integration, no posting capability.
- **No Facebook publishing.** Same as above.
- **No social OAuth of any kind.** Declaring a channel never requires connecting an account.
- **No analytics ingestion for any new channel type.** `supports_analytics` exists as a field; nothing sets it to `true` in this milestone.
- **No channel health dashboard.** `CheckChannelHealth` (existing, publishing-engine.md) is untouched. No new health-check UI is built for Marketing Presence.
- **No change to existing publishing orchestration** — `ChannelPublisherRegistry`, `PublishContent`, `ExecutionService`, `LogChannelPublisher`, `EmailPublisher` are not modified, except where capability *labeling* needs to read the new `MarketingChannel` data (Phase 7). No new `ChannelPublisher` implementation is added.
- **No claim of external publishing where it does not exist.** Every new UI surface must be consistent with the [Channel Publishing Reality Audit](../reviews/Channel-Publishing-Reality-Audit.md) — "Draft only," "Coming later," and "Not configured" remain honest, and "Connected" is never shown unless `supports_publishing` is genuinely `true` (which, per this milestone, it never is).
- **No new Opportunity type.** `channel_gap`-style detection is future work (spec §14), not this milestone.
- **No Blueprint schema version bump.** `CampaignBlueprint`'s structure is unchanged (spec §10).
- **No `Integration` ↔ `MarketingChannel` correlation.** The two remain unlinked (spec §7).
- **No code is written as part of the task that produced this plan.** This document and the spec are the complete deliverable of that task. Implementation begins in a future session against this plan.

---

## 3. Dependencies

- `specs/core/marketing-presence.md` — domain model, entity fields, enums, lifecycle, all integration points
- `specs/core/domain-model.md` — `BusinessBrain`, `Channel`, tenancy conventions (`HasUlids`, `BelongsToCompany`, ULID PKs)
- `specs/core/opportunity-engine.md` — `OpportunityDetector` contract (unchanged), `DecisionEngine` guard structure
- `specs/core/campaign-blueprint.md` — `channel_strategy` schema (unchanged), `CampaignPreparationAnalyst` context inputs
- Existing code: `App\Models\Channel`, `App\Services\Brain\BusinessBrainService`, `App\Services\Decision\DecisionEngine`, `App\Http\Controllers\OnboardingController`, `App\Http\Controllers\App\SettingsController`, `resources/js/lib/channelCapability.ts`, `Components/UI/ChannelCapabilityBadge.vue`

---

## 4. Implementation Sequence

### Phase 1 — Domain Model

**Migration**

`database/migrations/<timestamp>_create_marketing_channels_table.php`

```php
Schema::create('marketing_channels', function (Blueprint $table): void {
    $table->char('id', 26)->primary();
    $table->char('company_id', 26)->index();
    $table->char('channel_id', 26)->nullable()->index();
    $table->enum('type', [
        'website', 'email', 'instagram', 'facebook', 'linkedin', 'x',
        'youtube', 'tiktok', 'google_business_profile', 'events', 'print', 'other',
    ]);
    $table->string('display_name');
    $table->string('handle_or_url')->nullable();
    $table->enum('status', ['active', 'occasional', 'planned', 'inactive'])->default('active');
    $table->enum('importance', ['primary', 'secondary', 'experimental'])->default('secondary');
    $table->json('objective'); // array, min 1 item — validated in the service layer, not a DB constraint
    $table->text('audience')->nullable();
    $table->enum('posting_frequency', ['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'rarely', 'unknown'])
        ->default('unknown');
    $table->text('notes')->nullable();
    $table->boolean('is_connected')->default(false);
    $table->boolean('supports_publishing')->default(false);
    $table->boolean('supports_analytics')->default(false);
    $table->json('metadata')->nullable();
    $table->timestamps();

    $table->index(['company_id', 'status']);
    $table->index(['company_id', 'importance']);
    $table->index(['company_id', 'type']);
    $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
    $table->foreign('channel_id')->references('id')->on('channels')->nullOnDelete();
});
```

No unique constraint on `(company_id, type)` — see spec §2.

**Model**

`app/Models/MarketingChannel.php` — `use HasUlids, BelongsToCompany;` (spec §2, "Why Not Extend `Channel` Instead?"). Cast `objective` and `metadata` to `array`. `belongsTo(Channel::class)` (nullable). `belongsTo(Company::class)` (via the trait).

Add a scope mirroring existing conventions (`Opportunity::open()`-style):

```php
public function scopeActive(Builder $query): Builder
{
    return $query->where('status', 'active');
}

public function scopePrimary(Builder $query): Builder
{
    return $query->where('importance', 'primary');
}

public function scopeConnected(Builder $query): Builder
{
    return $query->whereNotNull('channel_id')->where('is_connected', true);
}
```

**Factory**

`database/factories/MarketingChannelFactory.php`. Note: most domain models in this codebase (Opportunity, Fact, Knowledge, Channel) do **not** have factories — tests create rows directly via `Model::withoutGlobalScopes()->create([...])`, following this repo's established pattern (only `Company` and `User` have factories today). Add the factory anyway since it was explicitly requested, but expect most Feature tests to still construct `MarketingChannel` rows directly for clarity, matching the rest of the test suite's style.

**Validation rules**

Enforced in `MarketingPresenceService` (Phase 2), not at the Eloquent model or migration level — consistent with how `CampaignPreparationService` validates the Blueprint (`specs/core/campaign-blueprint.md` §7) rather than relying on DB constraints:

- `type` — one of the 12 enum values
- `display_name` — required, non-empty string
- `status` — one of the 4 enum values (default `active`)
- `importance` — one of the 3 enum values (default `secondary`)
- `objective` — array, 1+ items, each a valid objective enum value
- `posting_frequency` — one of the enum values or `null`/`unknown`
- `handle_or_url` — soft duplicate check: warn (do not hard-block) if an identical `(company_id, type, handle_or_url)` already exists

**Tests for this phase:** migration runs; model relationships resolve; global scope prevents cross-company reads (mirrors the existing `Opportunity`/`Fact` tenant-isolation test pattern).

---

### Phase 2 — Service Layer

`app/Services/MarketingPresence/MarketingPresenceService.php`

```php
class MarketingPresenceService
{
    public function declare(Company $company, array $attributes): MarketingChannel;
    public function update(MarketingChannel $channel, array $attributes): MarketingChannel;
    public function setStatus(MarketingChannel $channel, string $status): MarketingChannel;
    public function link(MarketingChannel $channel, Channel $realChannel): MarketingChannel; // sets channel_id, is_connected
    public function suggestedDefaults(string $type): array; // seeds importance/objective/posting_frequency
}
```

- `declare()` and `update()` run the Phase 1 validation rules, persist the row, and fire `MarketingPresenceUpdated($company->id)` (spec §8) — the single coarse event that invalidates the `BusinessBrainService` cache, mirroring the existing `FactExtracted`/`KnowledgeSynthesized` listener pattern in `AppServiceProvider::boot()`.
- `link()` is the Phase-6/12-relevant method that sets `channel_id`/`is_connected` — implemented now, but only ever called manually or by a future OAuth flow. No caller exists in this milestone; write it and test it in isolation so Phase 6/12 has zero new plumbing to add later.
- `suggestedDefaults(string $type)` returns sensible onboarding defaults per type (e.g. `instagram` → `importance: 'secondary'`, `objective: ['awareness', 'community']`; `email` → `objective: ['retention', 'sales']`; `events` → `objective: ['awareness', 'trust']`, `posting_frequency: 'rarely'`). Used by Phase 3's onboarding step so the multi-select isn't followed by twelve empty forms — sensible defaults are pre-filled and editable.

**Capability mapping**

`app/Services/MarketingPresence/MarketingChannelCapabilityResolver.php` (PHP-side mirror of `resources/js/lib/channelCapability.ts`'s Section 11 table, so the same logic isn't duplicated ad hoc in controllers):

```php
class MarketingChannelCapabilityResolver
{
    public function resolve(MarketingChannel $channel): string
    {
        return match (true) {
            $channel->channel_id !== null && $channel->supports_publishing => 'connected',
            $channel->channel_id !== null => 'draft_only',
            $this->hasChannelEquivalent($channel->type) => 'not_configured',
            default => 'coming_later',
        };
    }
}
```

`hasChannelEquivalent()` checks against the intersection listed in spec §3/§6 (`email`, `instagram`, `facebook`, `linkedin`, `x`).

**Tests for this phase:** `declare()` persists correctly and fires the event; `update()` re-validates; `link()` sets exactly the expected fields and nothing else; `MarketingChannelCapabilityResolver` returns the correct label for every combination in spec §11's table.

---

### Phase 3 — Onboarding

**New step: "Where do you market today?"**

Inserted into the existing onboarding wizard (`resources/js/Pages/Onboarding/Index.vue`, `App\Http\Controllers\OnboardingController`) as a new step between the existing company-profile and website-URL steps, or immediately after website connection — exact placement is a UX call for the implementing session, but it must not block or gate the existing "connect website → status page" flow (spec's onboarding is additive, not a new blocker).

- Multi-select checklist: Website, Email, Instagram, Facebook, LinkedIn, X, YouTube, TikTok, Google Business Profile, Events, Print, Other
- Each selection may optionally capture `handle_or_url` inline (skippable)
- "Other" requires a free-text `display_name`
- **No API connection prompt anywhere in this step** — this is the hard requirement from the spec and the task boundaries
- Submitting creates one `MarketingChannel` per selection via `MarketingPresenceService::declare()`, using `suggestedDefaults()` for `importance`/`objective`/`posting_frequency`, with `status: 'active'` (the step asks about *today*, not *someday*) — a user who wants to mark something as `planned`/`inactive` does so afterward, in Settings (Phase 4)
- Route: `POST /onboarding/marketing-presence`, following the existing `onboarding.company` / `onboarding.integration` naming convention → name it `onboarding.marketing-presence`
- Skippable: a company with zero declared channels is valid (mirrors "no CTA, no apology" empty-state philosophy in `docs/design/System.md` §17) — Atlas still works with zero declared Marketing Presence; it simply has less business context

**Explicit non-interaction:** this step does **not** touch the existing auto-seeded `blog` `Channel` (onboarding's current `OnboardingController::createIntegration()` channel-seeding logic, unrelated). If the user selects "Website" here, it creates a `MarketingChannel(type: 'website')` row only — no link to the `blog` `Channel`, no link to the `website_crawl` `Integration` (spec §7).

**Tests for this phase:** submitting the step creates the expected `MarketingChannel` rows with `is_connected = false` across the board; skipping the step is allowed and does not block reaching `/onboarding/status`; "Other" requires `display_name`; no credentials/OAuth prompt is ever rendered.

---

### Phase 4 — Settings UI

**New section on `resources/js/Pages/App/Settings.vue`** (or a dedicated sub-page if the section grows large — implementer's call): "Marketing Presence."

- Lists all declared `MarketingChannel` rows for the company, grouped or sorted by `importance` then `status`
- Add / edit / remove (soft-remove via `status: 'inactive'`, not a hard delete — spec §2, no soft-delete column needed since `inactive` *is* the delete state) a declared channel
- Each row shows a capability badge: **Declared**, **Connected**, **Draft only**, **Publishing enabled**, **Analytics enabled** — computed from `MarketingChannelCapabilityResolver` (Phase 2) plus the existing `ChannelCapabilityBadge.vue` styling conventions (reuse the badge component's visual language; extend its label set if it currently only knows the four Reality-Audit labels — "Declared" and "Publishing/Analytics enabled" are new label text for the same badge family, not a new visual system)
- Controller: `App\Http\Controllers\App\MarketingPresenceController` (new; thin, delegates to `MarketingPresenceService`), routes under the existing `['auth', 'company']`-protected `/app` group: `GET /app/settings/marketing-presence`, `POST /app/settings/marketing-presence`, `PATCH /app/settings/marketing-presence/{marketingChannel}`, `DELETE /app/settings/marketing-presence/{marketingChannel}` (delete = set `status: inactive`, not a row delete — route name kept RESTful for convention, behavior documented in the controller)
- No credential fields, no "Connect account" button anywhere in this UI in this milestone — that remains explicitly out of scope (a future OAuth milestone would add it as a distinct, clearly-labeled action, not folded into this CRUD screen)

**Tests for this phase:** CRUD operations scoped correctly to the acting company; role authorization matches the existing `CompanyMembershipPolicy` pattern used elsewhere in Settings (owner/admin can manage, consistent with how Settings' other sections are gated); setting `status: 'inactive'` never deletes the row.

---

### Phase 5 — BusinessBrain Integration

- Add `public Collection $marketingPresence` to `App\Domain\BusinessBrain\BusinessBrain` (readonly VO — spec §8)
- `BusinessBrainService::assemble()` loads **all** `MarketingChannel` rows for the company (unfiltered by status — spec §8 rationale) and passes them in
- Register `MarketingPresenceUpdated` event (`app/Events/MarketingPresenceUpdated.php`, carries `companyId`) and its `AppServiceProvider::boot()` listener, exactly mirroring:

```php
Event::listen(MarketingPresenceUpdated::class, function (MarketingPresenceUpdated $event): void {
    BusinessBrainService::invalidate($event->companyId);
});
```

- Ensure `CampaignPreparationAnalyst`'s prompt-building step (wherever `BusinessBrain` fields are serialized into prompt context today) includes a `marketingPresence` summary — but scoped to *this phase only adding the data path*; prompt copy changes for `CampaignPreparationPrompt` are Phase 7 territory if UI-facing, or can be folded in here if purely internal to the prompt. Keep the prompt schema itself unchanged (no new required Blueprint field, per spec §10).

**Tests for this phase:** `BusinessBrainService::for()` returns a `BusinessBrain` whose `marketingPresence` matches the company's full `MarketingChannel` set, including `inactive` rows; a `MarketingChannel` create/update/status-change invalidates the brain cache (mirrors the existing `BusinessBrainCacheTest` pattern for `FactExtracted`); no cross-company leakage into `marketingPresence` (tenant isolation, including through the per-process memo).

---

### Phase 6 — Opportunity Engine Integration

**No changes to `OpportunityDetector` implementations or `OpportunityEngine::scan()`.** Detection is unaffected (spec §9).

**`DecisionEngine::resolveChannelIds()` gains a Marketing-Presence-aware filter/preference step**, applied after the existing type-affinity selection:

1. Build the map of `channel_id → MarketingChannel` for the company (from the `BusinessBrain` passed into `evaluate()`, not a fresh query — detectors and the engine already receive `$brain`).
2. **Exclude** any candidate `Channel` whose linked `MarketingChannel.status = 'inactive'` from the affinity-matched set, unless no channels remain — in which case fall through to the existing "all active channels" fallback exactly as today (do not introduce a new failure mode; a company with only inactive-linked channels behaves exactly as it does today, pre-Marketing-Presence).
3. **Prefer** candidates whose linked `MarketingChannel.importance = 'primary'` — when the affinity-matched (and now inactive-filtered) set contains both `primary` and non-`primary` channels, return only the `primary` ones (matching today's "narrow to the best match, else fall back to the wider set" style already used for the type-affinity match itself).
4. A `Channel` with no linked `MarketingChannel` at all (possible during migration — not every existing `Channel` row will have a `MarketingChannel` counterpart on day one) is treated as neutral: not excluded, not preferred. It behaves exactly as it does today.

This is an additive refinement to an existing private method — no signature change to `DecisionEngine::evaluate()`, no change to the `DecisionContext` value object, no change to guard conditions 1–4 (spec's opportunity-engine.md §11 guards are untouched; this only affects step "channel selection" inside the guard-passing branch).

**Tests for this phase:** existing `DecisionEngineTest` guard-condition tests continue to pass unmodified; new tests cover: an `inactive`-linked channel is excluded from `channel_ids` when an alternative exists; a `primary`-linked channel is preferred over a `secondary`-linked one for the same `campaign_type`; a `Channel` with no `MarketingChannel` link behaves identically to today's baseline; a `MarketingChannel` with no `channel_id` (declared-only) never appears in `channel_ids` regardless of `status`/`importance` (spec §9's core executability rule).

---

### Phase 7 — Campaign/Recommendation UI

- Wherever a channel is displayed in the Recommendation/Campaign UI (`ApproveActions.vue`, `Recommendations/Show.vue`, `Campaigns/Show.vue`, `Publishing.vue`, `Dashboard.vue` — the same surfaces touched by the Channel Publishing Reality Audit), resolve the shown capability from the linked `MarketingChannel` when one exists, falling back to the existing type-only `channelCapability.ts` lookup when it does not (spec §11's refinement — the global lookup remains the correct fallback, not a contradiction)
- Show a **recommended channel mix** summary on the Recommendation detail page: which of the business's declared channels this campaign touches, each clearly labeled **Can publish** (maps to "Connected"), **Draft only**, **Coming later**, or **Not configured** — using the exact same four-state vocabulary already established by the Reality Audit's `ChannelCapabilityBadge.vue`, not a fifth new label set
- A `MarketingChannel` that influenced the campaign's `audience`/`supporting_points` but has no `channel_strategy` entry (spec §9 — types with no `channels.type` equivalent, e.g. Print, Events) may be mentioned in the rationale/summary text but must never appear alongside an implication that content was or will be prepared for it specifically

**Tests for this phase:** a Recommendation touching a `channel_id`-linked, `supports_publishing = false` `MarketingChannel` renders "Draft only," never "Can publish" (regression-style test matching the existing `ApproveActions.spec.ts` pattern from the Reality Audit); a channel with no `channels.type` equivalent never appears as a `channel_strategy` destination in the UI even if referenced in prose.

---

### Phase 8 — Tests

Consolidated list (individual phases above already enumerate their own tests; this section is the full-suite checklist for "done"):

**Unit tests**
- `MarketingChannel` model: casts, relationships, scopes
- `MarketingPresenceService`: `declare()`, `update()`, `setStatus()`, `link()`, `suggestedDefaults()`, validation failure paths
- `MarketingChannelCapabilityResolver`: all four capability outcomes from spec §11's table

**Feature tests**
- Onboarding: declaring channels creates correct rows; skipping is allowed; no credential prompt ever renders
- Settings: CRUD scoped to company; role authorization; `inactive` is not a delete
- `BusinessBrainService`: `marketingPresence` populated correctly, unfiltered by status
- `DecisionEngine`: channel-selection preference/exclusion behavior (Phase 6 list above)
- Recommendation/Campaign UI: capability labels render correctly per linked `MarketingChannel` state (Vitest, following the `ApproveActions.spec.ts` pattern)

**Tenant isolation tests**
- A `MarketingChannel` for Company A is invisible to Company B via direct query, via `BusinessBrainService::for()`, and under the per-process `BusinessBrainService::$memo` cache (mirrors existing `Opportunity`/`Fact` isolation tests and the existing `BusinessBrainCacheTest` cross-company test)

**Onboarding tests**
- Full onboarding flow (company → website → marketing presence → status) completes with declared channels present; a company that skips the step still reaches `/onboarding/status` and gets a recommendation through the existing pipeline unaffected

**BusinessBrain tests**
- Cache invalidation on `MarketingPresenceUpdated` (create/update/status-change)
- A prompt-building `Analyst` reading `$brain->marketingPresence` gets the full, current set with no additional query

**Opportunity recommendation tests**
- End-to-end: a company with a `primary` Instagram `MarketingChannel` linked to a real `Channel`, and a `secondary` Facebook one, produces a `Decision.channel_ids` that prefers Instagram for an affinity-matching `campaign_type`
- End-to-end: a company with an `inactive`-linked email channel and an `active`-linked blog channel never selects the inactive one

No test in this milestone may assert that content was actually published externally, or that analytics were actually retrieved, for any channel — per the boundaries in Section 2.

---

## 5. File Structure After Milestone 11

```
backend/
├── app/
│   ├── Models/
│   │   └── MarketingChannel.php                              [new]
│   ├── Services/
│   │   └── MarketingPresence/
│   │       ├── MarketingPresenceService.php                  [new]
│   │       └── MarketingChannelCapabilityResolver.php        [new]
│   ├── Events/
│   │   └── MarketingPresenceUpdated.php                      [new]
│   ├── Http/Controllers/App/
│   │   └── MarketingPresenceController.php                   [new]
│   ├── Http/Controllers/
│   │   └── OnboardingController.php                          [modified — new step]
│   ├── Domain/BusinessBrain/
│   │   └── BusinessBrain.php                                 [modified — new property]
│   ├── Services/Brain/
│   │   └── BusinessBrainService.php                          [modified — assemble() loads marketingPresence]
│   ├── Services/Decision/
│   │   └── DecisionEngine.php                                [modified — resolveChannelIds() preference/exclusion]
│   └── Providers/
│       └── AppServiceProvider.php                            [modified — new event listener]
├── database/
│   ├── migrations/
│   │   └── <timestamp>_create_marketing_channels_table.php   [new]
│   └── factories/
│       └── MarketingChannelFactory.php                       [new]
├── resources/js/
│   ├── Pages/Onboarding/
│   │   └── Index.vue                                         [modified — new step]
│   ├── Pages/App/
│   │   └── Settings.vue                                      [modified — new section, or split to a sub-page]
│   ├── Components/UI/
│   │   └── ChannelCapabilityBadge.vue                        [modified — extended label set]
│   └── lib/
│       └── channelCapability.ts                              [modified — MarketingChannel-aware resolution, spec §11]
└── tests/
    ├── Unit/MarketingPresence/                                [new]
    └── Feature/MarketingPresence/                             [new]
```

---

## 6. Acceptance Criteria

This milestone is done when every checkbox in `specs/core/marketing-presence.md` §13 passes as an automated test, and additionally:

- [ ] `php artisan test` passes with the new suite included
- [ ] `phpstan analyse` (level 8) reports 0 errors on all new/modified files
- [ ] `pint --test` passes
- [ ] `npm run build` succeeds
- [ ] Vitest suite (if new frontend logic warrants it, per the Reality Audit's precedent) passes
- [ ] No existing test in the suite is modified to *loosen* an assertion in order to make Marketing Presence changes pass — only additive test changes are acceptable (e.g., adding a `marketingPresence` fixture parameter is fine; removing a channel-selection assertion is not)

---

## 7. Open Questions for the Implementing Session

These are judgment calls intentionally left open by this plan, to be resolved with the user or by reasonable default at implementation time — not blockers to approving this plan:

1. **Exact onboarding step placement** (before vs. after website connection) — either is consistent with the spec; UX flow testing should decide.
2. **Settings UI: inline section vs. dedicated sub-page** — depends on how large the Marketing Presence section turns out to be once designed; both are consistent with `docs/design/System.md`'s page-header conventions.
3. **Whether `suggestedDefaults()` per type needs product/design input** (e.g., is `events` really `objective: ['awareness', 'trust']`, or should it be configurable) — reasonable defaults are specified in Phase 2; refining them is a content/product decision, not an architecture one.
4. **Whether the four Settings-UI capability labels ("Declared," "Connected," "Draft only," "Publishing enabled," "Analytics enabled" — five, not four, per the task's Phase 4 list) fully replace or extend the existing Reality Audit four-label badge** — spec §11 treats them as compatible extensions of the same badge family; final visual design is a Phase 4 implementation detail.

---

## 8. What This Milestone Does Not Prove

Restated once more, plainly, because it is the point of the whole exercise: at the end of Milestone 11, Atlas still cannot publish to Instagram, Facebook, LinkedIn, X, YouTube, TikTok, Google Business Profile, print, or events, and still cannot pull real analytics from any channel beyond what already exists (nothing, per the Channel Publishing Reality Audit). What changes is that Atlas now **knows** about all of these — accurately, honestly labeled, and ready to be upgraded one boolean at a time the moment a real integration exists (spec §12) — instead of only knowing about the one or two channel types it happens to have simulated publishing code for.
