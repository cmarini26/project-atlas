# Product Validation Review — Version 0.2

**Reviewer:** Engineering  
**Date:** 2026-06-27  
**Method:** Source code review against FOUNDING_PRINCIPLES.md, docs/design/System.md, docs/product/PRD.md, docs/product/Personas.md, and docs/product/UserFlows.md  
**Scope:** All customer-facing Vue pages and components (`resources/js/`)

---

## Executive Summary

The core pipeline is solid. The data model, backend services, and approval workflow are all functioning. The frontend covers every required page and the overall aesthetic is calm and on-brand.

However, there are **24 distinct issues** across the 20 review areas ranging from critical (active DigitalTwin status silently breaking the Business Brain card) to cosmetic (form label typography). Several issues directly affect the two design personas:

- **Marcus** is most affected by: broken Brain health card, raw enum values in badges, no explanatory text on the approve button, and missing approval consequence copy.
- **Sofia** is most affected by: the "Edit & Approve" action not being a visible primary button, rationale text being too small to read comfortably, and analytics pages showing normalised metric keys.

A single focused sprint (Version 0.2 Polish, ~12 working days) can address all high and medium severity issues before the first customer is onboarded.

---

## Review Area 1 — First-Time Onboarding

### Issue 1.1 — Status page auto-redirects to dashboard, not recommendation

| | |
|--|--|
| **Severity** | High |
| **File** | `Pages/Onboarding/Status.vue:32–35` |
| **Description** | When `recommendation_count > 0`, the status page calls `router.visit('/app')` (the dashboard) rather than routing directly to the first recommendation. |
| **Why it matters** | UserFlows §1 step 5 specifies: "Show 'View your recommendation →' button (primary) → Button navigates to /app/recommendations/{id}". Marcus arrives at a generic dashboard after onboarding and has to find the recommendation himself. The first impression of Atlas should be the first recommendation, not summary cards showing zeroes. |
| **Screenshot location** | `Pages/Onboarding/Status.vue` — the `fetchStatus()` function |
| **Recommended fix** | The `OnboardingStatusController` should return the first recommendation ID when `recommendation_count > 0`. Status.vue reads `status.value.first_recommendation_id` and routes to `/app/recommendations/{id}` instead of `/app`. |
| **Estimated effort** | 2 hours (1h backend, 1h frontend) |

---

### Issue 1.2 — No timeout message for slow pipelines

| | |
|--|--|
| **Severity** | Medium |
| **File** | `Pages/Onboarding/Status.vue` |
| **Description** | The status page has no handling for pipelines that run longer than expected. The page spins indefinitely with no user guidance after ~5 minutes. |
| **Why it matters** | UserFlows §1 specifies: "If the pipeline takes longer than expected (>5 min), show: 'This is taking a moment. Atlas is doing a thorough analysis. You can leave this page — we'll notify you when the first recommendation is ready.'" Without this, Marcus closes the tab assuming Atlas isn't working. |
| **Recommended fix** | Track `startTime` on mount. If `Date.now() - startTime > 5 * 60 * 1000`, replace the spinner with the specified message and a "Go to dashboard" link. Stop polling after 10 minutes. |
| **Estimated effort** | 1 hour |

---

### Issue 1.3 — Status polling interval does not match spec

| | |
|--|--|
| **Severity** | Low |
| **File** | `Pages/Onboarding/Status.vue:51` |
| **Description** | Polls every 4,000ms. UserFlows §1 specifies 5 seconds. |
| **Why it matters** | Minor spec drift. 4s creates more API requests per session with no meaningful UX improvement. |
| **Recommended fix** | Change `setInterval(() => { void fetchStatus() }, 4000)` to `5000`. |
| **Estimated effort** | 5 minutes |

---

## Review Area 2 — Navigation

### Issue 2.1 — "Brain" sidebar label should be "Business Brain"

| | |
|--|--|
| **Severity** | Medium |
| **File** | `Layouts/AppLayout.vue:17` |
| **Description** | The sidebar nav link reads `{ name: 'Brain', href: '/app/brain', icon: 'cpu' }`. |
| **Why it matters** | Every spec document (CLAUDE.md, PRD.md, Personas.md) uses "Business Brain" as the canonical term. "Brain" is ambiguous. Marcus doesn't know what a "Brain" is in this context. Consistency with product naming builds trust and recognition. |
| **Recommended fix** | Change `name: 'Brain'` to `name: 'Business Brain'`. |
| **Estimated effort** | 5 minutes |

---

### Issue 2.2 — Settings link has no active state

| | |
|--|--|
| **Severity** | Low |
| **File** | `Layouts/AppLayout.vue:111–118` |
| **Description** | Settings is rendered separately from `navLinks` and does not use the `isActive()` function. When on `/app/settings`, no sidebar item appears highlighted. |
| **Why it matters** | Users lose their sense of location. The sidebar should always indicate where they are. |
| **Recommended fix** | Add Settings to `navLinks` or apply `:class="isActive('/app/settings') ? 'bg-[var(--color-accent-50)] text-[var(--color-accent-600)] font-medium' : '…'"` directly on the Settings link. |
| **Estimated effort** | 30 minutes |

---

### Issue 2.3 — "Publishing" label is unclear for business owners

| | |
|--|--|
| **Severity** | Low |
| **File** | `Layouts/AppLayout.vue:20` |
| **Description** | The nav item reads "Publishing" but the page shows a queue of executions (campaign content waiting to go out, already sent, or failed). It is not a publishing tool — it is a status view. |
| **Why it matters** | Marcus clicks "Publishing" expecting to do something. He finds a read-only list. "Activity" or "Publishing Queue" better describes what the page shows. |
| **Recommended fix** | Change `name: 'Publishing'` to `name: 'Publishing Queue'` and update the page `<h1>` to match. |
| **Estimated effort** | 15 minutes |

---

## Review Area 3 — Information Architecture

### Issue 3.1 — 8 primary nav items is too many for Marcus

| | |
|--|--|
| **Severity** | Low |
| **File** | `Layouts/AppLayout.vue:13–22` |
| **Description** | The sidebar has 8 navigation items in a flat list: Dashboard, Recommendations, Opportunities, Business Brain, Campaigns, Publishing Queue, Analytics, Learning. |
| **Why it matters** | Personas.md: Marcus checks the dashboard "once or twice a week" and his primary interactions are 1) Review recommendation, 2) Check campaign status, 3) Occasionally read the Business Brain. Publishing Queue, Analytics, and Learning are secondary for Marcus. A flat 8-item list feels like a tool, not a calm intelligent assistant. |
| **Recommended fix** | Group nav items into two sections with a subtle divider or section label: Primary (Dashboard, Recommendations, Campaigns) and Insights (Opportunities, Business Brain, Analytics, Learning, Publishing Queue). This is visual grouping only — no routes change. |
| **Estimated effort** | 2 hours |

---

## Review Area 4 — Recommendation Workflow

### Issue 4.1 — "Edit & Approve" is not a visible action button

| | |
|--|--|
| **Severity** | High |
| **File** | `Components/Recommendations/ApproveActions.vue`, `Components/Recommendations/ContentPreview.vue` |
| **Description** | The `ApproveActions` component has two buttons: "Approve" (primary) and "Not this time" (ghost). The "Edit & Approve" action exists but is accessed via a small "Edit before approving" link inside `ContentPreview`. When the user is reading the rationale section (above content), there is no visible indication that editing is an option. |
| **Why it matters** | Personas.md: "Sofia edits content before approving in many cases." UserFlows Flow 3 explicitly specifies three actions with distinct visual weight: `[ Approve ]` (primary), `[ Edit & Approve ]` (secondary), `[ Not this time ]` (tertiary). Without a visible "Edit & Approve" button in the action section, Sofia either approves content she would prefer to modify, or discovers the hidden edit link by chance. This breaks a key workflow for the higher-engagement persona. |
| **Screenshot location** | `Pages/App/Recommendations/Show.vue` — the "Your decision" section |
| **Recommended fix** | Add an "Edit & Approve" secondary button to `ApproveActions.vue`. When clicked, it scrolls to (or opens) the first content asset in edit mode. The three-button layout: `[Approve]` full-width on mobile; desktop: `[Approve] [Edit & Approve] + Not this time` link. |
| **Estimated effort** | 3 hours |

---

### Issue 4.2 — No explanatory copy below the action buttons

| | |
|--|--|
| **Severity** | Medium |
| **File** | `Components/Recommendations/ApproveActions.vue` |
| **Description** | The action section ends with the approve/reject buttons. No text explains what happens when "Approve" is clicked. |
| **Why it matters** | UserFlows §2: "Always show one sentence of plain-language explanation: 'Approving will queue this email for publishing. You can cancel before it sends.'" Design System §12: "These are not legal disclaimers. They are conversational acknowledgments." Marcus explicitly will not approve something if the consequences are unclear (Personas.md). |
| **Recommended fix** | Add `<p class="text-xs text-[var(--color-text-muted)] mt-3">Approving will queue this content for publishing. You can cancel before it goes out.</p>` below the buttons in `ApproveActions.vue`. |
| **Estimated effort** | 30 minutes |

---

### Issue 4.3 — Rejection confirmation label wording

| | |
|--|--|
| **Severity** | Low |
| **File** | `Components/Recommendations/ApproveActions.vue:40` |
| **Description** | The rejection textarea placeholder reads `"Optional: tell Atlas why (helps it learn)"`. The button reads "Confirm: not this time". |
| **Why it matters** | UserFlows §4: the field label should be "Help Atlas learn (optional)" (not "Optional: tell Atlas why"). A subtle but meaningful framing difference — "Help Atlas learn" positions rejection as a positive contribution, not a required explanation. |
| **Recommended fix** | Add a label element above the textarea: `<label class="text-xs font-medium text-[var(--color-text-muted)]">Help Atlas learn (optional)</label>`. Remove "Optional" from the placeholder. |
| **Estimated effort** | 15 minutes |

---

## Review Area 5 — Business Brain Presentation

### Issue 5.1 — Active DigitalTwin status not handled in HealthCard

| | |
|--|--|
| **Severity** | Critical |
| **File** | `Components/Dashboard/HealthCard.vue:17–31` |
| **Description** | `statusLabels` and `statusColors` define keys: `initializing`, `crawling`, `analyzing`, `ready`, `error`. The DigitalTwin model's actual status enum allows only `initializing` and `active`. When a DigitalTwin is `active`, neither dict has a matching key. The component falls back to rendering the raw string `"active"` with `text-[var(--color-text-muted)]` (gray) — indistinguishable from an error. |
| **Why it matters** | Every customer with a functioning Business Brain sees an unlabeled "active" in gray text rather than a clear "Active" or "Healthy" indicator in green. This is the primary health indicator on the dashboard. A new customer onboarded successfully sees what appears to be a broken state. |
| **Screenshot location** | `Pages/App/Dashboard.vue` — the Business Brain card (right column) |
| **Recommended fix** | Replace the statusLabels/Colors maps with the actual enum values: `initializing: { label: 'Setting up', color: 'text-amber-600' }`, `active: { label: 'Active', color: 'text-emerald-600' }`. Remove keys that don't exist in the DB enum. |
| **Estimated effort** | 30 minutes |

---

### Issue 5.2 — Health score not displayed

| | |
|--|--|
| **Severity** | Medium |
| **File** | `Components/Dashboard/HealthCard.vue` |
| **Description** | The `Health` interface in `HealthCard.vue` does not include `twin_health_score`. The `DashboardController` does return health data. The health score (0–100) and its label ("Healthy", "Building", "Learning") are never shown. |
| **Why it matters** | UserFlows §7: "Health score is displayed numerically (0–100) and as a label: 'Healthy' (80+), 'Building' (50–79), 'Learning' (<50)." The health score is the single most useful signal for Sofia to explain Atlas's progress to a client. Without it, the Business Brain card just shows raw counts with no interpretive context. |
| **Recommended fix** | Add `twin_health_score: number | null` to the `Health` interface. Add a health score display below the status label: percentage bar + numeric label ("Healthy", "Building", "Learning"). |
| **Estimated effort** | 2 hours |

---

## Review Area 6 — Opportunity Presentation

### Issue 6.1 — Opportunity type badge shows raw enum value

| | |
|--|--|
| **Severity** | High |
| **File** | `Pages/App/Opportunities.vue:43` |
| **Description** | `<Badge variant="default">{{ opp.type }}</Badge>` renders the raw DB enum value: `featured_item`, `urgency_promotion`, `new_arrival`, `re_engagement`. |
| **Why it matters** | Marcus does not know what `featured_item` means. This is the first piece of information he sees on an opportunity card. Showing raw enum values is a direct violation of FOUNDING_PRINCIPLES.md §3 ("plain language") and a recurring trust-destroying pattern for non-technical users. |
| **Recommended fix** | Add a type label map: `const typeLabels: Record<string, string> = { featured_item: 'Featured Item', urgency_promotion: 'Urgency Promotion', new_arrival: 'New Arrival', re_engagement: 'Re-engagement' }` and render `{{ typeLabels[opp.type] ?? opp.type }}`. |
| **Estimated effort** | 30 minutes |

---

### Issue 6.2 — Expiry treatment is binary and unformatted

| | |
|--|--|
| **Severity** | Medium |
| **File** | `Pages/App/Opportunities.vue:18–21, 45–49` |
| **Description** | `isExpiringSoon()` returns true if expiry is within 7 days, then displays only `"Expires Jun 14"`. No time-remaining calculation. No urgency color escalation. |
| **Why it matters** | Design System §13: "< 48 hours → amber-700 'Expires in 47h 12m' + ClockIcon (amber), < 24 hours → rose-700 'Expires in 6h 30m' + ClockIcon (rose)". The opportunity-urgency signal is core to Atlas's value proposition for CBB Auctions ("your auction closes in 48 hours"). Displaying a calendar date instead of a countdown does not communicate urgency. |
| **Recommended fix** | Replace `formatDate(opp.expires_at)` with a time-remaining function that returns "47h 12m" for hours-based remaining, "3 days" for days-based. Apply amber color for < 48h, rose for < 24h. |
| **Estimated effort** | 2 hours |

---

### Issue 6.3 — Score bars are a single fixed color

| | |
|--|--|
| **Severity** | Medium |
| **File** | `Components/UI/ScoreBar.vue:13` |
| **Description** | `ScoreBar.vue` uses `bg-[var(--color-accent-500)]` (indigo) for all score values. A score of 15 and a score of 95 look identical. |
| **Why it matters** | Design System §13: "Fill color varies by value — 0–39: red-400, 40–59: orange-400, 60–74: yellow-400, 75–89: green-400, 90+: emerald-400." Color conveys quality at a glance. A low-confidence opportunity displayed in indigo looks as strong as a high-confidence one. Marcus cannot tell which opportunities are strong without reading the numbers. |
| **Recommended fix** | Compute fill color from `score` prop: `score >= 90 ? 'bg-emerald-400' : score >= 75 ? 'bg-green-400' : score >= 60 ? 'bg-yellow-400' : score >= 40 ? 'bg-orange-400' : 'bg-red-400'`. |
| **Estimated effort** | 1 hour |

---

## Review Area 7 — Campaign Presentation

### Issue 7.1 — No campaign lifecycle progress trail

| | |
|--|--|
| **Severity** | Medium |
| **File** | `Pages/App/Campaigns/Index.vue`, `Pages/App/Campaigns/Show.vue` |
| **Description** | Campaign cards show a status badge but no visual lifecycle indicator. |
| **Why it matters** | Design System §14 specifies a progress trail: "Draft → Approved → Queued → Executing → Completed/Published" with filled circles for completed steps and open circles for future steps. UserFlows §5: "For Marcus: the most important answer is 'Did it go out?' — make that visible at a glance." A linear progress trail answers this immediately. A status badge requires the user to understand the status vocabulary. |
| **Recommended fix** | Add a `CampaignTrail` component: 5 steps as circles connected by lines. Completed steps get `bg-accent-500`, current step gets `bg-accent-500 + pulse ring`, future steps get open circle. Show it on campaign cards in both Index and Show. |
| **Estimated effort** | 4 hours |

---

### Issue 7.2 — Campaign status badge uses raw enum values

| | |
|--|--|
| **Severity** | Medium |
| **File** | `Pages/App/Campaigns/Index.vue`, `Pages/App/Campaigns/Show.vue` |
| **Description** | Campaign status badges pass the raw DB enum value through Filament or directly from the controller without translation. The Filament table uses raw badge strings. |
| **Why it matters** | UserFlows §5 State Transitions Reference: `draft → "Draft"`, `approved → "Approved"`, `published → "Published"`. Most of these match the raw value, but the absence of a translation layer means any future enum change breaks the display, and edge cases (e.g. `cancelled`) get the raw value with no context. |
| **Recommended fix** | Add a `statusLabels` map in campaigns pages matching the UserFlows state reference table. Apply to badge display. |
| **Estimated effort** | 1 hour |

---

## Review Area 8 — Analytics Presentation

### Issue 8.1 — Normalised metric keys shown to user

| | |
|--|--|
| **Severity** | High |
| **File** | `Pages/App/Analytics/Show.vue` |
| **Description** | The campaign analytics show page renders execution metric data including `normalised_reach`, `normalised_engagement_rate` — raw internal property names — directly as labels. |
| **Why it matters** | UserFlows §6: "Never show raw normalised metric keys. Translate: `normalised_reach` → 'Estimated Reach', `normalised_engagement` → 'Engagements.'" Sofia expects professional-grade analytics labels. Showing `normalised_reach` signals that this is an internal debug view, not a polished product. |
| **Recommended fix** | Add a `metricLabels` map in `Analytics/Show.vue`: `{ normalised_reach: 'Estimated Reach', normalised_engagement_rate: 'Engagement Rate', normalised_clicks: 'Clicks', normalised_impressions: 'Impressions' }`. Render `metricLabels[key] ?? key` in the metric display loop. |
| **Estimated effort** | 1 hour |

---

### Issue 8.2 — Analytics empty state lacks link to recommendations

| | |
|--|--|
| **Severity** | Low |
| **File** | `Pages/App/Analytics/Index.vue` |
| **Description** | When no analytics data exists, the empty state shows a generic message. |
| **Why it matters** | UserFlows §6: "Empty state: 'Analytics will appear here once your first campaign runs. Your first recommendation is waiting for your approval.' → Link to /app/recommendations." The link is missing, which breaks the loop for Marcus (who lands on analytics without campaigns and has no clear path forward). |
| **Recommended fix** | Add a CTA link `<a href="/app/recommendations">Review your first recommendation →</a>` to the analytics empty state. |
| **Estimated effort** | 30 minutes |

---

## Review Area 9 — Learning Presentation

### Issue 9.1 — Learning signal values are raw and unexplained

| | |
|--|--|
| **Severity** | Medium |
| **File** | `Pages/App/Learning.vue` |
| **Description** | The learning feed shows raw `signal` values from the database (e.g., `user_rejected_channel`, `engagement_low`, `reach_exceeded`) without translation. The `subject_type` column shows internal model type strings. |
| **Why it matters** | Personas.md: "Sofia reads the Learning insights to track how Atlas is improving per client — she uses it to explain Atlas's value trajectory to clients." A feed of raw signal keys is a debug log, not a professional insight. Clients will not be impressed by `user_rejected_channel`. |
| **Recommended fix** | Add a `signalLabels` map: `{ user_rejected_channel: 'Recommendation rejected — wrong channel', reach_exceeded: 'Campaign exceeded reach target', engagement_low: 'Campaign underperformed on engagement' }`. Apply to the signal column. Add a brief description sentence for each signal type. |
| **Estimated effort** | 2 hours |

---

## Review Area 10 — Empty States

### Issue 10.1 — EmptyState component does not render an icon slot in most uses

| | |
|--|--|
| **Severity** | Low |
| **File** | `Components/UI/EmptyState.vue`, all pages using `<EmptyState>` |
| **Description** | `EmptyState.vue` has a named `icon` slot but most page usages do not pass an icon. The default icon is three dots — a generic placeholder that communicates nothing. |
| **Why it matters** | Design System §17: "Icon: 40px, text-muted — conveys the category of empty state." A lightbulb icon on an empty Opportunities page and an academic cap on an empty Learning page immediately communicate context. The dots icon does not. |
| **Recommended fix** | Each page that uses `<EmptyState>` should pass a contextually appropriate Heroicon via `<template #icon>`. Standard icons per section: Opportunities → LightBulbIcon, Brain → CpuChipIcon, Campaigns → MegaphoneIcon, Learning → AcademicCapIcon. |
| **Estimated effort** | 1 hour |

---

### Issue 10.2 — Action-required empty states lack CTAs

| | |
|--|--|
| **Severity** | Medium |
| **File** | `Pages/App/Brain.vue`, `Pages/App/Opportunities.vue` |
| **Description** | When the DigitalTwin is initializing (no facts), both Brain and Opportunities show a message but no CTA. |
| **Why it matters** | Design System §17 Category 2: "User action required: one CTA only — never offer multiple paths." If the integration is missing or paused, Marcus needs a direct link to Settings. If Atlas is working, no CTA is correct. But if Atlas needs user action (e.g., crawl failed), there should be a "Reconnect your website" button. |
| **Recommended fix** | For Brain page initializing state: if integration_count === 0, show CTA "Connect your website → /app/settings". If integration_count > 0, show "Atlas is learning — no action needed." |
| **Estimated effort** | 2 hours |

---

## Review Area 11 — Loading States

### Issue 11.1 — No skeleton loading screens

| | |
|--|--|
| **Severity** | Medium |
| **File** | All app pages |
| **Description** | No page implements skeleton loading. Inertia page transitions happen without any loading indicator on the content area. |
| **Why it matters** | Design System §18: "Do not show a full-page spinner — always use layout-matching skeletons." Without skeletons, slow page loads produce a blank content area or a jump from old to new content. This is especially noticeable on the dashboard (multiple data sources) and the recommendation detail page (rationale + assets + impacts). |
| **Recommended fix** | Add Inertia's `onStart`/`onFinish` progress hooks in `app.ts` with a top-bar loading indicator (NProgress pattern). For the recommendation detail page, add a card skeleton while the page loads. This is a reasonable MVP loading pattern that improves from the current blank flash. |
| **Estimated effort** | 4 hours |

---

## Review Area 12 — Error Handling

### Issue 12.1 — No inline error handling for approval/rejection failures

| | |
|--|--|
| **Severity** | Medium |
| **File** | `Components/Recommendations/ApproveActions.vue` |
| **Description** | If the `/approve` or `/reject` POST fails (server error, network issue, validation), Inertia re-renders the page with the flash error. The approve button just silently re-enables. |
| **Why it matters** | UserFlows §2: "If approval fails (server error), show an inline error with a retry option. Never show a raw error message." Marcus clicks Approve, nothing visible happens (the button re-enables), and he doesn't know whether his action was recorded. He may click again, producing duplicate requests, or assume Atlas is broken. |
| **Recommended fix** | Add `onError` callback to the `approveForm.post()` call. On error, show an inline red message below the buttons: "Something went wrong. Your recommendation was not approved — please try again." Add a `try again` button. |
| **Estimated effort** | 2 hours |

---

## Review Area 13 — Forms

### Issue 13.1 — Form labels do not follow Design System specification

| | |
|--|--|
| **Severity** | Medium |
| **File** | `Pages/Auth/Login.vue`, `Pages/Auth/Register.vue`, `Pages/Onboarding/Index.vue`, `Pages/App/Settings.vue` |
| **Description** | All form labels use `class="block text-sm font-medium text-[var(--color-text-secondary)] mb-1.5"`. |
| **Why it matters** | Design System §10: "Font size: 12px (text-label). Font weight: 500 (medium). Color: var(--color-text-muted). Text transform: uppercase. Letter spacing: 0.06em. Margin bottom: 6px." The current labels are 14px (text-sm), secondary color (darker), and not uppercase. This affects every form in the product and creates inconsistency with the design system's typographic hierarchy where labels are clearly subordinate to body text. |
| **Recommended fix** | Update all form labels to: `class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5"`. Update Register, Login, Onboarding (all 3 steps), and Settings. |
| **Estimated effort** | 1 hour |

---

## Review Area 14 — Settings

### Issue 14.1 — Integration sync feedback is a full page redirect

| | |
|--|--|
| **Severity** | Low |
| **File** | `Pages/App/Settings.vue` |
| **Description** | The "Sync" button on an integration submits a form that redirects to `/app/settings` with a flash message. |
| **Why it matters** | The user loses their scroll position and the page reloads. For a page with multiple integrations, this is disorienting. A sync operation that runs in the background should acknowledge immediately and not navigate. |
| **Recommended fix** | Use `preserveScroll: true` on the Inertia form submission and `preserveState: true` to avoid full re-render. The flash message then appears without a scroll-reset. |
| **Estimated effort** | 30 minutes |

---

## Review Area 15 — Accessibility

### Issue 15.1 — Score bars missing ARIA attributes

| | |
|--|--|
| **Severity** | Medium |
| **File** | `Components/UI/ScoreBar.vue` |
| **Description** | `ScoreBar.vue` renders a visual progress bar with no ARIA roles. |
| **Why it matters** | Design System §20: "Score bars: `role='progressbar'`, `aria-valuenow`, `aria-valuemin`, `aria-valuemax`." Without these attributes, screen readers cannot communicate the score value to users with visual impairments. Score bars appear on the Opportunities page, the Dashboard health card, and the recommendation detail — the most important pages in the product. |
| **Recommended fix** | Add `role="progressbar"`, `:aria-valuenow="score"`, `aria-valuemin="0"`, `:aria-valuemax="max ?? 100"` to the outer `div` in `ScoreBar.vue`. Add `<span class="sr-only">{{ score }} out of {{ max ?? 100 }}</span>`. |
| **Estimated effort** | 30 minutes |

---

### Issue 15.2 — Focus rings not explicitly styled on buttons

| | |
|--|--|
| **Severity** | Low |
| **File** | All Vue components with `<button>` elements |
| **Description** | Buttons do not include explicit `focus:ring-2 focus:ring-[var(--color-border-focus)] focus:ring-offset-2` classes. Browser default focus outlines are present but inconsistent across browsers. |
| **Why it matters** | Design System §20: "Focus ring: 2px solid var(--color-border-focus) with 2px offset. Never remove the focus ring." Keyboard-only users navigating to the Approve or Reject button need a clearly visible focus indicator. |
| **Recommended fix** | Add `focus-visible:ring-2 focus-visible:ring-[var(--color-border-focus)] focus-visible:ring-offset-2` to the primary button class and all interactive elements. Using `focus-visible` (not `focus`) avoids the ring appearing on mouse click. |
| **Estimated effort** | 2 hours |

---

## Review Area 16 — Mobile Responsiveness

### Issue 16.1 — Page content padding is too large on small screens

| | |
|--|--|
| **Severity** | Medium |
| **File** | `Layouts/AppLayout.vue:162` |
| **Description** | Main content uses `class="flex-1 px-8 py-6"` (32px horizontal padding) at all breakpoints. |
| **Why it matters** | On a 375px iPhone screen, 32px padding each side leaves only 311px for content. With card padding of 16–24px inside, text lines become very short. The Design System layout grid specifies 32px padding for desktop, but mobile should use 16px (`px-4`). |
| **Recommended fix** | Change to `class="flex-1 px-4 lg:px-8 py-6"`. This is the standard Tailwind responsive pattern. |
| **Estimated effort** | 15 minutes |

---

### Issue 16.2 — Recommendation rationale cards stack to 1 column on mobile (correct) but card padding is cramped

| | |
|--|--|
| **Severity** | Low |
| **File** | `Components/Recommendations/RationaleCard.vue:13` |
| **Description** | `grid-cols-1 sm:grid-cols-2` is correct. But on mobile, with `p-4` card padding and `px-8` page padding, the content area is very tight. |
| **Why it matters** | Rationale text is the most important reading in the product (Personas.md, FOUNDING_PRINCIPLES.md §3). It should never feel cramped. |
| **Recommended fix** | This is resolved by fixing Issue 16.1 (reducing page padding on mobile). No additional change needed. |
| **Estimated effort** | Covered by 16.1 |

---

## Review Area 17 — Performance

### Issue 17.1 — No Inertia page transition progress indicator

| | |
|--|--|
| **Severity** | Medium |
| **File** | `resources/js/app.ts` |
| **Description** | Inertia page navigations happen with no visual feedback until the new page fully renders. |
| **Why it matters** | For slower network conditions (mobile), clicking a nav link produces no feedback for 1–2 seconds. Marcus has no indication the click was registered. Design System §19 specifies: "On navigate start: content fades to opacity 0 over 100ms. On navigate finish: content fades to opacity 1 over 200ms." NProgress is the standard Inertia approach. |
| **Recommended fix** | Install `nprogress` and wire it to Inertia's `router.on('start')` and `router.on('finish')` events in `app.ts`. Add the NProgress bar color (accent-500) to the CSS. |
| **Estimated effort** | 1 hour |

---

## Review Area 18 — Visual Consistency

### Issue 18.1 — Primary button color is one shade too dark

| | |
|--|--|
| **Severity** | Low |
| **File** | All components with primary buttons |
| **Description** | Primary buttons use `bg-[var(--color-accent-600)]` with `hover:bg-[var(--color-accent-700)]`. |
| **Why it matters** | Design System §9: "Primary: Background: var(--color-accent-500) (#6366f1). Hover bg: var(--color-accent-600) (#4f46e5)." Using `accent-600` as the default reduces the perceptible contrast between default and hover states — the hover effect is less visible. The indigo-500 (#6366f1) is the canonical Atlas button color. |
| **Recommended fix** | Replace `bg-[var(--color-accent-600)]` with `bg-[var(--color-accent-500)]` and `hover:bg-[var(--color-accent-700)]` with `hover:bg-[var(--color-accent-600)]` across all primary buttons. This affects: Register, Login, Onboarding (all steps), ApproveActions, ContentEditor. |
| **Estimated effort** | 1 hour |

---

### Issue 18.2 — Rationale text uses `text-sm` (14px) not `text-body-lg` (16px)

| | |
|--|--|
| **Severity** | Medium |
| **File** | `Components/Recommendations/RationaleCard.vue:24` |
| **Description** | `<p class="text-sm text-[var(--color-text-secondary)]">{{ value }}</p>` — rationale body text is 14px. |
| **Why it matters** | Design System §12: "Rationale text uses `text-body-lg` (16px / 26px line height). The 'why now / why this / why this channel / why it will work' explanations are the most important reading on the platform. Squinting at 13px copy is friction that leads to blind approval." Marcus and Sofia both need to read and trust this text before acting. Reducing the reading size by even 2px increases cognitive friction on the most trust-critical screen. |
| **Recommended fix** | Change `text-sm` to `text-base leading-relaxed` in RationaleCard body text. `text-base` is 16px — matches the `text-body-lg` spec. Add `leading-relaxed` (1.625 line height) to match the spec's line height token. |
| **Estimated effort** | 15 minutes |

---

## Review Area 19 — Design System Compliance

### Issue 19.1 — Custom typography tokens not registered in `app.css`

| | |
|--|--|
| **Severity** | Low |
| **File** | `resources/css/app.css`, `docs/design/System.md` §2 |
| **Description** | The Design System defines custom text tokens (`--text-display`, `--text-heading-1`, `--text-heading-2`, `--text-body-lg`, `--text-label`, etc.) in the `@theme` block specification. These tokens are never added to `app.css`. Pages use ad-hoc Tailwind sizes (`text-xl`, `text-sm`, `text-xs`) instead. |
| **Why it matters** | Without the token definitions, there is no enforced typographic scale. Each developer chooses sizes arbitrarily. Over time this produces inconsistent type sizes across pages (h1 is `text-xl` on some pages, `text-2xl` on others). |
| **Recommended fix** | Add the full typography token block from `docs/design/System.md §2` to `resources/css/app.css`. Migrate page headings to use `text-display`, `text-heading-1`, `text-heading-2` etc. This can be done incrementally — the tokens just need to exist first. |
| **Estimated effort** | 2 hours (tokens only) + ongoing migration |

---

### Issue 19.2 — Status color tokens not registered in `app.css`

| | |
|--|--|
| **Severity** | Low |
| **File** | `resources/css/app.css`, `docs/design/System.md` §3 |
| **Description** | The Design System defines semantic status color tokens (`--color-status-open-bg`, `--color-status-pending-bg`, `--color-status-success-bg`, etc.). These are not in `app.css`. Badges use ad-hoc Tailwind color classes (`emerald-50`, `amber-50`, `stone-100`) instead. |
| **Why it matters** | Without token definitions, status colors cannot be changed globally. If the design palette shifts (e.g., "approval is teal not green"), every badge class across every page must be updated individually. |
| **Recommended fix** | Add the status color token block from `docs/design/System.md §3` to `resources/css/app.css`. Create a `StatusBadge` component that accepts a `status` string and applies the correct token-based classes automatically. |
| **Estimated effort** | 2 hours |

---

## Review Area 20 — Production Readiness

### Issue 20.1 — No page `<title>` tags

| | |
|--|--|
| **Severity** | Medium |
| **File** | All Inertia pages (no page uses `<Head>` from `@inertiajs/vue3`) |
| **Description** | No page sets a `<title>` tag via Inertia's `<Head>` component. All pages show the browser default (the Laravel app name or blank). |
| **Why it matters** | Tab labels, bookmark names, browser history, and screen reader page announcements all rely on `<title>`. A user with multiple Atlas tabs open cannot distinguish "Recommendations" from "Dashboard". Screen readers announce "Untitled" or the app name on every navigation. |
| **Recommended fix** | Import `{ Head }` from `@inertiajs/vue3` in each page component and add `<Head><title>Recommendations — Atlas</title></Head>`. Pattern: `{Page Name} — Atlas`. |
| **Estimated effort** | 2 hours (30 pages × 4 minutes) |

---

### Issue 20.2 — No favicon

| | |
|--|--|
| **Severity** | Low |
| **File** | `resources/views/app.blade.php` |
| **Description** | No favicon is defined. The browser shows a blank/default icon in the tab. |
| **Why it matters** | A favicon is a minimum viable production signal. Without it, the product looks unfinished. It also affects bookmarks and PWA-like behaviors on mobile. |
| **Recommended fix** | Add a minimal SVG favicon using the Atlas "A" letter in indigo-500. Add `<link rel="icon" href="/favicon.svg" type="image/svg+xml">` to `app.blade.php`. Create `public/favicon.svg`. |
| **Estimated effort** | 1 hour |

---

### Issue 20.3 — `window.location.pathname` in `isActive()`

| | |
|--|--|
| **Severity** | Low |
| **File** | `Layouts/AppLayout.vue:24–29` |
| **Description** | The `isActive()` function uses `window.location.pathname`. This works for a client-side rendered Inertia app but is a code smell — it bypasses Vue's reactivity and will not update correctly if Inertia navigates without a full page reload. |
| **Why it matters** | The active nav item may not update when navigating between pages. Users may see the wrong item highlighted in the sidebar, breaking their sense of location. |
| **Recommended fix** | Use Inertia's `usePage()` to access `page.url` reactively: `const currentPath = computed(() => usePage().url)`. Replace `window.location.pathname` with `currentPath.value`. |
| **Estimated effort** | 30 minutes |

---

## Issue Summary

| # | Issue | Area | Severity | Effort |
|---|-------|------|----------|--------|
| 5.1 | Active DigitalTwin status not handled | Business Brain | **Critical** | 30 min |
| 1.1 | Status page routes to dashboard not recommendation | Onboarding | **High** | 2 h |
| 4.1 | "Edit & Approve" not a visible button | Recommendations | **High** | 3 h |
| 6.1 | Opportunity type shows raw enum | Opportunities | **High** | 30 min |
| 8.1 | Normalised metric keys shown to user | Analytics | **High** | 1 h |
| 1.2 | No timeout message (>5 min) | Onboarding | Medium | 1 h |
| 2.1 | "Brain" label not "Business Brain" | Navigation | Medium | 5 min |
| 4.2 | No explanatory copy below action buttons | Recommendations | Medium | 30 min |
| 5.2 | Health score not displayed | Business Brain | Medium | 2 h |
| 6.2 | Expiry treatment is binary/unformatted | Opportunities | Medium | 2 h |
| 6.3 | Score bars single fixed color | Opportunities | Medium | 1 h |
| 7.1 | No campaign lifecycle progress trail | Campaigns | Medium | 4 h |
| 7.2 | Campaign status uses raw enum | Campaigns | Medium | 1 h |
| 9.1 | Learning signals raw and unexplained | Learning | Medium | 2 h |
| 10.2 | Action-required empty states lack CTAs | Empty States | Medium | 2 h |
| 11.1 | No skeleton loading screens | Loading States | Medium | 4 h |
| 12.1 | No inline error on approval failure | Error Handling | Medium | 2 h |
| 13.1 | Form labels don't follow design system | Forms | Medium | 1 h |
| 15.1 | Score bars missing ARIA roles | Accessibility | Medium | 30 min |
| 16.1 | Page padding too large on mobile | Mobile | Medium | 15 min |
| 17.1 | No page transition progress indicator | Performance | Medium | 1 h |
| 18.2 | Rationale text is 14px not 16px | Visual | Medium | 15 min |
| 20.1 | No page `<title>` tags | Production | Medium | 2 h |
| 3.1 | 8 nav items — no grouping | IA | Low | 2 h |
| 2.2 | Settings has no active state | Navigation | Low | 30 min |
| 2.3 | "Publishing" label unclear | Navigation | Low | 15 min |
| 4.3 | Rejection label wording | Recommendations | Low | 15 min |
| 8.2 | Analytics empty state lacks recommendation link | Analytics | Low | 30 min |
| 10.1 | Empty states lack contextual icons | Empty States | Low | 1 h |
| 13.2 | Settings sync causes scroll reset | Settings | Low | 30 min |
| 14.1 | (see 13.2) | Settings | Low | — |
| 15.2 | Focus rings not explicitly styled | Accessibility | Low | 2 h |
| 18.1 | Primary button one shade too dark | Visual | Low | 1 h |
| 19.1 | Typography tokens not in app.css | Design System | Low | 2 h |
| 19.2 | Status color tokens not in app.css | Design System | Low | 2 h |
| 20.2 | No favicon | Production | Low | 1 h |
| 20.3 | `window.location.pathname` in isActive | Production | Low | 30 min |

**Total estimated effort for all issues:** ~46 hours

---

## What Is Working Well

The following areas are implemented correctly and should not be changed:

- **Mobile hamburger navigation** — correctly implemented with overlay, aria-label, close-on-navigate
- **Flash message system** — success/error with correct ARIA roles (`role="status"` / `role="alert"`)
- **Rejection flow** — optional note textarea with correct framing ("Not this time" vs "Reject")
- **Multi-company selector** — correctly routes single-membership users directly to app
- **Company scope enforcement** — correct use of `withoutGlobalScopes()` only in admin; customer app scoped correctly
- **Sign-out** — correct `router.post('/logout')` with CSRF via Inertia
- **Status page live region** — `aria-live="polite"` on the progress items ✓
- **LoadingSpinner ARIA** — `role="status"` and `aria-label="Loading"` ✓
- **Nav `aria-label`** — `<nav aria-label="Main navigation">` ✓
- **Modal hamburger icon swap** — open/close icon toggle on mobile ✓
- **Rationale card 2-column grid** — `grid-cols-1 sm:grid-cols-2` ✓
- **Content editor inline replacement** — replaces preview in-place without page navigation ✓
- **Recommendation status labels** — `Show.vue` correctly maps `pending → "Pending review"`, `rejected → "Passed"` ✓
- **prefers-reduced-motion** — global rule in `app.css` ✓
