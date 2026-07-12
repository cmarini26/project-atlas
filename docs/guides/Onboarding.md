# Onboarding — Internal Guide

What a new customer sees during onboarding, and how to manually intervene when something goes wrong.

---

## The Flow

Onboarding is a 4-step wizard (`resources/js/Pages/Onboarding/Index.vue`) followed by a polling status page (`resources/js/Pages/Onboarding/Status.vue`). `App\Http\Controllers\OnboardingController::index()` re-derives which step to show from the database on every visit — a user who closes the tab mid-onboarding and comes back lands on the right step automatically, no explicit "current step" column needed.

| Step | What the customer does | What happens server-side |
|------|-------------------------|---------------------------|
| 1. Company profile | Enters business name + optional industry | `Company::create()` — a `Str::slug()`-derived slug is generated with a `-2`, `-3`, ... suffix appended on collision (two different customers can legitimately share a business name) |
| 2. Connect your website | Enters a URL | `Integration` (`type: website_crawl`) created or reused; a default `blog` `Channel` is seeded; `SyncIntegration` is queued (never run inline — the crawl + AI pipeline can take minutes) |
| 3. Marketing presence | Selects channels the business already uses | `MarketingChannel` rows declared (unlinked, unconnected — declaring isn't connecting) |
| 4. Confirmation | Sees a brief "Connected" screen | Redirects to `/onboarding/status` |

The status page polls `GET /api/onboarding/status` every 5 seconds and shows one of several states depending on what it finds: normal progress (a 4-item checklist — website scanned → facts gathered → opportunities identified → first recommendation ready), a crawl failure, an AI-analysis failure, the AI provider being transiently overloaded (auto-retries, no action needed), the pipeline stalled because no queue worker is running, or a legitimate "no opportunity found yet" outcome. After 5 minutes with no recommendation, a "this is taking a moment" message replaces the spinner; after 10 minutes the page stops polling.

## Retrying a Failed Crawl

If the crawl or AI analysis failed, the status page shows a **Retry** button (crawl failures, AI failures) alongside "Try a different URL". Retry re-dispatches `SyncIntegration` against the *existing* `Integration` row — the customer doesn't need to re-type a URL that was already correct but hit a transient failure (site briefly down, AI provider hiccup).

To do this manually (e.g. from Tinker or support):

```php
$integration = Integration::where('company_id', $companyId)->where('type', 'website_crawl')->first();
$integration->update(['status' => 'active', 'last_error' => null]);
SyncIntegration::dispatch($integration);
```

This is exactly what `POST /onboarding/retry` (`OnboardingController::retry()`) does.

## Manually Triggering a Re-crawl (Post-Onboarding)

Once a company is fully onboarded, a re-crawl can be triggered from **Settings → Sync now** on any integration (`SettingsController::syncIntegration()`), which is the same `SyncIntegration` job. There's no separate "re-onboard" flow — re-crawling an existing integration and it going through the pipeline again is the same code path as the first crawl.

## Resetting Onboarding for a Test Account

There's no built-in "reset" button — onboarding state is entirely derived from what exists in the database (a company, an integration, a marketing channel). To force a test account back to step 1:

```php
$membership = CompanyMembership::where('user_id', $userId)->first();
$company = $membership->company;

// Deleting the company cascades to its dependent rows (catalog, digital twin,
// integrations, facts, etc. — check the migration's foreign key constraints
// for exactly what cascades before doing this against real data).
$company->delete();
$membership->delete();
```

The next visit to `/onboarding` will show step 1 again.

## First Recommendation Email

The first time a company's `RecommendationCreated` event fires, `App\Listeners\SendWelcomeEmailOnFirstRecommendation` emails the company's `owner` membership via `App\Notifications\FirstRecommendationReady`. It checks whether any *other* recommendation already exists for the company — so it fires exactly once per company, regardless of how many times the opportunity/decision/campaign pipeline re-runs afterward. Locally, `MAIL_MAILER=log` (the default) writes the email to `storage/logs/laravel.log` instead of sending it.

## Post-Onboarding Checklist vs. the Product Tour

Two distinct first-time-user aids exist on the Dashboard and are easy to confuse:

- **Product tour** (`useProductTour.ts`, `ProductTourOverlay.vue`) — a guided walkthrough of the Dashboard's own sections (recommendation prompt, summary cards, health card, recent executions). Persisted via `users.product_tour_completed_at`.
- **Onboarding checklist** (`OnboardingChecklist.vue`) — a dismissible "3 things to do first" card linking to actionable next steps (review the first recommendation, explore the Business Brain, review marketing presence). Persisted via `users.checklist_dismissed_at`.

Both are per-user (not per-company) — `company_memberships` is many-to-many, so "has seen this" is inherently a per-user fact, not something that should hide the tour/checklist from a teammate who hasn't seen it yet.
