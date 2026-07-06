# P0 Product Polish — Implementation Review

**Date:** 2026-07-06
**Source:** [Product-Polish-Audit.md](Product-Polish-Audit.md) — P0 items only
**Tests:** 657 total (655 passing, 2 Redis skipped) — 19 added
**PHPStan:** Level 8 — 0 errors · **Pint:** Clean · **Frontend build:** 0 errors

---

## 1. Close the loop

### The recurring Observe → Learn cycle now exists

**Before:** `next_run_at` was written by every sync (`+24h`) and by `IntegrationService::create()` (`+7d`) but consumed by nothing. Atlas observed a business exactly once, at onboarding.

**Now:**

- **`atlas:sync-due-integrations`** (new command, `app/Console/Commands/SyncDueIntegrations.php`) dispatches `SyncIntegration` for every `active` integration with a past-due, non-null `next_run_at`. Scheduled **every 15 minutes** in `routes/console.php`. `SyncIntegration` sets `next_run_at = now()+24h` on success, so integrations settle into a daily cadence; `error`-status integrations are excluded until a manual sync reactivates them. `ShouldBeUnique` on the job dedupes overlapping dispatches.
- **Deeper crawls on recurring syncs.** `WebPageCrawler::crawl()` accepts a per-call `?int $maxPages` override, and `WebsiteConnector` selects the budget per sync: the **first-ever** sync (`last_successful_run_at === null`) keeps the shallow onboarding budget (`crawler.max_pages`, default 1) so the first recommendation arrives fast; **every later sync** uses the new `crawler.recurring_max_pages` (default 10, env `CRAWLER_RECURRING_MAX_PAGES`) so the Brain deepens beyond the home page over time. No flag-plumbing through jobs — the integration's own sync history decides.
- **`ExpireOpportunities` scheduled hourly.** The job existed but was never dispatched. Since the engine's dedupe (`OpportunityRepository::hasDuplicate`) only counts `open`/`selected` rows, expiry is precisely what re-enables detection of a lapsed opportunity type — without it the loop silently stops producing recommendations of any type it has produced once.

### Tests

| Test | Coverage |
|------|----------|
| `test_dispatches_sync_for_due_active_integrations` | due + active → dispatched |
| `test_skips_integrations_not_yet_due` / `test_skips_errored_integrations` / `test_skips_integrations_without_next_run_at` | exclusion rules |
| `test_recurring_loop_jobs_are_scheduled` | both schedule entries actually registered |
| `test_expired_opportunity_no_longer_suppresses_fresh_detection` | `hasDuplicate` true while open → false after expiry |
| `test_recurring_sync_uses_deeper_crawl_budget` / updated first-sync expectation | per-sync page budget selection |

---

## 2. Account safety

### Password reset flow (new)

Standard Laravel password broker, Inertia-native:

- `Auth\PasswordResetController` — request form, send-link (always responds success — **no account enumeration**), reset form, update (fires `PasswordReset` event, redirects to login with a flash).
- Routes under the `guest` group: `password.request/email/reset/update` (named to match the framework's `ResetPassword` notification URL generation).
- New pages `Auth/ForgotPassword.vue` and `Auth/ResetPassword.vue` following the existing auth form conventions; **"Forgot password?" link added to Login**, which now also renders flash success (used post-reset).
- Token storage (`password_reset_tokens`) and the broker config were already present from the framework skeleton — no migration needed.

### Rate limiting

| Endpoint | Limit |
|----------|-------|
| `POST /login` | 5/min |
| `POST /register` | 5/min |
| `POST /forgot-password`, `POST /reset-password` | 5/min |
| `POST /onboarding/integration` | **3/min** — each submit can queue a crawl + 5-call AI pipeline run |

### AI spend protection on onboarding submits

Beyond the route throttle, `OnboardingController::createIntegration()` now **reuses the company's existing `website_crawl` integration** (updating URL, resetting `status` to `active` and clearing `last_error`) instead of creating a new row per submit. Combined with `SyncIntegration`'s `ShouldBeUnique` (keyed on integration id), repeat submits — retries with a different URL, double-clicks — can no longer stack queued pipeline runs. Side benefit: the "Try a different URL" recovery flow now properly resets the errored integration instead of orphaning it.

### Tests

`PasswordResetTest` (6): page renders, link sent, anti-enumeration (no notification for unknown email, same response), reset with valid token + login with new password, invalid token rejected.
`RateLimitingTest` (4): 429 after limits on login, register, forgot-password, onboarding submit.
`OnboardingControllerTest` (+2): resubmit reuses the single integration and updates its URL; resubmit clears a previous `error` state and re-dispatches.

---

## 3. Truthful copy

Two over-promises removed from `Onboarding/Status.vue`:

| Card | Before | After |
|------|--------|-------|
| No-opportunity | "…connect more channels or add catalog items in Settings…" (no channel UI exists) | "Review what Atlas learned in the Business Brain. Atlas re-scans your website automatically and will surface a recommendation as soon as it finds a strong opportunity." — now true, thanks to item 1 |
| 5-minute timeout | "…we'll notify you when the first recommendation is ready." (no notification system exists) | "…your first recommendation will be waiting on the dashboard when it's ready." |

Full channel management UI intentionally **not** built (P1 scope per instruction).

---

## Explicitly out of scope (per instruction)

P1/P2 items untouched: notification system, Inertia persistent-layout refactor, channel management UI, new publishers, approve confirmation, company switcher, AI usage persistence, Sentry/ops tooling.
