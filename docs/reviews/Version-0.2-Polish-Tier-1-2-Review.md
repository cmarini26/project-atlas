# Version 0.2 Polish — Tier 1 & 2 Implementation Review

**Date:** 2026-06-27
**Scope:** Tier 1 (trust blockers) and Tier 2 (clarity gaps) from `docs/plans/Version-0.2-Polish.md`
**Files modified:** 16 frontend files, 1 backend controller
**Tests:** 579 passing (581 total, 2 Redis skipped)
**PHPStan:** Level 8 — 0 errors
**Pint:** Clean
**Build:** 129 modules, 0 errors

---

## Tier 1 — Trust Blockers

### T1-1: HealthCard active DigitalTwin status

**Problem:** `HealthCard.vue` and `Brain.vue` had status label maps containing `crawling`, `analyzing`, and `ready` — values that don't exist in the database. Only `initializing` and `active` are valid. Every onboarded customer saw raw "active" in gray.

**Fix:**
- `HealthCard.vue` — labels now: `initializing: 'Getting started…'`, `active: 'Active'`, `error: 'Needs attention'`; colors: `active: 'text-emerald-600'`, `initializing: 'text-[var(--color-text-muted)]'`, `error: 'text-rose-600'`
- `Brain.vue` — same correction applied to `twinStatusLabels` and `twinStatusVariants`

**Decision:** Kept the label map minimal (3 entries) matching actual DB enum values rather than adding speculative future states.

---

### T1-2: Onboarding completion redirect

**Problem:** Onboarding Status.vue redirected to `/app` (dashboard) when complete, instead of the first pending recommendation.

**Fix:**
- `OnboardingStatusController.php` — added `first_recommendation_id` to JSON response; queries first pending recommendation for the company
- `Status.vue` — routes to `/app/recommendations/{id}` when `recommendation_count > 0` and `first_recommendation_id` is set; falls back to `/app` if no recommendation yet
- Polling interval corrected to 5000ms (was 4000ms in previous implementation)
- Hard stop after 10 minutes of polling

---

### T1-3: Raw enum values in badges

**Problem:** Opportunity type badges showed `featured_item`, campaign status badges showed `draft`, learning signals showed `channel_outperformed`, etc.

**Fix:** Label maps added to each page:
- `Opportunities.vue` — `typeLabels`: `featured_item → 'Featured Item'`, `urgency_promotion → 'Urgency Promotion'`, `new_arrival → 'New Arrival'`, `re_engagement → 'Re-engagement'`
- `Campaigns/Index.vue` and `Campaigns/Show.vue` — `statusLabels`: all 6 campaign statuses
- `Campaigns/Show.vue` — `executionStatusLabels`: all 7 execution statuses
- `Learning.vue` — `signalLabels`: all 11 signal types; `sourceTypeLabels`: 3 source types

---

### T1-4: Analytics metric keys

**Problem:** `Analytics/Show.vue` displayed raw keys like `normalised_reach`, `open_count`, `bounce_hard_count` to the user.

**Fix:** `metricLabels` map covers all normalised and platform-specific keys. `labelMetricKey()` function with titleCase fallback for unmapped keys. Applied to expected_impact, actual_kpis, and channel breakdown displays.

---

## Tier 2 — Clarity Gaps

### T2-1: Edit & Approve visible action

**Problem:** "Edit & Approve" was documented in the design system as a secondary button but did not exist as a button — the flow was only accessible by clicking "Edit" on a content preview card.

**Fix:**
- `ApproveActions.vue` — "Edit & Approve" button added alongside "Approve" and "Not this time"; emits `editAndApprove` event
- `Recommendations/Show.vue` — listens for `@edit-and-approve` and calls `startEdit(content_assets[0])`

**Decision:** The "Edit & Approve" button is the secondary action (between primary Approve and tertiary Not this time), matching the design system's button hierarchy.

---

### T2-2: Approval explanatory copy

**Problem:** No copy explained what approving actually does.

**Fix:** Added below the action buttons in `ApproveActions.vue`: "Approving queues this content for publishing. You can make edits before approving if anything needs changing."

---

### T2-3 + T2-4: Score bar colors + ARIA

**Problem:** Score bars used a fixed `bg-indigo-500` regardless of score value. No ARIA roles.

**Fix:** `ScoreBar.vue` fully rewritten:
- Color scale: 0–39 → `bg-red-400`, 40–59 → `bg-orange-400`, 60–74 → `bg-yellow-400`, 75–89 → `bg-green-400`, 90+ → `bg-emerald-400`
- `role="progressbar"` with `aria-valuenow`, `aria-valuemin`, `aria-valuemax`
- Screen-reader `.sr-only` span with full text description
- Numeric score always visible in `tabular-nums` span

---

### T2-5: Opportunity expiry treatment

**Problem:** Expiry was shown as a raw calendar date with no urgency signaling.

**Fix:** `Opportunities.vue` — `formatTimeRemaining()` function returns structured object:
- `< 24h` → `{ text: 'Expires in Xh Ym', urgency: 'rose' }`
- `24–48h` → `{ text: 'Expires in Xh Ym', urgency: 'amber' }`
- `2–7 days` → `{ text: 'Expires in X days', urgency: 'none' }`
- `> 7 days` → `{ text: 'Expires Jun 30', urgency: 'none' }`

Urgency classes: rose → `text-rose-700 font-medium`, amber → `text-amber-700 font-medium`.

---

### T2-6: Page title tags

**Problem:** No `<title>` tags on any page — browser tabs all showed the default "Atlas".

**Fix:** `<Head><title>Page Name — Atlas</title></Head>` added to all 16 app pages. The `title` formatter in `app.ts` (`title ? \`${title} — Atlas\` : 'Atlas'`) means the page title inside `<Head>` is used as-is (already formatted).

Pages covered: Dashboard, Recommendations/Index, Recommendations/Show, Opportunities, Brain, Campaigns/Index, Campaigns/Show, Publishing, Analytics/Index, Analytics/Show, Learning, Settings, Onboarding/Index, Onboarding/Status, Auth/Login, Auth/Register.

---

### T2-7: Mobile page padding

**Problem:** `<main>` in `AppLayout.vue` used `px-8` on all screen sizes, leaving no breathing room on mobile.

**Fix:** Changed to `px-4 py-6 lg:px-8`. Flash message wrapper also updated from `px-8` to `px-4 lg:px-8`.

---

### T2-8: Inertia page transition

Already implemented. `progress: { color: '#6366f1' }` in `createInertiaApp()` calls `setupProgress` from `@inertiajs/core`. No additional work needed.

---

### T2-9: Inline approval errors

**Problem:** If the approve or reject Inertia request failed (network error, server error), the form silently did nothing.

**Fix:** `ApproveActions.vue` — `approveError` and `rejectError` refs initialized to `null`; `onError` callbacks set them to "Something went wrong. Please try again."; error paragraphs rendered with `role="alert"` when non-null.

---

### T2-10: Form label typography

**Problem:** Form labels used `text-sm font-medium text-[var(--color-text-secondary)]` — indistinguishable from body text and inconsistent with the design system's label spec.

**Fix:** All form labels in 4 pages updated to `text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest`. Pages: `Auth/Login.vue`, `Auth/Register.vue`, `Onboarding/Index.vue`, `App/Settings.vue`.

---

### T2-11: Health score in HealthCard

**Problem:** The HealthCard showed twin status and fact counts but not the computed health score.

**Fix:**
- `HealthCard.vue` — `twin_health_score` prop added; computed `score` (0 when null); computed `healthLabel` ("Healthy" ≥80, "Building" ≥50, "Learning" <50); health row added: score number + colored label
- `Dashboard.vue` — passes `health.twin_health_score` to HealthCard

The `twin_health_score` was already returned by `DashboardController` in the `health` object but was not being forwarded to the component.

---

### T2-12: Business Brain nav label

**Problem:** Nav link showed "Brain" instead of "Business Brain".

**Fix:** `AppLayout.vue` navLinks — `name: 'Brain'` → `name: 'Business Brain'`.

---

### T2-13: Rationale text size

**Problem:** The rationale quadrant text (the most important reading on the platform per the design system) used `text-sm`, not the specified `text-body-lg` (16px/26px).

**Fix:** `RationaleCard.vue` body `<p>` — `text-sm` → `text-base leading-relaxed`.

---

### T2-14: Onboarding timeout message

**Problem:** If Atlas took longer than expected to process a new business, the Status page showed an infinite spinner with no context.

**Fix:** `Status.vue` — `startTime = Date.now()` on mount; `isTimedOut` computed as `Date.now() - startTime > 5 * 60 * 1000`; when timed out and no recommendation yet, shows guidance message with suggestions (check integration, contact support, or proceed to dashboard).

---

## What Was Not Changed

- **Tier 3 items** — no empty state CTA links, no favicon, no focus ring improvements, no campaign lifecycle trail, no nav active state fix for Settings
- **No new backend intelligence** — all changes are pure frontend polish
- **No new AI capabilities** — nothing touched in the AI or analytics pipeline
- **No database migrations** — all changes are Vue component and layout updates plus one controller method addition

---

## Files Modified

| File | Changes |
|------|---------|
| `backend/app/Http/Controllers/Api/OnboardingStatusController.php` | Added `first_recommendation_id` to response |
| `resources/js/Layouts/AppLayout.vue` | Brain → Business Brain; mobile padding |
| `resources/js/Components/Dashboard/HealthCard.vue` | Status labels fix; health score display |
| `resources/js/Components/UI/ScoreBar.vue` | Dynamic colors; ARIA roles |
| `resources/js/Components/Recommendations/RationaleCard.vue` | Rationale text size |
| `resources/js/Components/Recommendations/ApproveActions.vue` | Edit & Approve button; explanatory copy; inline errors |
| `resources/js/Pages/App/Dashboard.vue` | Pass `twin_health_score` to HealthCard; Head tag |
| `resources/js/Pages/App/Recommendations/Index.vue` | Head tag |
| `resources/js/Pages/App/Recommendations/Show.vue` | Handle `@edit-and-approve`; Head tag |
| `resources/js/Pages/App/Opportunities.vue` | Type labels; expiry treatment; Head tag |
| `resources/js/Pages/App/Brain.vue` | Status labels fix; Head tag |
| `resources/js/Pages/App/Campaigns/Index.vue` | Status labels; Head tag |
| `resources/js/Pages/App/Campaigns/Show.vue` | Status + execution labels; Head tag |
| `resources/js/Pages/App/Publishing.vue` | Head tag |
| `resources/js/Pages/App/Analytics/Index.vue` | Head tag |
| `resources/js/Pages/App/Analytics/Show.vue` | Metric labels; Head tag |
| `resources/js/Pages/App/Learning.vue` | Signal + source labels; Head tag |
| `resources/js/Pages/App/Settings.vue` | Form label typography; Head tag |
| `resources/js/Pages/Onboarding/Index.vue` | Form label typography; Head tag |
| `resources/js/Pages/Onboarding/Status.vue` | Redirect to recommendation; timeout message |
| `resources/js/Pages/Auth/Login.vue` | Form label typography; Head tag |
| `resources/js/Pages/Auth/Register.vue` | Form label typography; Head tag |
