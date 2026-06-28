# Milestone 10 — Customer Dashboard & UX

**Status:** Complete
**Completed:** 2026-06-28
**Tests:** 581 (579 passing, 2 Redis skipped)
**PHPStan:** Level 8 — 0 errors
**Pint:** Clean
**Frontend build:** 129 modules, 0 errors

---

## Objective

Build the full customer-facing product on top of the Atlas pipeline. The backend had been complete since Milestone 9.5, but there was no way for a business owner to see or interact with any of it. This milestone gives Atlas a face: a calm, capable dashboard where the business owner reviews intelligence, acts on recommendations, and sees their campaigns in motion.

---

## Delivered

### Phase 1 — Personas and User Flows

`docs/product/Personas.md` and `docs/product/UserFlows.md` written before any code.

- Two personas: Marcus (auction house owner, mobile-first, time-poor) and Sofia (marketing manager, data-fluent, multi-company)
- Six core user flows: onboarding, daily check-in, recommendation approval, content editing, campaign monitoring, learning review

### Phase 2 — Frontend Foundation

| File | Description |
|------|-------------|
| `package.json` | Vue 3, TypeScript, Inertia.js v3, Tailwind CSS v4, Heroicons, Vite |
| `vite.config.ts` | Vite configuration with Laravel plugin |
| `tsconfig.json` | Strict TypeScript for the frontend |
| `resources/js/app.ts` | Inertia app bootstrap with `createInertiaApp` |
| `resources/css/app.css` | `@theme {}` design token block — warm stone neutrals + indigo accent |
| `resources/views/app.blade.php` | Inertia root template |
| `app/Http/Middleware/HandleInertiaRequests.php` | Shares `auth.user`, `company`, and `flash` on every request |

### Phase 3 — Auth and Company Routing

| File | Description |
|------|-------------|
| `resources/js/Layouts/AuthLayout.vue` | Centered card layout for auth pages |
| `resources/js/Layouts/AppLayout.vue` | Fixed 240px sidebar; mobile hamburger + overlay; flash message display; user menu with logout |
| `resources/js/Pages/Auth/Login.vue` | Login form with Inertia `useForm` |
| `resources/js/Pages/Auth/Register.vue` | Registration form with Inertia `useForm` |
| `resources/js/Pages/App/CompanySelector.vue` | Multi-company selector (redirects single-membership users) |
| `app/Http/Middleware/EnsureCompanyMembership.php` | Resolves company from session (multi) or direct (single); sets `company` and `membership` request attributes |

### Phase 4 — Onboarding Wizard

| File | Description |
|------|-------------|
| `resources/js/Pages/Onboarding/Index.vue` | 3-step wizard: company name + industry → website URL → confirmation |
| `resources/js/Pages/Onboarding/Status.vue` | Polls `/api/onboarding/status` every 4 seconds; auto-redirects when `recommendation_count > 0` |
| `app/Http/Controllers/OnboardingController.php` | `createCompany`, `createIntegration`, `status` actions |
| `app/Http/Controllers/Api/OnboardingStatusController.php` | JSON endpoint returning `twin_status`, `fact_count`, `opportunity_count`, `recommendation_count` |

### Phase 5 — Dashboard

| File | Description |
|------|-------------|
| `resources/js/Pages/App/Dashboard.vue` | Summary counts, digital twin health card, pending recommendation prompt, recent campaigns, recent executions |
| `resources/js/Components/Dashboard/SummaryCard.vue` | Icon + count + label card |
| `resources/js/Components/Dashboard/HealthCard.vue` | Twin status, health score bar, fact/knowledge/integration counts |
| `resources/js/Components/Dashboard/RecommendationPrompt.vue` | Highlighted card with rationale preview; Approve and Review links |
| `app/Http/Controllers/App/DashboardController.php` | Assembles all dashboard data; health block nested under `health` key |

### Phase 6 — Recommendation Workflow

The core product loop. An owner or admin can approve, edit and approve, or reject.

| File | Description |
|------|-------------|
| `resources/js/Pages/App/Recommendations/Index.vue` | Pending and recent recommendations; campaign type as heading |
| `resources/js/Pages/App/Recommendations/Show.vue` | Full recommendation review: rationale, expected impact, content preview, approval actions |
| `resources/js/Components/Recommendations/RationaleCard.vue` | Renders `rationale_display` as key/value pairs with human labels |
| `resources/js/Components/Recommendations/ImpactCard.vue` | Renders `decision.expected_impact` as flat key/value grid |
| `resources/js/Components/Recommendations/ContentPreview.vue` | Content asset preview with channel badge |
| `resources/js/Components/Recommendations/ContentEditor.vue` | Editable body/title before approve-with-edit |
| `resources/js/Components/Recommendations/ApproveActions.vue` | Three action buttons: Approve, Edit & Approve, Reject |
| `app/Http/Controllers/App/RecommendationController.php` | `index`, `show`, `approve`, `approveEdit`, `reject`; role-gated to `owner` and `admin` |

**Security invariant:** `requireApprovalRole` checks `CompanyMembership.role` on every mutation. `member` role gets 403.

### Phase 7 — Business Brain and Opportunities

| File | Description |
|------|-------------|
| `resources/js/Pages/App/Brain.vue` | Facts list, knowledge list, recent observations; shows initializing state when twin not ready |
| `resources/js/Pages/App/Opportunities.vue` | Scored opportunity cards with relevance/timing/confidence/urgency bars |
| `app/Http/Controllers/App/BusinessBrainController.php` | Assembles Brain VO; falls back to empty arrays for initializing twin |
| `app/Http/Controllers/App/OpportunityController.php` | Filters by `status = 'open'` ordered by `composite_score` descending |

### Phase 8 — Campaigns and Publishing

| File | Description |
|------|-------------|
| `resources/js/Pages/App/Campaigns/Index.vue` | Paginated campaign list with status badges |
| `resources/js/Pages/App/Campaigns/Show.vue` | Campaign detail: blueprint, content assets, execution queue, KPI snapshot |
| `resources/js/Pages/App/Publishing.vue` | Paginated execution queue; channel type + content body preview |
| `app/Http/Controllers/App/CampaignController.php` | `index` (paginated) and `show` (with decision, assets, executions, KPI snapshot) |
| `app/Http/Controllers/App/PublishingController.php` | Paginated execution list with eager-loaded channel and content asset |

### Phase 9 — Analytics and Learning

| File | Description |
|------|-------------|
| `resources/js/Pages/App/Analytics/Index.vue` | Final KPI snapshots across campaigns; recommendation-level aggregates |
| `resources/js/Pages/App/Analytics/Show.vue` | Campaign analytics: expected vs actual KPIs, execution metrics by channel |
| `resources/js/Pages/App/Learning.vue` | Paginated learning signals; applied effects with rollback indicator |
| `app/Http/Controllers/App/AnalyticsController.php` | `index` (company-wide snapshots + recommendation KPIs) and `show` (campaign detail) |
| `app/Http/Controllers/App/LearningController.php` | Paginated learnings + recent applied effects |

### Phase 10 — Settings and Polish

| File | Description |
|------|-------------|
| `resources/js/Pages/App/Settings.vue` | Company profile form; integration list with type/status/last-run; sync button |
| `resources/js/Components/UI/Badge.vue` | 6 variants: default, accent, success, warning, neutral, muted |
| `resources/js/Components/UI/EmptyState.vue` | Icon + heading + description; 3 tones |
| `resources/js/Components/UI/ScoreBar.vue` | Animated width bar for opportunity scores |
| `resources/js/Components/UI/LoadingSpinner.vue` | Pulse spinner |
| `resources/js/types/index.ts` | Complete TypeScript type definitions matching actual controller response shapes |
| `app/Http/Controllers/App/SettingsController.php` | Company update + integration sync trigger |

---

## Feature Tests (62 new tests)

| File | Tests |
|------|-------|
| `tests/Feature/App/MiddlewareTest.php` | 5 — auth redirect, no memberships, single membership, multi-membership session |
| `tests/Feature/App/DashboardControllerTest.php` | 4 — auth, render, twin status when absent, active twin |
| `tests/Feature/App/RecommendationControllerTest.php` | 8 — auth, index, show, cross-company 404, owner approve, admin approve, member 403, owner reject |
| `tests/Feature/App/OnboardingControllerTest.php` | 6 — auth, render, redirect if company exists, company step creates records, validates name, integration step, status page |
| `tests/Feature/App/BusinessBrainControllerTest.php` | 4 — auth, render, null twin, active twin with facts |
| `tests/Feature/App/OpportunityControllerTest.php` | 4 — auth, render, open opportunities, dismissed excluded, other company excluded |
| `tests/Feature/App/CampaignControllerTest.php` | 5 — auth, index render, company isolation, show render, cross-company 404 |
| `tests/Feature/App/PublishingControllerTest.php` | 3 — auth, render, executions for company |
| `tests/Feature/App/AnalyticsControllerTest.php` | 5 — auth, index render, snapshots count, show render, cross-company 404 |
| `tests/Feature/App/LearningControllerTest.php` | 4 — auth, render, company learnings count, other company excluded, applied effects |
| `tests/Feature/App/SettingsControllerTest.php` | 6 — auth, render, company data, update saves, validates name, sync dispatches, other company 404 |

---

## PHPStan Fixes (48 → 0 errors)

The most significant category of fixes was larastan v3.10 not recognizing method-style `casts()` as property-style `$casts` for type inference.

| Root cause | Fix |
|-----------|-----|
| `Knowledge`, `Opportunity`, `DigitalTwin` models using method-style `protected function casts(): array` | Converted to `protected array $casts = [...]` — identical runtime behavior, larastan now infers Carbon types |
| `BusinessBrain` VO typed as `Collection<int, mixed>` | Updated PHPDoc to `Collection<int, Fact>`, `Collection<int, Knowledge>`, `Collection<int, Observation>` — matches what the repositories actually return |
| `User|null` from `$request->user()` | `abort_unless($user instanceof User, 401)` pattern applied to all App controllers |
| `Company|null` from request attributes | `/** @var Company $company */` docblock (set by middleware) |
| Nullsafe on non-nullable Carbon columns | Changed `$x->col?->toIso8601String() ?? ''` to `$x->col->toIso8601String()` for columns larastan confirms non-nullable |
| BelongsTo relation typing | Ternary null check `$m->company !== null ? $m->company->name : ''` |

---

## Type System Design Decisions

- `Recommendation.rationale_display` is a flat `Record<string, string>` — not a nested structured object. Every Vue component that renders rationale uses `Object.entries()` and human-readable label mappings.
- `Execution.channel` is always `{ type: string } | null` — never a raw string.
- `CampaignKpiSnapshot.snapshotted_at` and `LearningApplication.created_at` are non-nullable Carbon — larastan confirmed, used `->` not `?->`.
- `ExecutionMetric` does not have `normalised_*` properties — removed from controller response to match the actual model.

---

## Known Gaps (Not Blocking)

These items exist and are known but were explicitly out of scope for this milestone:

| Item | Notes |
|------|-------|
| Frontend unit tests (Vitest) | Plan called for component tests; deferred — PHPUnit feature tests cover the contract instead |
| `BusinessBrainService` Redis caching | 5-min TTL per company; pre-production performance item from Milestone 9.5 |
| Rate limiting on analytics webhooks | Low-risk; no production traffic yet |
