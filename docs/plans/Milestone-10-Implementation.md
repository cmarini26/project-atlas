# Milestone 10 — Customer Dashboard & UX
## Implementation Plan

**Status:** Plan — not yet implemented  
**Author:** Claude Sonnet 4.6  
**Date:** 2026-06-27  
**Prerequisite:** Milestone 9.5 complete. All production blockers resolved. Backend intelligence platform fully operational.

---

## 1. What We Are Building

The first customer-facing experience on top of the completed Atlas intelligence platform.

Atlas already does everything: it observes businesses, builds Digital Twins, detects opportunities, commits decisions, prepares campaigns, and records learnings. None of that is visible to the business owner. This milestone changes that.

The output of Milestone 10 is a web dashboard where a business owner or marketing manager can:

- Understand what Atlas knows about their business (Business Brain summary)
- See what opportunities Atlas has identified
- Review a recommendation waiting for their approval — with a full explanation of why Atlas made it
- Approve, edit, or reject that recommendation
- Track their campaigns across their lifecycle
- See their publishing activity and status
- View analytics and performance data
- Read learning insights (what Atlas has learned from their history)
- Understand their company health and Digital Twin status

This is not a blank-prompt AI tool. It is an intelligence surface. Every screen answers a question the user has, without them having to ask it.

---

## 2. What We Are NOT Building

This plan is strictly bounded. The following are out of scope for Milestone 10:

- No new AI capabilities. All AI output is already in the database. The dashboard reads it.
- No redesign of backend services. Controllers are thin wrappers on existing services.
- No new event chains, jobs, or listeners.
- No billing, subscription, or payments.
- No team multi-seat collaboration beyond owner/admin.
- No mobile application.
- No ads integrations.
- No new publishing channels.
- No new analytics providers.

---

## 3. Personas

*These must be written before any UI code. Placed here because `docs/product/Personas.md` is currently empty.*

### 3.1 Marcus — Comic Book Auction House Owner

**Role:** Owner, CBB Auctions  
**Technical level:** Low. Uses email, Facebook, a browser. Not a marketer.  
**Time available for marketing:** 30–60 minutes per week, fragmented  
**Goal:** Know which auctions to promote, have the content already written, publish without stress  
**Pain:** Writes the same posts over and over. Doesn't know which items to feature. Misses promotion windows because he's busy running the auction.  
**What Atlas means to him:** Someone who noticed the auction closes in 48 hours and already drafted the email before Marcus thought to ask

**Key behaviors:**
- Checks the dashboard once or twice a week
- Wants to see exactly one pending recommendation, not twenty
- Will read the explanation if it's short and plain-language
- Will not approve something he doesn't understand
- Expects Atlas to get better over time — remembers what he liked

**Primary interactions with the dashboard:**
- Reviewing and approving a pending recommendation
- Checking that last week's campaign went out
- Occasionally reviewing what Atlas knows about his business

---

### 3.2 Sofia — Marketing Manager

**Role:** Part-time marketing contractor managing 2–3 clients  
**Technical level:** Medium. Familiar with email marketing, social scheduling tools, basic analytics.  
**Time available:** Dedicated marketing time (unlike Marcus). Reviews Atlas output more critically.  
**Goal:** Produce high-quality, on-brand content efficiently. Catch anything Atlas gets wrong before it goes out.  
**Pain:** Client feedback loops are slow. Scheduling tools don't understand context. She has to remember what worked last time manually.

**Key behaviors:**
- Logs in more frequently than Marcus (daily or near-daily during active campaigns)
- Edits content before approving — Atlas's first draft is a starting point
- Wants to see analytics after publishing to validate decisions
- Reviews Learning insights to understand how Atlas is improving
- Would flag a recommendation if the rationale didn't make sense

**Primary interactions with the dashboard:**
- Reviewing recommendations in detail (all four rationale fields, content previews)
- Editing content before approving (edit & approve flow)
- Monitoring campaign status and execution
- Reading analytics and comparing to expected impact

---

## 4. User Flows

*These must be documented before building pages. Placed here because `docs/product/UserFlows.md` is currently empty.*

### Flow 1: First-time Setup (Onboarding)

```
Sign up → Create company profile → Connect website URL
  → Atlas crawls → "Atlas is learning about your business" state
  → Digital Twin activates → First recommendation appears
  → Notification or redirect to recommendation
```

**Key UX decisions:**
- The setup wizard is 3 steps: company name/industry, website URL, confirm
- After URL submission, show a live status page: crawling → extracting facts → synthesizing knowledge → ready
- First recommendation appears as a notification card as soon as it's ready
- No blank state — the user should never see an empty dashboard wondering what to do next

---

### Flow 2: Reviewing and Approving a Recommendation (Primary Loop)

```
Dashboard → "1 recommendation pending" → Open recommendation
  → Read rationale (why now / why this / why this channel / why it will work)
  → Preview content for each channel
  → Approve → Confirmation → Return to dashboard
```

**Key UX decisions:**
- The recommendation card is always the most prominent thing on the dashboard
- The rationale is not hidden — it is the primary content, not a collapsed footnote
- Content preview is faithful to the final output (not a lorem ipsum placeholder)
- Approve is one click, with a confirmation. No multi-step approval for MVP.
- After approval, show a brief success state with "What happens next" explanation

---

### Flow 3: Editing Before Approving

```
Open recommendation → Read content → Click "Edit" on a content asset
  → Inline edit view → Save changes → Edit & Approve
```

**Key UX decisions:**
- Editing is optional — most users won't use it at first
- Changes are captured in `Approval.edits` — this is how Atlas learns preferences
- The edit UI does not need to be a rich editor for MVP — plain textarea is sufficient
- After editing, the user sees a diff of what they changed (optional enhancement)

---

### Flow 4: Rejecting a Recommendation

```
Open recommendation → Read → Click "Not this time" → Optional rejection reason
  → Confirm rejection → Atlas acknowledges and returns to dashboard
```

**Key UX decisions:**
- Rejection is never punishing — Atlas doesn't make the user feel bad for saying no
- Rejection note is optional but encouraged ("help Atlas learn")
- After rejection, the dashboard explains what Atlas will do next (keep watching for the next opportunity)

---

### Flow 5: Checking Campaign Status

```
Dashboard → Campaigns → Filter by status (draft, approved, publishing, completed)
  → Campaign detail → See content assets, channels, execution status
  → For completed campaigns: see analytics vs expected impact
```

---

### Flow 6: Reading Analytics

```
Dashboard → Analytics → Campaign KPI summary
  → Campaign selector → Actual vs expected side-by-side
  → Channel breakdown → Best-performing content
```

---

## 5. Architecture Decision: Inertia.js + Vue 3 + TypeScript

### 5.1 Decision

The customer dashboard is built with **Inertia.js + Vue 3 + TypeScript + Tailwind CSS**.

The Filament admin panel (`/admin`) remains unchanged — superadmin only.

The customer dashboard lives at `/app/*`.

### 5.2 Why Inertia.js (not API-first SPA)

- Eliminates separate auth setup, CORS configuration, and token management for the dashboard
- Server-side routing with client-side transitions — feels like a SPA, works like a web app
- Company context is resolved by Laravel middleware before any controller runs — no client-side company resolution
- All existing services return PHP objects — Inertia serializes them directly via `toArray()` or typed DTOs
- Aligns with CLAUDE.md preferred stack: "Vue 3 + TypeScript + Tailwind CSS + Inertia.js"
- Simpler to deploy — one server, one codebase, one session store

### 5.3 Why not extend Filament

- Filament targets admin/ops users — its component language and interaction patterns are not appropriate for a business owner with 30 minutes per week
- Filament uses Livewire/AlpineJS — adding Vue on top creates a mixed-paradigm codebase
- The recommendation approval UX (content previews, rationale cards, edit flow) requires custom component design that Filament's table/form/infolist primitives cannot express cleanly

### 5.4 Auth Architecture

Two session-based auth contexts exist in parallel:

| Context | Path | Panel | Auth |
|---------|------|-------|------|
| Customer dashboard | `/app/*` | Inertia | Laravel web session (`auth:web` guard) |
| Admin panel | `/admin/*` | Filament | Filament auth (same users, `is_superadmin` gate) |

No API tokens for the dashboard. Sessions only.

New middleware: `EnsureCompanyMembership` — resolves the current company from the authenticated user's `company_memberships` record and binds it in the request for all `/app/*` routes. For MVP: a user with one company membership is automatically scoped to it. A user with multiple memberships sees a company selector on first login.

---

## 6. Route Structure

```
GET  /                              → Welcome/marketing page (public)
GET  /login                         → Login page (Inertia)
POST /login                         → Authenticate
POST /logout                        → Log out
GET  /register                      → Register page (Inertia)
POST /register                      → Create account

# Onboarding (company setup)
GET  /onboarding                    → Onboarding wizard (step 1: company profile)
POST /onboarding/company            → Create company + twin + catalog
POST /onboarding/integration        → Register website URL + dispatch SyncIntegration
GET  /onboarding/status             → Pipeline status page (polls /api/onboarding/status)

# Customer dashboard (company-scoped, requires EnsureCompanyMembership)
GET  /app                           → Dashboard overview
GET  /app/brain                     → Business Brain detail
GET  /app/opportunities             → Active Opportunities list
GET  /app/recommendations           → Recommendations list (pending + recent)
GET  /app/recommendations/{id}      → Recommendation detail
POST /app/recommendations/{id}/approve         → Approve
POST /app/recommendations/{id}/approve-edit    → Edit & Approve
POST /app/recommendations/{id}/reject          → Reject
GET  /app/campaigns                 → Campaign timeline
GET  /app/campaigns/{id}            → Campaign detail with assets
GET  /app/publishing                → Publishing activity feed
GET  /app/analytics                 → Analytics summary
GET  /app/analytics/{campaignId}    → Campaign analytics detail
GET  /app/learning                  → Learning insights (read-only)
GET  /app/settings                  → Company settings

# API (lightweight JSON endpoints for polling/partial refreshes)
GET  /api/onboarding/status         → Pipeline status for onboarding page
GET  /api/dashboard/summary         → Summary counts for dashboard header cards
```

---

## 7. Controller Inventory

One controller per domain surface. All controllers are thin — they call existing services, shape data for the view, and return `Inertia::render()`. No business logic in controllers.

| Controller | Namespace | Responsibility |
|------------|-----------|----------------|
| `AuthController` | `Http\Controllers\Auth` | Login, logout, register |
| `OnboardingController` | `Http\Controllers` | Company setup wizard + integration trigger |
| `DashboardController` | `Http\Controllers\App` | Overview — assembles all surface counts and recent activity |
| `BusinessBrainController` | `Http\Controllers\App` | Brain detail — calls `BusinessBrainService::for()` |
| `OpportunityController` | `Http\Controllers\App` | Active opportunities list |
| `RecommendationController` | `Http\Controllers\App` | List, detail, approve, reject, editAndApprove |
| `CampaignController` | `Http\Controllers\App` | Timeline + campaign detail |
| `PublishingController` | `Http\Controllers\App` | Execution activity feed |
| `AnalyticsController` | `Http\Controllers\App` | KPI summary + campaign analytics detail |
| `LearningController` | `Http\Controllers\App` | Learning feed (read-only) |
| `SettingsController` | `Http\Controllers\App` | Company settings, integration management |
| `OnboardingStatusController` | `Http\Controllers\Api` | JSON endpoint for pipeline status polling |

---

## 8. Data Shape per Surface

Each surface describes exactly which existing model/service provides the data and how it is shaped for the view.

### 8.1 Dashboard Overview

**Route:** `GET /app`  
**Controller:** `DashboardController::index()`  
**Data provided to Inertia:**

```
company: { name, slug, industry }
twin: { status, health_score, last_enriched_at }
counts: {
  pending_recommendations: int,
  open_opportunities: int,
  active_campaigns: int,
  unapplied_learnings: int,
}
pending_recommendation: Recommendation|null  ← the topmost pending one, fully loaded
recent_campaigns: Campaign[]               ← last 3
recent_executions: Execution[]             ← last 5
```

**Services called:**
- `DigitalTwin::where('company_id', ...)->first()`
- `Recommendation::where(['company_id', 'status' => 'pending'])->count()`
- `Opportunity::where(['company_id', 'status' => 'open'])->count()`
- `Recommendation::with(['decision', 'campaign.contentAssets'])->where('status', 'pending')->first()`

---

### 8.2 Business Brain

**Route:** `GET /app/brain`  
**Controller:** `BusinessBrainController::index()`  
**Data provided to Inertia:**

```
twin: { status, health_score, last_enriched_at }
facts: Fact[]                    ← is_current = true, ordered by confidence desc
knowledge: Knowledge[]           ← is_active = true
recent_observations: Observation[] ← last 5
catalog: { name, type, item_count }
```

**Services called:**
- `BusinessBrainService::for($company)` — the cached VO
- Unpack from VO: `$brain->twin`, `$brain->activeFacts`, `$brain->activeKnowledge`, `$brain->recentObservations`

---

### 8.3 Active Opportunities

**Route:** `GET /app/opportunities`  
**Controller:** `OpportunityController::index()`  
**Data provided to Inertia:**

```
opportunities: Opportunity[]  ← status=open, ordered by composite_score desc
  each has: { type, title, description, composite_score, detected_at, expires_at,
              relevance_score, timing_score, confidence_score, urgency_score,
              subject_type, subject_id }
```

**Services called:**
- Direct Eloquent query via `OpportunityRepository` (existing)

---

### 8.4 Recommendations

**Route:** `GET /app/recommendations`  
**Controller:** `RecommendationController::index()`  
**Data provided to Inertia:**

```
pending: Recommendation[]   ← status=pending, newest first, with decision + campaign
recent: Recommendation[]    ← status in [approved, rejected], last 10
```

**Route:** `GET /app/recommendations/{id}`  
**Controller:** `RecommendationController::show()`  
**Data provided to Inertia:**

```
recommendation: {
  id, status, rationale_display, expected_impact, campaign_type, responded_at
}
decision: {
  rationale: { why_now, why_this, why_channel, why_works },
  expected_impact: { summary, reach_estimate, engagement_signal, confidence_basis },
  confidence_score
}
campaign: {
  id, title, campaign_type, blueprint, status
}
content_assets: ContentAsset[]  ← one per channel, includes body, type, metadata
channels: Channel[]
```

**POST endpoints** delegate to existing `ApprovalService::approve()`, `reject()`, `editAndApprove()`.

---

### 8.5 Campaign Timeline

**Route:** `GET /app/campaigns`  
**Controller:** `CampaignController::index()`  
**Data provided to Inertia:**

```
campaigns: Campaign[]   ← all statuses, ordered by created_at desc, paginated (15/page)
  each has: { id, title, campaign_type, status, created_at, completed_at }
```

**Route:** `GET /app/campaigns/{id}`  
**Controller:** `CampaignController::show()`  
**Data provided to Inertia:**

```
campaign: Campaign (all fields)
content_assets: ContentAsset[]  ← with channel
executions: Execution[]         ← with status, scheduled_at, completed_at
kpi_snapshot: CampaignKpiSnapshot|null
decision: { rationale, expected_impact }
```

---

### 8.6 Publishing Activity

**Route:** `GET /app/publishing`  
**Controller:** `PublishingController::index()`  
**Data provided to Inertia:**

```
executions: Execution[]  ← all statuses, ordered by created_at desc, paginated (20/page)
  each has: { id, status, scheduled_at, executed_at, completed_at, last_error,
              channel: { type, name },
              content_asset: { type, body truncated to 120 chars } }
```

---

### 8.7 Analytics Summary

**Route:** `GET /app/analytics`  
**Controller:** `AnalyticsController::index()`  
**Data provided to Inertia:**

```
recommendation_kpis: {
  approval_rate: float,
  rejection_rate: float,
  edit_rate: float,
  median_time_to_decision_hours: float,
  total_recommendations: int,
}
campaign_snapshots: CampaignKpiSnapshot[]  ← last 10 final snapshots, newest first
```

**Route:** `GET /app/analytics/{campaignId}`  
**Controller:** `AnalyticsController::show()`  
**Data provided to Inertia:**

```
campaign: Campaign (title, type, status)
decision: { expected_impact }
snapshot: CampaignKpiSnapshot  ← or null if not yet finalized
metrics: ExecutionMetric[]     ← per-execution breakdown
```

**Services called:**
- `RecommendationKpiService` (existing)
- `CampaignKpiService` (existing)

---

### 8.8 Learning Insights

**Route:** `GET /app/learning`  
**Controller:** `LearningController::index()`  
**Data provided to Inertia:**

```
learnings: Learning[]   ← company-scoped, ordered by created_at desc, paginated (20/page)
  each has: { signal, value, applied_at, source_type, created_at }
applied_effects: LearningApplication[]  ← last 10, with effects descriptor
```

*Read-only. No actions from this page.*

---

### 8.9 Company Health Overview (Dashboard Card)

Assembled inline in `DashboardController::index()`. Not a separate page.

```
health: {
  twin_status: string,            ← 'initializing' | 'active'
  twin_health_score: int,
  twin_last_enriched_at: datetime|null,
  fact_count: int,
  knowledge_count: int,
  integration_count: int,
  integration_statuses: { type: string, status: string }[]
}
```

---

## 9. Vue Page and Component Inventory

### 9.1 Layout

```
resources/js/Layouts/AppLayout.vue
  ├── Sidebar navigation (links to all /app/* pages)
  ├── Company name + Digital Twin status badge in header
  ├── Notification bell (pending recommendations count)
  └── User menu (logout)

resources/js/Layouts/AuthLayout.vue
  └── Centered card layout for login/register/onboarding
```

### 9.2 Pages (one per route)

```
resources/js/Pages/
├── Auth/
│   ├── Login.vue
│   └── Register.vue
├── Onboarding/
│   ├── Index.vue         ← wizard with 3 steps
│   └── Status.vue        ← live pipeline progress
├── App/
│   ├── Dashboard.vue     ← overview with all surface cards
│   ├── Brain.vue         ← Business Brain detail
│   ├── Opportunities.vue
│   ├── Recommendations/
│   │   ├── Index.vue
│   │   └── Show.vue      ← detail + approval actions
│   ├── Campaigns/
│   │   ├── Index.vue
│   │   └── Show.vue
│   ├── Publishing.vue
│   ├── Analytics/
│   │   ├── Index.vue
│   │   └── Show.vue
│   ├── Learning.vue
│   └── Settings.vue
```

### 9.3 Reusable Components

```
resources/js/Components/
├── UI/
│   ├── Card.vue              ← base card with optional title/badge
│   ├── Badge.vue             ← status pill (color-coded by status value)
│   ├── ScoreBar.vue          ← horizontal score visualization (0–100)
│   ├── EmptyState.vue        ← consistent empty state with message + CTA
│   ├── Pagination.vue        ← standard pagination controls
│   └── LoadingSpinner.vue
├── Dashboard/
│   ├── SummaryCard.vue       ← count card (pending recommendations, open opportunities)
│   ├── HealthCard.vue        ← Digital Twin health overview
│   ├── RecommendationPrompt.vue  ← prominent CTA card for pending recommendation
│   └── RecentActivity.vue    ← combined campaign + execution feed
├── Brain/
│   ├── FactList.vue          ← table of current facts with key/value/confidence
│   ├── KnowledgeCard.vue     ← single knowledge entry with subject + body
│   └── TwinStatus.vue        ← status badge + health score + last enriched
├── Opportunities/
│   └── OpportunityCard.vue   ← score visualization + type + expiry
├── Recommendations/
│   ├── RationaleCard.vue     ← 4-panel: why_now / why_this / why_channel / why_works
│   ├── ImpactCard.vue        ← expected_impact display
│   ├── ContentPreview.vue    ← single channel content preview
│   ├── ApproveActions.vue    ← approve / edit & approve / reject buttons
│   └── ContentEditor.vue     ← inline textarea for edit-before-approve
├── Campaigns/
│   ├── CampaignTimeline.vue  ← status-ordered campaign list
│   └── CampaignStatusBadge.vue
├── Analytics/
│   ├── KpiRow.vue            ← expected vs actual side-by-side
│   └── ChannelBreakdown.vue
└── Learning/
    └── LearningEntry.vue     ← signal + value + applied status
```

---

## 10. TypeScript Types

Create `resources/js/types/` with shared types:

```typescript
// types/index.ts — domain types matching backend models

type TwinStatus = 'initializing' | 'active'
type RecommendationStatus = 'pending' | 'approved' | 'rejected'
type CampaignStatus = 'draft' | 'approved' | 'published' | 'cancelled' | 'completed'
type OpportunityStatus = 'open' | 'selected' | 'expired' | 'dismissed'
type ExecutionStatus = 'queued' | 'executing' | 'completed' | 'failed' | 'cancelled'

interface Company { id: string; name: string; slug: string; industry: string | null }
interface DigitalTwin { status: TwinStatus; health_score: number; last_enriched_at: string | null }
interface Fact { id: string; key: string; value: unknown; confidence: number; is_current: boolean }
interface Knowledge { id: string; subject: string; body: string; confidence: number }
interface Opportunity { id: string; type: string; title: string; description: string; composite_score: number; detected_at: string; expires_at: string | null }
interface Decision { id: string; rationale: Rationale; expected_impact: ExpectedImpact; confidence_score: number }
interface Rationale { why_now: string; why_this: string; why_channel: string; why_works: string }
interface ExpectedImpact { summary: string; reach_estimate: string; engagement_signal: string; confidence_basis: string }
interface Recommendation { id: string; status: RecommendationStatus; campaign_type: string; rationale_display: Record<string, string>; expected_impact: Record<string, string> }
interface ContentAsset { id: string; type: string; body: string; status: string; metadata: Record<string, unknown> }
interface Campaign { id: string; title: string; campaign_type: string; status: CampaignStatus; created_at: string }
interface Execution { id: string; status: ExecutionStatus; scheduled_at: string | null; executed_at: string | null; completed_at: string | null; last_error: string | null }
interface Learning { id: string; signal: string; value: Record<string, unknown>; applied_at: string | null; created_at: string }
interface CampaignKpiSnapshot { id: string; snapshot_type: string; actual_kpis: Record<string, number>; performance_rating: string | null }
```

---

## 11. Implementation Sequence

Phases are ordered strictly. Each phase must be complete and tested before the next begins. No parallel implementation tracks.

---

### Phase 1 — Specification (do first, commit before any code)

**Deliverables:**
1. Write `docs/product/Personas.md` — Marcus and Sofia, fully described (see §3 above)
2. Write `docs/product/UserFlows.md` — all 6 flows with steps and UX decisions (see §4 above)

**Why first:** Founding Principle 6 — spec before code. The Personas and UserFlows are empty. Writing them first ensures the UI is designed for real users, not abstract requirements.

---

### Phase 2 — Frontend Foundation

**Deliverables:**
1. Install frontend dependencies:
   ```
   composer require inertiajs/inertia-laravel
   npm install @inertiajs/vue3 vue @vitejs/plugin-vue
   npm install --save-dev typescript vue-tsc @types/node
   ```
2. Configure Vite: add `@vitejs/plugin-vue` plugin to `vite.config.js`
3. Create `resources/js/app.ts` (rename from `app.js`): mount Inertia Vue 3 app
4. Create `resources/views/app.blade.php`: root layout with `@inertiaHead` and `@inertia`
5. Configure Inertia middleware: `HandleInertiaRequests` in `bootstrap/app.php`; shared data: `auth.user`, `company`, `twin.status`, `flash`
6. Create `tsconfig.json` with paths for `@/` alias pointing to `resources/js/`
7. Update `vite.config.js` for TypeScript + Vue
8. Smoke test: one Inertia route renders a Vue component

**Why before auth:** The asset pipeline must work before anything else can be verified.

---

### Phase 3 — Auth + Company Routing

**Deliverables:**
1. Create web auth controllers: `AuthController` (login, logout, register)
2. Create Inertia auth pages: `Login.vue`, `Register.vue` using `AuthLayout.vue`
3. Create `EnsureCompanyMembership` middleware:
   - Resolves company from `CompanyMembership::where('user_id', auth()->id())->first()`
   - Binds `$request->company` for all `/app/*` routes
   - If no membership: redirect to `/onboarding`
   - If multiple memberships: redirect to company selector (list companies, choose one)
4. Register `/app/*` route group with `auth` + `EnsureCompanyMembership` middleware
5. Create `AppLayout.vue`: sidebar, header with company name and twin status, nav links
6. Confirm: login → redirect to `/app` → company resolved → `AppLayout` renders

**Dependency:** Phase 2 complete.

---

### Phase 4 — Onboarding Wizard

**Deliverables:**
1. `OnboardingController` — step 1 (company profile form), step 2 (integration URL), status polling endpoint
2. `Onboarding/Index.vue` — 3-step wizard (company name/industry → website URL → confirm)
3. `Onboarding/Status.vue` — live pipeline status page polling `/api/onboarding/status`; shows progress through: crawling → processing → facts extracted → knowledge ready → opportunities detected → first recommendation ready
4. `OnboardingStatusController` — JSON endpoint returning `{ twin_status, fact_count, opportunity_count, recommendation_count }` for the status page to poll

**UX decision:** After the company is created and integration dispatched, the user is immediately redirected to the status page. The status page polls every 5 seconds. When `recommendation_count > 0`, it shows a "Your first recommendation is ready" button and stops polling.

**Dependency:** Phase 3 complete.

---

### Phase 5 — Dashboard Overview

**Deliverables:**
1. `DashboardController::index()` — assembles overview data (see §8.1)
2. `App/Dashboard.vue` — main dashboard layout with:
   - `RecommendationPrompt.vue` if pending recommendation exists (prominent, top of page)
   - `SummaryCard.vue` ×4 for pending recommendations / open opportunities / active campaigns / unapplied learnings
   - `HealthCard.vue` for Digital Twin status
   - `RecentActivity.vue` for recent campaigns + executions combined feed

**This is the screen the user sees every time they log in. It is the most important screen.**

**Dependency:** Phase 3 complete.

---

### Phase 6 — Recommendation Workflow (Most Critical)

**Deliverables:**
1. `RecommendationController` — index (list), show (detail), approve, reject, approveAndEdit
2. `App/Recommendations/Index.vue` — two sections: pending (prominent) + recent (secondary)
3. `App/Recommendations/Show.vue`:
   - `RationaleCard.vue` — 4-quadrant layout: why now / why this / why this channel / why it will work (each as a distinct readable paragraph)
   - `ImpactCard.vue` — expected reach, engagement signal, confidence, summary
   - Confidence score displayed as a score bar + plain language descriptor ("High confidence", "Moderate confidence")
   - `ContentPreview.vue` per channel — email: subject line + body preview; social: post text + hashtags
   - `ApproveActions.vue` — three actions with distinct visual weight:
     - **Approve** (primary, most prominent)
     - **Edit & Approve** (secondary)
     - **Reject** (tertiary, styled to not tempt clicking)
   - `ContentEditor.vue` — simple textarea that appears inline when "Edit & Approve" is selected; one asset at a time

**This is the primary business loop. Get this right before building anything else.**

**Dependency:** Phase 5 complete.

---

### Phase 7 — Opportunities + Business Brain

**Deliverables:**
1. `OpportunityController::index()` + `App/Opportunities.vue` — score bars, type badges, expiry indicators
2. `BusinessBrainController::index()` + `App/Brain.vue`:
   - Twin status card (status, health score, last enriched)
   - Facts table (key, value, confidence, detected_at)
   - Knowledge cards (subject, body)
   - Recent observations list

**Dependency:** Phase 6 complete. (Can be built in parallel with Phase 6 by a second engineer, but Phase 6 is the priority.)

---

### Phase 8 — Campaign Timeline + Publishing

**Deliverables:**
1. `CampaignController` (index + show) + `App/Campaigns/Index.vue` + `App/Campaigns/Show.vue`
2. `PublishingController::index()` + `App/Publishing.vue`
3. Campaign show page includes:
   - Content assets per channel
   - Execution status per asset
   - KPI snapshot if available (expected vs actual side-by-side)

**Dependency:** Phase 7 complete.

---

### Phase 9 — Analytics + Learning

**Deliverables:**
1. `AnalyticsController` (index + show) + `App/Analytics/Index.vue` + `App/Analytics/Show.vue`
2. `LearningController::index()` + `App/Learning.vue`
3. Analytics summary: approval rate, rejection rate, edit rate, campaign KPI snapshots
4. Campaign analytics detail: expected vs actual KPIs, channel breakdown
5. Learning feed: signal type, value, applied/unapplied status

**Dependency:** Phase 8 complete.

---

### Phase 10 — Settings + Polish

**Deliverables:**
1. `SettingsController` + `App/Settings.vue`: company name/industry editing, integration list with sync status and manual re-sync trigger
2. Empty states for all pages (when no data exists yet — e.g., new company with no recommendations)
3. Flash messages for all form submissions (approve success, reject success, error states)
4. Page titles, meta tags, head management via Inertia `<Head>`
5. Responsive layout (mobile-readable sidebar collapses to hamburger)

**Dependency:** Phase 9 complete.

---

## 12. Testing Plan

### 12.1 Feature Tests (PHPUnit)

One test class per controller. Tests use `RefreshDatabase`, seed minimum required records, and assert Inertia page + prop structure.

```
tests/Feature/App/
├── DashboardControllerTest.php
├── BusinessBrainControllerTest.php
├── OpportunityControllerTest.php
├── RecommendationControllerTest.php   ← includes approve/reject flow tests
├── CampaignControllerTest.php
├── PublishingControllerTest.php
├── AnalyticsControllerTest.php
├── LearningControllerTest.php
├── SettingsControllerTest.php
└── OnboardingControllerTest.php
```

Each test asserts:
- 200 response (or correct redirect)
- Inertia component name matches
- Key props present and typed correctly
- For POST actions: model state changes, correct redirects, flash messages

**No new AI fixtures needed** — all dashboard data comes from existing model records.

### 12.2 Approval Workflow Integration Tests

Critical path — needs explicit end-to-end coverage:

```
test_approve_transitions_recommendation_and_dispatches_publish_campaign
test_reject_creates_learning_and_cancels_campaign
test_edit_and_approve_captures_edits_in_approval_record
test_cannot_approve_already_approved_recommendation_via_http
test_non_member_cannot_access_recommendation  ← security
```

### 12.3 Middleware Tests

```
test_unauthenticated_user_redirected_to_login
test_authenticated_user_without_company_redirected_to_onboarding
test_authenticated_user_with_company_membership_resolves_company
test_superadmin_without_membership_is_redirected_to_onboarding  ← important edge case
```

### 12.4 Vue Component Tests (Vitest)

Install Vitest + `@vue/test-utils`. Test components in isolation.

Priority components for unit tests:
- `RationaleCard.vue` — renders all 4 rationale fields, handles missing fields gracefully
- `ApproveActions.vue` — emits correct events, confirm state works
- `ContentPreview.vue` — renders email vs social formats correctly
- `ScoreBar.vue` — renders score in range, correct color thresholds

---

## 13. Security Constraints

These are invariants that must hold throughout implementation:

1. **Company isolation**: All data queries go through `withoutGlobalScopes()` ONLY when `company_id` is explicitly provided as a filter. The `EnsureCompanyMembership` middleware must set the company before any controller runs. No controller infers the company from the model's global scope — it uses `$request->company`.

2. **Approval authorization**: `RecommendationController` approve/reject actions must check that the authenticated user has `owner` or `admin` role on the company membership. `member` role can read but not approve.

3. **No global scope bypass**: Dashboard controllers do not call `withoutGlobalScopes()` without also filtering on `company_id`. The global scope exists as defense-in-depth — don't disable it casually.

4. **SSRF**: `OnboardingController` takes a website URL from user input and passes it to `IntegrationService`. `SsrfValidator` is already wired into `WebPageCrawler` — this is already protected. No additional guard needed in the controller, but the test for the onboarding flow should assert that an invalid URL (private IP) is rejected.

5. **Filament remains superadmin-only**: Nothing in the customer dashboard should loosen the `is_superadmin` gate on the Filament panel.

---

## 14. Frontend Package Additions

The following packages are added to the existing Vite/Tailwind setup:

**npm (production):**
- `@inertiajs/vue3` — Inertia adapter for Vue 3
- `vue` — Vue 3 core
- `@vueuse/core` — composables (for polling, local storage, etc.)

**npm (dev):**
- `@vitejs/plugin-vue` — Vue SFC support in Vite
- `typescript` — TypeScript compiler
- `vue-tsc` — TypeScript checking for Vue SFCs
- `@types/node` — Node types for Vite config
- `vitest` — component unit tests
- `@vue/test-utils` — Vue component test utilities
- `@testing-library/vue` — accessible component queries

**composer (production):**
- `inertiajs/inertia-laravel` — Inertia server-side adapter

No UI component library is added. Components are built from scratch with Tailwind CSS utility classes. This avoids style conflicts with Filament and gives full control over the customer-facing visual language.

---

## 15. File Structure After Milestone 10

```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/
│   │   │   │   └── AuthController.php
│   │   │   ├── App/               ← new
│   │   │   │   ├── DashboardController.php
│   │   │   │   ├── BusinessBrainController.php
│   │   │   │   ├── OpportunityController.php
│   │   │   │   ├── RecommendationController.php
│   │   │   │   ├── CampaignController.php
│   │   │   │   ├── PublishingController.php
│   │   │   │   ├── AnalyticsController.php
│   │   │   │   ├── LearningController.php
│   │   │   │   └── SettingsController.php
│   │   │   ├── Api/
│   │   │   │   ├── HealthController.php  (existing)
│   │   │   │   └── OnboardingStatusController.php  ← new
│   │   │   └── OnboardingController.php  ← new
│   │   └── Middleware/
│   │       └── EnsureCompanyMembership.php  ← new
├── resources/
│   ├── js/
│   │   ├── app.ts                 ← renamed from app.js; Inertia mount
│   │   ├── types/
│   │   │   └── index.ts           ← domain types
│   │   ├── Layouts/
│   │   │   ├── AppLayout.vue
│   │   │   └── AuthLayout.vue
│   │   ├── Pages/
│   │   │   ├── Auth/
│   │   │   ├── Onboarding/
│   │   │   └── App/
│   │   └── Components/
│   │       ├── UI/
│   │       ├── Dashboard/
│   │       ├── Brain/
│   │       ├── Opportunities/
│   │       ├── Recommendations/
│   │       ├── Campaigns/
│   │       ├── Analytics/
│   │       └── Learning/
│   └── views/
│       ├── app.blade.php          ← new Inertia root view
│       └── welcome.blade.php      (existing)
├── routes/
│   └── web.php                    ← extended with auth + /app/* routes
└── tests/
    └── Feature/
        └── App/
            └── *.php              ← one per controller
```

---

## 16. Open Questions (Resolve Before Phase 2)

| Question | Options | Recommendation |
|----------|---------|----------------|
| Does the customer auth share users with Filament? | Yes (same `users` table) / No (separate) | **Yes.** Same users table. A superadmin can also be a company member. Guards separate the surfaces. |
| How does a user with multiple company memberships select a company? | Auto-select first / Company selector page / URL-based `/app/{company}` | **Company selector page** for MVP. Future: last-used company stored in session. |
| Does the recommendation approval page require a full-page reload or live update? | Inertia form POST + redirect / Livewire / Vue async | **Inertia form POST + redirect** for MVP. After approve, redirect back to `/app/recommendations` with a flash message. Simpler, testable, no WebSocket complexity. |
| Should the onboarding status page poll or use WebSockets? | Polling (simple) / Echo + Pusher (real-time) | **Polling every 5 seconds** for MVP. No new infrastructure. Status changes are infrequent enough that polling is imperceptible. |
| What happens if `BusinessBrainService::for()` returns an empty brain (no facts)? | Error / empty state | **Empty state with explanation.** "Atlas hasn't analyzed your business yet — connect a website to get started." |
| What roles can approve a recommendation? | Owner only / Owner + Admin | **Owner + Admin** per existing `CompanyMembership.role` values. `member` role is read-only. |

---

## 17. Acceptance Criteria

Milestone 10 is complete when all of the following are true:

- [ ] A new user can sign up, create a company, enter a website URL, and reach a pending recommendation within 10 minutes (the PRD north-star metric)
- [ ] The dashboard shows all 8 intelligence surfaces listed in the mission brief
- [ ] A user can approve, edit & approve, or reject a recommendation from the UI
- [ ] Approval correctly calls `ApprovalService::approve()` and produces an `Approval` record in the database
- [ ] Rejection correctly calls `ApprovalService::reject()` and produces a `Learning` record
- [ ] Company data is strictly isolated — no user can see another company's data through the dashboard
- [ ] All `/app/*` routes redirect to `/login` for unauthenticated users
- [ ] All `/app/*` routes redirect to `/onboarding` for users with no company membership
- [ ] PHPStan level 8 passes with 0 errors (including new controllers and middleware)
- [ ] Pint passes
- [ ] All feature tests pass

---

## 18. What This Milestone Does Not Prove

To set correct expectations:

- This dashboard **reads** from the intelligence platform — it does not make Atlas smarter
- Publishing still requires configured channel credentials (which don't exist in MVP) — approvals create Executions in `queued` status but don't publish yet
- Analytics data will only appear for campaigns that have been executed and measured — new customers will see empty analytics sections initially
- The learning insights page will be sparse until a company has approved/rejected several recommendations

These are intentional. The dashboard proves that the Atlas loop is visible and actionable. The downstream effects (publishing, analytics, learning accumulation) come as the platform is used over time.
