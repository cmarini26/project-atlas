# Milestone 11 Phase 3 — Marketing Presence Onboarding — Review

**Date:** 2026-07-09
**Scope:** Phase 3 only, per [Milestone-11-Marketing-Presence.md](../plans/Milestone-11-Marketing-Presence.md). No Business Brain, Opportunity Engine, publishing, channel configuration, or OAuth changes.
**Tests:** 771 total (769 passing, 2 Redis skipped) — 22 new/changed in `OnboardingControllerTest`
**PHPStan:** Level 8 — 0 errors · **Pint:** Clean · **Frontend build:** succeeds

---

## What shipped

### New onboarding step — "Where do your customers find you?"

Added as the **final** step of the onboarding wizard (company profile → website URL → marketing presence → confirm), per the task's explicit instruction. Title and supporting copy match verbatim: *"Where do your customers find you?"* / *"Help Atlas understand your marketing presence. You can change these later."*

- `resources/js/Pages/Onboarding/Index.vue` — a checklist of the 12 required channels (Website, Email Newsletter, Instagram, Facebook, LinkedIn, X, YouTube, TikTok, Google Business Profile, Events, Print, Other), rendered as a 2-column grid of toggle chips using the existing design tokens (`--color-accent-500`/`--color-border`/`--color-surface-elevated`, `text-sm`, `rounded-lg`, `duration-fast` transitions — no new components introduced). **Website is preselected** (`channels: ['website']` is the form's initial state); every other channel starts unchecked. No text input of any kind on this step — purely checkbox selection, matching "no required metadata yet, just channel selection." Submitting posts to the new endpoint and then flows into the same transient "Connected" confirmation screen the wizard already had (now step 4, unchanged in content).
- `App\Http\Controllers\OnboardingController::saveMarketingPresence()` — new action. Resolves the acting user's company (mirroring `createIntegration()`'s membership-lookup pattern exactly, including redirecting to `/onboarding` if no membership exists), validates `channels` as a `present` array of `MarketingChannelType` values (`Rule::in(MarketingChannelType::values())`), then for each selected type calls `MarketingPresenceService::declare()` with a human-readable `display_name` (a private `CHANNEL_LABELS` map — e.g. `email` → "Email Newsletter", `google_business_profile` → "Google Business Profile" — matching the checklist copy exactly). Redirects to `onboarding.status`.
- `POST /onboarding/marketing-presence` — new route, `routes/web.php`, inside the existing `auth` onboarding group, unthrottled (unlike `/onboarding/integration`'s `throttle:3,1`, since this step queues no crawl or AI work — it's a synchronous DB write).
- `OnboardingController::index()` — extended with a third gating condition: company exists, integration exists, but no `MarketingChannel` row exists yet → render step 3 (marketing presence). Only once both an `Integration` and at least one `MarketingChannel` exist does `index()` redirect to `/onboarding/status`, exactly mirroring the existing "resume the wizard where you left off" pattern already used for steps 1–2.

### Persistence — `MarketingPresenceService`, not raw `MarketingChannel::create()`

Every declaration goes through `MarketingPresenceService::declare()` (Phase 2), so this step inherits all of that service's guarantees for free: `company_id` is always taken from the resolved `Company`, never from user input; `channel_id`/`is_connected`/`supports_publishing`/`supports_analytics` are always forced to `null`/unset/`false`; `status` defaults to `active` (the step asks about *today*); `importance`/`objective`/`posting_frequency` are filled in via `suggestedDefaults()` per type; structural validation runs before persisting. **No `App\Models\Channel` record is created. No `Integration` record is touched. No API connection, credential, or OAuth flow exists anywhere in this step** — confirmed by `test_marketing_presence_step_creates_no_channel_or_integration_records`.

Resubmitting the same selection (back button, double-click) is idempotent: `saveMarketingPresence()` skips any type already declared for the company rather than calling `declare()` again, so repeat submits don't produce duplicate rows — the same defensive posture as `createIntegration()`'s existing reuse-on-resubmit handling for the website URL.

### Ordering — marketing presence before "Atlas is now learning"

`createIntegration()`'s final redirect changed from `route('onboarding.status')` to `route('onboarding')`, so submitting the website URL returns the user to the wizard (which now shows step 3) instead of jumping straight to the pipeline status page. This guarantees the declared marketing presence is captured as part of the same onboarding session, before the user ever sees the status page's "Atlas is now learning about your business" experience — satisfying "Atlas now knows: Website, Marketing Presence" ahead of that moment, without touching `SyncIntegration`, the crawl pipeline, or any Business Brain code. See "Deviations" below for the reasoning on why the dispatch timing itself (queued in `createIntegration()`, unchanged) was left alone.

---

## UX

- Lightweight by design: one screen, one control type (checkboxes), no per-channel forms, no handle/URL/username fields, no "connect your account" buttons. This is a deliberate rejection of the plan document's Phase 3 sketch, which describes optional inline `handle_or_url` capture and a free-text label for "Other" — the live task's boundaries ("No required metadata yet. Just channel selection." / "Do NOT ask for handles, usernames, or URLs") are more specific and were treated as authoritative. "Other" gets the same fixed `display_name` ("Other") as every other channel; a user who wants a custom label edits it later in Settings (Phase 4+), not during onboarding.
- Existing design system only: no new component was introduced. The channel checklist reuses the same border/background/radius/transition tokens as the company-profile and website-URL steps' text inputs, and the checkbox itself follows `docs/design/System.md` §10's spec (24px touch target via padding, 1.5px border, filled accent when checked).
- The step is optional in the sense that a user can submit with zero channels selected (only `Website` is pre-checked, and unchecking it is allowed) — `test_marketing_presence_step_allows_an_empty_selection` confirms this doesn't block progression to the status page. This matches the plan's instruction that this addition must not gate the existing "connect website → status" flow.

---

## Completion

Once both the website URL and marketing presence steps are submitted, Atlas has, on record, before the pipeline status page is reached:

- **Website** — both a `website_crawl` `Integration` (technical, for crawling) and, if selected, a `type: website` `MarketingChannel` (business declaration)
- **Marketing Presence** — one `MarketingChannel` row per selected channel, `status: active`, fully unlinked and unconnected

`OnboardingController::index()` now requires both facts to exist before it will redirect to `/onboarding/status` — the pipeline status page is only reached once onboarding is genuinely complete.

---

## Tests (22 new/changed, all in `tests/Feature/App/OnboardingControllerTest.php`)

| Area | Covers |
|---|---|
| Marketing presence step | Declares one `MarketingChannel` per selected type with the correct `display_name`; creates no `Channel`/`Integration` row; declared channels are unlinked/unconnected/`status: active`; an empty selection is allowed; an unknown channel type is rejected (`Rule::in`); a missing `channels` key is rejected (`present`); resubmitting the same selection doesn't duplicate rows; no-company redirects to `/onboarding`; a second company's channels are unaffected by another company's submission (tenant isolation) |
| Onboarding progression | A single end-to-end test drives a fresh user through all three data-entry steps (company → integration → marketing presence) via real HTTP requests, asserting the correct `initial_step` is shown before each submission and that `/onboarding` finally redirects to `/onboarding/status` only once every step is done |
| Regression — `index()` | Existing step-1/step-2 tests unchanged; the old `test_onboarding_index_redirects_to_status_when_integration_exists` was replaced with two tests reflecting the new, intentional behavior: integration-without-marketing-presence now shows step 3 (was: immediate redirect), and redirect-to-status now additionally requires a declared `MarketingChannel` |
| Regression — `createIntegration()` | The four existing tests that asserted a redirect to `route('onboarding.status')` (`test_integration_step_creates_integration_and_redirects_to_status` → renamed `..._and_redirects_to_marketing_presence_step`, plus three others) were updated to assert a redirect to `route('onboarding')` instead — all other assertions in those tests (job dispatch, error handling, resubmit-reuse behavior) are untouched and still pass unmodified |

No test in this phase touches `BusinessBrain`, `OpportunityEngine`, `ChannelPublisherRegistry`, or any publishing class.

---

## Deviations from the plan (and why)

1. **Marketing presence is the literal final step (after website URL), not "between company profile and website URL"** as the plan document's Phase 3 section allows as an alternative. The live task instruction was explicit — "Extend onboarding with a new **final** step" — so that was treated as authoritative over the plan's flexible placement, consistent with how Phase 2 treated a live task instruction as superseding a looser plan sketch.
2. **No optional inline `handle_or_url` capture, no free-text "Other" label** — the plan's Phase 3 sketch describes both; the live task's explicit boundaries ("No required metadata yet," "Do NOT ask for handles, usernames, or URLs") rule them out entirely, not just as "required." Every declared channel gets a fixed, type-derived `display_name` and nothing else; a user can rename or add detail afterward in Settings.
3. **`createIntegration()`'s redirect changed from `onboarding.status` to `onboarding`, but `SyncIntegration`'s dispatch site and timing are untouched.** The alternative — moving the crawl/AI pipeline dispatch itself into `saveMarketingPresence()` so it fires strictly after marketing presence is saved — was considered and rejected: `SyncIntegration` is already queued (never run inline), so real crawl/AI/knowledge-synthesis work only begins once a queue worker picks up the job, which in every real deployment takes measurably longer than the second, synchronous HTTP round-trip for the marketing-presence step. Moving the dispatch would also have touched heavily-tested, previously-fixed-multiple-times pipeline code (`git log` shows several recent onboarding-pipeline-stall fixes) for a guarantee the existing async architecture already provides in practice. The chosen approach — reordering only the wizard's own redirect target — achieves the same practical outcome with zero changes to `SyncIntegration`, `IntegrationService`, or anything queue-related.
4. **`docs/product/UserFlows.md` Flow 1 was updated** (not originally listed as a required doc for this phase) because it explicitly asserted "the wizard is exactly 3 steps... No more," which this phase deliberately makes false. Per Founding Principle 6 ("when a spec and code conflict... update the spec"), the flow doc now describes 4 steps and the new marketing-presence step's contract.

---

## Quality gates

```
php artisan test           771 tests, 769 passing, 2 Redis-skipped, 0 failures
phpstan analyse (level 8)  0 errors
pint --test                clean
npm run build              succeeds
```

---

## What Phase 3 does not include (confirmed)

- No `BusinessBrain`/`BusinessBrainService` changes
- No `DecisionEngine`/`OpportunityEngine` changes
- No publishing changes — `ChannelPublisherRegistry`, `PublishContent`, `LogChannelPublisher`, `EmailPublisher` untouched
- No channel configuration of any kind — no handle, username, URL, or metadata field on the onboarding step
- No OAuth or API connection flow introduced anywhere in onboarding
- No Settings UI changes (declared channels remain editable only via the Phase 2 service layer today; a Settings screen is a later phase)
- No `DemoSeeder` changes

---

## Next step

Phase 4 (Settings UI) is specified in the plan but **not started**. Per instruction, this session stops here.
