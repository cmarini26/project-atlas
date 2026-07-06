# Product Polish Audit — UI, User Flow, System Design

**Date:** 2026-07-06
**Scope:** Full application audit following the Phase 1–9 onboarding pipeline stabilization. Goal: identify what separates the current state from a polished product, with prioritized recommendations.
**Baseline:** 638 tests passing, PHPStan level 8 clean, full pipeline verified end-to-end with real Anthropic responses (crawl → 19 facts → 5 opportunities → decision → campaign → pending recommendation).

---

## Executive Summary

The foundations are genuinely strong: an event-driven pipeline with explainable recommendations (the four-part rationale is a real differentiator), disciplined testing, clean provider abstraction, SSRF-protected crawling, and a coherent design-token system. The product now works end-to-end.

What separates it from *polished* falls into three themes:

1. **The core promise is only half-delivered.** Atlas's philosophy is "know more about the business tomorrow than today" — but the observe→learn loop runs exactly once. Nothing re-syncs integrations, nothing expires opportunities, and nothing notifies the user when Atlas has something for them. Today it is a one-shot analysis tool with a recurring-intelligence UI.
2. **Users can get in, but not always back out or onward.** No password reset, no channel management (the UI tells users to "connect more channels" but there is no way to do it), no team invites, one-click irreversible approval.
3. **The frontend is one layer of finish away.** Layout remounts on every navigation, mixed full-page/SPA links, no toasts, no confirmations, hand-rolled SVGs — all mechanical fixes with outsized feel improvements.

**Scorecard**

| Area | Grade | One-line assessment |
|------|-------|---------------------|
| Pipeline architecture | A− | Event-driven, queued, explainable, well-tested; missing the recurring loop |
| Onboarding flow | B+ | Every failure mode now has a state; over-promises notification |
| Core app flows | B− | Review/approve works; dead-ends around channels, teams, account recovery |
| UI craft | B− | Consistent tokens & empty states; navigation mechanics and feedback patterns undercooked |
| Security posture | C+ | Solid tenancy + SSRF; no rate limiting, no password reset, no email verification |
| Operability | B− | Structured logs + health endpoints; no error tracking, no failed-job alerting, no AI cost metering |

---

## A. System Design

### A1. The learning loop never repeats — CRITICAL

`SyncIntegration` sets `next_run_at = now()+24h` and `IntegrationService` sets `+7 days`, but **nothing ever reads `next_run_at`**. The scheduler (`routes/console.php`) runs publishing, channel health, metric pruning, and learnings — but never re-syncs an integration. After onboarding, Atlas never observes the business again unless the user manually clicks "Sync" in Settings.

This breaks founding principle #8 and silently degrades everything downstream: facts go stale, the Brain describes last month's inventory, opportunity scans (now correctly triggered per processed observation) never fire again because no observations arrive.

**Recommendation (P0):**
- Add `atlas:sync-due-integrations` command: dispatch `SyncIntegration` for every `active` integration with `next_run_at <= now()`; schedule it every 15 minutes.
- Use a larger crawl budget for recurring syncs than the onboarding one (`CRAWLER_MAX_PAGES=1` is right for first-response speed, wrong for depth). Suggest a separate `crawler.recurring_max_pages` (e.g. 10–20) so the Brain deepens over time.
- Guard with `ShouldBeUnique` (already on the job) and per-integration failure isolation so one bad site doesn't block the sweep.

### A2. Opportunities never expire

`ExpireOpportunities` exists but is never scheduled or dispatched. Consequences compound: `expires_at` is decorative, stale opportunities accumulate as `open`, and — because `OpportunityEngine::hasDuplicate()` dedupes against existing rows — **stale opportunities suppress fresh detection of the same type indefinitely**. Once A1 lands, this becomes the next bottleneck in the loop.

**Recommendation (P0):** `Schedule::job(new ExpireOpportunities())->hourly()`. Add a test that an expired opportunity no longer blocks re-detection.

### A3. No notification channel exists at all

There is no `app/Notifications` directory, no mail configuration beyond the log driver, and no in-app notification model. Meanwhile the onboarding timeout screen says *"You can leave this page — we'll notify you when the first recommendation is ready"* — a promise the system cannot keep.

**Recommendation (P1):**
- Minimum: email on `RecommendationCreated` ("Atlas has a recommendation for you") with a deep link; email on repeated integration failure. This is the retention loop for a product whose whole model is "smart recommendation waiting for approval."
- Fix the timeout copy now (one line) until email exists.
- Postmark/Mailgun setup is already a beta-audit blocker; this is the product reason to do it.

### A4. No abuse protection on auth or public endpoints

- `POST /login`, `POST /register`: no `throttle` middleware, no lockout — unlimited brute-force attempts.
- `POST /api/analytics/webhooks/{provider}`: has a `verify()` step per provider (good), but no rate limit in front of signature verification.
- `POST /onboarding/integration`: each submit queues a crawl + 5 AI calls; a hostile user can burn AI spend freely.

**Recommendation (P0, small):** `throttle:5,1` on login/register, `throttle:60,1` on webhooks, and a per-company cap on concurrent onboarding syncs (the `ShouldBeUnique` on `SyncIntegration` already covers the worst of it).

### A5. AI usage is untracked

`AiResponse` carries `inputTokens`/`outputTokens` and now `stopReason` — and every caller drops them. There is no per-company AI spend record, no way to price customers, detect runaway loops, or debug "why was this slow."

**Recommendation (P1):** Persist an `ai_calls` row per completion (company_id, prompt name + `version()`, model, tokens, duration, request_id). Cheap now, near-impossible to retrofit historically. This also delivers the "versioned prompts" principle from CLAUDE.md — prompts have `version()` but nothing records which version produced which fact/decision, so learning-provenance is currently unreliable.

### A6. Operability gaps

- **No error tracker** (Sentry/Bugsnag/Flare). `report()` goes to a log file nobody watches; the P0s of the last week were all diagnosed by grepping `laravel.log` by hand.
- **No failed-job visibility**: `failed_jobs` fills silently; no alert, no admin surface (Filament exists — a failed-jobs resource is ~an hour of work).
- **Worker supervision** is `composer dev` locally and undefined in production; the queue is now load-bearing for the entire product (Phase 8). Document/provision Supervisor or Horizon before beta.
- The status endpoint's sync-mode inline retry (`retryStaleObservations`) is a GET with side effects — acceptable dev pragmatism, but gate it more tightly (it already checks `queue.default === 'sync'`) and plan its removal once workers are standard everywhere.

**Recommendation (P1):** Sentry + a Filament failed-jobs page + Supervisor config. Half a day total, transforms debuggability.

### A7. Tenancy is sound but conventionally fragile

`EnsureCompanyMembership` is correct, and jobs consistently use `withoutGlobalScopes()` + explicit `company_id` filters. But the codebase mixes three idioms (global scope, explicit `where('company_id')`, both). The pattern relies on every future controller author remembering the rules.

**Recommendation (P2):** Pick one idiom per context and document it in CLAUDE.md (e.g. "web controllers rely on the global scope; queue/CLI contexts must use `withoutGlobalScopes()->where('company_id', ...)`"). Add an architectural test (pest-arch or a simple grep test) that flags `Model::all()`/unscoped queries in jobs.

---

## B. User Flow

### B1. Account lifecycle dead-ends — CRITICAL for real users

- **No password reset.** A locked-out user is permanently locked out. This is the single most predictable support ticket.
- **No email verification** — weakens deliverability trust for A3's emails and invites junk signups.
- **No profile management**: no change-password, change-email, or delete-account anywhere (Settings only edits company name/industry).

**Recommendation (P0):** Laravel's password-broker scaffolding + a "Profile" section in Settings. This is stock framework work (~a day) with disproportionate trust impact.

### B2. The channel dead-end

The no-opportunity state and the whole decision model revolve around channels, and onboarding seeds a lone Blog channel. But **there is no UI to view, add, connect, or deactivate channels** — Settings manages only company fields and integrations. The empty-state copy literally advises "connect more channels or add catalog items in Settings," which is impossible.

**Recommendation (P0 for honesty, P1 for depth):**
- Short term: change the copy to only suggest what exists.
- Real fix: a Channels section in Settings — list seeded/connected channels, toggle active, add email/social stubs. Even without real OAuth connections, letting users declare channels materially changes what the DecisionEngine can do.

### B3. Approval is one tap and irreversible

"Approve" immediately schedules publishing (the MVP's only human gate) with no confirmation, no summary of *what will happen* (channel, timing), and no undo window. An accidental tap publishes content to the user's actual blog/channel.

**Recommendation (P1):** A confirm step on Approve stating the concrete effect ("Publish 1 blog post to *Blog* now") — and ideally a short cancellation window (execution already has `scheduled/cancelled` states to hang this on). Keep Reject friction-free as it is.

### B4. Multi-company users can't switch companies

`EnsureCompanyMembership` supports multiple memberships and `/company/select` exists, but no UI element links to it. A two-company user can only switch by typing the URL.

**Recommendation (P1):** Make the company name in the sidebar header a switcher (menu with memberships + "switch"), visible only when memberships > 1.

### B5. Onboarding niggles

Now in good shape after Phases 1–9 (each failure mode has a distinct card). Remaining polish:

- No back navigation from step 2 to step 1; a typo'd company name is uncorrectable until Settings.
- Step 3 "Connected — Redirecting…" pane is dead weight (the server redirects before it meaningfully renders); remove it or make the redirect explicit.
- The 5-minute "This is taking a moment" card promises notification (see A3).
- Post-onboarding orientation: after the first recommendation, users land in an 8-item navigation with no guidance. A one-time dashboard hint ("Start here: review your first recommendation") or a 3-step checklist card (✓ website connected → review recommendation → connect a channel) would carry new users to the aha moment.

### B6. Vocabulary check

Navigation exposes internal domain names: "Business Brain," "Learning," "Opportunities" vs "Recommendations." The Brain concept is charming and worth keeping; "Learning" (a list of `unapplied_learnings`) is engineering vocabulary. Small-business owners think in terms of "what did you do, what did it achieve, what should I approve."

**Recommendation (P2):** User-test the nav labels; consider folding Learning into Analytics ("What Atlas learned") and gating rarely-used pages until they have content.

---

## C. UI / Frontend

### C1. Navigation mechanics (highest UI leverage, all mechanical)

1. **Layout remounts on every page.** Pages wrap `<AppLayout>` in their own templates, so Inertia tears down the sidebar per navigation — scroll position resets, icons flash. Switch to [persistent layouts](https://inertiajs.com/pages#persistent-layouts) (`defineOptions({ layout: AppLayout })`).
2. **Active-link detection reads `window.location`** (`AppLayout.vue:24-29`) — works only *because* of the remount bug above; will silently break once layouts persist. Use `usePage().url`.
3. **Mixed `<a>` and `<Link>`**: Dashboard "View all" links, recommendation list cards, and the Show page's back arrow are raw `<a>` tags → full page reloads inside an SPA. Sweep to `<Link>`.

### C2. Feedback patterns

- **Flash messages never dismiss** and sit at the top of the content column. Adopt a small toast pattern (auto-dismiss ~5s, close button, `aria-live="polite"`); flash and `onSuccess` messages both feed it.
- **No confirmation dialog primitive** — needed for B3 (approve) and any future destructive action. One `ConfirmDialog.vue` with focus trap covers all cases.
- **Error states inside ApproveActions** show a generic "Something went wrong." Surface the server's message (403 role errors are meaningful: "Only company owners and admins can approve").

### C3. Component hygiene

- **Inline SVG icons are copy-pasted** across layout, cards, and status pages (the layout alone carries ~8 full Heroicons paths; the same warning-triangle path appears in 4 status cards). Adopt an `Icon.vue` (or `@heroicons/vue`) — smaller payloads, consistent sizing, and the "Icon placeholder" comment in AppLayout stops being true.
- **UI kit is thin but clean** (Badge, EmptyState, LoadingSpinner, ScoreBar). Missing primitives that pages keep re-implementing: Button (5+ bespoke button class-strings per page), Input/FormField (label+input+error trio duplicated in Login, Register, Onboarding, Settings), Card. Extracting these three would delete a large fraction of the template noise and lock in consistency.
- **Dates**: `toLocaleDateString('en-US')` hardcoded in several pages; use the user's locale (no argument) and consider relative time ("2h ago") for activity feeds.

### C4. Accessibility

Decent base (labels, `aria-label`s, `role=alert/status`). Gaps worth closing for polish:

- Mobile sidebar has no focus trap or `Esc` to close, and the overlay isn't `aria-hidden` aware.
- No "skip to content" link.
- Status badges communicate by color + text — fine — but ScoreBar and progress checkmarks are color-only; add text/aria equivalents.
- First-field autofocus on Login/Register/Onboarding forms.

### C5. Perceived performance

- Inertia progress bar is configured (good). Add lightweight **skeletons** for the dashboard cards and recommendation lists instead of blank-until-loaded.
- The onboarding status page polls at a fixed 5s forever; consider backing off after 2 minutes (12 polls) to 10–15s to reduce noise, since the interesting transitions are early.

### C6. Brand and finish

- Title-only "Atlas" wordmark, no favicon/logo treatment, `/` redirects straight to `/login` — fine for private beta, but plan a minimal logged-out landing before customers share links around.
- The design-token system (CSS variables throughout) is a real asset: dark mode later is nearly free, so avoid hardcoded palette classes creeping in (the flash banners already use raw `green-50`/`rose-50` instead of tokens).

---

## Prioritized Roadmap

### P0 — Do before onboarding another real customer (≈3–4 days)

| # | Item | Area | Effort |
|---|------|------|--------|
| 1 | Scheduled `atlas:sync-due-integrations` (the recurring loop) | System | ½ day |
| 2 | Schedule `ExpireOpportunities` hourly + anti-suppression test | System | 2 h |
| 3 | Rate limiting: login/register/webhooks | System | 2 h |
| 4 | Password reset flow | Flow | 1 day |
| 5 | Fix over-promising copy (notify-you, connect-channels) | Flow | 1 h |
| 6 | Channels section in Settings (list + activate/deactivate) | Flow | 1 day |

### P1 — The polish sprint (≈1 week)

| # | Item | Area |
|---|------|------|
| 7 | Email notifications: recommendation ready, sync failed (Postmark) | Flow |
| 8 | Approve confirmation with concrete effect summary | Flow |
| 9 | Persistent layout + `usePage().url` active state + `<Link>` sweep | UI |
| 10 | Toast system replacing static flash banners | UI |
| 11 | Company switcher in sidebar (multi-membership) | Flow |
| 12 | Sentry + Filament failed-jobs page + Supervisor config | System |
| 13 | Persist AI usage per call (tokens, prompt version, request_id) | System |
| 14 | Icon component + Button/FormField/Card primitives | UI |

### P2 — Fast-follows

- Post-onboarding checklist card on dashboard; nav vocabulary pass (fold Learning into Analytics).
- Email verification; profile section (change password/email).
- Accessibility pass (focus trap, skip link, autofocus, non-color signals).
- Locale-aware dates; poll backoff on status page; dashboard skeletons.
- Tenancy idiom documentation + architectural test; dark mode on the existing tokens; logged-out landing page.

---

## What's already good (keep doing this)

- **Explainability as product**: the why-now/why-this/why-channel/why-works rationale rendered on the recommendation page is the differentiator — the real Anthropic output reads impressively well.
- **Failure-mode-complete onboarding**: crawl failure, AI failure, provider overload, missing worker, no-opportunity — each has a distinct, actionable state. Rare discipline.
- **Test and analysis rigor**: 638 tests, PHPStan level 8, realistic-payload tests that now cover the exact bugs fixtures used to hide.
- **Clean seams**: `AiProvider` abstraction (fake/local/Anthropic), connector registry, scoring weights, event-driven job chain — all extensible in the directions the roadmap needs (new verticals, new channels).
- **Structured pipeline logging** end-to-end (facts → knowledge → scan → decision → recommendation) with drop-reason counters; the next debugging session will be minutes, not hours.
