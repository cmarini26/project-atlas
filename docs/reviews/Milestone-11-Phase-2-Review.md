# Milestone 11 Phase 2 — Marketing Presence Service Layer — Review

**Date:** 2026-07-09
**Scope:** Phase 2 only, per [Milestone-11-Marketing-Presence.md](../plans/Milestone-11-Marketing-Presence.md). No onboarding, no Business Brain integration, no Opportunity Engine changes, no publishing changes, no UI.
**Tests:** 760 total (758 passing, 2 Redis skipped) — 45 new
**PHPStan:** Level 8 — 0 errors · **Pint:** Clean · **Frontend build:** succeeds (no frontend files touched this phase)

---

## What shipped

### `App\Services\MarketingPresence\MarketingPresenceService`

The single entry point for creating and mutating a company's declared marketing channels — nothing outside this class writes to `marketing_channels`.

- `declare(Company $company, array $attributes): MarketingChannel` — creates a new declaration. `company_id` is always taken from the `$company` argument, never from `$attributes` (a caller-supplied `company_id` is silently discarded). Fills in `suggestedDefaults()` for `importance`/`objective`/`posting_frequency` when the caller omits them, so a minimal `declare($company, ['type' => 'instagram', 'display_name' => 'Acme Instagram'])` call succeeds without the caller having to know sensible per-type defaults. `channel_id`/`is_connected`/`supports_publishing`/`supports_analytics` are always forced to `null`/`false` regardless of what's passed — `declare()` only ever produces an unlinked declaration; `link()` is the only path to a connected one. Validates the fully-merged attribute set against `MarketingChannel::rules()` before persisting, then fires `MarketingPresenceUpdated`.
- `update(MarketingChannel $channel, array $attributes): MarketingChannel` — partial update restricted to the nine business-context fields (`type`, `display_name`, `handle_or_url`, `status`, `importance`, `objective`, `audience`, `posting_frequency`, `notes`). `company_id`, `channel_id`, and the three capability booleans are stripped from `$attributes` before anything else happens, so a generic `update()` call can never re-parent a channel or fake a connection. Re-validates the *prospective full state* (current row overlaid with the caller's changes) so a partial update that would leave the record invalid (e.g. clearing `objective` to `[]`) is rejected, not silently accepted.
- `setStatus()` / `disable()` / `reactivate()` — status transitions. `disable()`/`reactivate()` are thin wrappers over `setStatus()` for the two states the plan calls out by name.
- `link(MarketingChannel $channel, Channel $realChannel): MarketingChannel` — sets `channel_id` and `is_connected = true` only, exactly as the plan's method signature comment specifies ("sets `channel_id`, `is_connected`"); `supports_publishing`/`supports_analytics` are untouched, since those represent a further, independent upgrade not implied by linking alone. Throws `ChannelBelongsToDifferentCompanyException` if `$realChannel->company_id !== $channel->company_id` — including when `$realChannel->company_id` is `null` (a global/template `Channel` row). This check is explicit because `App\Models\Channel` has no `BelongsToCompany` trait and no global scope (it supports company-less template rows), so tenant isolation for this one operation cannot be delegated to Eloquent scoping and must be asserted directly.
- `suggestedDefaults(MarketingChannelType|string $type): array` — a `match` over all twelve `MarketingChannelType` cases returning `importance`/`objective`/`posting_frequency` defaults tuned per channel type (e.g. `email` → primary/retention+sales/monthly; `x` → experimental/awareness/weekly). Pure and side-effect free; also used internally by `declare()`.
- `wouldDuplicate(Company $company, MarketingChannelType|string $type, ?string $handleOrUrl, ?string $excludingId = null): bool` — a soft, non-blocking check for an identical `(company, type, handle_or_url)` combination, for a future caller (a controller or onboarding form) to warn a user with, not to enforce. Returns `false` immediately when `$handleOrUrl` is `null`/empty, since only channels with a stated handle/URL can meaningfully collide. `$excludingId` lets an `update()`-adjacent caller check "would this change collide with some *other* row" without the row's own value tripping the check.
- `suggestChannels(Company $company): Collection<MarketingChannelSuggestion>` — read-only, non-persisting. Combines two sources: a connected `Integration` of type `website_crawl` (if no `website` `MarketingChannel` is already declared), and any active `Channel` rows not yet linked to a `MarketingChannel` (filtered through `MarketingChannelType::tryFrom()` so the three `Channel` types with no `MarketingChannelType` equivalent — `blog`, `sms`, `landing_page` — are silently excluded rather than throwing). Nothing calls `declare()` from inside this method; per the plan, "do not automatically create suggestions yet."

### `App\Services\MarketingPresence\MarketingChannelCapabilityResolver`

A dedicated, single-purpose resolver — the only place in the application that inspects `is_connected`/`supports_publishing`/`supports_analytics` to decide what a channel can do.

```php
$resolver->resolve($channel): MarketingChannelCapability
```

returns one of `Declared`, `Connected`, `PublishingEnabled`, `AnalyticsEnabled` (`App\Enums\MarketingChannelCapability`, a new backed enum), computed from the `MarketingChannel`'s own flags **and** its linked `Channel`'s `is_active` state: a `supports_publishing`/`supports_analytics` flag can never yield `PublishingEnabled`/`AnalyticsEnabled` unless the linked `Channel` both exists and is currently active — a deactivated or missing link can never outrank plain `Connected`. `AnalyticsEnabled` outranks `PublishingEnabled` when both apply, matching the natural lifecycle ordering (analytics implies a channel that's also publishing).

This is the lifecycle vocabulary from `specs/core/marketing-presence.md` §5 (Declared → Connected → Publishing enabled → Analytics enabled), not spec §11's four UI-facing labels (Connected/Draft only/Coming later/Not configured) carried over from the earlier Channel Publishing Reality Audit — those two vocabularies serve different purposes. §11's UI labels describe what a human sees in a settings screen; §5's lifecycle describes what the domain layer knows. Translating one into the other is Phase 7 (Recommendation/Campaign UI) work, not this resolver's.

### `App\Events\MarketingPresenceUpdated`

A single, coarse event — `public function __construct(public readonly MarketingChannel $marketingChannel)` — fired by every mutating method in `MarketingPresenceService` (`declare`, `update`, `setStatus`, `link`). Per Founding Principle 7, one event per entity-changed rather than one per verb, since the only planned consumer (Business Brain cache invalidation, Phase 5) doesn't care what changed about a channel, only that something did. **No listener is registered for it** — `AppServiceProvider` was not touched, so this event is inert until Phase 5 wires it up.

### `App\Services\MarketingPresence\Exceptions\ChannelBelongsToDifferentCompanyException`

A dedicated, named exception (Founding Principle 9) thrown only by `link()`.

### `App\Services\MarketingPresence\MarketingChannelSuggestion`

A small `readonly` value object (`type`, `displayName`, `handleOrUrl`, `reason`, `channelId`) returned by `suggestChannels()`. Not an Eloquent model — it represents something that does not exist yet.

---

## Validation

- Duplicate handles/URLs are **allowed**, not blocked: `declare()` has no uniqueness rule on `handle_or_url`, and two channels of different types (or even the same type — a business may run more than one Instagram account) can share an identical handle. `wouldDuplicate()` exists purely as an opt-in warning signal for a future caller.
- Multiple channels of the same `type` are fully supported — no unique constraint, no service-level check preventing it (carried over unchanged from Phase 1's migration).
- Tenant isolation is enforced at three independent points: `declare()`'s forced `company_id`, `update()`'s stripped `company_id`/`channel_id`, and `link()`'s explicit cross-company guard — the last one specifically because `Channel` has no global scope to lean on.

---

## Tests (45 new)

| File | Covers |
|---|---|
| `tests/Feature/MarketingPresence/MarketingPresenceServiceTest.php` | `declare()` (creation, defaults, overrides, forced `company_id`, forced unlinked state, event dispatch, invalid-type/missing-field rejection, same-type-twice, duplicate-handle-across-types); `update()` (structural changes, full-state revalidation, stripped `company_id`/`channel_id`/lifecycle booleans, event dispatch); `setStatus()`/`disable()`/`reactivate()`; `link()` (sets exactly `channel_id`+`is_connected` and nothing else, cross-company rejection, global-template-channel rejection); `wouldDuplicate()` (null handle, same type+handle, different type same handle, excluding-id); two explicit tenant-isolation tests |
| `tests/Feature/MarketingPresence/MarketingChannelCapabilityResolverTest.php` | All four capability outcomes plus the two "flag says yes but linked Channel is missing/inactive" edge cases that must not outrank `Connected`, and `AnalyticsEnabled` outranking `PublishingEnabled` when both flags are set |
| `tests/Feature/MarketingPresence/MarketingPresenceSuggestionTest.php` | Empty case; website-integration suggestion (with URL, and suppressed once already declared); existing-`Channel`-row suggestion; `blog` type correctly filtered out via `tryFrom()` instead of throwing; inactive `Channel` rows excluded; already-linked `Channel` rows excluded; no persistence occurs; suggestions scoped to the given company only |

No test in this phase touches a controller, a Vue component, `BusinessBrain`, `OpportunityEngine`, or any publishing class — consistent with the phase boundary. All Phase 2 tests are `Tests\Feature\*` (`RefreshDatabase`, real Postgres) rather than `Tests\Unit\*`, since both new service classes read Eloquent relationships (`MarketingChannel::channel`) and the codebase's existing Unit tests are reserved for pure, framework-free logic (enums) — testing the resolver against real persisted rows was judged more representative than hand-constructing unsaved model instances.

---

## Deviations from the plan (and why)

1. **Capability resolver returns the spec §5 lifecycle enum, not the plan's illustrative UI-label sketch.** The Phase 2 section of the plan document contains a `match` returning string labels `'connected'`/`'draft_only'`/`'not_configured'`/`'coming_later'` — spec §11's UI vocabulary, not spec §5's. The live task instruction for this phase explicitly named the four labels to implement: Declared, Connected, PublishingEnabled, AnalyticsEnabled — spec §5's lifecycle. Treated the task instruction as authoritative over the plan's illustrative snippet, on the reasoning that the plan's Phase 2 section appears to have mixed in a fragment of Phase 7 (UI capability-label) content by mistake; §11's UI-label mapping remains correctly deferred to Phase 7.
2. **`update()` also strips `is_connected`/`supports_publishing`/`supports_analytics`**, not just `company_id`/`channel_id` as the plan's method signature comment states verbatim. These three booleans are lifecycle/linkage state exactly like `channel_id` — allowing a generic business-context edit to flip them would let a caller fake a connection without ever calling `link()`. Judged to be within the spirit of "structural validation" and "company isolation" the task asked for, not a scope expansion.
3. **`declare()` fills in per-type defaults before validating**, rather than validating the caller's raw input as-is. `MarketingChannel::rules()` marks `importance`/`objective`/`posting_frequency` as required, but the migration gives all three DB-level defaults — without this, a bare `declare($company, ['type' => ..., 'display_name' => ...])` call would fail validation despite being a perfectly reasonable minimal declaration. `suggestedDefaults()` (an explicit plan deliverable) is applied first, and any caller-supplied value always overrides the default for that field.

---

## Quality gates

```
php artisan test           760 tests, 758 passing, 2 Redis-skipped, 0 failures
phpstan analyse (level 8)  0 errors
pint --test                clean
npm run build              succeeds (no frontend files touched this phase)
```

---

## What Phase 2 does not include (confirmed)

- No onboarding changes — `OnboardingController` and onboarding Vue pages untouched
- No Settings UI, no controller, no route, no Vue component of any kind
- No `BusinessBrain`/`BusinessBrainService` changes — `MarketingPresenceUpdated` has no registered listener yet
- No `DecisionEngine`/`OpportunityEngine` changes
- No publishing changes — `ChannelPublisherRegistry`, `PublishContent`, `LogChannelPublisher`, `EmailPublisher` untouched
- No automatic creation of suggested channels — `suggestChannels()` is read-only
- No `DemoSeeder` changes

---

## Next step

Phase 3 (Onboarding integration) is specified in the plan but **not started**. Per instruction, this session stops here.
