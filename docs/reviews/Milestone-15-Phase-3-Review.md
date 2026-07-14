# Milestone 15 Phase 3 Review — Business Discovery Cutover and Recovery

**Completed:** 2026-07-14
**Tests:** 1238 total | 1235 passing | 3 skipped (local-environment-only) — 16 new PHP tests since Phase 2 (7 `BusinessDiscoveryServiceTest` additions, 4 `OnboardingControllerTest` additions, 2 `OnboardingStatusControllerTest` additions, 3 stage/idempotency additions folded into the existing files) + 6 Vitest tests (2 new, 4 rewritten) for `Status.vue`
**PHPStan:** Level 8 — 0 errors
**Pint:** Clean
**npm run build / npm run test:** Clean

---

## What This Phase Is

Phase 1 built the onboarding wizard and its persistence. Phase 2 built `BusinessDiscoveryService` — the source-agnostic orchestrator that dispatches the existing connector pipeline for whatever a company has declared. Phase 3 makes that orchestrator the **only** onboarding execution path, closes the two real gaps Phase 2 left open (no recovery from a partial failure, and an indefinite "Recommend" spinner when a scan legitimately finds nothing to act on), and audits the codebase for anything still describing onboarding in pre-Discovery terms.

Nothing here touches Business Brain, Marketing Health, the Opportunity Engine, the Decision Engine, the Connector architecture, or the publishing/OAuth surfaces — per this phase's explicit boundaries.

---

## 1. Complete Onboarding Cutover

Confirmed rather than re-built: `OnboardingController::finish()` already called `BusinessDiscoveryService::start()` (Phase 2), and a grep across `app/` for every `SyncIntegration::dispatch()` call site found exactly four: `BusinessDiscoveryService::attempt()` (onboarding), `SettingsController::syncIntegration()`/`connectInstagram()` (manual, outside onboarding), and `Console\Commands\SyncDueIntegrations` (recurring, outside onboarding). There was no second onboarding-specific dispatch path to remove — Phase 2 never created one. What Phase 3 added:

- A new `onboarding.discovery.retry` route/controller action — the single recovery path (§3 below). No parallel orchestrator; it calls the same `BusinessDiscoveryService`.
- `docs/reviews` note: the recurring/manual sync call sites above are unchanged and intentionally untouched — Phase 3 does not alter `SettingsController::syncIntegration()`, `connectInstagram()` (beyond the Phase 2 `linkIntegration()` addition already reviewed), or `SyncDueIntegrations`.

**Test:** `test_finish_invokes_business_discovery_service_as_the_single_orchestrator` mocks `BusinessDiscoveryService::start()` and asserts it's called with the correct company — proving `finish()` has no alternate path.

---

## 2. Asset Detail Activation

Reviewed against the actual Phase 1/2 code (`DiscoveryPlanner`, `ConnectorRegistry::autoDiscoverableFor()`, `WebsiteConnector implements AutoDiscoverableConnector`) — all four requirements were already true by construction, not something Phase 3 needed to add:

- Website URL/platform persist in `MarketingChannel.handle_or_url`/`metadata` (Phase 1) and `WebsiteConnector::buildIntegrationConfig()` reads `handle_or_url` directly (Phase 2).
- A declared Website always gets a new-or-reused `website_crawl` Integration via `DiscoveryPlanner::planFor()`'s "create" branch.
- An already-connected Instagram asset (`marketing_channels.integration_id` set, `is_connected: true`) is picked up via the planner's "reuse" branch — no connector-specific code involved.
- Facebook/LinkedIn/etc. with no `AutoDiscoverableConnector` and no existing linkage get **no** `DiscoveryConnectorAttempt` row at all — verified explicitly by `test_declared_assets_with_no_connector_get_no_attempt_row` and the new `test_unsupported_assets_do_not_block_completion_alongside_a_successful_one`, which also proves the run still reaches `completed` with a Recommendation despite them.
- No fake/placeholder Integration is ever created for these — `planFor()` returns `null`, and `attempt()`/`retry()` both `return` immediately on `null`.

---

## 3. Retry and Recovery

This is the phase's real new capability. `BusinessDiscoveryService::retry(DiscoveryRun $run): DiscoveryRun`:

- **Reuses the exact same `DiscoveryRun` row** — never creates a new one. (`start()` remains the "fresh run" entry point for onboarding, manual refresh, and future scheduled discovery; `retry()` is specifically "finish what this run didn't.")
- Walks every currently-declared, active `MarketingChannel` again:
  - **Already succeeded in this run** → skipped entirely, never re-touched (status, `attempt_count`, `completed_at` all untouched — verified with object-identity-level assertions in `test_retry_preserves_a_successful_attempt_while_retrying_a_failed_one`).
  - **Failed/pending/running in this run** → `retryAttempt()` re-dispatches `SyncIntegration` against the *same* `Integration` row (no new Integration, no new attempt row).
  - **No attempt existed yet** (e.g. a type with no connector when the run started) → `attempt()` gives it a first try now — this is how a mid-onboarding Instagram connection made from Settings after a failed run gets picked up on retry, verified by `test_retry_picks_up_an_asset_that_only_became_connected_after_the_original_run`.
- One connector failing again during a retry still can't abort the others — `retryAttempt()` wraps its dispatch in the same try/catch as `attempt()`.
- `OnboardingController::retryDiscovery()` (new `POST /onboarding/discovery/retry`) finds the company's latest run and calls `retry()` on it — a no-op if Discovery was never started.
- `BusinessDiscoveryService::progressFor()` now returns `retry_available: bool` (true when any attempt has genuinely failed, or the run reached a terminal-but-incomplete state) so `Status.vue` only shows the "Try again" control when there's real work for it to do.

**Recovery paths explicitly covered by name in the requirements:**

| Scenario | How it's handled |
|---|---|
| Failed connector attempt | `retry()` re-dispatches that attempt's Integration |
| Incorrect asset URL | User edits Asset Details (existing `MarketingPresenceService::update()`, unchanged) → retry re-dispatches with the corrected `handle_or_url` |
| Temporarily unavailable provider | Same retry path; nothing provider-specific — the connector just succeeds on the next try |
| Partially completed DiscoveryRun | `retry()` only touches the non-succeeded attempts, by construction |

**Tests:** `test_retry_reuses_the_same_run_and_only_retries_the_failed_attempt`, `test_retry_never_duplicates_integrations_or_observations`, `test_retry_preserves_a_successful_attempt_while_retrying_a_failed_one`, `test_retry_picks_up_an_asset_that_only_became_connected_after_the_original_run`, `test_retry_never_touches_another_companys_discovery_run` (tenant isolation), plus the controller-level `test_retry_discovery_never_duplicates_attempts_end_to_end`.

---

## 4. Reusable Discovery

No separate onboarding-only implementation exists or was created. `start()` and `retry()` both live on `BusinessDiscoveryService` with no dependency on onboarding-specific state (they take a `Company`/`DiscoveryRun`, nothing from the wizard's request context). `OnboardingController` is the only current caller, but nothing prevents a future Settings-page "refresh my business" button or a scheduled job from calling `BusinessDiscoveryService::start($company)` directly — it already behaves correctly on a company with prior runs (idempotent, reuses Integrations), which is exactly what a manual refresh or scheduled re-discovery needs. No new class was introduced for this — it would have been the "separate onboarding-only implementation" this requirement explicitly rules out.

---

## 5. Completion Behavior

`Status.vue` no longer waits for the user to click through a completion summary once a Recommendation exists — Phase 2's manual "View my recommendation" link is replaced with an automatic `router.visit()` the moment `recommendation_count > 0`, per this phase's explicit requirement. When no Recommendation exists, the screen shows one of two honest terminal states instead of hanging:

- **`completed_with_errors`** — every attempted connector failed (or nothing was observable at all). Shows per-asset detail and, when `retry_available`, a "Try again" button.
- **`completed_no_opportunities`** (new — see §6) — Atlas understood the business but the scan legitimately found nothing to act on. Shows the fact count gathered, a link to the Business Brain, and a retry option.

A 5-minute client-side timeout (unchanged from Phase 2) still surfaces a "this is taking a moment" message for the rare case where a real async queue worker hasn't caught up yet — the user is never left on a spinner with zero feedback.

**Tests:** `test_reports_recommendation_count_and_first_id_when_a_recommendation_exists` (backend payload) + the Vitest `redirects to the first pending recommendation when one exists` (frontend `router.visit` call) prove the redirect path end to end across the API contract.

---

## 6. The `completed_no_opportunities` Stage (real gap closed)

Phase 2's `computeStage()` had no terminal condition for "every attempt finished, Atlas understood the business, but no Opportunity ever resulted." A company whose only asset legitimately produced no campaign opportunity (a real, previously-observed outcome — see `OnboardingPipelineTest::test_no_opportunities_from_scan_is_legitimate_and_observation_stays_processed`) would sit at `DiscoveryStage::Recommending` **forever**, which the client's polling loop would show as an indefinitely "active" Recommend stage — precisely the indefinite-progress-page failure mode this phase's completion-behavior requirement rules out.

Added `DiscoveryStage::CompletedNoOpportunities`, detected in `computeStage()` once every attempt is terminal, no Opportunity exists for the company since the run started, and the last processed Observation is more than 90 seconds old — the same grace-period heuristic the pre-Phase-2 `OnboardingStatusController` used for its old `no_opportunities` flag, ported onto the new stage model instead of reintroduced as a separate boolean. A new migration (`2026_07_16_000100_add_completed_no_opportunities_stage_to_discovery_runs.php`) extends the Postgres CHECK constraint on `discovery_runs.stage`, mirroring the exact pattern `2026_07_05_000100_add_retrying_status_to_observations.php` already established for adding an enum value after a table has shipped; the base `create_discovery_runs_table` migration was also updated in place so fresh (including sqlite test) databases include the value from the start.

**Tests:** `test_reaches_completed_no_opportunities_when_nothing_to_act_on` (the terminal state itself) and `test_stays_recommending_within_the_grace_period_after_processing` (proves it doesn't fire prematurely against a still-possibly-in-flight async chain).

---

## 7. Legacy Cleanup

Audited for anything still describing Discovery in pre-Milestone-15 or website-only terms:

- `routes/web.php`'s onboarding block comment was stale ("that's Discovery, a future phase") — rewritten to reflect that Discovery is implemented and `finish()`/`onboarding.discovery.retry` are its only entry points.
- `App\Services\Analyst\Exceptions\FactExtractionFailedException`'s docblock referenced the removed `ai_failed` onboarding-status field — corrected to describe the current per-connector-attempt behavior.
- `OnboardingPipelineTest`'s `no_opportunities`-scenario test had a comment referencing the same removed field — corrected to point at `DiscoveryStage::CompletedNoOpportunities` and `BusinessDiscoveryServiceTest`.
- Confirmed (not just assumed) that the pre-Milestone-15 routes (`onboarding.integration`, `onboarding.retry`, `onboarding.marketing-presence`) genuinely no longer resolve — `test_legacy_website_only_onboarding_routes_no_longer_exist` posts to all three and asserts 404, closing the loop on "the legacy website-only path can no longer bypass Discovery" rather than trusting that Phase 1's removal was never quietly reverted.
- Left untouched, deliberately: `SettingsController::syncIntegration()`/`connectInstagram()`/`connectWordPress()`, `Console\Commands\SyncDueIntegrations`, and the generic "Connect your website →" empty-state links on `Brain.vue`/`Opportunities.vue` (Settings-facing, not onboarding-facing, and accurate as written).

---

## What Changed

### Added
- `App\Enums\DiscoveryStage::CompletedNoOpportunities`
- `BusinessDiscoveryService::retry()` / private `retryAttempt()`
- `retry_available` field on `BusinessDiscoveryService::progressFor()`'s payload
- `OnboardingController::retryDiscovery()` + `POST /onboarding/discovery/retry`
- Migration `2026_07_16_000100_add_completed_no_opportunities_stage_to_discovery_runs.php`

### Changed
- `BusinessDiscoveryService::computeStage()` — new terminal branch for the no-opportunities outcome
- `discovery_runs` base migration — `stage` enum gains `completed_no_opportunities` for fresh databases
- `Status.vue` — auto-redirects to a pending Recommendation instead of requiring a click; new no-opportunities branch; retry button wherever `retry_available` is true
- `OnboardingStatusController` — empty payload and docblock updated for `retry_available`
- Stale comments in `routes/web.php`, `FactExtractionFailedException`, `OnboardingPipelineTest`

### Not Changed (explicitly out of scope)
- Business Brain, Marketing Health, Opportunity/Decision Engine logic
- The Connector architecture itself (no new connector types)
- Publishing, OAuth
- Google Business implementation

---

## Verification

`php artisan test` (1238 tests, 1235 passing, 3 skipped), `./vendor/bin/phpstan analyse --no-progress` (0 errors), `./vendor/bin/pint --test` (clean), `npm run build` (clean), `npm run test` (104 Vitest tests passing). The new migration was verified against real local PostgreSQL: applied, rolled back, and re-applied, with the resulting `discovery_runs_stage_check` constraint confirmed via `psql` to include all seven stage values.
