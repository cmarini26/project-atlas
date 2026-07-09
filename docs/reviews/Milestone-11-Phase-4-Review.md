# Milestone 11 Phase 4 — Marketing Presence Settings UI — Review

**Date:** 2026-07-09
**Scope:** Phase 4 only, per [Milestone-11-Marketing-Presence.md](../plans/Milestone-11-Marketing-Presence.md). No Business Brain, Opportunity Engine, publishing, channel health dashboard, or external integration changes.
**Tests:** 792 total (790 passing, 2 Redis skipped) + 18 Vitest tests (4 new) — 21 new PHP tests in `MarketingPresenceControllerTest`
**PHPStan:** Level 8 — 0 errors · **Pint:** Clean · **Frontend build:** succeeds · **Vitest:** all green

---

## What shipped

### Settings page — `/app/settings/marketing-presence`

A dedicated sub-page (`resources/js/Pages/App/Settings/MarketingPresence/Index.vue`), linked from a new "Marketing Presence" card on the main Settings page, rather than an inline section on `Settings.vue` itself — the plan explicitly leaves this as "implementer's call," and the CRUD surface (add form, per-row status/importance/objective editing, disable/reactivate) was judged large enough to justify its own page, matching the existing `Campaigns/Index.vue` + `Campaigns/Show.vue` sub-folder convention.

The page supports every action the task lists:

- **View** — all declared channels for the company, sorted by `importance` (primary → secondary → experimental) then `status` (active → occasional → planned → inactive).
- **Add** — a form with a channel-type select (all 12 `MarketingChannelType` values, sharing the same label set as the onboarding step — see "Shared code" below), a required display name, and an optional handle/URL.
- **Edit status** — a per-row select, saved immediately on change.
- **Edit importance** — a per-row select, saved immediately on change.
- **Edit objectives** — a per-row multi-select rendered as toggle chips (checkboxes), saved immediately on change.
- **Disable / reactivate** — a "Disable" button on any non-inactive row, a "Reactivate" button on an inactive one.
- **Capability badges** — every row shows a `MarketingChannelCapabilityBadge` reflecting the server-computed capability.

### Backend — thin delegation, no reimplemented domain logic

`App\Http\Controllers\App\MarketingPresenceController` (new) does exactly four things and nothing else:

- `index()` — fetches the company's channels, sorts them, calls `MarketingChannelCapabilityResolver::resolve()` per row, and serializes the result. The sort itself is presentation ordering, not domain logic; the actual capability value is never computed in the controller.
- `store()` — validates `type`/`display_name`/`handle_or_url` at the HTTP boundary, then calls `MarketingPresenceService::declare()` for everything else (suggested defaults, forced `company_id`, forced unlinked state).
- `update()` — validates `status`/`importance`/`objective` at the HTTP boundary, then calls `MarketingPresenceService::update()`, which strips `company_id`/`channel_id`/the capability booleans and revalidates the full resulting state itself (Phase 2 behavior, unchanged).
- `destroy()` — calls `MarketingPresenceService::disable()`. No row is ever deleted; `status` becomes `inactive`.

No validation rule, capability derivation, or default-suggestion logic is duplicated in the controller or the Vue page — every one of those concerns already lived in `MarketingPresenceService` / `MarketingChannelCapabilityResolver` from Phase 2, and this phase only calls them.

### Routes

Exactly the four routes the plan specifies, under the existing `['auth', 'company']`-protected `/app` group:

```
GET    /app/settings/marketing-presence
POST   /app/settings/marketing-presence
PATCH  /app/settings/marketing-presence/{marketingChannel}
DELETE /app/settings/marketing-presence/{marketingChannel}
```

### Capability display

`resources/js/lib/marketingChannelCapability.ts` + `resources/js/Components/UI/MarketingChannelCapabilityBadge.vue` — a new, presentation-only label/description map for the four values `MarketingChannelCapabilityResolver` returns (`declared`, `connected`, `publishing_enabled`, `analytics_enabled`), rendered through the existing `Badge.vue` component exactly like the pre-existing `ChannelCapabilityBadge.vue` does for the separate Channel Publishing Reality Audit vocabulary. This is a **new, sibling component**, not an extension of `ChannelCapabilityBadge.vue` — see "Deviations" below for why.

### Shared code (small refactor)

`resources/js/lib/marketingChannelTypes.ts` — the 12 `(type, label)` pairs used by the Settings "Add a channel" select were factored out of `Onboarding/Index.vue` (which previously inlined the same array) into a shared module, so both surfaces present identical channel names without duplicating the list. `Onboarding/Index.vue` was updated to import from it; its behavior is unchanged.

### Authorization

Every action scopes queries by the request's bound `company` (via the existing `EnsureCompanyMembership` middleware) and, for `update()`/`destroy()`, explicitly checks `abort_if($marketingChannel->company_id !== $company->id, 404)` — the same pattern `SettingsController::syncIntegration()` already uses for `Integration`. See "Deviations" for why this phase does not add a `CompanyMembershipPolicy`-style owner/admin gate.

---

## UX boundaries honored

- **No OAuth, no "Connect account" button, no credential fields anywhere in this UI.**
- **No real publisher setup, no analytics setup** — nothing in this screen enables `supports_publishing`/`supports_analytics`; those remain untouched by every action this phase adds (`declare()`/`update()`/`disable()` never set them, per Phase 2's design).
- **No technical `Channel` creation** — `store()` calls `declare()` only; a `MarketingChannel` can still be linked to a real `Channel` via `MarketingPresenceService::link()`, but no UI in this phase calls it (`link()` remains, as documented in Phase 2, reachable only by a future OAuth flow).
- **Honest copy** — the page's supporting text reads: *"Declaring a channel here means Atlas knows about it — not that Atlas can publish or read analytics there yet. Those capabilities light up automatically once a real connection exists."* The `Declared` and `Connected` badge descriptions both explicitly state Atlas can't publish or read analytics yet; only `Publishing enabled`/`Analytics enabled` claim otherwise.

---

## Tests

### `tests/Feature/App/MarketingPresenceControllerTest.php` (21 new)

| Area | Covers |
|---|---|
| `index` | Auth required; renders the correct Inertia component; lists declared channels with the resolved `capability` value (`declared` for an unlinked channel, `connected` for one linked to an active `Channel`); only shows the acting company's channels (tenant isolation) |
| `store` | Declares a new channel; accepts an optional `handle_or_url`; creates no `Channel` row; requires `display_name`; rejects an unknown `type`; allows a second channel of an already-declared type |
| `update` | Changes `status`/`importance`/`objective` independently; rejects an empty `objective` array; rejects an unknown `status`; ignores a caller-supplied `company_id`/`channel_id`; **denied (404) for a channel belonging to another company** |
| `destroy` / reactivate | Sets `status: inactive` without deleting the row; reactivating (PATCH `status: active` on an inactive row) works; **destroy denied (404) for a channel belonging to another company** |

### `resources/js/Components/UI/MarketingChannelCapabilityBadge.spec.ts` (4 new Vitest tests)

Renders the correct label for all four capability values, plus an explicit assertion that the `declared` badge's text never contains the word "publish" — a direct, automated check of the "keep copy honest" boundary.

No test in this phase touches `BusinessBrain`, `OpportunityEngine`, or any publishing class.

---

## Deviations from the plan (and why)

1. **A new `MarketingChannelCapabilityBadge.vue` component, not an extension of `ChannelCapabilityBadge.vue`.** The plan suggested extending the existing badge's label set. On inspection, `ChannelCapabilityBadge.vue` takes a raw `channelType` string prop and derives its own capability via `channelCapability(channelType)` (a lookup keyed by *type*, e.g. `email` → `draft_only`) — it has no way to accept an already-resolved capability value. Retrofitting it to accept either a type-derived or a directly-supplied capability would have mixed two different resolution strategies (client-side type lookup vs. server-computed `MarketingChannelCapabilityResolver` output) behind one prop, which risks exactly the "domain logic duplicated in Vue" the task warns against. A second, sibling component that only ever renders a value the server already resolved keeps the domain logic in exactly one place (the PHP resolver) while still reusing `Badge.vue` and the same visual language (variant colors, `title` tooltip pattern) as its predecessor.
2. **No `CompanyMembershipPolicy`-based owner/admin gate.** The plan's "Tests for this phase" note says role authorization should match "the existing `CompanyMembershipPolicy` pattern used elsewhere in Settings" — no such policy class exists anywhere in this codebase, and `SettingsController` (company profile, integration sync) applies no role check beyond company membership itself. Introducing a new authorization layer for this one screen, when no comparable Settings action is gated by role today, would be inconsistent scope creep beyond "implement only Phase 4." Every action here uses exactly the tenant-isolation check the rest of Settings already uses (`abort_if($resource->company_id !== $company->id, 404)`); this is verified in the two "denied for another company" tests.
3. **PHPStan level 8 fix: `@property` annotations added to `App\Models\MarketingChannel`.** Reading `$channel->importance->value` (needed for both the sort and the JSON serialization) is the first place in the codebase that reads one of `MarketingChannel`'s enum-cast attributes and immediately calls `->value` on it. Larastan does not infer the enum type from the model's `casts()` method by default (`parseModelCastsMethod` defaults to `false` in this Larastan version) — enabling that flag globally in `phpstan.neon` was tried and rejected, because it surfaced two unrelated pre-existing type errors in `App\AI\Prompts\CampaignPreparationPrompt`/`RationaleGenerationPrompt`, which are out of this phase's scope to fix. A narrowly-scoped `@property` PHPDoc block on `MarketingChannel` (matching the existing precedent on `Integration`/`ChannelCredentials`) fixes the inference for this model only, with zero blast radius elsewhere.

---

## Quality gates

```
php artisan test           792 tests, 790 passing, 2 Redis-skipped, 0 failures
phpstan analyse (level 8)  0 errors
pint --test                clean
npm run build              succeeds
vitest run                 18 tests, all passing (4 new)
```

---

## What Phase 4 does not include (confirmed)

- No `BusinessBrain`/`BusinessBrainService` changes
- No `DecisionEngine`/`OpportunityEngine` changes
- No publishing changes — `ChannelPublisherRegistry`, `PublishContent`, `LogChannelPublisher`, `EmailPublisher` untouched
- No channel health dashboard — this screen shows current declared state, not history, trends, or aggregate health
- No external integration, OAuth, or credential UI of any kind
- No `link()` caller — `MarketingChannel.channel_id` still can only be set by a future OAuth flow, exactly as Phase 2 left it

---

## Next step

Phase 5 (BusinessBrain Integration) is specified in the plan but **not started**. Per instruction, this session stops here.
