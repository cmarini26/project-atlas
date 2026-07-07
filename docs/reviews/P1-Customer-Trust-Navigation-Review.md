# P1 Customer Trust & Navigation — Implementation Review

**Date:** 2026-07-07
**Source:** [Product-Polish-Audit.md](Product-Polish-Audit.md) — P1 items 8, 9, 10, 11 only
**Tests:** 667 total (665 passing, 2 Redis skipped) — 22 PHP tests + 13 Vitest tests added
**PHPStan:** Level 8 — 0 errors · **Pint:** Clean · **Vitest:** 13/13 passing · **Frontend build:** 0 errors

Out of scope for this slice (per instruction): email notifications, Sentry/error tracking, Channels management UI. Not implemented.

---

## 1. Approval confirmation

**Before:** clicking "Approve" on a recommendation immediately posted to `/approve` — one tap, irreversible, no summary of what would happen.

**Now:** `ApproveActions.vue` opens a `ConfirmDialog` before submitting. The dialog lists one line per content asset naming the content and its destination channel, e.g. *"Publish the blog post "Rare finds this week" to your blog channel."* — falling back to a generic explanation when a recommendation has no content assets yet. A closing note sets expectations: *"Publishing starts right after you approve. You can follow progress on the Publishing page."* Only confirming the dialog calls `approveForm.post(...)`; cancelling (button, overlay click, or Escape) does not.

- New primitive: `Components/UI/ConfirmDialog.vue` — teleported modal, focuses the confirm button on open, closes on Escape (guarded while `processing`), reusable for any future confirm-before-destructive-action need.
- `RecommendationController::show()` already returned `content_assets` with `channel.type`; `ApproveActions` now receives that data as a prop from `Recommendations/Show.vue` instead of needing a new request.

**Tests:**
- `ApproveActions.spec.ts` (Vitest, 4 tests) — clicking Approve does not submit immediately; the dialog names the content and channel; falls back to generic copy with no assets; confirming the dialog is what actually calls `post()`.
- `RecommendationControllerTest::test_show_includes_content_asset_channel_for_approval_confirmation` — the `show()` payload carries `content_assets.*.channel.type`, which the confirmation dialog depends on.

---

## 2. Company switcher

**Before:** `/company/select` existed and `EnsureCompanyMembership` correctly supported multiple memberships, but no UI element linked to it — a multi-company user could only switch by typing the URL.

**Now:** `Components/App/CompanySwitcher.vue` renders in the sidebar header, replacing the static company-name label, **only when the user belongs to more than one company** (single-company users see the same plain label as before). It's a small dropdown listing the *other* companies (never the current one) and posts to the existing `/company/select` endpoint on selection.

**Tenant-switching safety** — verified, not just assumed:
- `CompanySelectorController::select()` already scoped the lookup to `CompanyMembership::where('user_id', ...)->where('company_id', ...)->firstOrFail()`, so selecting a company the user doesn't belong to 404s. New tests lock this in.
- New end-to-end test switches company mid-session and asserts the *next* dashboard request is scoped to the newly selected company, not the previous one.

**A real, pre-existing bug found and fixed along the way:** the shared Inertia `company` prop was computed **eagerly** in `HandleInertiaRequests::share()`, but that middleware is registered globally on the `web` group (`bootstrap/app.php`) and therefore runs **before** the route-level `company` middleware (`EnsureCompanyMembership`) has set the `company` request attribute. `$request->attributes->get('company')` was always `null` at share-time, so the shared `company` prop — and anything reading `page.props.company`, including the sidebar's plain-text company name before this change — has silently resolved to `null` on every request. Fixed by making `company` a closure in the shared-props array (the same pattern already used for the new `companies` prop), deferring evaluation until the Inertia response is actually built, after the controller and its middleware have run. A regression test (`test_shared_company_prop_reflects_the_current_tenant`) asserts the prop is populated for a real request.

**Tests:**
- `CompanySwitcher.spec.ts` (Vitest, 3 tests) — lists only other companies; posts `/company/select` with the chosen id; renders no menu items for a single-company list.
- `CompanySelectorControllerTest` (new file, 6 tests) — index lists all memberships; select switches the session; **select rejects a company the user doesn't belong to** (404, session untouched); select requires `company_id`; end-to-end switch changes which company's data the dashboard serves.
- `MiddlewareTest` (+3): shared `companies` prop lists all memberships / is a single entry for one membership; **shared `company` prop reflects the current tenant** (the regression test for the bug above).

---

## 3. Frontend navigation cleanup

**Persistent layout.** All 12 pages under `Pages/App/` (`Dashboard`, `Brain`, `Settings`, `Learning`, `Opportunities`, `Publishing`, `Analytics/Index`, `Analytics/Show`, `Campaigns/Index`, `Campaigns/Show`, `Recommendations/Index`, `Recommendations/Show`) now use Inertia's `defineOptions({ layout: AppLayout })` instead of wrapping their template in `<AppLayout>...</AppLayout>`. The sidebar, its state (mobile drawer), and the new toast stack now survive Inertia navigations — no more per-page remount.

**Active-link detection fixed at the root cause.** `AppLayout.vue` used `window.location.pathname`, which only "worked" because the layout remounted on every navigation (recomputing the check on mount). With persistent layouts that would have silently gone stale. Replaced with `computed(() => page.url.split('?')[0])` off Inertia's reactive `usePage()`, so the active nav item updates correctly on every visit — including sidebar/company-switcher visits that don't remount anything.

**`<Link>` sweep.** Every internal navigation that was a raw `<a href="/app/...">` is now an Inertia `<Link>` (18 anchors across 10 files: `Dashboard.vue`, `Recommendations/Index.vue`, `Recommendations/Show.vue`, `Analytics/Index.vue`, `Analytics/Show.vue`, `Campaigns/Index.vue`, `Campaigns/Show.vue`, `CompanySelector.vue`, `Components/Dashboard/HealthCard.vue`, `Components/Dashboard/RecommendationPrompt.vue`). `SummaryCard.vue`'s dynamic `<component :is="href ? 'a' : 'div'">` now resolves to the `Link` component instead of a literal `'a'` tag. External links and non-navigational anchors (the "Forgot password?" link on the guest-only Login page, `mailto:`-style cases — none present) were left as-is by design; this is an internal-app-navigation sweep, not a blanket conversion.

No broad visual redesign was done — every page's markup, spacing, and styling is unchanged; only the wrapping mechanism and anchor tags changed.

---

## 4. Toast / flash primitive

**Before:** flash messages rendered as static, non-dismissible banners pinned above the page content, re-rendered fresh (and disappearing) on the next navigation.

**Now:**
- `composables/useToasts.ts` — a module-scoped reactive toast stack (shared across the app, independent of any one component's lifecycle so it survives Inertia navigations under the persistent layout). `addToast(type, message, durationMs?)` auto-dismisses after 5s by default (`durationMs: 0` opts out); `dismissToast(id)` and `clearToasts()` for manual control.
- `Components/UI/ToastStack.vue` — fixed bottom-right stack, animated enter/leave, dismiss button, `aria-live="polite"` region, `role="alert"`/`role="status"` per type.
- `AppLayout.vue` watches the shared `flash` prop (`{ success, error }`) and feeds it into `addToast` — the static banner markup was deleted entirely.
- **Design tokens, not hardcoded colors:** two new semantic pairs added to `resources/css/app.css` — `--color-success-surface/border/text` and `--color-danger-surface/border/text` — replacing the raw `green-50`/`rose-50` Tailwind classes the old banners used. `ToastStack` and `ConfirmDialog`'s error text consume these tokens exclusively.

**Tests:** `useToasts.spec.ts` (Vitest, 6 tests) — add/dismiss/auto-dismiss-after-default-duration/no-auto-dismiss-when-durationMs-is-0/multiple-simultaneous-toasts/clearToasts-cancels-pending-timers.

---

## Test infrastructure added

No JS test runner existed before this slice. Added:
- `happy-dom` (new dev dependency) as the Vitest DOM environment.
- `vitest.config.ts` — Vue plugin, `@` path alias matching `vite.config.ts`, `happy-dom` environment, globs `resources/js/**/*.spec.ts`.
- `npm test` script (`vitest run`).

This is now the home for any future component/composable test in this project — the three new spec files (`ApproveActions.spec.ts`, `CompanySwitcher.spec.ts`, `useToasts.spec.ts`) are the first tenants.

---

## Files touched

**New:** `Components/UI/ConfirmDialog.vue`, `Components/UI/ToastStack.vue`, `Components/App/CompanySwitcher.vue`, `composables/useToasts.ts` (+ spec), `ApproveActions.spec.ts`, `CompanySwitcher.spec.ts`, `vitest.config.ts`, `tests/Feature/App/CompanySelectorControllerTest.php`.

**Changed:** `HandleInertiaRequests.php` (lazy `company`/`companies` shared props), `types/index.ts` (`CompanyOption`, `SharedProps.companies`), `AppLayout.vue` (persistent-layout-ready active state, switcher, toast host, flash→toast watcher, static banner removed), `app.css` (semantic tokens), `ApproveActions.vue` (confirmation flow), `Recommendations/Show.vue` (passes `content_assets` to `ApproveActions`), 12 `Pages/App/**` files (persistent layout), `SummaryCard.vue` + 9 other files (`<a>` → `<Link>`), `MiddlewareTest.php` (+3), `RecommendationControllerTest.php` (+1), `package.json` (`happy-dom`, `test` script).
