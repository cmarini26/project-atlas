# Production Deployment Readiness Audit

**Date:** 2026-07-10
**Scope:** Read-only audit of the current repository (`main` branch) for production deployment readiness — not a repeat of the [Beta-Readiness-Audit.md](Beta-Readiness-Audit.md) (2026-06-27), which scored operational maturity broadly. This audit inspects the actual code, config, and repo contents that will run in production and reports what is verifiably true today, with file/line evidence for every finding.
**Method:** Direct inspection of `config/*.php`, `bootstrap/app.php`, `routes/*.php`, `composer.json`, `.env.example`, `.github/workflows/ci.yml`, `infrastructure/`, and the relevant `app/` middleware, services, controllers, and jobs. No code was written or modified to produce this audit.
**Related documents:** [Version-1.0-Roadmap.md](../plans/Version-1.0-Roadmap.md), [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md), [Beta-Readiness-Audit.md](Beta-Readiness-Audit.md), [STATUS.md](../STATUS.md)

---

## Headline Finding

The single most consequential finding in this audit: **`CompanyScope`, the global scope every tenant model relies on, never activates during a real HTTP request.** It only engages when `current_company_id` is bound in the container, and the only place in the entire codebase that binds that key is test scaffolding (`tests/Feature/**`). `EnsureCompanyMembership` — the middleware that resolves the acting company — only ever sets a request *attribute* (`$request->attributes->set('company', ...)`), never a container binding. In production, every tenant-scoped Eloquent query runs with **zero automatic company filter**.

This is not currently an active data leak: every controller and job this audit inspected explicitly re-derives `$company` from the request and manually adds `where('company_id', $company->id)` or an `abort_if` ownership check — a pattern applied consistently, if by convention rather than by structural guarantee. But it means tenant isolation today has exactly one layer of defense, applied by hand, in every single query, forever. There is no automated check that would catch a future contributor forgetting it, and the codebase's own naming and doc comments (`CompanyScope`, `BelongsToCompany`) imply a safety net that does not actually exist in production traffic. This deserves top billing over every infrastructure gap below, because infrastructure gaps are absences (nothing happens), while this is a false sense of security (something appears to protect data that in practice does not).

Everything else in this audit is, in effect, restating and updating the June Beta-Readiness-Audit with current evidence: **the product code is materially further along than in June (Milestone 11 shipped in full), but almost none of the June audit's infrastructure blockers have been resolved in the repository** — because most of them are provisioning work that happens outside the repo. What *has* changed since June, confirmed by this audit, is documented below.

---

## 1. Infrastructure

| Area | Verdict | Strongest evidence |
|---|---|---|
| Environment variables | 🔴 NOT READY | `.env.example:2-4` — `APP_ENV=local`, `APP_DEBUG=true`; `composer.json`'s `setup` script (`file_exists('.env') || copy('.env.example', '.env')`) would copy these local-dev values verbatim if a real `.env` isn't separately provisioned |
| Queue workers | 🟡 PARTIALLY READY | `infrastructure/supervisor/atlas-worker.conf` exists and matches the five queues actually dispatched to (`high`, `ai`, `default`, `observations`, `maintenance`) — but no Horizon, and the `publishing`/`analytics` queue names referenced in `composer.json`'s dev script have no matching Supervisor program or `onQueue()` call anywhere in `app/` (stale reference) |
| Scheduler | 🔴 NOT READY | `routes/console.php` defines 6 scheduled jobs; **no cron entry, systemd timer, or deploy file anywhere in the repo invokes `schedule:run`** — only the local-dev `composer dev` script runs `schedule:work` in the foreground |
| Storage | 🔴 NOT READY | `config/filesystems.php:16` — default disk is `local`; `.env.example:58-61` ships blank `AWS_*` values; zero occurrences of the `Storage::` facade found anywhere in `app/` |
| Logging | 🟡 PARTIALLY READY | `config/logging.php` — default stack resolves to the non-rotating `single` driver (`.env.example:16` `LOG_STACK=single`); the `daily` channel (with 14-day retention) exists but isn't active by default; the custom `publishing` channel has a hardcoded `debug` level, not env-driven |
| Cache | 🟢 READY | `.env.example:42` sets `CACHE_STORE=redis`; `BusinessBrainService` (`app/Services/Brain/BusinessBrainService.php:18-31`) deliberately avoids the cache facade via a documented prior incident (a Redis-cached Brain deserialized as `__PHP_Incomplete_Class` and crashed `CommitDecision`) — this is a considered design decision, not an oversight |
| Sessions | 🔴 NOT READY | `config/session.php:172` — `'secure' => env('SESSION_SECURE_COOKIE')` has no default and `.env.example` never sets this variable at all, so secure-cookie enforcement falls back to Laravel's auto-detection rather than being explicitly forced; `SESSION_ENCRYPT=false` (`.env.example:29`) |
| Redis | 🟡 PARTIALLY READY | `config/database.php`'s redis section sources every credential from `env()` with no hardcoded values, and cache is isolated on its own logical DB (`REDIS_CACHE_DB`, default `1`) — but `.env.example:46` ships `REDIS_PASSWORD=null` (no auth), and queue + session share the same logical DB (`default`, DB `0`) with no dedicated separation |
| SSL / proxy trust | 🔴 NOT READY | `bootstrap/app.php` (29 lines, read in full) has no `TrustProxies` configuration and no `URL::forceScheme('https')` anywhere in the app — behind any real load balancer or reverse proxy, Laravel cannot reliably determine the original request scheme, which also undermines session-cookie security auto-detection above |
| Backups | 🔴 NOT READY | Exhaustive `find -iname "*backup*"` across the whole repo (excluding vendor/node_modules) returns nothing; `spatie/laravel-backup` is not in `composer.json`; no backup script, cron entry, or Forge recipe exists anywhere |
| Email | 🔴 NOT READY | `.env.example:49` — `MAIL_MAILER=log` is the shipped default; Postmark/SES exist as configured transport *options* in `config/mail.php` but carry no credentials in `.env.example`; **zero uses of the `Mail::` facade exist anywhere in `app/`**, and no `app/Notifications` directory exists at all |
| Monitoring | 🟡 PARTIALLY READY | Real health/readiness/liveness endpoints exist and do real work — `routes/api.php:8-10` plus Laravel's built-in `/up` (`bootstrap/app.php:15`); `HealthController::ready()` genuinely checks database, cache, and queue connectivity — but no Laravel Pulse, Telescope, or third-party APM package is installed |
| Error tracking | 🔴 NOT READY | `composer.json` has no Sentry/Flare/Bugsnag package; `bootstrap/app.php`'s entire `withExceptions()` customization is one `shouldRenderJsonWhen` rule for API routes — no error-reporting integration of any kind |

### Notable detail: the "acceptable to defer" queue choice

`.env.example:40` pins `QUEUE_CONNECTION=database` — not `sync` (good, avoids the documented onboarding-request-blocking hazard) and not `redis` (the value the surrounding code comment and the Supervisor topology assume). This is a workable middle setting, not a blocker, but worth fixing before Stage A so the deployed configuration matches the architecture the Supervisor config was actually written for.

---

## 2. Laravel Production Configuration

| Area | Verdict | Strongest evidence |
|---|---|---|
| `APP_ENV` | 🔴 NOT READY | `.env.example:2` — `APP_ENV=local`. `config/app.php:29`'s framework-level fallback (`env('APP_ENV', 'production')`) is itself safe; it is specifically the checked-in example file that overrides it |
| `APP_DEBUG` | 🔴 NOT READY | `.env.example:4` — `APP_DEBUG=true`, directly contradicting `config/app.php:42`'s safe framework fallback (`(bool) env('APP_DEBUG', false)`). If ever deployed unmodified, this enables full stack traces and environment data on every error page |
| Cache/config/route/view optimization | 🔴 NOT READY | The only CI/deploy-adjacent file in the repo, `.github/workflows/ci.yml`, runs Pint → PHPStan → PHPUnit only. It never runs `config:cache`, `route:cache`, `view:cache`, or `event:cache` — because it is a test pipeline, not a deploy pipeline. No Dockerfile, `Envoy.blade.php`, or Forge recipe exists anywhere in the repo to run these commands as part of an actual deploy |
| Queue configuration | 🟡 PARTIALLY READY | `.env.example:40` — `database`, not `sync` (see note above); functionally safe, architecturally mismatched with the Redis-backed Supervisor topology |
| Horizon | 🔴 NOT READY (by choice, not oversight) | `laravel/horizon` is absent from `composer.json`; the project uses plain `queue:work` processes under Supervisor instead. This is a legitimate operational choice at this scale, not a defect — flagged here only because the audit explicitly asks about it |
| Scheduler | 🔴 NOT READY | Same finding as Section 1 — no `withoutOverlapping()`/`onOneServer()` on any of the 6 scheduled jobs in `routes/console.php`, and no in-repo mechanism triggers `schedule:run` in production |

---

## 3. Security

| Area | Verdict | Strongest evidence |
|---|---|---|
| Secrets | 🟢 READY | `.env`, `.env.backup`, `.env.production` are all git-ignored and confirmed actually ignored (`git check-ignore -v`); no tracked file contains a real-looking secret; `config/services.php` sources every credential via `env()` with no hardcoded fallback values |
| Cookie settings | 🟡 PARTIALLY READY | `http_only` (default `true`) and `same_site` (default `lax`) are sane; `secure` (`config/session.php:172`) has no default and no `.env.example` entry — not forced true for production |
| HTTPS enforcement | 🔴 NOT READY | No `TrustProxies` middleware, no `URL::forceScheme`, and **no security-headers middleware of any kind** (no HSTS, X-Frame-Options, X-Content-Type-Options, or CSP) exist anywhere in the codebase — only two custom middleware classes exist at all (`EnsureCompanyMembership`, `HandleInertiaRequests`), neither addresses headers or scheme |
| CSRF | 🟢 READY | Default Laravel 11+/13.x behavior applies unmodified — no `validateCsrfTokens(except:)` override, no `withoutMiddleware` bypasses anywhere. The one unauthenticated POST route (`/api/analytics/webhooks/{provider}`) is correctly placed under the stateless `api.php` group rather than carved out of `web.php`'s CSRF protection |
| Rate limits | 🟡 PARTIALLY READY | Login, register, password-reset request, and password-reset completion are all throttled at `5,1`; onboarding integration submission is throttled at `3,1`. **Not throttled at all:** onboarding company creation, onboarding Marketing Presence submission, and — most notably — the analytics webhook endpoint (`routes/api.php:12-13`), which also has **no authentication middleware whatsoever**, making it a fully public, unthrottled POST endpoint |
| Password reset | 🟡 PARTIALLY READY | The flow itself is solid and tested (6 tests in `tests/Feature/Auth/PasswordResetTest.php`, including anti-enumeration): request → email → reset → login all work correctly. Gap: `PasswordResetController::update()` never calls `Auth::logoutOtherDevices()` or invalidates other sessions — a stolen session on a victim's account survives a password reset it should have killed. No test covers this gap |
| Tenant isolation | 🔴 NOT READY as designed (mitigated in practice) | See Headline Finding above. `CompanyScope::apply()` (`app/Domain/Shared/Scopes/CompanyScope.php:20`) is a no-op unless `current_company_id` is bound in the container; that binding occurs **only** in three test files (`MarketingChannelTenantIsolationTest.php`, `MarketingPresenceServiceTest.php`, `Discovery/TenantIsolationTest.php`), never in `app/`. Isolation today holds because every controller inspected (Dashboard, Opportunity, Recommendation, Campaign, Settings, MarketingPresence) manually filters by `company_id` on every query — over 80 call sites across the codebase already use `withoutGlobalScopes()` explicitly, meaning the team already implicitly treats the scope as unreliable |
| Authorization review | 🟡 PARTIALLY READY | `RecommendationController::approve()/approveEdit()/reject()` correctly enforce an owner/admin role check via `requireApprovalRole()` (lines 213-227). **No other mutating endpoint does**: `SettingsController::update()` (renames the company), `SettingsController::syncIntegration()` (dispatches a real crawl+AI-spend job), and all three `MarketingPresenceController` mutations (`store`, `update`, `destroy`) check only that the resource belongs to the acting company — any authenticated member, regardless of role, can perform all of these. No `app/Policies` directory exists; authorization is entirely ad hoc `abort_if`/`abort_unless` calls, not centralized Policy classes |

---

## 4. Operational Risks

| Area | Verdict | Strongest evidence |
|---|---|---|
| Single points of failure | 🔴 NOT READY | Exactly one production AI vendor (`AnthropicProvider` — the only non-test, non-local-stub implementation under `app/AI/Providers/`), no database read replica configuration in `config/database.php` (no `read`/`write` keys on any connection), and while five named Redis queues provide workload isolation, they all share one underlying Redis connection with no active failover path (`config/queue.php`'s `failover` connection exists but nothing routes to it by default) |
| AI provider resilience | 🟡 PARTIALLY READY | `AnthropicProvider` has real engineering behind it: a bounded retry loop (3 retries, `[500, 1500, 3000]`ms backoff) specifically for `overloaded_error`/HTTP 529 responses, explicit 120s/10s timeouts, and no swallowed exceptions — non-overloaded failures are logged and rethrown as `RuntimeException`. Consuming jobs (`ProcessObservation`, `SyncIntegration`) declare `$tries`/`$backoff` and have overload-aware failure handling. What's missing: no circuit breaker, no explicit HTTP 429 handling, and this resilience protects against *transient* Anthropic issues, not a sustained outage or vendor-level failure — there is no second provider to fall back to |
| Queue recovery | 🟡 PARTIALLY READY | Most business-critical jobs declare sensible `$tries`/`$backoff` (`CommitDecision`: 3/60s, `GenerateContent`: 3/30s, `PrepareCampaign`: 3/60s, `ProcessObservation`: 3/[30,120]s, `SyncIntegration`: 3/60s, `PublishContent`: 4 tries) — but four jobs (`CheckChannelHealth`, `ProcessAnalyticsWebhookEvent`, `PruneRawMetrics`, `PublishScheduledContent`) have no `$tries`/`$backoff` at all and fall back to Laravel's untuned defaults. Failed jobs land in `failed_jobs` (per `config/queue.php`'s `'failed' => ['driver' => 'database-uuids', ...]`), but no application code references `failed_jobs`/`FailedJob` anywhere — no Filament resource, no recovery command, no automated alerting on a new failure |
| Database recovery | 🔴 NOT READY | No backup mechanism exists at all (Section 1) — the strongest form of database recovery, a tested restore, cannot exist without a backup to restore from. Every migration has a `down()` method and recent ones pair `up()`/`down()` correctly; one exception worth noting — `2026_07_05_000100_add_retrying_status_to_observations.php`'s `down()` performs a lossy data remap (`UPDATE observations SET status = 'failed' WHERE status = 'retrying'`) before restoring the prior constraint, meaning a rollback of that migration silently discards the distinction between "genuinely failed" and "was mid-retry." None of this matters yet without a working backup/restore story underneath it |
| Deployment rollback | 🔴 NOT READY | No deploy pipeline exists in the repo at all (Section 2) — `.github/workflows/ci.yml` is test-only. There is consequently no rollback *procedure* either, automated or documented, because there is no deploy mechanism to roll back from |

---

## 5. Prioritized Findings

### Critical Blockers (must resolve before any production deployment, including private beta)

1. **No production environment exists at all.** No server, no domain, no deploy pipeline. `.env.example` ships `APP_ENV=local`/`APP_DEBUG=true`, which the repo's own `composer.json` `setup` script would copy verbatim if a real production `.env` isn't separately and deliberately provisioned.
2. **Tenant isolation has no structural safety net.** `CompanyScope` never activates in production; every tenant-scoped query is safe only because every current code path remembers to filter manually. This should be fixed at the root (bind `current_company_id` from `EnsureCompanyMembership`, or formally document and test that manual filtering is the *sole, intentional* isolation strategy) before any real customer data exists in the system — whichever direction is chosen should be a deliberate decision, not an accidental gap.
3. **No backups, therefore no tested restore.** A single bad migration or operational mistake is unrecoverable today. This was already flagged in June and remains completely unaddressed in the repository.
4. **No real error tracking.** Production errors are only visible in a log file nobody is automatically alerted to. Combined with no monitoring/APM, an outage would be discovered by a customer, not by the team.
5. **No real transactional email.** `MAIL_MAILER=log` by default, no credentials configured for any real provider, and zero application code calls `Mail::` at all — password reset (the one flow that most needs email) would silently do nothing in production as shipped.
6. **The analytics webhook endpoint is public and unthrottled.** `POST /api/analytics/webhooks/{provider}` has no authentication middleware and no rate limit — it is reachable and spammable by anyone on the internet today.
7. **No production trigger for the scheduler.** Six scheduled jobs — including the recurring integration sync that is the mechanism for Atlas's entire "knows more tomorrow" promise, and opportunity expiration — have no cron/systemd entry anywhere in the repo to actually run `schedule:run` in production.
8. **No SSL/HTTPS enforcement or security headers.** No `TrustProxies`, no forced HTTPS scheme, no HSTS/X-Frame-Options/CSP middleware anywhere.

### High Priority (should resolve before inviting the first 10 customers, per Private-Beta-Execution.md's Go/No-Go gate)

1. **Authorization gaps on non-approval mutations.** Any company member, regardless of role, can rename the company, trigger a real crawl+AI-spend job (`SettingsController::syncIntegration`), and create/edit/disable declared marketing channels. Only the approval workflow enforces owner/admin.
2. **Session cookie security is not explicitly forced.** `SESSION_SECURE_COOKIE` has no value anywhere in `.env.example`; production deployment should set this explicitly rather than rely on auto-detection, especially given the missing `TrustProxies` configuration that would make that auto-detection unreliable behind a proxy.
3. **Password reset doesn't invalidate other sessions.** A session hijacked before a password reset survives the reset.
4. **Log rotation is not active by default.** The default `single` log channel (and the custom `publishing` channel) grow unbounded; the `daily` channel with 14-day retention exists but isn't the active default.
5. **No Redis authentication configured by default**, and the queue and session share a Redis logical database with no dedicated separation from each other (cache is separated; queue/session are not).
6. **File storage defaults to local disk** with no object storage populated — a problem the moment the app runs on more than one instance, or an instance is replaced.
7. **Single AI provider, no fallback.** Retry/backoff logic is solid for transient issues; a sustained Anthropic outage still halts the entire pipeline for every company simultaneously.
8. **No deploy automation of any kind** — not even the basic Laravel `config:cache`/`route:cache` optimization steps, let alone a documented or automated rollback procedure.

### Nice-to-Have Improvements (worth doing, not blocking)

1. Add Horizon (or equivalent queue dashboard) once queue volume justifies it beyond what raw `failed_jobs` inspection and Supervisor logs provide.
2. Clean up the stale `publishing`/`analytics` queue name references in `composer.json`'s dev script and the `.env.example` comment — no code dispatches to these queues today, so the reference is misleading.
3. Add `withoutOverlapping()`/`onOneServer()` to the scheduled jobs in `routes/console.php` — currently harmless on a single-server deployment, but worth hardening before any horizontal scaling.
4. Introduce Laravel `Policy` classes for authorization instead of continuing to hand-roll `abort_if`/`abort_unless` checks per controller — not urgent, but the current pattern will get harder to audit as more mutating endpoints are added.
5. Add a `retryUntil()` ceiling to long-running jobs alongside the existing `$tries`/`$backoff`, for defense against a job that keeps being legitimately retryable but never actually succeeds.
6. Formalize log level via env var for the `publishing` channel (currently hardcoded to `debug`) so it can be turned down in production without a code change.

---

## What This Audit Confirms Is Already Solid

Not everything above is a gap — several things checked out cleanly and are worth stating plainly so they aren't re-litigated later:

- **CSRF protection** is correctly configured by default, with no bypasses anywhere, including the one legitimately-unauthenticated webhook route being correctly placed in the stateless API group.
- **Secrets management** is clean: nothing real is tracked in git, and every credential in the codebase is sourced from `env()`.
- **The health/readiness endpoints are real**, not decorative — `HealthController::ready()` genuinely checks database, cache, and queue connectivity, which is more than many pre-launch products have.
- **`BusinessBrainService`'s decision to avoid the Cache facade is a documented, considered engineering call**, not an oversight — it exists because of a real prior production incident, and the reasoning is sound.
- **AI provider retry/backoff engineering is genuinely good** for what it covers (transient overload) — the gap is the absence of a second vendor, not the quality of the single-vendor handling.
- **The password reset flow's core logic and anti-enumeration protection are correct and well-tested** — the one gap (session invalidation) is narrow and specific, not indicative of a broader problem with the flow.
- **Supervisor configuration exists, is real, and matches the actual queue topology** the application code dispatches to (aside from the two stale, unused queue-name references noted above).

---

*This audit should be re-run (or at minimum, re-verified item by item) once a production environment is actually provisioned, since several findings here (SSL, backups, monitoring) are things this repository cannot itself prove or disprove — they depend on infrastructure that doesn't exist yet. The tenant isolation finding is the one exception: it is a pure code-level fact that will remain true regardless of what infrastructure is provisioned, and should be resolved or explicitly, deliberately accepted before it is forgotten.*
