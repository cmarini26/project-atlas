# Version 0.2 Polish Plan

**Goal:** Close the gap between a working product and a trustworthy product.  
**Constraint:** Do not implement new AI capabilities or new marketing features.  
**Source:** [Product-Validation-Review.md](../reviews/Product-Validation-Review.md)  
**Time estimate:** ~12 working days for all tiers.

---

## What This Is

The product validation review identified 24 distinct issues across 20 areas. Most are not feature gaps — they are presentation gaps that materially affect whether a first-time customer trusts the product and completes a first action.

This plan sequences those fixes into three tiers:

- **Tier 1 — Trust blockers (do first):** Issues that silently misrepresent the product's state. A customer who sees these will doubt whether Atlas is working at all.
- **Tier 2 — Clarity gaps (do second):** Issues that require extra effort from the customer to figure out. Atlas should be effortless.
- **Tier 3 — Polish (do last):** Issues that make the product feel rough but do not impede a customer's ability to complete a task.

---

## Tier 1 — Trust Blockers

Estimated: ~2.5 days

### T1-1 — Fix HealthCard for active DigitalTwin status

**Issue:** 5.1 — Active DigitalTwin status not handled. An active twin shows raw string `"active"` in gray text. The dashboard brain card looks broken for every onboarded customer.

**Fix:**
- In `Components/Dashboard/HealthCard.vue`: replace the `statusLabels` / `statusColors` maps to include `active: { label: 'Active', color: 'text-emerald-600' }` and `initializing: { label: 'Setting up', color: 'text-amber-600' }`. Remove the non-existent enum values (`crawling`, `analyzing`, `ready`).
- This is the single highest-priority fix in the entire product.

**Effort:** 30 min

---

### T1-2 — Fix onboarding redirect to first recommendation

**Issue:** 1.1 — Status page routes to `/app` (dashboard), not `/app/recommendations/{id}`.

**Fix:**
- `OnboardingStatusController` (or the backing service): return `first_recommendation_id` in the status JSON when `recommendation_count > 0`.
- `Pages/Onboarding/Status.vue`: change `router.visit('/app')` to `router.visit('/app/recommendations/' + status.value.first_recommendation_id)`.
- The first impression of Atlas should be the first recommendation, not a dashboard with summary counts that don't yet mean anything.

**Effort:** 2 hours

---

### T1-3 — Translate raw enum values in all badges

**Issues:** 6.1 (opportunity type), 7.2 (campaign status), 9.1 (learning signals)

**Fix:**
- `Pages/App/Opportunities.vue`: add `typeLabels` map (`featured_item → 'Featured Item'`, `urgency_promotion → 'Urgency Promotion'`, `new_arrival → 'New Arrival'`, `re_engagement → 'Re-engagement'`).
- `Pages/App/Campaigns/Index.vue` + `Show.vue`: add `statusLabels` map matching UserFlows §5 state reference.
- `Pages/App/Learning.vue`: add `signalLabels` map for all 11 signal types.
- Pattern: `{{ map[value] ?? value }}` — always falls back to the raw value if a new enum is added in future.

**Effort:** 2 hours

---

### T1-4 — Translate analytics metric keys

**Issue:** 8.1 — `normalised_reach`, `normalised_engagement_rate` etc. displayed as-is in analytics.

**Fix:**
- `Pages/App/Analytics/Show.vue`: add `metricLabels` map and render `metricLabels[key] ?? key` in the metric display.

**Effort:** 1 hour

---

## Tier 2 — Clarity Gaps

Estimated: ~5.5 days

### T2-1 — Make "Edit & Approve" a visible action

**Issue:** 4.1 — "Edit & Approve" exists in the code path but is only accessible via a small link inside ContentPreview, not as a button in the decision section.

**Fix:**
- `Components/Recommendations/ApproveActions.vue`: add a secondary button "Edit & Approve" between Approve and Not this time. Clicking it should emit an `edit-and-approve` event that the parent `Show.vue` handles by calling `startEdit()` on the first content asset, then setting a flag that triggers approval on `saveEdit()` success.
- Desktop layout: `[Approve]` + `[Edit & Approve]` + `Not this time` link.
- Mobile layout: `[Approve]` full width, `[Edit & Approve]` full width, `Not this time` centered link.

**Effort:** 3 hours

---

### T2-2 — Add explanatory copy to the approval section

**Issue:** 4.2 — No copy explains what happens when "Approve" is clicked.

**Fix:**
- `Components/Recommendations/ApproveActions.vue`: add a single sentence below the buttons: `"Approving will queue this content for publishing. You can cancel before it goes out."` Style: `text-xs text-[var(--color-text-muted)]`.

**Effort:** 30 min

---

### T2-3 — Fix score bar colors

**Issue:** 6.3 — All score bars are indigo regardless of value. A low score looks identical to a high score.

**Fix:**
- `Components/UI/ScoreBar.vue`: add a computed `fillColor` based on `score` prop:
  - 90+ → `bg-emerald-400`
  - 75–89 → `bg-green-400`
  - 60–74 → `bg-yellow-400`
  - 40–59 → `bg-orange-400`
  - 0–39 → `bg-red-400`
- Apply `fillColor` as a dynamic class on the fill div.

**Effort:** 1 hour

---

### T2-4 — Add ARIA roles to score bars

**Issue:** 15.1 — Score bars have no `role="progressbar"` or `aria-value*` attributes.

**Fix:**
- `Components/UI/ScoreBar.vue`: add `role="progressbar"`, `:aria-valuenow="score"`, `aria-valuemin="0"`, `:aria-valuemax="max ?? 100"` to the outer div. Add `<span class="sr-only">{{ score }} out of {{ max ?? 100 }}</span>`.
- Do this at the same time as T2-3 (same file).

**Effort:** 30 min (combine with T2-3)

---

### T2-5 — Fix opportunity expiry treatment

**Issue:** 6.2 — Shows "Expires Jun 14" instead of "Expires in 47h 12m" with urgency colors.

**Fix:**
- `Pages/App/Opportunities.vue`: replace `formatDate(opp.expires_at)` with `formatTimeRemaining(expiresAt)`:
  - Returns `"Expires in Xh Ym"` for < 48 hours
  - Returns `"Expires in X days"` for 2–7 days
  - Returns calendar date only for > 7 days
- Apply amber text for < 48h, rose text for < 24h (matching Design System §13).

**Effort:** 2 hours

---

### T2-6 — Add page `<title>` tags

**Issue:** 20.1 — All pages show the same default title. Tabs, bookmarks, and screen readers cannot distinguish pages.

**Fix:**
- Import `{ Head }` from `@inertiajs/vue3` in every page component.
- Add `<Head><title>{{ pageTitle }} — Atlas</title></Head>`.
- Titles per page: Dashboard → "Dashboard", Recommendations → "Recommendations", etc. Show pages include the entity name.
- This can be done as a batch pass across all 15 page files.

**Effort:** 2 hours

---

### T2-7 — Fix mobile page padding

**Issue:** 16.1 — `px-8` (32px) on all breakpoints leaves very little content width on mobile.

**Fix:**
- `Layouts/AppLayout.vue`: change `px-8 py-6` to `px-4 py-6 lg:px-8`.
- Single-line change. High impact on mobile experience.

**Effort:** 15 min

---

### T2-8 — Add Inertia page transition progress indicator

**Issue:** 17.1 — No visual feedback during page navigation. Users don't know if their click registered.

**Fix:**
- Install `nprogress` package (`npm install nprogress`).
- Wire in `app.ts`: `router.on('start', () => NProgress.start())`, `router.on('finish', () => NProgress.done())`.
- Add NProgress CSS with `accent-500` bar color to `app.css`.

**Effort:** 1 hour

---

### T2-9 — Fix inline approval error handling

**Issue:** 12.1 — If the approve/reject POST fails, the button silently re-enables with no message.

**Fix:**
- `Components/Recommendations/ApproveActions.vue`: add `onError` callback to the `approveForm.post()` and `rejectForm.post()` calls. On error, show an inline red message: `"Something went wrong — please try again."` with a retry hint.

**Effort:** 2 hours

---

### T2-10 — Fix form label typography

**Issue:** 13.1 — All form labels are 14px, secondary color, and not uppercase. Design system specifies 12px, muted, uppercase, 0.06em letter-spacing.

**Fix:**
- Update all form labels in: `Pages/Auth/Login.vue`, `Pages/Auth/Register.vue`, `Pages/Onboarding/Index.vue` (all 3 steps), `Pages/App/Settings.vue`.
- Change `text-sm font-medium text-[var(--color-text-secondary)]` to `text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest`.

**Effort:** 1 hour

---

### T2-11 — Add health score to HealthCard

**Issue:** 5.2 — The Business Brain card shows status text but not the health score (0–100) + label.

**Fix:**
- `Components/Dashboard/HealthCard.vue`: add `twin_health_score: number | null` to the `Health` interface.
- Verify `DashboardController` already returns this field (it does — check the response shape).
- Add a score display: `{{ score }}/100` + label based on score range: 80+ → "Healthy", 50–79 → "Building", <50 → "Learning".

**Effort:** 2 hours

---

### T2-12 — Fix "Business Brain" nav label

**Issue:** 2.1 — Nav reads "Brain" not "Business Brain".

**Fix:**
- `Layouts/AppLayout.vue`: change `name: 'Brain'` to `name: 'Business Brain'`.

**Effort:** 5 min

---

### T2-13 — Fix rationale text size

**Issue:** 18.2 — Rationale body text is `text-sm` (14px). Spec says `text-base` (16px) with `leading-relaxed`.

**Fix:**
- `Components/Recommendations/RationaleCard.vue`: change `text-sm` on the rationale value `<p>` to `text-base leading-relaxed`.

**Effort:** 15 min

---

### T2-14 — Add timeout message to status page

**Issue:** 1.2 — No ">5 min" message for slow pipelines.

**Fix:**
- `Pages/Onboarding/Status.vue`: track `startTime = Date.now()` on mount. If elapsed time > 5 minutes and not yet complete, replace the spinner area with: "This is taking a moment. Atlas is doing a thorough analysis. You can leave this page — we'll notify you when the first recommendation is ready." Stop polling after 10 minutes.

**Effort:** 1 hour

---

## Tier 3 — Polish

Estimated: ~4 days

### T3-1 — Add campaign lifecycle progress trail

**Issue:** 7.1 — No visual trail showing Draft → Approved → Queued → Executing → Published.

**Fix:**
- Create `Components/Campaign/CampaignTrail.vue`: 5 circles connected by lines. Completed steps = filled `bg-accent-500`. Current step = filled with a pulse ring. Future steps = open circle with `border-[var(--color-border)]`.
- Add to `Pages/App/Campaigns/Show.vue` (detail) and optionally to `Index.vue` (compact single-row version).

**Effort:** 4 hours

---

### T3-2 — Add contextual icons to empty states

**Issue:** 10.1 — All empty states use the default three-dot icon. Contextual icons communicate category.

**Fix:**
- Pass contextually appropriate Heroicons via the `<template #icon>` slot to each `<EmptyState>` usage:
  - Opportunities → `LightBulbIcon`
  - Business Brain → `CpuChipIcon`
  - Campaigns → `MegaphoneIcon`
  - Learning → `AcademicCapIcon`
  - Analytics → `ChartBarIcon`
  - Recommendations → `InboxIcon`

**Effort:** 1 hour

---

### T3-3 — Add CTA to empty states that need user action

**Issue:** 10.2 — Brain and Opportunities empty states have no CTA when integration is missing.

**Fix:**
- `Pages/App/Brain.vue` and `Pages/App/Opportunities.vue`: if `integration_count === 0` in props, show "Connect your website → /app/settings" CTA button on the empty state.

**Effort:** 2 hours

---

### T3-4 — Fix Settings active state in nav

**Issue:** 2.2 — Settings link has no active state.

**Fix:**
- `Layouts/AppLayout.vue`: apply `isActive('/app/settings')` conditional class to the Settings nav link, matching the same active state classes used in `navLinks`.

**Effort:** 30 min

---

### T3-5 — Register design system token blocks in app.css

**Issues:** 19.1, 19.2 — Typography tokens and status color tokens are specified in `docs/design/System.md` but not added to `resources/css/app.css`.

**Fix:**
- Copy the full `@theme {}` token additions from `docs/design/System.md` Appendix A into `resources/css/app.css`.
- This enables all future component development to use `text-display`, `text-heading-1`, and `bg-[var(--color-status-open-bg)]` without adding them ad-hoc.
- No page-level changes needed immediately — this is infrastructure for future consistency.

**Effort:** 2 hours

---

### T3-6 — Add no-skeleton loading fallback (NProgress only for now)

**Issue:** 11.1 — No skeleton loading screens.

**Note:** Full skeleton screens are a significant investment. The NProgress bar (T2-8) addresses the most visible symptom. For V0.2 Polish, the minimum viable improvement is:
- NProgress bar on navigation (T2-8 — already in Tier 2)
- `aria-busy="true"` on the main content area during navigation

Full skeleton components are deferred to a later sprint unless user research shows this is a high-friction moment.

**Effort:** 30 min (aria-busy only — NProgress in T2-8)

---

### T3-7 — Add explicit focus rings to buttons

**Issue:** 15.2 — Buttons lack explicit `focus-visible:ring-*` classes.

**Fix:**
- Add a global button focus style to `app.css`: `button:focus-visible, a:focus-visible { outline: 2px solid var(--color-accent-500); outline-offset: 2px; }`. This applies across all interactive elements without editing every individual component.
- Or, add `focus-visible:ring-2 focus-visible:ring-[var(--color-accent-500)] focus-visible:ring-offset-2` to the shared button class in any future `Button.vue` component.

**Effort:** 30 min (global CSS approach)

---

### T3-8 — Fix primary button color

**Issue:** 18.1 — Primary buttons use `accent-600` default instead of `accent-500`.

**Fix:**
- Replace `bg-[var(--color-accent-600)]` with `bg-[var(--color-accent-500)]` and `hover:bg-[var(--color-accent-700)]` with `hover:bg-[var(--color-accent-600)]` on all primary buttons across: Auth pages, Onboarding pages, ApproveActions, ContentEditor.

**Effort:** 1 hour

---

### T3-9 — Fix isActive() to use Inertia page URL

**Issue:** 20.3 — `window.location.pathname` bypasses Vue reactivity.

**Fix:**
- `Layouts/AppLayout.vue`: import `usePage` from `@inertiajs/vue3`. Add `const page = usePage()`. Change `window.location.pathname` in `isActive()` to `page.url`.

**Effort:** 30 min

---

### T3-10 — Add rejection field label

**Issue:** 4.3 — Rejection textarea label should read "Help Atlas learn (optional)" not "Optional: tell Atlas why".

**Fix:**
- `Components/Recommendations/ApproveActions.vue`: add `<label>` element above the textarea with text "Help Atlas learn (optional)". Remove "Optional" from the placeholder.

**Effort:** 15 min

---

### T3-11 — Add favicon

**Issue:** 20.2 — No favicon.

**Fix:**
- Create `public/favicon.svg`: simple SVG with an "A" letterform in indigo-500 on a transparent background.
- Add `<link rel="icon" href="/favicon.svg" type="image/svg+xml">` to `resources/views/app.blade.php`.

**Effort:** 1 hour

---

### T3-12 — Rename "Publishing" to "Publishing Queue"

**Issue:** 2.3 — "Publishing" is ambiguous. The page is a read-only queue view.

**Fix:**
- `Layouts/AppLayout.vue`: change `name: 'Publishing'` to `name: 'Publishing Queue'`.
- `Pages/App/Publishing.vue`: update the `<h1>` from "Publishing" to "Publishing Queue".

**Effort:** 15 min

---

### T3-13 — Add analytics empty state CTA

**Issue:** 8.2 — Analytics empty state has no link to recommendations.

**Fix:**
- `Pages/App/Analytics/Index.vue`: update the EmptyState `description` to include: "Analytics will appear here once your first campaign runs." Add `<template #action><a href="/app/recommendations">Review your first recommendation →</a></template>` to the EmptyState slot.

**Effort:** 30 min

---

### T3-14 — Fix Settings sync scroll reset

**Issue:** 13.2 — Integration sync button redirects the page and resets scroll position.

**Fix:**
- `Pages/App/Settings.vue`: add `preserveScroll: true` and `preserveState: true` to the sync form submission options.

**Effort:** 30 min

---

## Implementation Order

| Priority | Issue | File | Effort |
|----------|-------|------|--------|
| **T1** (trust blockers) | | | **~2.5 days** |
| T1-1 | Fix HealthCard active status | `HealthCard.vue` | 30 min |
| T1-2 | Redirect to recommendation after onboarding | `Status.vue` + Controller | 2h |
| T1-3 | Translate raw enum values in all badges | Opp, Campaign, Learning pages | 2h |
| T1-4 | Translate analytics metric keys | `Analytics/Show.vue` | 1h |
| **T2** (clarity gaps) | | | **~5.5 days** |
| T2-12 | "Business Brain" nav label | `AppLayout.vue` | 5 min |
| T2-7 | Mobile padding fix | `AppLayout.vue` | 15 min |
| T2-13 | Rationale text size | `RationaleCard.vue` | 15 min |
| T2-4 | Score bar ARIA | `ScoreBar.vue` | 30 min |
| T2-3 | Score bar colors | `ScoreBar.vue` | 1h |
| T2-10 | Form label typography | Auth + Onboarding + Settings | 1h |
| T2-8 | NProgress page transition | `app.ts` | 1h |
| T2-11 | Health score display | `HealthCard.vue` | 2h |
| T2-6 | Page titles | All 15 pages | 2h |
| T2-9 | Inline approval error | `ApproveActions.vue` | 2h |
| T2-5 | Opportunity expiry treatment | `Opportunities.vue` | 2h |
| T2-2 | Approval explanatory copy | `ApproveActions.vue` | 30 min |
| T2-1 | Edit & Approve button | `ApproveActions.vue` + `Show.vue` | 3h |
| T2-14 | Onboarding timeout message | `Status.vue` | 1h |
| **T3** (polish) | | | **~4 days** |
| T3-9 | isActive Inertia URL fix | `AppLayout.vue` | 30 min |
| T3-4 | Settings active state | `AppLayout.vue` | 30 min |
| T3-12 | "Publishing Queue" rename | `AppLayout.vue` + page | 15 min |
| T3-8 | Primary button color fix | Multiple pages | 1h |
| T3-7 | Focus rings | `app.css` | 30 min |
| T3-10 | Rejection label | `ApproveActions.vue` | 15 min |
| T3-13 | Analytics empty state CTA | `Analytics/Index.vue` | 30 min |
| T3-14 | Settings scroll reset | `Settings.vue` | 30 min |
| T3-2 | Empty state icons | All pages with EmptyState | 1h |
| T3-3 | Empty state CTAs | `Brain.vue`, `Opportunities.vue` | 2h |
| T3-5 | Design system tokens in app.css | `app.css` | 2h |
| T3-6 | aria-busy during navigation | `AppLayout.vue` | 30 min |
| T3-11 | Favicon | `public/` + `app.blade.php` | 1h |
| T3-1 | Campaign lifecycle trail | New `CampaignTrail.vue` | 4h |

---

## Not Included

The following issues from the validation review are explicitly deferred:

- **Full skeleton loading screens** (11.1) — NProgress bar addresses the most visible symptom. Full skeleton components are a significant investment for marginal gain at this stage. Revisit when the product is in customer hands and slowness is confirmed as a friction point.
- **Nav item grouping** (3.1) — the 8-item flat list is functional. Grouping is a visual design decision that should wait for actual customer navigation patterns to inform it.
- **Learning signal descriptions** (9.1) — the Learning feed is not a primary workflow for Marcus. Cleaning up signal labels is valuable but low-urgency relative to Tier 1/2 work.

---

## Definition of Done

Version 0.2 Polish is complete when:

- All Tier 1 issues are resolved
- All Tier 2 issues are resolved
- At least Tier 3 items T3-1 through T3-8 are complete
- `npm run build` produces 0 errors
- `php artisan test` passes (no regressions introduced)
- PHPStan level 8 — 0 errors
- A first-time customer can: register → onboard → be redirected to their first recommendation → read the rationale → approve → see a queued campaign — with no confusing raw values, broken status cards, or invisible actions
