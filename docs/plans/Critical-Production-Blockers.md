# Critical Production Blockers — Execution Plan

**Source:** [Production-Deployment-Audit.md](../reviews/Production-Deployment-Audit.md), "Critical Blockers" section (8 findings, reproduced here in full with an execution plan added).
**Purpose:** Turn each critical finding into a scoped, independently shippable unit of work — one blocker per pull request/commit, in an order chosen to minimize merge conflicts and unblock the highest-safety-value fixes first.
**Rule for this document:** every blocker below is extracted directly from the audit's "Critical Blockers" list — nothing has been added, removed, or re-scoped from that list. Only the ordering, sizing, and acceptance criteria are new.

---

## How blockers are ordered, and why

Two of the eight critical findings (production environment, backups) are fundamentally **infrastructure provisioning work** — a server, a domain, a managed database's backup schedule. No amount of code in this repository can complete them; they require an operator with cloud/DNS/billing access to act outside version control. The other six are **code-level fixes**: a middleware change, a route constraint, a config addition, a package install. This plan orders code-fixable blockers first, since they can be implemented, tested, and merged immediately with no external dependency, and places the two infrastructure-only blockers last, since they depend on decisions (hosting provider, domain name) that are out of scope for a coding session and because one of them (backups) is only meaningful once the other (a production database) exists.

Within the code-fixable group, ordering is by: (a) no dependency on any other blocker in this list, (b) smallest, most isolated file footprint (to avoid two in-flight blockers touching the same file), and (c) highest safety value per unit of effort.

| Order | Blocker | Type | Touches |
|---|---|---|---|
| 1 | Tenant isolation container binding | Code | `EnsureCompanyMembership.php`, new/extended tenancy tests |
| 2 | Analytics webhook rate limit + auth | Code | `routes/api.php`, webhook tests |
| 3 | HTTPS enforcement + security headers | Code | `bootstrap/app.php`, new middleware, tests |
| 4 | Scheduler production-readiness | Code + deployable artifact | `routes/console.php`, `infrastructure/` |
| 5 | Real error tracking | Code (package) | `composer.json`, `bootstrap/app.php`, `.env.example` |
| 6 | Real transactional email | Code (config) | `config/mail.php`, `.env.example` |
| 7 | Production environment | Infrastructure | none in-repo beyond a deploy-readiness template |
| 8 | Database backups | Infrastructure | none in-repo |

Blockers 5 and 3 both touch `bootstrap/app.php` — sequencing them non-adjacently (3 before 4, 5 after 4) means the file is only being changed by one blocker at a time when this plan is executed serially, and a reviewer never has two open blockers editing the same file simultaneously.

---

## Blocker 1 — Enforce Tenant Isolation at the Container Level

**Status:** ✅ Complete (commit `63cc1dc`) — see `docs/reviews/` STATUS.md/CHANGELOG.md entries dated 2026-07-10. Fixing this surfaced a real regression (three cross-company membership lookups were incorrectly narrowed by the newly-active scope), fixed in the same commit with explicit `withoutGlobalScopes()` at each site.

### Title
Bind `current_company_id` in `EnsureCompanyMembership` so `CompanyScope` is a real safety net, not dead code.

### Why it is critical
The audit's headline finding: `App\Domain\Shared\Scopes\CompanyScope::apply()` only adds its `WHERE company_id = ?` constraint when `current_company_id` is bound in the container. That binding currently happens **only inside test files** — never in `EnsureCompanyMembership` or anywhere else in `app/`. Every tenant-scoped query in production is therefore safe only because every controller and job that touches tenant data remembers to filter by `company_id` manually. This works today by consistent discipline, not by structural guarantee — a single future contributor forgetting the manual filter would leak data cross-tenant with no automated check to catch it. This is a data-isolation risk for a multi-tenant SaaS product, and it is the one finding in the audit that a purely operational fix (a server, a policy document) cannot resolve — it can only be fixed in code.

### Acceptance criteria
- [ ] `EnsureCompanyMembership` binds `current_company_id` into the container (scoped to the resolved company) on every request path that proceeds past the middleware — both the single-membership auto-select path and the multi-membership session-selected path.
- [ ] Redirect paths (no membership → onboarding; unresolved multi-membership → company selector) do **not** bind anything, since no company has been resolved yet.
- [ ] `CompanyScope` requires no code change — it already does the right thing once the binding exists; this blocker only makes the existing, previously-inert mechanism actually engage.
- [ ] A new or extended feature test proves, via a real HTTP request through the full middleware stack, that `current_company_id` is bound to the correct company after `EnsureCompanyMembership` runs — not merely that manual filtering still produces the correct result (which would be true with or without this fix and wouldn't prove anything).
- [ ] A test proves the global scope is now **actively filtering** during a real `/app/*` request: querying a tenant model with no explicit `company_id` filter, immediately after visiting a real route as an authenticated member of Company A, returns only Company A's rows.
- [ ] No behavior change outside of real web requests: CLI commands, queued jobs, and existing tests that bind `current_company_id` manually (or don't touch tenant models at all) continue to pass unmodified — the fix only adds a constraint that is redundant with, not a replacement for, existing manual filters.
- [ ] Full test suite passes, PHPStan level 8 clean, Pint clean, `npm run build` green.

### Estimated effort
Small (a few hours). One middleware method change, two lines of new logic, plus a focused new test file. No schema change, no migration, no new dependency.

### Dependencies
None. This is the first blocker precisely because it depends on nothing else in this list.

### Verification steps
1. Read `app/Http/Middleware/EnsureCompanyMembership.php` and confirm the two "proceed" branches (single membership, resolved multi-membership) are the only places a binding is added.
2. Add `app()->instance('current_company_id', $company->id)` (or equivalent) in both proceed branches, using the same `$company` already resolved for the `$request->attributes->set('company', ...)` call.
3. Write a new feature test that: creates two companies with one user each, a tenant-scoped row for each company (e.g., a `MarketingChannel` or `Opportunity`), authenticates as Company A's user, visits a real `/app/*` route, then — still within the same test, same request-scoped container — asserts `app('current_company_id') === $companyA->id` and that an unfiltered query on the tenant model returns exactly Company A's row, not Company B's.
4. Run the full existing test suite (`php artisan test`) and confirm zero regressions — every existing test that touches a `/app/*` route or a tenant model should still pass, since the new constraint is consistent with what manual filtering already enforced.
5. Run `./vendor/bin/phpstan analyse --no-progress`, `./vendor/bin/pint --test`, and `npm run build`; confirm all three are clean/green.
6. Update `docs/STATUS.md` and `CHANGELOG.md` to record the fix, then commit and push.

---

## Blocker 2 — Rate-Limit and Authenticate the Analytics Webhook Endpoint

**Status:** ✅ Complete — 2026-07-10. Implemented as a named rate limiter (`analytics-webhook`, registered in `AppServiceProvider::boot()`) rather than the plan's originally-suggested bare `throttle:60,1` string, after discovering that bare `throttle:N,M` middleware shares one rate-limit bucket (keyed only by route domain + IP, with no route distinction) across *every* route using it with no explicit prefix — confirmed by testing that exhausting `/login`'s existing `throttle:5,1` bucket also blocked `/register`. A named limiter gives this endpoint its own isolated bucket and a place to log rejections. See "Discovered during implementation" below.

### Title
Add rate limiting to `POST /api/analytics/webhooks/{provider}`, and confirm its signature verification is the correct sole gate (no additional auth needed for a public webhook receiver).

### Why it is critical
`routes/api.php` registers this endpoint with no `throttle:` middleware and no authentication middleware at all — it is a fully public, unthrottled POST endpoint reachable by anyone on the internet today. Even though `AnalyticsWebhookHandler` implementations verify a provider-specific signature (e.g., HMAC) before processing a payload, the verification step itself still costs CPU and log volume per request, and an unthrottled endpoint is an easy target for basic denial-of-service or log-flooding, independent of whether any payload is ever accepted.

### Acceptance criteria
- [x] The route has a rate limit applied — a named limiter (`analytics-webhook`, 60/minute per IP), not a bare `throttle:60,1` string; generous enough for legitimate webhook bursts, tight enough to blunt abuse.
- [x] Signature verification remains the correctness gate for whether a payload is processed (unchanged — webhooks are legitimately unauthenticated in the traditional sense; the provider's signature is the authentication mechanism). This blocker adds a volume limit, not a login requirement.
- [x] A test confirms the route is throttled (requests beyond the limit receive a 429).
- [x] Existing webhook-handling tests (signature verification, event processing) continue to pass unmodified.
- [x] Full test suite passes, PHPStan clean, Pint clean, build green.
- [x] (Added beyond the original acceptance criteria, per the live task's explicit requirements) Structured logging on rejection, a limit-reset test, an explicit legitimate-retry test, and cross-route bucket-isolation tests.

### Estimated effort
Small (under an hour) as originally estimated — actual effort was closer to medium, once the bare-`throttle:N,M` bucket-sharing discovery (below) required a named limiter instead of the one-line fix originally planned.

### Dependencies
None.

### Verification steps
1. Add `->middleware('throttle:60,1')` (or a named limiter if one is introduced) to the webhook route in `routes/api.php`.
2. Add a feature test that sends requests past the configured limit and asserts a 429 response.
3. Run the existing analytics webhook test suite and confirm no regression.
4. Run all four quality gates; update `STATUS.md`/`CHANGELOG.md`; commit and push.

### Discovered during implementation: bare `throttle:N,M` shares one bucket per IP across every route using it

Laravel's default (non-named) `throttle:maxAttempts,decayMinutes` middleware computes its rate-limit key as `sha1($route->getDomain().'|'.$request->ip())` — **it does not factor in the route path at all**, and no prefix is applied unless a third middleware argument is explicitly given. Confirmed empirically: in a throwaway test, exhausting `/login`'s existing `throttle:5,1` bucket caused the very next `/register` request (also `throttle:5,1`, a different route) to receive a 429 too — the two routes share one counter per IP today.

This means `/login`, `/register`, `/forgot-password`, `/reset-password`, and `/onboarding/integration` (all pre-existing, all using bare `throttle:N,M`) already silently share rate-limit buckets by IP wherever their decay windows overlap. This is out of scope for Blocker 2 (those are unrelated, already-throttled routes, and the live task's instruction was explicit: "do not modify unrelated endpoints") — but it is a genuine, previously-undocumented finding worth its own follow-up. **Recommendation: add this as a new High Priority item** the next time `docs/reviews/Production-Deployment-Audit.md` is revisited, and fix by giving each existing bare-throttled route its own prefix or named limiter, the same pattern this blocker used for the webhook.

This is precisely why Blocker 2 uses a named limiter instead of a bare `throttle:60,1` string: a bare string would have put the webhook's traffic into the *same* shared bucket as login/register/password-reset/onboarding, meaning a burst of legitimate webhook deliveries could have silently locked out real users trying to log in, and vice versa.

---

## Blocker 3 — Enforce HTTPS and Add Security Headers

**Status:** ✅ Complete — 2026-07-10. `TrustProxies` is now configured (trusting `*`, the immediate calling proxy, since Blocker 7's deployment topology isn't finalized yet), and a new global `SecurityHeaders` middleware adds `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, a baseline `Content-Security-Policy`, and conditional HSTS to every response. A full script/style/connect-src CSP was deliberately deferred — see "Discovered/decided during implementation" below.

### Title
Add `TrustProxies` configuration and a security-headers middleware (HSTS, X-Frame-Options, X-Content-Type-Options, and a baseline CSP).

### Why it is critical
No code anywhere in the application configures trusted proxies or forces the HTTPS scheme, and no middleware sets any of the standard security headers. Behind any real reverse proxy or load balancer, Laravel cannot reliably determine the original request scheme, which directly undermines the session-cookie `secure` flag's auto-detection (itself a High Priority finding). Absent security headers leave the application without baseline protections against clickjacking (X-Frame-Options), MIME-sniffing attacks (X-Content-Type-Options), and downgrade attacks (HSTS) — all standard, low-effort protections expected of any production web application.

### Acceptance criteria
- [x] `TrustProxies` middleware is configured (trusting the expected proxy layer, or `*` if the deployment topology isn't finalized yet — documented either way).
- [x] A new middleware adds `Strict-Transport-Security`, `X-Frame-Options`, `X-Content-Type-Options`, and a baseline `Content-Security-Policy` header to every response.
- [x] HSTS is only sent over an actual HTTPS connection (decided and documented — see below).
- [x] A feature test asserts the headers are present on a representative response.
- [x] No existing test breaks (in particular, Inertia responses and the Filament admin panel must not be broken by an overly strict CSP — verify both surfaces still render in tests).
- [x] Full test suite passes, PHPStan clean, Pint clean, build green.

### Estimated effort
Small–Medium (half a day). New middleware class, registration in `bootstrap/app.php`, and enough CSP care to avoid breaking Inertia/Vite asset loading or Filament's own asset pipeline.

### Dependencies
None, but sequenced after Blocker 1 (tenant isolation) and before Blocker 5 (error tracking), both of which also touch `bootstrap/app.php`, to avoid two in-flight blockers editing the same file at once.

### Verification steps
1. Add `TrustProxies` middleware registration to `bootstrap/app.php`'s `withMiddleware()`.
2. Create `app/Http/Middleware/SecurityHeaders.php` (or similar) and append it to the global or `web` middleware stack.
3. Write a feature test hitting any route and asserting the four headers are present with sane values.
4. Manually verify (via existing Vitest/frontend build, and by re-running the full backend test suite) that Inertia page responses and the Filament panel are unaffected.
5. Run all four quality gates; update docs; commit and push.

### Decided during implementation: HSTS gating, and a deliberately narrow CSP

**HSTS gating.** `SecurityHeaders` only sends `Strict-Transport-Security` when `$request->secure()` is true — i.e., an actual TLS connection, or (once `TrustProxies` is trusting the calling proxy) a proxy that forwarded `X-Forwarded-Proto: https`. Sending HSTS over plain HTTP isn't harmful, but it has no effect there, so we don't send a header that does nothing.

**`TrustProxies` set to `*`.** No production proxy/load-balancer IP exists yet (Blocker 7 is still infrastructure-pending), so trusting the immediate calling proxy (`*`) is the standard guidance for an unknown or not-yet-provisioned single-hop reverse proxy (e.g. Forge/nginx). Revisit with a specific IP or IP range once Blocker 7 stands up the real proxy layer.

**CSP deliberately narrow, not full script/style/connect lockdown.** The shipped `Content-Security-Policy` is `frame-ancestors 'none'; object-src 'none'; base-uri 'self'` — real protection against clickjacking-via-iframe, legacy plugin/object embeds, and `<base>`-tag injection, all of which are safe to restrict unconditionally because nothing in the app relies on them. A full `default-src`/`script-src`/`style-src`/`connect-src` policy was deliberately **not** attempted in this blocker, because:
- Filament's admin panel (Livewire + Alpine.js) and Inertia both rely on inline `<script>`/`<style>` in places; restricting those sources correctly requires a nonce or hash-based rollout wired through Blade, Filament's own asset pipeline, and Inertia's SSR (if ever enabled) — a materially larger change than a middleware addition.
- The Vite dev server used in local development serves assets from a different origin/port (HMR websocket + module scripts), which a strict same-origin `script-src`/`connect-src` would break for every local-dev contributor, not just production.
- Getting this reintroduced wrong (e.g., silently breaking Filament widget rendering or Inertia navigation) is a worse outcome than shipping the narrower, unconditionally-safe policy now and expanding it deliberately later.

**Recommendation:** track a full CSP rollout (nonce-based script/style-src, tested against Filament + Inertia + Vite-built production assets) as a follow-up item the next time the audit is revisited — it is real hardening, but it is its own scoped project, not a one-middleware fix.

---

## Blocker 4 — Make the Scheduler Production-Ready

**Status:** ✅ Complete — 2026-07-10. All six `routes/console.php` entries now have `->withoutOverlapping()`, and `->onOneServer()` on the five not already deduped via `ShouldBeUnique` (`ApplyLearnings` is the exception). Added `infrastructure/cron/atlas-scheduler`, mirroring `infrastructure/supervisor/atlas-worker.conf`'s style. Also addressed the related audit finding (from the "Queue recovery" operational-risk section, not originally listed in this blocker's acceptance criteria, but explicitly in scope for the live task): `CheckChannelHealth`, `ProcessAnalyticsWebhookEvent`, `PruneRawMetrics`, and `PublishScheduledContent` — the four jobs the audit flagged as having no `$tries`/`$backoff` — now have both, plus a `failed()` method for structured failure logging.

### Title
Add overlap/single-server protection to every scheduled job, and commit a deployable cron/systemd artifact for `schedule:run` (mirroring the existing `infrastructure/supervisor/` pattern for queue workers).

### Why it is critical
`routes/console.php` defines six scheduled jobs — including the recurring integration sync that is the entire mechanism behind Atlas's "knows more tomorrow than today" promise, and opportunity expiration, which prevents stale opportunities from silently suppressing fresh detection. None of the six use `withoutOverlapping()` or `onOneServer()`, and — more fundamentally — nothing in the repository triggers `schedule:run` in production at all; only the local-dev `composer dev` script runs a foreground scheduler loop. Without a production trigger, all six scheduled behaviors simply never happen after deployment, silently.

### Acceptance criteria
- [x] Every scheduled job in `routes/console.php` has `->withoutOverlapping()` added (and `->onOneServer()` where the job is not already idempotent/unique via `ShouldBeUnique`), so a slow run doesn't stack with the next scheduled tick.
- [x] A committed, deployable artifact exists (`infrastructure/cron/atlas-scheduler`) that an operator can install to invoke `php artisan schedule:run` every minute — mirroring how `infrastructure/supervisor/atlas-worker.conf` already documents the queue-worker deployment shape.
- [x] The artifact is documented (a short comment block, matching the existing Supervisor config's style) explaining exactly what it does and how to install it.
- [x] Existing scheduled-job tests continue to pass; a new test suite confirms `withoutOverlapping()`/`onOneServer()` on every scheduled entry, not just the highest-value ones.
- [x] Full test suite passes, PHPStan clean, Pint clean, build green.
- [x] (Added beyond the original acceptance criteria, per the live task's explicit requirements) `$tries`/`$backoff`/`failed()` added to the four jobs the audit's "Queue recovery" section flagged as missing retry/backoff configuration: `CheckChannelHealth`, `ProcessAnalyticsWebhookEvent`, `PruneRawMetrics`, `PublishScheduledContent`.

### Estimated effort
Small (a few hours). The code change is mechanical; the deployable artifact is a short, well-precedented file (the Supervisor config is the template to follow).

### Dependencies
None. The actual installation of the cron/systemd artifact on a real server is Blocker 7's (production environment) job to execute — this blocker only produces the artifact and hardens the schedule definitions in code.

### Verification steps
1. Add `->withoutOverlapping()` (and `->onOneServer()` for non-unique jobs) to each of the six `Schedule::` calls in `routes/console.php`.
2. Add `infrastructure/cron/` (or `infrastructure/systemd/`) with a committed template file and a short explanatory comment.
3. Confirm existing tests for `SyncDueIntegrations`, `ExpireOpportunities`, and the other scheduled jobs still pass.
4. Run all four quality gates; update docs; commit and push.

### Decided during implementation: retry/backoff values, and why `failed_jobs` visibility was deferred

**Retry/backoff values chosen.** All four newly-configured jobs use `$tries = 3` (consistent with every other job in the codebase — `SyncIntegration`, `CommitDecision`, `GenerateContent`). Backoff varies by how quickly a retry is likely to help: `CheckChannelHealth`/`PublishScheduledContent` use `$backoff = 60` (transient network/DB blips, matching `SyncIntegration`/`CommitDecision`'s existing convention), `ProcessAnalyticsWebhookEvent` uses `$backoff = 30` (a lighter-weight, single-metric update), and `PruneRawMetrics` uses `$backoff = 300` (a monthly bulk update with no urgency — a longer backoff avoids hammering the database again immediately if the first attempt failed for a capacity reason). Each of the four also gained a `failed()` method logging a structured `Log::error(...)` once retries are exhausted, matching the `SyncIntegration`/`PublishContent` convention already in the codebase.

**`failed_jobs` visibility/recovery command deferred, not implemented.** The audit's "Queue recovery" section separately notes that failed jobs land in `failed_jobs` but nothing in the app references them — no Filament resource, no recovery command, no alerting. This blocker's own acceptance criteria never asked for that (it asked for `$tries`/`$backoff`/overlap protection/a cron artifact), and the live task's instructions were explicit that a failed-job recovery command should only be added here if this blocker's plan already called for it. It doesn't, so it wasn't added — it belongs with Blocker 5 (real error tracking/monitoring), where "how does a human find out a job failed" is already the blocker's whole point. Recommendation: fold a minimal `failed_jobs` Filament resource or `artisan queue:failed`-adjacent recovery step into Blocker 5's scope when it's executed.

---

## Blocker 5 — Failed Job Visibility and Error Tracking

**Status:** ✅ Complete — 2026-07-10. Scope was widened at execution time (per the live task's explicit instructions) to fold in the `failed_jobs` visibility/recovery gap Blocker 4 identified and deliberately deferred here, alongside this blocker's original error-tracking scope. Error tracking was **not** fully integrated — no real vendor package (Sentry or equivalent) was installed. Instead, an `ErrorTracker` abstraction was built and wired into `withExceptions()`, bound to a `NullErrorTracker` no-op until a real driver is configured — see "Decided during implementation" below for exactly what remains for production activation.

### Title
Install and configure a real error-tracking integration (Sentry or an equivalent Laravel-native option) so production exceptions are reported somewhere a human is alerted, not only written to a log file. **Widened during execution** to also give operators visibility into and a recovery workflow for `failed_jobs`, which had none.

### Why it is critical
`bootstrap/app.php`'s entire exception-handling customization today is a single `shouldRenderJsonWhen` rule. No error-tracking package exists in `composer.json`. Combined with no monitoring/APM (a High Priority finding, not critical on its own, but compounding this one), a production outage today would be discovered by a customer noticing something wrong, not by the team noticing an alert — directly contradicting the operational maturity the Private Beta Execution Checklist's Go/No-Go gate requires. Separately (per Blocker 4's own deferred finding): failed jobs land in `failed_jobs` with zero operational visibility — no Filament resource, no recovery command, no alerting — so a job that exhausts its retries today is invisible and unrecoverable without a manual database query.

### Acceptance criteria
- [x] ~~A real error-tracking package is added to `composer.json`~~ — **not done**; deliberately deferred (see notes below). Instead, an `ErrorTracker` interface + `NullErrorTracker` no-op implementation were added, ready for a real driver to be substituted in with a single `AppServiceProvider` binding change.
- [x] `bootstrap/app.php`'s `withExceptions()` reports exceptions to the tracker in addition to (not instead of) existing logging.
- [x] The integration is entirely config/env-driven (`ERROR_TRACKING_DRIVER`/`ERROR_TRACKING_DSN` via `.env`) — no hardcoded project identifiers, and the integration is inert (forced to `NullErrorTracker`) in `testing` regardless of config, so test runs never attempt to phone home.
- [x] `.env.example` documents the new variables with placeholders, consistent with every other credential in that file.
- [x] A test confirms the binding resolves correctly in the test environment, and that the `withExceptions()` wiring actually invokes whatever `ErrorTracker` is bound.
- [x] Full test suite passes, PHPStan clean, Pint clean, build green.
- [x] (Added — folded in from Blocker 4's deferred finding) A `FailedJobResource` Filament panel gives operators visibility into `failed_jobs` (queue, job class, failure timestamp, exception summary) and a Retry/Discard recovery workflow, gated by the same superadmin-only panel access every other Filament resource already relies on.

### Estimated effort
Medium (half a day to a day) — package install, configuration, and care to ensure test/CI runs are unaffected (no accidental outbound calls during `php artisan test`). Actual effort was closer to a full day once the `failed_jobs` visibility work (folded in from Blocker 4) was added.

### Dependencies
None functionally, but sequenced after Blocker 3 (security headers) since both touch `bootstrap/app.php`'s exception/middleware configuration — doing them in this order means the file's state is simple and single-purpose at each step.

### Verification steps
1. Build an `ErrorTracker` interface + `NullErrorTracker` implementation; wire `withExceptions()->reportable()` to call whatever is bound, without removing existing log-based reporting.
2. Confirm via a test that the binding resolves to the no-op implementation in `testing`, and that a directly-invoked exception report reaches a substituted fake tracker.
3. Add the new env var(s) to `.env.example` with a placeholder value.
4. Add a `FailedJob` model (mapping the framework's own `failed_jobs` table), a `FailedJobRecoveryService` (retry/forget, mirroring `artisan queue:retry`/`queue:forget`), and a `FailedJobResource` Filament panel with Retry/Discard actions.
5. Run all four quality gates; update docs; commit and push.

### Decided during implementation: why Sentry wasn't actually installed, and what production activation requires

The live task's instructions explicitly allowed deferring full Sentry integration in favor of "prepare the abstraction/configuration needed and clearly document what remains for production activation" — taken deliberately, for the same reason the codebase already abstracts its AI provider (`App\AI\Contracts\AiProvider`) rather than hardcoding a vendor: installing a real error-tracking vendor package is an operational/billing decision (which service, whose account, what plan) that shouldn't block shipping the code-side wiring, and a `composer require` of a real SaaS SDK is exactly the kind of external, budget-affecting dependency this session's scope discipline (one blocker at a time, no unrelated changes) argues against introducing speculatively.

What exists now: `App\ErrorTracking\Contracts\ErrorTracker` (an interface with one method, `report(Throwable $exception, array $context = [])`), `App\ErrorTracking\NullErrorTracker` (a no-op, bound by default and unconditionally in `testing`), and `bootstrap/app.php`'s `withExceptions()->reportable()` callback that resolves `ErrorTracker` from the container and calls it for every reported exception — additive to Laravel's own exception logging, never a replacement.

**What remains for production activation:**
1. `composer require sentry/sentry-laravel` (or another vendor SDK).
2. Implement `App\ErrorTracking\SentryErrorTracker implements ErrorTracker`, wrapping the vendor SDK's own exception-capture call.
3. Add a `'sentry' => new SentryErrorTracker(...)` arm to the `match` in `AppServiceProvider::register()`'s `ErrorTracker` binding (the commented-out example line is already there).
4. Set `ERROR_TRACKING_DRIVER=sentry` and the vendor's DSN env var in the real production `.env` (never in `.env.example`, which only ships placeholders).
5. Re-run the existing `ErrorTrackerTest` suite — it should pass unmodified, since it tests the abstraction boundary, not a specific vendor.

### Decided during implementation: the `failed_jobs` recovery workflow

**Filament, not a CLI command or new dashboard.** `failed_jobs` is a system-wide table (no `company_id`, not tenant data), and the app already has a superadmin-gated Filament panel with an established resource pattern (`ExecutionResource`, `RecommendationResource`) that this reuses directly — a `FailedJobResource` needed no new authorization mechanism, no new panel, and no new access-control code, since panel-level `canAccessPanel()` (superadmin-only) already gates it exactly like every other resource.

**Retry mirrors `artisan queue:retry` exactly**, including resetting the payload's `attempts` counter to 0 before re-pushing it onto the job's original connection/queue — this is the same mechanism Laravel's own CLI command uses (`Illuminate\Queue\Console\RetryCommand::resetAttempts()`), so the recovery workflow behaves identically to the documented CLI path, just reachable from the admin panel. A successful retry removes the `failed_jobs` row; if the retry fails again, a *new* row appears with a new UUID — there is no separate "retry status" column to maintain, since presence/absence in the table already communicates it.

**No bulk actions.** Retry/Discard are per-row only. Bulk-retrying many failed jobs at once risks re-triggering whatever caused the original failure (e.g., a downstream outage) all at once; an operator reviewing failures one at a time is the safer default until there's a real operational need for bulk recovery.

---

## Blocker 6 — Wire Real Transactional Email

**Status:** ✅ Complete — 2026-07-10. Scope grew beyond this section's original "no controller changes" constraint, per the live task's explicit delivery-safety requirements (production-misconfiguration rejection, failure logging, anti-enumeration verification) — see "Decided during implementation" below. The Postmark mailer transport itself was also missing its underlying package (`symfony/postmark-mailer` was never installed, so selecting `MAIL_MAILER=postmark` would have thrown a class-not-found error even with valid credentials); that gap is now fixed too.

### Title
Configure a real transactional email mailer (Postmark, already stubbed in `config/mail.php`) as an available, credential-driven production option, and confirm the password-reset flow actually sends through it once configured.

### Why it is critical
`MAIL_MAILER=log` is the shipped default and no application code calls `Mail::` directly — but Laravel's built-in password-reset flow already sends through the framework's notification system (`Password::sendResetLink()` → `ResetPassword` notification → mail channel), which means the *code path* already exists and needs no new application code, only a real mailer being selected and credentialed. As shipped, in production, this flow would silently do nothing — password reset (the one flow that most needs email to work) would appear to succeed to the user while never actually sending anything.

### Acceptance criteria
- [x] The Postmark mailer in `config/mail.php` is fully wired (`message_stream_id` uncommented and filled in; `symfony/postmark-mailer` + `symfony/http-client` added to `composer.json` — without them, the transport class doesn't exist at all, so `MAIL_MAILER=postmark` would fail even with valid credentials).
- [x] `.env.example` documents every variable needed to activate Postmark in production (`POSTMARK_API_KEY`, `POSTMARK_MESSAGE_STREAM_ID`), with placeholders, without changing the safe `MAIL_MAILER=log` default for local/test environments.
- [x] A test confirms that, given Postmark selected as the mailer (via config override in the test), the password-reset notification resolves to the correct mailer/transport without needing a real API call.
- [x] ~~No change to the actual password-reset business logic~~ — **superseded by the live task's explicit requirements**; `PasswordResetController::email()` was extended (not its outward behavior or anti-enumeration guarantee) to add a production-misconfiguration guard and delivery-failure logging. See notes below.
- [x] Full test suite passes, PHPStan clean, Pint clean, build green.
- [x] (Added, per the live task's explicit requirements beyond this plan's original scope) A `ProductionMailerGuard` service refuses to attempt delivery — and logs critically — when `APP_ENV=production` and `MAIL_MAILER` is `log`/`array`; delivery failures from a real transport are caught and logged without leaking secrets; the generic anti-enumeration response is preserved in every case.

### Estimated effort
Small (a few hours) — this is primarily configuration completion, not new application code, since the sending code path already exists via Laravel's framework. Actual effort was medium once the missing Postmark package and the live task's delivery-safety requirements were addressed.

### Dependencies
None.

### Verification steps
1. Complete the Postmark mailer's config block in `config/mail.php` and add the corresponding `.env.example` entries.
2. Add a test that swaps the mail mailer to `postmark` in config and confirms the password-reset notification would route through it (using Laravel's `Notification::fake()`/`Mail::fake()` assertions, not a live send).
3. Confirm the safe `log` default remains untouched for `local`/`testing`.
4. Run all four quality gates; update docs; commit and push.

### Decided during implementation: the Postmark package was never actually installed

Auditing `config/mail.php`'s Postmark block revealed it couldn't have worked even fully configured: `symfony/postmark-mailer` (the bridge package Laravel's `MailManager::createPostmarkTransport()` requires) was never in `composer.json`. Selecting `MAIL_MAILER=postmark` in production, even with a valid API key, would have thrown `Class "Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkTransportFactory" not found` on the very first send attempt — a second, previously-undocumented way this blocker's "silently do nothing" failure mode could occur. Fixed by `composer require symfony/postmark-mailer symfony/http-client`.

### Decided during implementation: why `PasswordResetController` was touched despite the original "no controller changes" note

This plan's original acceptance criteria said "no change to the actual password-reset business logic," written when the blocker's scope was purely configuration completion. The live task's instructions explicitly asked for delivery safety beyond configuration: rejecting a production+`log` misconfiguration "clearly," logging delivery failures without secrets, and re-verifying no user-enumeration regression. None of that is expressible as pure config — `MAIL_MAILER=log`/`array` never throws (it "succeeds" by writing to a log file instead of delivering), so there is no exception for a try/catch to catch; it has to be checked explicitly, at the one call site that ever sends mail. `PasswordResetController::email()` is that site.

The change is deliberately narrow: a new `App\Services\Mail\ProductionMailerGuard::isMisconfigured(string $environment, string $mailer): bool` (pure, easily unit-tested) is checked before calling `Password::sendResetLink()`. If misconfigured, delivery is skipped and a `Log::critical(...)` fires instead — loud, in the log/error-tracker an operator actually watches (see Blocker 5). If not misconfigured, the existing call is wrapped in a `try/catch (Throwable)` that logs `Log::error(...)` (mailer + exception message + the recipient email — not a secret — but never the reset token or password) on any real transport failure. **In every branch, the exact same generic "If an account exists..." response is returned** — the anti-enumeration guarantee and the actual reset business logic are both completely unchanged; only the code path leading up to the existing `Password::sendResetLink()` call gained a guard and a catch.

### Decided during implementation: operator-facing signal vs. user-facing signal

"Fail clearly" (an operational requirement) and "avoid leaking whether an account exists" (a security requirement) point in different directions for different audiences. The resolution: operators get a loud, unmissable signal (`Log::critical`, distinct message, immediately actionable), while the end user always sees the same reassuring, non-committal message regardless of whether the account exists, whether the mailer is misconfigured, or whether a real send actually failed. A misconfigured production mailer is an incident an operator needs to see immediately — it is never something a website visitor should be able to infer from response differences.

### Not done, per explicit instruction: a mail health/readiness check

The live task's requirements made this conditional — "if this is part of the blocker plan" — and it isn't; this plan's acceptance criteria never called for one. `HealthController` was not touched, and no health check sends real email (nothing about mail was added to `/api/health`/`/api/ready`/`/api/live` at all).

---

## Blocker 7 — Provision the Production Environment

**Status:** 🟡 Partially complete — 2026-07-10. This blocker's original acceptance criteria are entirely operator-executed infrastructure provisioning (a real server, domain, SSL, a live deploy) and remain genuinely undone — no server, domain, or DNS record exists as a result of this session, and none was provisioned, per explicit instruction. What **was** completed is the code-representable subset the live task titled "Production Infrastructure Configuration": removing the hardcoded `TrustProxies` wildcard from Blocker 3 in favor of an operator-configurable `TRUSTED_PROXIES` setting, and documenting the expected production topology. See "Decided during implementation" below.

### Title
Stand up a real production server, domain, and deploy pipeline.

### Why it is critical
No production environment exists at all today — no server, no domain, no deploy automation. `.env.example` ships local-development defaults (`APP_ENV=local`, `APP_DEBUG=true`) that the repository's own `composer.json` `setup` script would copy verbatim into `.env` if a real production environment file isn't separately and deliberately provisioned. Every other blocker in this plan is moot without somewhere real for the fixed code to run.

### Why this blocker is infrastructure, not code
This cannot be completed by writing code in this repository. It requires an operator to choose a hosting provider, register a domain, provision a server and managed database, and wire a deploy mechanism — decisions and actions that happen outside version control. This plan's contribution is limited to making sure the repository is *ready* to be deployed correctly once that infrastructure exists (Blockers 1–6 above), plus, optionally, a checked-in deploy-pipeline skeleton (a GitHub Actions CD workflow, or an `Envoy.blade.php`) that an operator can point at real infrastructure once it exists — but the actual provisioning is not a pull request.

### Acceptance criteria (operator-executed, not code-reviewed)
- [ ] A production server is provisioned and reachable.
- [ ] A registered domain resolves to it, with valid SSL.
- [ ] The application is deployed with a production-appropriate `.env` (not a copy of `.env.example`) — `APP_ENV=production`, `APP_DEBUG=false`, real database/Redis/queue credentials.
- [ ] `config:cache`, `route:cache`, and `event:cache` are run as part of the deploy process.
- [ ] Queue workers (per `infrastructure/supervisor/atlas-worker.conf`) and the scheduler (per Blocker 4's deployable artifact) are both running under process supervision.
- [ ] A second, successful deploy has been performed, proving the process is repeatable.

**Code-representable subset completed this session (not part of the original acceptance criteria above, added per the live task's explicit scope):**
- [x] The hardcoded `TrustProxies` wildcard (`at: '*'`, set in Blocker 3 as a deliberate placeholder) is replaced with an operator-configured `TRUSTED_PROXIES` env var, parsed by a new `App\Services\Http\TrustedProxyResolver`.
- [x] Default (unset `TRUSTED_PROXIES`) now trusts **no** proxies — a fail-closed change from Blocker 3's fail-open `'*'` default — correct for local/testing (no proxy exists) and safer for an unconfigured production (silently trusting everyone is worse than a misconfigured deploy that visibly fails HTTPS detection until fixed).
- [x] `docs/deployment/Production-Topology.md` documents the expected reverse proxy → application server → {database, Redis, queue workers, scheduler} shape.
- [x] Tests prove HTTPS detection, HSTS, client IP resolution, and IP-keyed rate limiting all behave correctly given a trusted proxy, and are correctly *not* fooled by an untrusted one forging the same headers.

### Estimated effort
Large (days, per the existing `Private-Beta-Plan.md` Week 1 sprint estimate — roughly matches this blocker one-to-one). The code-representable subset above was small (a few hours); the operator-executed remainder is unchanged and still large.

### Dependencies
Should follow Blockers 1–6, so the code being deployed already has tenant isolation, webhook protection, security headers, a hardened scheduler, error tracking, and real email ready to be credentialed — deploying before those land just means redeploying again immediately after.

### Verification steps
See the "Production Infrastructure Checklist" section of [Private-Beta-Execution.md](Private-Beta-Execution.md) — that document is the detailed, step-by-step verification procedure for this blocker and should be run in full once infrastructure work begins.

### Decided during implementation: why a hardcoded wildcard became an operator-configured variable, not a hardcoded IP

Blocker 3 hardcoded `trustProxies(at: '*')` with an explicit comment to revisit "once Blocker 7 fixes the actual proxy layer in place." That proxy layer still doesn't exist — no hosting provider has been chosen, so there is no real IP to hardcode. Hardcoding a *guess* would be worse than the wildcard it replaces. Instead, the trust decision moved from application code into `TRUSTED_PROXIES`, an environment variable an operator sets once the real topology is chosen (a specific IP/CIDR, or `*` again if the real proxy's own IP genuinely isn't fixed — e.g. most managed load balancers). This satisfies "replace wildcard proxy trust with a production-ready configuration strategy" without requiring infrastructure that doesn't exist yet: the *mechanism* is now production-ready; the *value* is an operator decision, exactly like every other credential in `.env.example`.

The default changed from fail-open (`'*'`, trust the immediate caller unconditionally) to fail-closed (unset → trust nothing). A production deploy that forgets to set `TRUSTED_PROXIES` will visibly misbehave (no HSTS, wrong client IPs) rather than silently trusting whatever happens to connect — consistent with the fail-clearly philosophy already established for mail in Blocker 6's `ProductionMailerGuard`.

### Decided during implementation: testing trusted-proxy behavior without a real proxy

`bootstrap/app.php` resolves `TRUSTED_PROXIES` once at application boot, before any test method's body runs — too early to vary per test. Two things made this testable anyway: the parsing logic itself was pulled into `App\Services\Http\TrustedProxyResolver` (a pure function, unit-tested directly), and the actual runtime trust decision uses `Illuminate\Http\Middleware\TrustProxies::at()`/`::flushState()` — static methods that take effect immediately, letting tests simulate "a request from a trusted proxy" vs. "a request from an untrusted one claiming the same forwarded headers" within the same running application, including a full round-trip through the real `analytics-webhook` rate limiter to prove IP-keyed limiting survives being placed behind a proxy.

---

## Blocker 8 — Configure and Verify Database Backups

**Status:** 🟡 Partially complete — 2026-07-10. Like Blocker 7, this blocker's original acceptance criteria are entirely operator-executed (real backups running against a real production database) and remain genuinely undone — no production database exists yet. What **was** completed is the repository-representable portion: working, tested backup/verify/restore scripts, a real local restore drill (against scratch PostgreSQL databases, not a mock), and full documentation in [Backup-and-Recovery.md](../operations/Backup-and-Recovery.md). See "Decided during implementation" below.

### Title
Configure automated database backups and perform (and document) at least one successful restore.

### Why it is critical
No backup mechanism exists anywhere in the repository or, per the audit, in any provisioned infrastructure (none exists yet). A single bad migration or operational mistake is unrecoverable today. This was already flagged in the June Beta Readiness Audit and remains completely unaddressed.

### Why this blocker is infrastructure, not code
Like Blocker 7, this is fundamentally a provisioning and verification task against a real, managed database — most managed PostgreSQL offerings (the stack's documented preference) provide automated WAL archiving/backups as a configuration toggle, not a code change. The repository has no code-level role to play here beyond, optionally, a documented restore runbook.

### Acceptance criteria (operator-executed, not code-reviewed)
- [ ] Automated backups are configured and confirmed running on a schedule against the real production database.
- [ ] At least one backup has been restored to a separate/scratch database, and the restored data was spot-checked for correctness.
- [ ] A restore procedure is documented well enough that a second person could follow it without asking whoever performed the first restore.

**Repository-representable subset completed this session (not part of the original acceptance criteria above, added per the live task's explicit scope):**
- [x] A provider-neutral `pg_dump` backup script (`infrastructure/backup/atlas-db-backup.sh`) — fails loudly, never treats an empty dump as success, supports optional GPG encryption and an optional off-site upload hook.
- [x] A lightweight backup verification script (`atlas-db-verify.sh`) distinguishing "the file isn't corrupt" from "this backup is confirmed restorable" (only the full drill proves the latter).
- [x] A restore script (`atlas-db-restore.sh`) that never proceeds without explicit, exact-match confirmation of the target database name — interactively or via `--yes --confirm-database=<name>`.
- [x] A real, automated local restore drill (`tests/Feature/Backup/BackupRestoreDrillTest.php`) against two disposable scratch PostgreSQL databases, proving the scripts round-trip real data — not merely that they parse arguments. Skips gracefully (mirroring `RedisConnectionTest`) when a compatible local PostgreSQL client/server isn't available, rather than failing the build.
- [x] `docs/operations/Backup-and-Recovery.md` — strategy for the database (the only stateful store today), uploaded files (none exist — verified via `grep -rn "Storage::" app/`), and secrets recovery references (never stored in the repo); retention, encryption, off-site, and scheduling guidance; and an explicit code-complete-vs-operator-complete distinction so this work is never mistaken for "backups are operational."

### Estimated effort
Small–Medium (a day, mostly waiting on the first backup cycle and performing the restore drill) — but entirely gated on Blocker 7 existing first. The repository-representable subset above was small (a few hours); the operator-executed remainder is unchanged and still gated on Blocker 7.

### Dependencies
Blocker 7 (production environment) — there is no database to back up until then.

### Verification steps
See the "Backups" subsection of [Private-Beta-Execution.md](Private-Beta-Execution.md)'s Production Infrastructure Checklist, and the Go/No-Go gate's explicit backup-restore requirement — both already specify exactly what "done" looks like for this blocker.

### Decided during implementation: logical (`pg_dump`) backups, not WAL archiving

WAL-based physical backups/point-in-time recovery are typically a managed-provider dashboard toggle, not something this repository can meaningfully configure in advance of choosing a provider (Blocker 7 remains unprovisioned). `pg_dump`-based logical backups were chosen instead because they're **provider-neutral** — the same script works against any PostgreSQL instance, local or managed, which is exactly what made a real, automated local restore drill possible without any real infrastructure existing yet. Once a managed provider is chosen, its own automated backup feature should likely become the *primary* mechanism, with these scripts as the portable fallback/local-drill tool — this is documented explicitly in `Backup-and-Recovery.md` rather than left to be rediscovered later.

### Decided during implementation: a real local drill, not just documentation

The live task asked for a "local or disposable-database restore drill procedure." Rather than writing that up as prose alone, `tests/Feature/Backup/BackupRestoreDrillTest.php` actually performs it — creating two scratch databases, seeding one, backing it up, verifying the dump, restoring into the other, and asserting the data matches — every time the test suite runs (skipping gracefully if the local environment can't support it). Building this surfaced a real, worth-documenting operational gotcha: `pg_dump` refuses to dump from a database server newer than itself, and a dump taken by a *newer* `pg_dump` than the restore target's server can include settings the older server doesn't recognize (encountered directly while building this: Homebrew's `pg_dump` 14 refused a PostgreSQL 16 server; a mismatched PostgreSQL 17 client's dump then failed to restore into that PostgreSQL 16 server over an unrecognized `transaction_timeout` setting). This is now documented in `Backup-and-Recovery.md`'s drill section as a real, specific pitfall to check for, not a hypothetical one.

### Decided during implementation: no uploaded-file backup strategy, because there's nothing to back up

The live task asked this blocker to define a backup strategy for "application-managed uploaded files, if any." A repository-wide check (`grep -rn "Storage::" app/`) confirms there are none — no code path anywhere uploads, generates, or stores a file on disk or object storage today (consistent with the audit's existing High Priority finding on file storage). Rather than inventing a speculative mechanism for data that doesn't exist, `Backup-and-Recovery.md` documents this explicitly and states what to do if this changes in the future, so the gap is a deliberate, checked observation — not a silent omission.

---

## After all eight blockers

Once Blockers 1–6 are merged and Blockers 7–8 are operationally complete, re-run [Production-Deployment-Audit.md](../reviews/Production-Deployment-Audit.md)'s critical section against the then-current state of the repository and infrastructure — don't assume this plan's completion is self-verifying. Move on to the audit's High Priority list next, following the same one-blocker-at-a-time discipline established here.
