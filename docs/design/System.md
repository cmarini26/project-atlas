# Atlas Customer Dashboard — Design System

**Milestone:** 10.1  
**Date:** 2026-06-27  
**Stack:** Vue 3 · TypeScript · Tailwind CSS v4 · Inertia.js  
**Font:** Instrument Sans (400 / 500 / 600), loaded via Bunny fonts  
**Status:** Specification — no code written yet

---

## Contents

1. [Design Philosophy](#1-design-philosophy)
2. [Typography](#2-typography)
3. [Color Palette](#3-color-palette)
4. [Spacing Scale](#4-spacing-scale)
5. [Layout Grid](#5-layout-grid)
6. [Responsive Breakpoints](#6-responsive-breakpoints)
7. [Icons](#7-icons)
8. [Card Components](#8-card-components)
9. [Buttons](#9-buttons)
10. [Form Controls](#10-form-controls)
11. [Tables](#11-tables)
12. [Recommendation Cards](#12-recommendation-cards)
13. [Opportunity Cards](#13-opportunity-cards)
14. [Campaign Cards](#14-campaign-cards)
15. [Metric Cards](#15-metric-cards)
16. [Timeline Components](#16-timeline-components)
17. [Empty States](#17-empty-states)
18. [Loading Skeletons](#18-loading-skeletons)
19. [Animations](#19-animations)
20. [Accessibility](#20-accessibility)
21. [Dark Mode Strategy](#21-dark-mode-strategy)

---

## 1. Design Philosophy

### The guiding question for every design decision

> Would Marcus — who runs a comic book auction house, doesn't think of himself as a marketer, and has 40 minutes a week — be able to complete this action without reading documentation?

If the answer is no, the design is wrong.

### The interface is a capable, quiet presence

Atlas does the analytical work before the user arrives. The dashboard surfaces conclusions, not raw data. The user's job is to review and approve, not to interpret.

This means:

- **One primary action per screen.** When there is a recommendation pending, the entire dashboard orients toward reviewing it. Everything else recedes.
- **Outcomes, not process.** A campaign card does not show 12 status indicators. It shows where the campaign is in its life and what, if anything, the user needs to do.
- **Explanation is the content, not a tooltip.** The rationale behind a recommendation is not hidden behind an expand button or an info icon. It is the first thing the user reads.
- **Quiet when nothing needs attention.** When Atlas is working in the background and nothing requires user input, the dashboard is calm and informational — not empty, not alarming.

### Four qualities, in priority order

**1. Calm.** Generous whitespace. Muted color palette. No aggressive call-to-action patterns. No unread badges competing for attention in the sidebar. No red unless something actually failed.

**2. Clear.** High-contrast text on white surfaces. Strong typographic hierarchy so the user knows in 2 seconds what matters. Labels in plain language ("Why now" not "Temporal relevance signal").

**3. Low cognitive load.** Consistent patterns across every page. Predictable component behavior. Progressive disclosure: the overview is always simple, and detail appears only when requested.

**4. Built for business owners, not marketers.** Atlas's interface must never feel like a marketing tool. No jargon. No campaign builder metaphors. No "impressions funnel" language. Marcus is a collector and auctioneer. Sofia is a contractor who produces content for clients. The interface serves their mental model, not a marketer's.

### What this system is not

- Not a data-dense dashboard (not Grafana, not Mixpanel)
- Not a social media scheduling tool (not Buffer, not Hootsuite)
- Not a document editor (not Notion, not Coda)
- Not a CRM (not HubSpot, not Salesforce)

Atlas looks closest to a trusted advisor portal — clean, intelligent, purposeful.

---

## 2. Typography

**Typeface:** Instrument Sans — a humanist geometric sans-serif. Warm, readable, professional without being corporate. Ideal for dashboard prose and recommendations that users need to read carefully before acting.

**Loaded weights:** 400 (Regular), 500 (Medium), 600 (Semibold). No bold (700) — Semibold at 600 provides sufficient contrast hierarchy without aggression.

**Fallback stack:** `'Instrument Sans', ui-sans-serif, system-ui, sans-serif`

### Type Scale

All sizes in `rem` (base 16px). Line heights optimize for reading long rationale text, not scanning tables.

| Token | Size | Line Height | Weight | Usage |
|-------|------|-------------|--------|-------|
| `text-display` | 30px / 1.875rem | 1.2 (36px) | 600 | Page hero moments: onboarding completion, first recommendation |
| `text-heading-1` | 24px / 1.5rem | 1.33 (32px) | 600 | Page titles (`<h1>`) |
| `text-heading-2` | 20px / 1.25rem | 1.4 (28px) | 600 | Section headings (`<h2>`), card titles in detail views |
| `text-heading-3` | 16px / 1rem | 1.5 (24px) | 600 | Subsection headings (`<h3>`), card titles in list views |
| `text-body-lg` | 16px / 1rem | 1.625 (26px) | 400 | Recommendation rationale paragraphs — primary reading text |
| `text-body` | 14px / 0.875rem | 1.57 (22px) | 400 | General interface text, card body, table cells |
| `text-body-sm` | 13px / 0.8125rem | 1.54 (20px) | 400 | Secondary text, captions, helper text |
| `text-label` | 12px / 0.75rem | 1.33 (16px) | 500 | Form labels, column headers, section eyebrows |
| `text-label-sm` | 11px / 0.6875rem | 1.45 (16px) | 500 | Tight labels only: status badges, chip text |
| `text-mono` | 13px / 0.8125rem | 1.54 (20px) | 400 | IDs, technical values, raw keys — use `ui-monospace` |

### Typography Rules

**Label style.** Field labels use `text-label`, `text-muted`, uppercase, `letter-spacing: 0.06em`. Never use uppercase for body text or headings — only for labels and column headers.

**Rationale text uses `text-body-lg`.** The "why now / why this / why this channel / why it will work" explanations are the most important reading on the platform. They get 16px / 26px line height — the same consideration you would give editorial prose.

**Number display.** Metric cards display their primary number at `text-display` or `text-heading-1`. Use `font-variant-numeric: tabular-nums` on any number that changes or sits in a column so digits don't shift layout.

**Truncation policy.** Truncate with `overflow: hidden; text-overflow: ellipsis; white-space: nowrap` only in table cells and compact card views. Never truncate rationale or recommendation body text — if it doesn't fit, the card needs to be taller, not the text shorter.

### Tailwind v4 `@theme` additions

```css
@theme {
  --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji';
  --font-mono: ui-monospace, 'Cascadia Code', 'Source Code Pro', Menlo, Consolas, 'DejaVu Sans Mono', monospace;

  --text-display: 1.875rem;
  --text-display--line-height: 1.2;
  --text-display--font-weight: 600;

  --text-heading-1: 1.5rem;
  --text-heading-1--line-height: 1.333;
  --text-heading-1--font-weight: 600;

  --text-heading-2: 1.25rem;
  --text-heading-2--line-height: 1.4;
  --text-heading-2--font-weight: 600;

  --text-heading-3: 1rem;
  --text-heading-3--line-height: 1.5;
  --text-heading-3--font-weight: 600;

  --text-body-lg: 1rem;
  --text-body-lg--line-height: 1.625;

  --text-body: 0.875rem;
  --text-body--line-height: 1.571;

  --text-body-sm: 0.8125rem;
  --text-body-sm--line-height: 1.538;

  --text-label: 0.75rem;
  --text-label--line-height: 1.333;
  --text-label--font-weight: 500;

  --text-label-sm: 0.6875rem;
  --text-label-sm--line-height: 1.454;
  --text-label-sm--font-weight: 500;
}
```

---

## 3. Color Palette

### Philosophy

One accent. Neutral warmth everywhere else. Status communicated through muted tones, never through saturation.

The base neutral is **stone** (Tailwind's warm gray family — slightly warmer than slate, avoiding the cold blue-gray of pure grays). Stone reads as calm and natural.

The accent is **indigo** — trustworthy, clear, not electric. Used exclusively for interactive elements: primary buttons, links, focus rings, active nav items. Nothing decorative is indigo.

### Semantic Token System

Define semantic tokens in `@theme {}`. Implementation references tokens, never raw colors.

```css
@theme {
  /* ── Surfaces ─────────────────────────────────── */
  --color-surface:           #fafaf9;   /* page background      stone-50  */
  --color-surface-elevated:  #ffffff;   /* cards, panels                  */
  --color-surface-subtle:    #f5f5f4;   /* inputs, code, hover  stone-100 */
  --color-surface-overlay:   #292524;   /* modal backdrop dark  stone-800 */

  /* ── Borders ──────────────────────────────────── */
  --color-border:            #e7e5e4;   /* default borders      stone-200 */
  --color-border-strong:     #d6d3d1;   /* table dividers       stone-300 */
  --color-border-focus:      #6366f1;   /* focus ring           indigo-500 */

  /* ── Text ─────────────────────────────────────── */
  --color-text-primary:      #1c1917;   /* headings, emphasis   stone-950 */
  --color-text-secondary:    #44403c;   /* body text            stone-700 */
  --color-text-muted:        #78716c;   /* captions, labels     stone-500 */
  --color-text-placeholder:  #a8a29e;   /* input placeholders   stone-400 */
  --color-text-disabled:     #d6d3d1;   /* disabled elements    stone-300 */
  --color-text-inverse:      #fafaf9;   /* text on dark         stone-50  */
  --color-text-link:         #4f46e5;   /* inline links         indigo-600 */

  /* ── Accent (indigo) ──────────────────────────── */
  --color-accent-50:         #eef2ff;
  --color-accent-100:        #e0e7ff;
  --color-accent-200:        #c7d2fe;
  --color-accent-500:        #6366f1;   /* primary buttons, active states */
  --color-accent-600:        #4f46e5;   /* hover on primary               */
  --color-accent-700:        #4338ca;   /* pressed / dark                 */

  /* ── Status: Open / Active ────────────────────── */
  --color-status-open-bg:    #eff6ff;   /* blue-50   */
  --color-status-open-text:  #1d4ed8;   /* blue-700  */
  --color-status-open-border:#bfdbfe;   /* blue-200  */

  /* ── Status: Pending (needs attention) ────────── */
  --color-status-pending-bg:    #fffbeb; /* amber-50  */
  --color-status-pending-text:  #92400e; /* amber-800 */
  --color-status-pending-border:#fde68a; /* amber-200 */

  /* ── Status: Success / Approved ──────────────── */
  --color-status-success-bg:    #f0fdf4; /* green-50  */
  --color-status-success-text:  #166534; /* green-800 */
  --color-status-success-border:#bbf7d0; /* green-200 */

  /* ── Status: Neutral / Rejected / Cancelled ──── */
  /* Rejection is a valid user action, not a failure. Stone — not red. */
  --color-status-neutral-bg:    #f5f5f4; /* stone-100 */
  --color-status-neutral-text:  #57534e; /* stone-600 */
  --color-status-neutral-border:#e7e5e4; /* stone-200 */

  /* ── Status: Error / Failed (technical failures) */
  --color-status-error-bg:    #fff1f2;  /* rose-50   */
  --color-status-error-text:  #9f1239;  /* rose-800  */
  --color-status-error-border:#fecdd3;  /* rose-200  */

  /* ── Status: Scheduled / Queued ──────────────── */
  --color-status-queued-bg:    #f5f3ff; /* violet-50  */
  --color-status-queued-text:  #5b21b6; /* violet-800 */
  --color-status-queued-border:#ddd6fe; /* violet-200 */
}
```

### Status Color Assignments

| Status value | Color family | Rationale |
|---|---|---|
| `open` (Opportunity) | Blue | Active, needs processing |
| `pending` (Recommendation) | Amber | Awaiting user decision |
| `approved` | Green | Positive completion |
| `rejected` | Stone/neutral | Valid user choice, not a failure |
| `cancelled` | Stone/neutral | Expected lifecycle end |
| `draft` | Stone/neutral | In-progress, not alarming |
| `completed` | Green | Successful completion |
| `published` | Green | Successfully delivered |
| `queued` | Violet | Waiting for system action |
| `executing` | Violet | System is working |
| `failed` | Rose | Technical failure requiring attention |
| `error` | Rose | Technical error |
| `initializing` | Amber | System is warming up |
| `active` | Green | Healthy operational state |
| `selected` | Blue | Chosen for processing |
| `dismissed` | Stone/neutral | Intentionally closed |
| `expired` | Stone/neutral | Natural lifecycle end |

### Critical color rule: Rejection is never red

A user rejecting a recommendation is providing Atlas with a valuable learning signal. Red communicates "error" or "danger." Showing rejection in red creates anxiety and discourages users from rejecting things they should reject. Rejected states use **stone/neutral** throughout — calm, informational, not alarming.

### Urgency indicators

For time-sensitive opportunities (auction closes soon, expiry approaching):

```
expires_at within 48 hours → amber treatment (amber text, no bg)
expires_at within 24 hours → rose/orange treatment (rose-600 text)
expires_at past → strike-through + muted
```

These are inline text treatments, not full badge color swaps.

---

## 4. Spacing Scale

Base unit: **4px**. All spacing is a multiple of 4px.

| Token | Value | Tailwind | Common use |
|-------|-------|---------|-----------|
| `space-0` | 0px | `p-0` | Resets |
| `space-1` | 4px | `p-1` | Icon padding, tight gaps |
| `space-2` | 8px | `p-2` | Inline element gaps, chip padding |
| `space-3` | 12px | `p-3` | Small card padding, button vertical |
| `space-4` | 16px | `p-4` | Default element padding, form inputs |
| `space-5` | 20px | `p-5` | — |
| `space-6` | 24px | `p-6` | Card padding (standard) |
| `space-8` | 32px | `p-8` | Page horizontal padding, section gap |
| `space-10` | 40px | `p-10` | — |
| `space-12` | 48px | `p-12` | Section vertical padding |
| `space-16` | 64px | `p-16` | Hero area vertical padding |
| `space-20` | 80px | `p-20` | — |
| `space-24` | 96px | `p-24` | — |

### Component spacing rules

| Component | Padding | Gap between items |
|-----------|---------|-----------------|
| Card (standard) | 24px (space-6) | — |
| Card (compact) | 16px (space-4) | — |
| Button (md) | 12px vertical / 20px horizontal | — |
| Button (lg) | 14px vertical / 24px horizontal | — |
| Form group | — | 20px (space-5) between groups |
| Page sections | — | 32px (space-8) |
| Sidebar items | 10px vertical / 12px horizontal | 4px between items |
| Table row | 14px vertical | — |
| Dashboard grid | — | 24px (space-6) |
| Rationale quadrants | — | 16px (space-4) |

### Sidebar fixed measurements

```
Sidebar width:     240px
Sidebar padding:   16px horizontal
Sidebar top gap:   24px (below logo)
Nav item height:   40px
Nav section gap:   8px between items, 24px between groups
Footer area:       72px (user menu at bottom)
```

---

## 5. Layout Grid

### App shell

```
┌─────────────────────────────────────────────────────────────┐
│  Sidebar (240px fixed)  │  Content area (fluid)             │
│                         │                                   │
│  [Logo]                 │  ┌─ Page header ────────────────┐ │
│                         │  │  Page title                  │ │
│  Navigation             │  └──────────────────────────────┘ │
│  ─────────              │                                   │
│  Dashboard              │  ┌─ Content ─────────────────────┐│
│  Brain                  │  │  Max width: 1140px             ││
│  Opportunities          │  │  Padding: 32px sides           ││
│  Recommendations        │  │  24px top                      ││
│  Campaigns              │  └───────────────────────────────┘│
│  Publishing             │                                   │
│  Analytics              │                                   │
│  Learning               │                                   │
│  ─────────              │                                   │
│  Settings               │                                   │
│                         │                                   │
│  [User menu]            │                                   │
└─────────────────────────────────────────────────────────────┘
```

### Content grid

12 columns with 24px gutters. Content area max-width: 1140px, centered.

Common compositions:

| Layout | Cols | Use |
|--------|------|-----|
| Full width | 12/12 | Campaign timeline, analytics tables, page headers |
| Main + sidebar | 8/12 + 4/12 | Recommendation detail (content left, metadata right) |
| Two equal | 6/12 + 6/12 | Metric pairs, two-column stat rows |
| Three equal | 4/12 + 4/12 + 4/12 | Metric triples (approval rate, rejection rate, edit rate) |
| Dashboard hero | 12/12 | Recommendation prompt card when pending |
| Dashboard grid | 3×4/12 | Summary cards below the hero |

### Page header pattern

Every `/app/*` page has a consistent header zone:

```
┌──────────────────────────────────────────────────────────┐
│  [Page title — text-heading-1]          [Primary action] │
│  [Subtitle — text-body text-muted]                       │
└──────────────────────────────────────────────────────────┘
```

Page title is always an `<h1>`. Subtitle is optional. Primary action (if any) floats right. No breadcrumbs for MVP — sidebar navigation provides context.

---

## 6. Responsive Breakpoints

| Name | Min width | Behavior |
|------|-----------|---------|
| `xs` (base) | 0px | Single column. Sidebar hidden, replaced by top nav bar + hamburger drawer. |
| `sm` | 640px | Two-column grids unlock. Top nav bar still used. |
| `md` | 768px | Three-column grid available. Table horizontal scroll removed on some views. |
| `lg` | 1024px | Sidebar appears (240px). Content area is fluid. Multi-column dashboard grids. |
| `xl` | 1280px | Content max-width (1140px) reached. Extra horizontal breathing room. |
| `2xl` | 1536px | Same as xl — no additional layout change. |

### Sidebar behavior at breakpoints

- **`< lg`**: Sidebar hidden. Fixed top navigation bar (56px height). Hamburger opens a full-height drawer overlay.
- **`>= lg`**: Sidebar fixed left at 240px. Top bar hidden. Content fills remaining width.

### Mobile-first rule

All styles are written mobile-first. Responsive modifiers (`lg:`, `xl:`) add complexity at wider sizes. Do not use `md:hidden` to hide desktop-only elements — add them progressively with `lg:block`.

---

## 7. Icons

**Library:** Heroicons v2 (MIT license, designed for Tailwind CSS)  
**Package:** `@heroicons/vue/24/outline` and `@heroicons/vue/24/solid`  
**Available in Vue:** tree-shakeable named imports

### Icon sizes

| Size | Tailwind | Use |
|------|----------|-----|
| 16px | `size-4` | Tight spaces: table action icons, inline within text |
| 20px | `size-5` | Buttons (default), form control icons, navigation items |
| 24px | `size-6` | Card headers, section icons, empty state icons (subdued) |
| 40px | `size-10` | Empty state illustrations (prominent) |
| 48px | `size-12` | Onboarding step icons only |

### Icon style rules

- Navigation uses **outline** icons when inactive, **solid** when active
- Buttons use **outline** icons only
- Status indicators within cards use **solid** icons (filled circle, check, x-mark)
- Standalone icons (empty states) use **outline** at larger sizes

### Standard icon mapping

| Concept | Heroicon name |
|---------|--------------|
| Dashboard / Home | `HomeIcon` |
| Business Brain | `BrainIcon` → `CpuChipIcon` |
| Opportunities | `LightBulbIcon` |
| Recommendations | `SparklesIcon` |
| Campaigns | `MegaphoneIcon` |
| Publishing / Executions | `PaperAirplaneIcon` |
| Analytics | `ChartBarIcon` |
| Learning | `AcademicCapIcon` |
| Settings | `Cog6ToothIcon` |
| Approve | `CheckIcon` |
| Reject | `XMarkIcon` |
| Edit | `PencilIcon` |
| Time / Expiry | `ClockIcon` |
| Confidence / Score | `ShieldCheckIcon` |
| Channel / Email | `EnvelopeIcon` |
| Channel / Social | `ShareIcon` |
| Warning | `ExclamationTriangleIcon` |
| Info | `InformationCircleIcon` |
| External link | `ArrowTopRightOnSquareIcon` |
| Expand / Chevron | `ChevronDownIcon` / `ChevronRightIcon` |
| Refresh / Sync | `ArrowPathIcon` |

---

## 8. Card Components

Cards are the primary container for all dashboard content.

### Base card anatomy

```
┌─────────────────────────────────────────┐  ← border-radius: 12px
│  [Icon] Card Title          [Badge]     │  ← card header, 24px padding
│  ─────────────────────────────────────  │  ← optional divider
│                                         │
│  Card body content                      │  ← 24px padding
│  Secondary text                         │
│                                         │
│  [Action]                    [Action]   │  ← optional card footer
└─────────────────────────────────────────┘
```

### Card variants

**Default card**
```
Background:  white (#ffffff)
Border:      1px solid var(--color-border)          (#e7e5e4)
Radius:      12px
Shadow:      0 1px 2px rgb(0 0 0 / 0.05)
Padding:     24px
```

**Highlighted card** — the active pending recommendation
```
Background:  white
Border:      1px solid var(--color-accent-200)       (#c7d2fe)
Left accent: 4px solid var(--color-accent-500)       (#6366f1)
Radius:      0 12px 12px 0   (right side rounded, left flush to accent stripe)
Shadow:      0 1px 3px rgb(99 102 241 / 0.10)        (subtle indigo tint)
Padding:     24px
```

**Subtle / secondary card**
```
Background:  var(--color-surface-subtle)             (#f5f5f4)
Border:      none
Radius:      8px
Shadow:      none
Padding:     20px
```

**Ghost card** (nested inside another card)
```
Background:  transparent
Border:      1px solid var(--color-border)
Radius:      8px
Shadow:      none
Padding:     16px
```

### Card padding rules

- Standard card: 24px all sides
- Compact card (list contexts): 16px all sides
- Card header with divider: 20px bottom, 24px sides
- Card footer with actions: 16px top, 24px sides, 20px bottom

### Card header pattern

```html
<!-- Structure (not implementation code) -->
<div class="card-header">
  <div class="card-title-group">
    <Icon class="card-icon" />        <!-- 20px, text-muted -->
    <h2 class="card-title" />         <!-- text-heading-3, text-primary -->
  </div>
  <Badge />                           <!-- optional status badge -->
</div>
```

Card title is always a heading element (`h2` or `h3`). Icon is decorative (aria-hidden). Badge is optional.

---

## 9. Buttons

### Button hierarchy

The three-level hierarchy enforces visual weight matching action importance:

```
Primary     ████████████████   Most important action on the page. One per view.
Secondary   ┌──────────────┐   Alternative important action. Appears alongside primary.
            └──────────────┘
Tertiary    Text only →        Low-priority or destructive-adjacent actions.
```

**The approval flow maps directly:**
- Approve → **Primary**
- Edit & Approve → **Secondary**
- Reject → **Tertiary** (labeled "Not this time")
- Cancel → **Tertiary**

Never use a destructive (red) button for Reject. Rejection is a valid, learning-generating action.

### Button specifications

**Primary**
```
Background:  var(--color-accent-500)     (#6366f1)
Text:        white
Border:      none
Hover bg:    var(--color-accent-600)     (#4f46e5)
Active bg:   var(--color-accent-700)     (#4338ca)
Disabled:    opacity-40, cursor not-allowed
Focus ring:  2px var(--color-accent-500), 2px offset
```

**Secondary**
```
Background:  white
Text:        var(--color-accent-600)     (#4f46e5)
Border:      1.5px solid var(--color-accent-500)
Hover bg:    var(--color-accent-50)      (#eef2ff)
Active bg:   var(--color-accent-100)     (#e0e7ff)
```

**Tertiary / Ghost**
```
Background:  transparent
Text:        var(--color-text-muted)     (#78716c)
Border:      none
Hover bg:    var(--color-surface-subtle) (#f5f5f4)
Hover text:  var(--color-text-secondary) (#44403c)
```

**Destructive** (used only for irreversible system-level actions, not for Reject)
```
Background:  #be123c    (rose-700)
Text:        white
Hover bg:    #9f1239    (rose-800)
```

### Button sizes

| Size | Height | Padding H | Font size | Use |
|------|--------|-----------|-----------|-----|
| `sm` | 32px | 12px | 13px (text-body-sm) | Table actions, compact contexts |
| `md` | 40px | 16px | 14px (text-body) | Default — most buttons |
| `lg` | 48px | 24px | 15px | Single hero CTA only (Approve on recommendation page) |

### Button with icon

Icon always 16px (`size-4`) in sm, 16px in md, 20px (`size-5`) in lg.
Icon–label gap: 6px for sm, 8px for md/lg.
Leading icon: preferred for navigation-like actions (← Back)
Trailing icon: preferred for forward actions (Continue →, Review →)

### Loading state

Replace icon/label with an animated spinner. Width is fixed (no layout shift). Disable interaction during load.

```
[⠿ Approving…]   ← spinner + progressive label
```

---

## 10. Form Controls

### Text input

```
Height:          40px
Background:      white
Border:          1px solid var(--color-border-strong)   (#d6d3d1)
Border radius:   6px
Padding:         0 12px
Font size:       14px (text-body)
Placeholder:     var(--color-text-placeholder)

Focus:
  border-color:  var(--color-accent-500)
  box-shadow:    0 0 0 3px rgb(99 102 241 / 0.15)

Error:
  border-color:  #f43f5e    (rose-500)
  box-shadow:    0 0 0 3px rgb(244 63 94 / 0.10)

Disabled:
  background:    var(--color-surface-subtle)
  border-color:  var(--color-border)
  cursor:        not-allowed
```

### Textarea

Same border treatment as text input. Auto-resize via `resize-y` (user can expand vertically, not horizontally). Min height: 96px (6 lines at 16px).

Used for content editing on the Recommendation detail page. Generous min-height — the user is editing marketing copy, not a tweet.

### Form label

```
Font size:       12px (text-label)
Font weight:     500 (medium)
Color:           var(--color-text-muted)
Text transform:  uppercase
Letter spacing:  0.06em
Margin bottom:   6px
```

Labels are always positioned above the control. No inline or placeholder-as-label patterns.

### Helper text

```
Font size:       12px (text-body-sm)
Color:           var(--color-text-muted)
Margin top:      6px
```

### Error text

```
Font size:       12px (text-body-sm)
Color:           #be123c    (rose-700)
Margin top:      6px
Icon:            ExclamationCircleIcon (16px), inline-leading
```

### Select

Same height and border as text input. Append a `ChevronDownIcon` (16px, muted) in the right padding zone.

### Checkbox / Radio

24px touch target, 16px visual indicator. Border: 1.5px solid var(--color-border-strong). Checked: filled accent background, white check mark.

### Form group spacing

20px gap between form groups (label + input + helper text). 32px gap between form sections.

---

## 11. Tables

Tables appear on: Opportunities list, Publishing activity, Learning feed.

### Table anatomy

```
┌────────────────────────────────────────────────────────────────┐
│  [COLUMN HEADER]   [COLUMN HEADER]   [COLUMN HEADER]  [ACTIONS]│  ← thead: 44px
├────────────────────────────────────────────────────────────────┤
│  Row content       Value             Badge                 [→] │  ← tbody: 52px
├────────────────────────────────────────────────────────────────┤  ← border-stone-200
│  Row content       Value             Badge                 [→] │
├────────────────────────────────────────────────────────────────┤
│  Row content       Value             Badge                 [→] │
└────────────────────────────────────────────────────────────────┘
```

### Table styles

```
Table:       width: 100%, border-collapse: separate, border-spacing: 0
Header row:  background none, border-bottom: 1px solid var(--color-border-strong)
Header cell: text-label, text-muted, uppercase, letter-spacing 0.06em, padding: 10px 16px
Body row:    52px height, border-bottom: 1px solid var(--color-border)
Body cell:   text-body, text-secondary, padding: 0 16px
Row hover:   background: var(--color-surface-subtle)
Last row:    no border-bottom
```

### Column patterns

| Column type | Alignment | Width | Notes |
|------------|-----------|-------|-------|
| Primary label | Left | Auto (flex) | First column, text-primary |
| Date/time | Left | 140px | Format: `Jun 14` or `Jun 14, 4:30 PM` |
| Status badge | Left | 120px | Badge component |
| Number/score | Right | 80px | Tabular nums, right-aligned |
| Short value | Left | 100px | — |
| Actions | Right | 56px | Icon buttons, revealed on row hover |

### Table pagination

Compact strip below the table:
```
Showing 1–20 of 47    [← Prev]  1  2  3  [Next →]
```

`text-body-sm`, `text-muted`. Prev/Next use tertiary button style.

---

## 12. Recommendation Cards

The most important UI component in the system. Appears in two forms.

### Compact form (Dashboard)

Used in the `RecommendationPrompt` card when one pending recommendation exists.

```
┌─ Highlighted card ───────────────────────────────────────────────┐
│                                                    [Pending] [→] │
│  SparklesIcon  Featured Item Recommendation                      │
│                                                                  │
│  "Your Silver Age auction closes in 47 hours — this is          │
│  the highest-converting window for urgency campaigns."           │
│  — Why now                                                       │
│                                                                  │
│  Confidence: ████████░░  78%       1 channel · Email            │
│                                                                  │
│  [Review Recommendation →]              Created 2 hours ago     │
└──────────────────────────────────────────────────────────────────┘
```

Compact card rules:
- "Why now" text is a single sentence — the single most important reason
- Confidence shown as a score bar + percentage
- Channel summary: count + type names
- Primary CTA: "Review Recommendation →" (Primary button, full width on mobile, inline on desktop)
- No approval action from the dashboard — review first, approve in detail view

### Expanded form (Recommendation detail page)

The recommendation detail page lays out the rationale as the primary content, not as a sidebar or expandable:

```
┌─ Page ───────────────────────────────────────────────────────────┐
│  ← Recommendations                                               │
│                                                                  │
│  Featured Item Recommendation                    [Pending] ●     │
│  Silver Age Comic Collection — Email             Created Jun 14  │
│                                                                  │
│  ┌─ Why Atlas is recommending this ──────────────────────────── ┐│
│  │                                                              ││
│  │  WHY NOW                          WHY THIS                   ││
│  │  Your Silver Age auction closes   This collection has the    ││
│  │  in 47 hours. Urgency-framed      highest composite score    ││
│  │  posts at this window see 2–3×    of your current inventory, ││
│  │  higher engagement than standard  with strong timing and     ││
│  │  announcements for this type.     confidence signals.        ││
│  │                                                              ││
│  │  WHY THIS CHANNEL                 WHY IT WILL WORK           ││
│  │  Email outperforms social for     Your subscriber list has   ││
│  │  this audience based on your      historically responded     ││
│  │  past campaigns. Your open rate   well to pre-close          ││
│  │  is 34% — above the 22%          auction emails, averaging  ││
│  │  industry benchmark.              41% open rate.             ││
│  │                                                              ││
│  └──────────────────────────────────────────────────────────────┘│
│                                                                  │
│  ┌─ Expected impact ───────────────────────────────────────────┐ │
│  │  ~2,000 reach · 25% open rate · Bids may increase 15%      │ │
│  │  Confidence: ████████░░  78%  ·  Based on 4 past campaigns  │ │
│  └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  ┌─ Email content ─────────────────────────────────────────────┐ │
│  │  Subject: "Last 47 hours — Silver Age Collection"           │ │
│  │  Preview: "These don't come up often. Here's why this…"    │ │
│  │  ─────────────────────────────────────────────────────────  │ │
│  │  We run weekly auctions and this week's Silver Age…        │ │
│  │  [Read more]                                                │ │
│  │                                                             │ │
│  │                                             [Edit content]  │ │
│  └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  ┌─ Your decision ─────────────────────────────────────────────┐ │
│  │                                                             │ │
│  │  [  Approve  ]   [ Edit & Approve ]       Not this time     │ │
│  │   Primary btn      Secondary btn          Tertiary text     │ │
│  │                                                             │ │
│  │  Approving will queue this email for publishing.            │ │
│  │  You can cancel before it sends.                            │ │
│  └─────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────┘
```

### Rationale quadrant styles

```
Grid:           2×2, 16px gap
Quadrant bg:    var(--color-surface-subtle)
Quadrant pad:   20px
Quadrant radius: 8px
Label:          text-label, text-muted, uppercase, mb-2
Body:           text-body-lg (16px / 26px), text-secondary
```

Why `text-body-lg`? The user must read this. It is not metadata. It is the explanation that earns trust. Squinting at 13px copy is friction that leads to blind approval.

### Content preview styles

```
Container:      ghost card variant (border only)
Subject line:   text-body, text-primary, font-weight-500
Preview text:   text-body-sm, text-muted, italic
Divider
Body:           text-body, text-secondary, max 6 lines before "Read more"
Edit trigger:   tertiary-style link, right-aligned ("Edit content")
```

### Inline content editor (Edit & Approve flow)

When the user clicks "Edit & Approve" or "Edit content":

```
[Content editor appears inline, replacing the preview]
┌─────────────────────────────────────────────────────────┐
│  Subject line                                           │
│  ┌─────────────────────────────────────────────────┐   │
│  │ Last 47 hours — Silver Age Collection           │   │
│  └─────────────────────────────────────────────────┘   │
│                                                         │
│  Body                                                   │
│  ┌─────────────────────────────────────────────────┐   │
│  │ We run weekly auctions and this week's          │   │
│  │ Silver Age...                                   │   │
│  │                                                 │   │
│  └─────────────────────────────────────────────────┘   │
│                                                         │
│  [Save & Approve]                [Cancel edit]          │
└─────────────────────────────────────────────────────────┘
```

"Save & Approve" is a Primary button. "Cancel edit" reverts to the preview without losing the page state.

### Decision explanation copy

Below the action buttons, always show one sentence of plain-language explanation:

- Before approval: "Approving will queue this email for publishing. You can cancel before it sends."
- After approval: "Queued for publishing. Atlas will let you know when it goes out."
- After rejection: "Got it. Atlas will keep watching for the next opportunity."

These are not legal disclaimers. They are conversational acknowledgments.

---

## 13. Opportunity Cards

Used in `/app/opportunities` list and as a summary in the dashboard.

### Card layout

```
┌─────────────────────────────────────────────────────────┐
│  [LightBulbIcon]  Urgency Opportunity        [Open] [→] │
│                                                         │
│  12 Silver Age auctions close in 48 hours               │
│  High-value items nearing their promotion window        │
│                                                         │
│  Composite Score                                        │
│  ████████████████████████░░░░   84 / 100               │
│                                                         │
│  Relevance  ██████████  78      Timing   ████████  91  │
│  Confidence ███████░░░  72      Urgency  ██████████  88 │
│                                                         │
│  Detected 2 hours ago · Expires in 47h 12m             │
└─────────────────────────────────────────────────────────┘
```

### Score bar styles

```
Track:       4px height, background: var(--color-border), radius: 2px
Fill:        4px height, radius: 2px, color varies by value

0–39:  #f87171   (red-400)    — low
40–59: #fb923c   (orange-400) — below average
60–74: #facc15   (yellow-400) — moderate
75–89: #4ade80   (green-400)  — good
90+:   #34d399   (emerald-400)— excellent
```

Score is always shown as a number (`84`) next to the bar. Never "84%" (it's a score, not a percentage).

### Expiry treatment

```
No expiry:         text-muted "No expiry set"
> 7 days:          text-muted "Expires in 9 days"
2–7 days:          text-secondary "Expires in 3 days"
< 48 hours:        amber-700 "Expires in 47h 12m" + ClockIcon (amber)
< 24 hours:        rose-700 "Expires in 6h 30m" + ClockIcon (rose)
Expired:           text-muted + strikethrough, status → stone neutral
```

---

## 14. Campaign Cards

Used in the `/app/campaigns` timeline list.

### Card layout

```
┌──────────────────────────────────────────────────────────────┐
│  [MegaphoneIcon]  Silver Age Email Campaign   [Published] →  │
│                                                              │
│  Featured Item · Email                                       │
│                                                              │
│  ○────●────●────●────●   Progress trail                      │
│  Draft  Approved  Queued  Sent  Analytics                    │
│                                                              │
│  Approved Jun 14 · Published Jun 14 · 2,100 reach           │
└──────────────────────────────────────────────────────────────┘
```

### Progress trail

Visual indicator of where a campaign is in its lifecycle. Five steps:

```
Draft → Approved → Queued → Executing → Completed / Published
              ↘ Cancelled (branch, not shown in trail)
```

Trail style:
```
Connector line:    2px solid
Completed step:    filled circle (accent-500), filled connector
Current step:      filled circle (accent-500), pulsing indicator
Future step:       open circle (border-200)
Cancelled:         all steps rendered stone/neutral, x-mark on cancelled step
```

---

## 15. Metric Cards

Used in Analytics summary and Dashboard summary area.

### Single-metric card

```
┌─────────────────────────────┐
│  APPROVAL RATE              │
│                             │
│  73%                        │  ← text-display, text-primary
│                             │
│  ↑ 8pp from last month      │  ← text-body-sm, trend color
└─────────────────────────────┘
```

### Metric card styles

```
Card variant:  standard (white, border)
Label:         text-label, text-muted, uppercase, mb-4
Primary value: text-display (30px/600) or text-heading-1 (24px/600) depending on available width
Trend:         text-body-sm
Trend up:      green-600, ArrowTrendingUpIcon (16px)
Trend down:    rose-600, ArrowTrendingDownIcon (16px)
Trend neutral: text-muted, MinusIcon (16px)
```

### Expected vs actual KPI card

Used in campaign analytics detail:

```
┌──────────────────────────────────────────────────────┐
│  REACH                                               │
│                                                      │
│  Actual      2,140        Expected  ~2,000           │
│  ──────────────────────────────────────────────────  │
│  ████████████████████████░░  +7% above expected      │
└──────────────────────────────────────────────────────┘
```

Actual is text-primary / bold. Expected is text-muted. The bar shows actual vs expected with a clear visual comparison.

---

## 16. Timeline Components

Used in the Publishing activity page and Campaign detail page.

### Vertical timeline

```
  ●  Email published to 2,100 recipients      Jun 14, 4:30 PM
  │  Silver Age Campaign · Email channel
  │
  ●  Campaign approved by Marcus              Jun 14, 2:15 PM
  │
  ●  Recommendation created                  Jun 14, 11:00 AM
  │
  ○  Opportunity detected                    Jun 13, 8:45 AM
```

### Timeline styles

```
Connector line:  1px solid var(--color-border)
                 left-aligned, 20px from left edge of dots
Dot (complete):  8px filled circle, color matches event status
Dot (pending):   8px open circle, border var(--color-border-strong)
Dot (failed):    8px rose-500 filled

Event label:     text-body, text-primary, font-weight 500
Event detail:    text-body-sm, text-muted, mt-1
Timestamp:       text-body-sm, text-muted, float right
Vertical gap:    24px between events
```

---

## 17. Empty States

Three categories of empty state, each with distinct tone.

### Category 1: Atlas is working (no action needed)

Tone: Reassuring. Atlas is doing its job. No anxiety.

```
          ○
         /|\
        [···]

        Learning about your business

  Atlas is analyzing your website.
  Your first recommendation will appear here — usually within a few minutes.

  No action needed.
```

Icon: calm animated pulse (not a spinner — that implies the user is waiting for something interactive)

### Category 2: User action required

Tone: Clear and direct. One CTA. No judgment.

```
          ○──

        Connect your website

  Atlas needs a website to analyze before it can start finding
  opportunities for your business.

  [Connect Website →]
```

Icon: integration/link icon  
CTA: Primary button  
One CTA only — never offer multiple paths from an empty state.

### Category 3: Genuinely empty, and that's fine

Tone: Matter-of-fact. Not apologetic.

```
          ○

        No campaigns yet

  Atlas will recommend your first campaign once it has analyzed
  your business and identified a strong opportunity.
  Usually happens within 24 hours of connecting your website.

```

No CTA (no action needed). No apology. Just context.

### Empty state styles

```
Container:     centered, max-width 360px, mx-auto, py-16
Icon:          40px, text-muted
Heading:       text-heading-3, text-primary, mt-4
Body:          text-body, text-muted, mt-2, text-center
CTA:           mt-6, Primary button (when applicable)
```

---

## 18. Loading Skeletons

Shown while Inertia page data loads or during API polling. Mirror the layout of the content being loaded.

### Skeleton animation

```css
@keyframes skeleton-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}

.skeleton {
  background-color: var(--color-border);
  border-radius: 4px;
  animation: skeleton-pulse 1.5s ease-in-out infinite;
}
```

### Skeleton variants

**Card skeleton:**
```
┌─────────────────────────────────────────┐
│  ████ ██████████████░░░░    ████████    │  ← title + badge
│  ─────────────────────────────────────  │
│  █████████████████████████████████████  │  ← body line 1
│  ███████████████████░░░░░░░░░░░░░░░░░  │  ← body line 2
│  ████████████░░░░░░░░░░░░░░░░░░░░░░░░  │  ← body line 3
└─────────────────────────────────────────┘
```

**Metric card skeleton:**
```
┌──────────────────┐
│  ████████        │  ← label
│                  │
│  ████            │  ← large number (wide-short block)
│                  │
│  ████████░░      │  ← trend
└──────────────────┘
```

**Table row skeleton:**
```
│  ████████████░░  ████████░░  ████░░  ██░  │  ← ~3 rows visible
```

### Skeleton usage rules

- Show skeleton for initial page load only, not for background refreshes
- Do not show a full-page spinner — always use layout-matching skeletons
- Skeleton should match the approximate width of real content (not full-width blocks everywhere)
- Minimum skeleton display: 300ms (prevents jarring flash for fast loads)

---

## 19. Animations

### Philosophy

Animations in Atlas should be invisible. The user should never think "that was a nice animation." They should simply find that the interface feels smooth and responsive.

**Permitted:**
- State transitions (hover, focus, active)
- Content appearing (fade-in)
- Skeleton pulse
- Score bar fill on first render
- Badge status color transitions

**Not permitted:**
- Bounce or spring physics
- Slide-from-side page transitions
- Celebratory animations on approval (confetti, fireworks) — this is a business tool
- Scroll-triggered reveals
- Rotating or moving decorative elements

### Transition tokens

```css
@theme {
  --duration-instant:  0ms;     /* no animation — toggle states */
  --duration-fast:     100ms;   /* hover state transitions */
  --duration-base:     150ms;   /* most UI transitions */
  --duration-smooth:   200ms;   /* content appearing */
  --duration-slow:     300ms;   /* larger layout changes */

  --ease-standard: cubic-bezier(0.4, 0, 0.2, 1);   /* material ease */
  --ease-in:       cubic-bezier(0.4, 0, 1, 1);
  --ease-out:      cubic-bezier(0, 0, 0.2, 1);
}
```

### Standard transitions

| Element | Property | Duration | Easing |
|---------|----------|---------|--------|
| Button hover | background-color | 100ms | ease-standard |
| Link hover | color | 100ms | ease-standard |
| Card hover | box-shadow | 150ms | ease-standard |
| Input focus ring | box-shadow | 150ms | ease-out |
| Badge status change | color, background | 150ms | ease-standard |
| Page fade-in | opacity | 200ms | ease-out |
| Skeleton pulse | opacity | 1500ms | ease-in-out |
| Score bar fill | width | 400ms | ease-out (once, on mount) |
| Sidebar nav active | background | 100ms | ease-standard |

### Inertia page transitions

Use `@inertiajs/vue3`'s `onStart` / `onFinish` hooks to fade between pages:

```
On navigate start:   content fades to opacity 0 over 100ms
On navigate finish:  content fades to opacity 1 over 200ms
```

This is the only slide or fade that crosses the full viewport. Keep it subtle — 100ms out, 200ms in.

---

## 20. Accessibility

### Contrast requirements

All text must meet WCAG 2.1 AA at minimum. Target AAA for body text.

| Use | Min ratio | Target |
|-----|-----------|--------|
| Body text (`text-secondary` on white) | 4.5:1 | 8.0:1 |
| Headings (`text-primary` on white) | 4.5:1 | 16.0:1 |
| Muted text (`text-muted` on white) | 4.5:1 | 6.0:1 |
| Placeholder text | 3.0:1 | — |
| Status badge text on badge background | 4.5:1 | — |
| White text on accent-500 (primary button) | 4.5:1 | — |

Check computed values: `indigo-500 (#6366f1)` on white = ~5.9:1. Passes AA. Stone-500 (#78716c) on white = ~4.6:1. Passes AA.

### Keyboard navigation

All interactive elements reachable via Tab. Focus order matches visual reading order (left-to-right, top-to-bottom).

Focus ring: `2px solid var(--color-border-focus)` with `2px offset`. Never remove the focus ring (`outline: none` without replacement is prohibited).

Action buttons in tables: revealed on keyboard focus even when not hovered (hover-to-reveal patterns break keyboard navigation).

### ARIA requirements

| Pattern | Requirement |
|---------|------------|
| Icon-only buttons | `aria-label` required |
| Status badges | `role="status"` if they update dynamically |
| Live regions (polling status) | `aria-live="polite"` |
| Score bars | `role="progressbar"`, `aria-valuenow`, `aria-valuemin`, `aria-valuemax` |
| Loading skeletons | `aria-busy="true"` on container while loading |
| Modal dialogs | `role="dialog"`, `aria-modal="true"`, focus trap |
| Navigation | `<nav aria-label="Main navigation">` |
| Recommendation rationale | Proper heading structure `h2` → `h3` per quadrant |

### Heading structure

Each page has exactly one `<h1>` (page title). Section headings use `<h2>`. Card titles use `<h3>`. Rationale quadrant labels use `<h4>` or strong labels — not headings (they are labels, not document sections).

Never skip heading levels for visual styling. Use CSS to change visual size; use the correct semantic element for structure.

### Screen reader text

Use the `.sr-only` Tailwind class for text that is meaningful to screen readers but visually absent:
- Context for icon-only buttons: `<span class="sr-only">Approve recommendation</span>`
- Status announcements: `<span class="sr-only" aria-live="polite">Recommendation approved</span>`

### Motion preferences

Respect `prefers-reduced-motion`:

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    transition-duration: 0.01ms !important;
  }
}
```

Score bar fill: instant on `prefers-reduced-motion`. Skeleton: no pulse (static gray block). Page transitions: none.

---

## 21. Dark Mode Strategy

### Decision: Light mode only for MVP

Dark mode is not implemented in Milestone 10. The dashboard serves business owners who access it during working hours, primarily on desktop or laptop. There is no clear user demand that justifies the additional implementation and QA cost for the first customer release.

Dark mode is explicitly deferred — not abandoned.

### Token architecture enables future dark mode

All color definitions use semantic CSS custom properties. When dark mode is added, only one layer changes: the token definitions under `@media (prefers-color-scheme: dark)` or `.dark` class. No component-level color changes are needed.

```css
/* Current (light only) */
@theme {
  --color-surface: #fafaf9;
  --color-surface-elevated: #ffffff;
  --color-text-primary: #1c1917;
  /* ... */
}

/* Future dark mode addition (example — not implemented now) */
@media (prefers-color-scheme: dark) {
  :root {
    --color-surface: #1c1917;
    --color-surface-elevated: #292524;
    --color-text-primary: #fafaf9;
    /* ... */
  }
}
```

### Dark mode design constraints (for when it is implemented)

- Backgrounds shift from stone-50/white → stone-950/stone-900
- Card elevated surfaces use stone-800 (not black — too stark)
- Accent color lightens to indigo-400 for better contrast on dark backgrounds
- Status colors use their `-400` variants on dark backgrounds (not `-700` — too hard to read on dark)
- Filament's dark mode is already separate — it does not affect the customer panel

---

## Appendix A: Tailwind v4 `@theme` Summary

The full custom token block that extends the base Tailwind theme in `resources/css/app.css`:

```css
@import 'tailwindcss';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';

@theme {
  /* Typography */
  --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
  --font-mono: ui-monospace, 'Cascadia Code', 'Source Code Pro', Menlo, Consolas, monospace;

  /* Colors — surfaces */
  --color-surface:            #fafaf9;
  --color-surface-elevated:   #ffffff;
  --color-surface-subtle:     #f5f5f4;
  --color-surface-overlay:    #292524;

  /* Colors — borders */
  --color-border:             #e7e5e4;
  --color-border-strong:      #d6d3d1;
  --color-border-focus:       #6366f1;

  /* Colors — text */
  --color-text-primary:       #1c1917;
  --color-text-secondary:     #44403c;
  --color-text-muted:         #78716c;
  --color-text-placeholder:   #a8a29e;
  --color-text-disabled:      #d6d3d1;
  --color-text-inverse:       #fafaf9;
  --color-text-link:          #4f46e5;

  /* Colors — accent (indigo) */
  --color-accent-50:          #eef2ff;
  --color-accent-100:         #e0e7ff;
  --color-accent-200:         #c7d2fe;
  --color-accent-500:         #6366f1;
  --color-accent-600:         #4f46e5;
  --color-accent-700:         #4338ca;

  /* Transitions */
  --duration-fast:            100ms;
  --duration-base:            150ms;
  --duration-smooth:          200ms;
  --duration-slow:            300ms;
  --ease-standard:            cubic-bezier(0.4, 0, 0.2, 1);
  --ease-out:                 cubic-bezier(0, 0, 0.2, 1);
}
```

Status colors use Tailwind's built-in `blue`, `amber`, `green`, `stone`, `rose`, `violet` families directly — no custom tokens needed since they map cleanly to existing Tailwind scales.

---

## Appendix B: Component Checklist

Before marking any component as implementation-complete, verify:

- [ ] Renders correctly at all three responsive states (mobile / tablet / desktop)
- [ ] All interactive states implemented: default, hover, focus, active, disabled
- [ ] Focus ring visible and meets contrast requirements
- [ ] Icon buttons have `aria-label`
- [ ] Heading levels correct (no skipped levels)
- [ ] Empty/null state handled (no blank space or undefined text)
- [ ] Loading state handled (skeleton or spinner as appropriate)
- [ ] `prefers-reduced-motion` respected for any animation
- [ ] Color contrast verified for all text/background combinations in use
- [ ] Keyboard-navigable (Tab reaches it; Enter/Space activates it)
