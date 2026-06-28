# New Company Onboarding — Happy Path Fix

**Status:** Complete
**Date:** 2026-06-28
**Tests:** 587 (585 passing, 2 Redis skipped) — 6 new onboarding tests, 1 new dashboard redirect test
**PHPStan:** Level 8 — 0 errors
**Pint:** Clean
**Frontend build:** 0 errors

---

## Problem

A new user who created a company was immediately redirected to `/app` with an empty dashboard and no clear next step. The "Connect your website" step was never reached. The analysis pipeline was never triggered.

Three bugs combined to break the happy path:

| # | Bug | Location | Symptom |
|---|-----|----------|---------|
| 1 | `OnboardingController::index()` redirected any user with a membership to `/app`, bypassing the integration step | `OnboardingController.php` | After company creation, user lands on empty dashboard |
| 2 | Integration form submitted field `url` but server validated `website_url` | `Onboarding/Index.vue` | Integration step would fail validation even if reached |
| 3 | `/app` had no guard for companies with no integration | `DashboardController.php` | User saw empty dashboard with no CTA |

---

## Fixed Flow

```
Register
  → /onboarding  (step 1: company name + industry)
  → POST /onboarding/company
  → /onboarding  (step 2: website URL — detected from company state)
  → POST /onboarding/integration
  → /onboarding/status  (polls /api/onboarding/status every 5s)
  → when recommendation_count > 0: /app/recommendations/{id}
```

If a user visits `/app` with no integration at any time, they are redirected to `/onboarding` which shows step 2.

---

## Changes

### `app/Http/Controllers/OnboardingController.php`

`index()` now routes by company state instead of membership presence:

| State | Before | After |
|-------|--------|-------|
| No membership | Show step 1 | Show step 1 (same) |
| Membership, no integration | Redirect to `/app` ❌ | Show step 2 ✅ |
| Membership + integration | Redirect to `/app` | Redirect to `/onboarding/status` |

`createCompany()` cleaned up: removed unused `with('step', 'integration')` flash.

### `resources/js/Pages/Onboarding/Index.vue`

- Accepts `initial_step` prop (1 | 2 | 3) and uses it as initial state — step is now server-authoritative
- Fixed form field: `url` → `website_url` to match server validation
- Removed "Skip for now" button from step 2 — website URL is required for the pipeline to run
- Step 3 (confirmation) is now a brief "Redirecting…" state before Inertia follows the server redirect to `/onboarding/status`

### `app/Http/Controllers/App/DashboardController.php`

Added guard at top of `index()`: if the company has no integration, redirect to `/onboarding`. This ensures users cannot reach an empty dashboard without completing the website step.

### Tests updated

**`MiddlewareTest.php`**: Helper `userWithCompany()` renamed `userWithCompanyAndIntegration()` — all `/app` access tests now create an integration so the dashboard guard does not interfere with middleware-level assertions.

**`OnboardingControllerTest.php`**: Replaced and expanded:

| Test | What it covers |
|------|---------------|
| `test_onboarding_index_shows_step_1_for_new_user` | No membership → step 1 |
| `test_onboarding_index_shows_step_2_when_company_has_no_integration` | Membership, no integration → step 2 |
| `test_onboarding_index_redirects_to_status_when_integration_exists` | Integration exists → status |
| `test_company_step_creates_company_and_membership` | Company + membership created |
| `test_company_step_requires_name` | Validation |
| `test_integration_step_creates_integration_and_redirects_to_status` | Integration created, redirect correct |
| `test_integration_step_dispatches_sync_job` | `SyncIntegration` dispatched with correct company |
| `test_integration_step_requires_valid_url` | Validation |
| `test_integration_step_redirects_to_onboarding_with_no_company` | Edge case: no company |
| `test_status_page_renders_for_authenticated_user` | Status page renders |
| `test_status_page_redirects_unauthenticated` | Auth guard |

**`DashboardControllerTest.php`**: Added `test_dashboard_redirects_to_onboarding_when_no_integration`. All existing dashboard tests updated to use `userWithCompanyAndIntegration()`.

---

## Pipeline Dispatch (confirmed wired, no changes needed)

`IntegrationService::create()` dispatches `SyncIntegration` immediately after creating the integration. `SyncIntegration` calls the connector, records observations via `ObservationService::recordAll()`, which fires `ObservationRecorded` for each result. `AppServiceProvider` listens to `ObservationRecorded` with `DispatchObservationProcessing`, which kicks off the full chain:

```
ObservationRecorded
  → DispatchObservationProcessing
  → ProcessObservation (extracts Facts, synthesizes Knowledge, activates DigitalTwin)
  → DigitalTwinActivated → TriggerOpportunityDetection
  → OpportunityDetected → TriggerDecisionEvaluation
  → DecisionCommitted → DispatchCampaignPreparation
  → CampaignAssetsReady → TriggerRecommendationCreation
  → Recommendation created → /onboarding/status polls pick it up → redirect to recommendation
```

No pipeline changes were needed. The fix was purely in the routing and form field.

---

## Known Limitation

The status page has a 5-minute timeout message with a "Go to dashboard" link to `/app`. If the pipeline is still running when the user clicks it, `/app` will redirect back to `/onboarding` (step 2) because the integration exists but the redirect guard... wait, actually this is fine: the integration WAS created, so `/app` will NOT redirect to `/onboarding`. The dashboard guard only redirects when NO integration exists. A company with an integration (even if the twin is still initializing) reaches the full dashboard.
