# Beta Readiness Audit

**Reviewer:** Engineering (CTO-style operational audit)
**Date:** 2026-06-27
**Scope:** Full operational review against beta readiness criteria — first 10 paying customers
**Method:** Source code review, documentation review, architecture assessment
**Artifacts reviewed:** CLAUDE.md, FOUNDING_PRINCIPLES.md, ROADMAP.md, docs/STATUS.md, docs/design/System.md, docs/product/*, docs/technical/*, specs/core/*, docs/reviews/*, backend source tree

---

## What This Audit Is Not

This is not an enterprise readiness audit. It is not a SOC 2 gap analysis. It does not evaluate scaling to thousands of customers. It evaluates one specific question:

> **Can Atlas safely onboard 10 paying private beta customers without losing their data, exposing their information to other companies, or delivering an unacceptably broken experience?**

---

## Executive Summary

The Atlas backend pipeline is architecturally sound. The domain model, AI abstraction, decision engine, approval workflow, and learning engine are all well-designed and thoroughly tested (579/581 tests passing, PHPStan level 8, 0 errors). The customer-facing frontend is polished and covers the full product loop.

However, the platform has **7 critical blockers** that prevent any paying customer from being onboarded safely. The most severe is that the multi-tenancy middleware binding does not exist — meaning a customer could, under certain conditions, see another company's data. Several others are infrastructure absences: no production server, no real email delivery, no monitoring, and no backups.

None of these blockers are architectural problems. They are provisioning and configuration work. A focused 3–4 week sprint resolves all critical blockers. The platform is not broken — it is not deployed.

**Beta Readiness Score: 31 / 100**
**Go / No-Go: NO-GO**

---

## Critical Blockers Summary

| # | Blocker | Area |
|---|---------|------|
| B1 | `ResolveCurrentCompany` middleware does not exist | Multi-tenancy |
| B2 | No production server provisioned | Deployment |
| B3 | Email delivery uses log driver only | Email |
| B4 | No error monitoring or alerting | Monitoring |
| B5 | No database backups configured | Backups |
| B6 | No domain configured (APP_URL is localhost) | Domain |
| B7 | No privacy policy or terms of service | Legal |

---

## Audit Findings

---

### 1. Product Readiness

**Severity:** Medium
**Blocks Beta?** No

**Description:**
The core Atlas loop (Observe → Understand → Decide → Recommend → Prepare → Approve → Execute → Measure → Learn) is fully implemented in the backend. The AI pipeline produces real output via `AnthropicProvider`. The customer dashboard covers every page in the product. The approval workflow is wired and tested.

Publishing is currently log-based only (`LogChannelPublisher`, `LogEmailProvider`). Content is approved and marked as executed, but nothing actually reaches an Instagram account or email list. Phases 6–8 of the ROADMAP exist in implementation, but real provider integrations (Meta OAuth, Postmark, Mailchimp) are not implemented.

**Business impact:**
The first beta customers will onboard, receive recommendations, and be able to approve them — but nothing will be published. The approval is real; the publishing is not. This is a significant gap in the product value proposition, but it can be framed honestly in beta communications ("You'll see exactly what Atlas wants to publish before we connect your channels") and is not a safety issue.

**Technical impact:**
`Execution` records are created in `queued` status and stay there. `LogChannelPublisher` fires and writes to `storage/logs/publishing.log`. No platform APIs are called. The loop feels complete to the user but produces no external output.

**Recommendation:**
Before beta launch, implement at minimum one real publishing channel (email via Postmark is the safest — no OAuth complexity, no content policy risk) so beta customers see real output. Frame social publishing as "coming soon" in the beta onboarding.

**Estimated effort:** 3–5 days (Postmark email only)

---

### 2. Customer Onboarding

**Severity:** Medium
**Blocks Beta?** No

**Description:**
The onboarding wizard (3-step: company details → website URL → confirmation) is implemented and tested. The status polling page provides live feedback during pipeline processing. The redirect to the first recommendation is wired. A timeout message appears after 5 minutes.

Gaps: No email verification. No onboarding email sent after completion. No error recovery path if the website crawl fails (user sees a spinner that times out). No way to reconnect a failed integration without contacting support.

**Business impact:**
A customer whose website crawl fails silently during onboarding has no recovery path. They will email or churn. At 10 customers, this is manageable with manual support, but the experience is poor.

**Technical impact:**
`Integration.status` transitions to `error` after 3 failed crawl attempts. The customer-facing UI shows the timeout message but does not explain that a crawl failed or how to fix it.

**Recommendation:**
Add a Filament view to manually re-trigger a sync for a given integration. Add a customer-visible "Your website connection had an issue" state on the Settings page with a "Retry" button.

**Estimated effort:** 1–2 days

---

### 3. Authentication

**Severity:** Medium
**Blocks Beta?** No

**Description:**
Authentication is implemented via Laravel Sanctum with session-based auth for the web UI. Login, registration, and logout all work. Sessions are stored in Redis. Password hashing uses bcrypt (12 rounds). The `auth` middleware gates all `/app/*` routes.

Gaps: No email verification enforcement. `email_verified_at` exists on the `users` table but is never checked or enforced. No password reset flow. No multi-factor authentication. No account lockout after failed attempts (rate limiting on auth routes is not configured).

**Business impact:**
Paying customers with weak passwords are not protected by MFA. No email verification means fake accounts can be created. Password reset is not available — a customer who forgets their password has no self-service path.

**Technical impact:**
A forgotten password requires a manual database intervention. No verification means `email_verified_at` is always null, which will matter if any future notification logic gates on it.

**Recommendation:**
Implement email verification enforcement and password reset before beta. These are core auth requirements, not nice-to-haves. Laravel's built-in `password.reset` and `verification.notice` flows take one day to implement.

**Estimated effort:** 1 day (password reset + email verification)

---

### 4. Authorization

**Severity:** High
**Blocks Beta?** No (mitigated by note below)

**Description:**
Role-based authorization is implemented. `CompanyMembership.role` carries `owner`, `admin`, `member`, `viewer`. The `RecommendationController` checks `requireApprovalRole()` before any mutation — only `owner` and `admin` can approve or reject. The Filament admin panel is gated behind `is_superadmin`. All these checks work correctly.

The gap: `CompanyMembershipPolicy` is checked in some controllers but not others. A `member`-role user can call `SettingsController::update()` (no role check present in the reviewed code). The policy enforcement is inconsistent.

**Business impact:**
A `member`-role employee could modify company settings they should not be able to change. This is unlikely to matter at 10 customers (most of whom will have a single owner) but is a correctness gap.

**Technical impact:**
No catastrophic exposure — company data isolation is enforced at the `CompanyScope` level, not at the policy level. The risk is within-company, not cross-company.

**Recommendation:**
Audit every App controller method for role checks. Every mutation should either (a) check `owner`/`admin` or (b) explicitly document why `member` is permitted.

**Estimated effort:** 1 day

---

### 5. Multi-Tenancy

**Severity:** ⚠️ CRITICAL
**Blocks Beta?** Yes

**Description:**
The multi-tenancy strategy uses `CompanyScope` as a global scope on all tenant models. This is well-designed and correctly implemented. However, the `CompanyScope` reads from a `CurrentCompany` resolver that **must be bound for each request** — and the middleware that does this binding does not exist.

From `backend/docs/technical/Tenancy.md`:
> "`ResolveCurrentCompany` middleware does not exist yet. `CurrentCompany` singleton binding is not wired in any route group."

The current `EnsureCompanyMembership` middleware resolves the company and sets it on `$request` as an attribute, which the controllers then cast with `/** @var Company $company */`. If `CompanyScope` relies on an application-level singleton rather than the request attribute, there may be a gap between what the controllers receive and what the scope enforces.

**Business impact:**
If two companies' requests are processed concurrently by the same queue worker, and `CompanyScope` reads from a singleton that is not reset per-request, Company A could see Company B's data. This is a critical data isolation failure. Even if the current implementation happens to work due to how the request attribute is used, the explicit acknowledgment in the technical documentation that the binding "is not wired" is a blocker.

**Technical impact:**
The `CompanyScope` implementation must be verified to read from the correct per-request binding, not a process-level singleton. If the scope is a no-op when no company is bound (documented as "safe in CLI/tests"), then requests without a resolved company would return unscoped results.

**Recommendation:**
1. Audit `CompanyScope` to confirm it reads from the request, not a process-level singleton
2. If singleton: implement `ResolveCurrentCompany` middleware immediately and wire it to all `/app/*` routes
3. Add a test that creates two companies, makes a request as Company A, and asserts that Company B's data is not returned
4. Add PostgreSQL RLS as defense-in-depth (can be deferred post-beta, but document the deferral)

**Estimated effort:** 2–3 days (middleware + isolation test)

---

### 6. Data Isolation

**Severity:** ⚠️ CRITICAL
**Blocks Beta?** Yes

**Description:**
Same root cause as Finding 5. Cross-company data isolation is enforced at the application layer (Eloquent global scopes), not at the database layer (PostgreSQL RLS is documented but not implemented).

All domain tables carry `company_id`. The `CompanyScope` adds `WHERE company_id = ?` to every query. Foreign key lookups that cross company boundaries (e.g., fetching a recommendation by ID) are tested in feature tests for 404 behavior.

However, the defense-in-depth RLS layer — which would catch scope failures at the database level — is acknowledged as not implemented. The scope is the only enforcement layer.

**Business impact:**
A scope misconfiguration or middleware ordering issue could expose one customer's data to another. For a platform that holds business intelligence about marketing strategy, inventory, and campaign performance, cross-company data leaks are catastrophic to trust.

**Technical impact:**
The existing feature tests do verify cross-company 404 behavior at the controller level. The gaps are: (a) no test at the service layer, (b) no test at the queue worker level (cross-company queries in background jobs), and (c) no database-level enforcement.

**Recommendation:**
1. Resolve Finding 5 (middleware binding) first
2. Add cross-company isolation tests at the service layer (not just controller 404 tests)
3. Implement PostgreSQL RLS on the five most sensitive tables (`recommendations`, `decisions`, `facts`, `knowledge_entries`, `approvals`) as a beta blocker; defer full RLS to post-beta

**Estimated effort:** 3 days (service-layer tests + partial RLS)

---

### 7. AI Provider Resilience

**Severity:** High
**Blocks Beta?** No

**Description:**
`AnthropicProvider` is implemented and functional. It uses tool-use for structured JSON output. Retry logic is handled at the job level (3 attempts with exponential backoff). Failed AI jobs go to the failed queue.

Gaps: No fallback to `OpenAiProvider` if Anthropic is down (the `OpenAiProvider` exists but is not wired into any fallback logic). No per-company cost controls or token budget. No AI spend monitoring. The 3-retry backoff is defined in job config but the actual backoff intervals are not confirmed in the supervisor configuration (which does not exist as a deployed file). AI call latency is not instrumented — slow responses degrade the pipeline silently.

**Business impact:**
An Anthropic outage halts every company's pipeline simultaneously. No recommendation is generated while the provider is unavailable. At 10 customers, this is manageable, but they deserve a communication that Atlas is aware and working on it.

**Technical impact:**
All AI calls are serialized through the `ai` queue with a single worker. No circuit breaker. No fallback. `AnthropicProvider` does not implement rate limiting — the per-minute token limit for the configured model is not enforced, so simultaneous jobs for 10 companies could exhaust the rate limit.

**Recommendation:**
1. Add rate limiting in `AnthropicProvider` (use Redis leaky bucket or Anthropic's retry-after header)
2. Define an alerting rule: if `failed_jobs` count on the `ai` queue exceeds 3, page the engineer
3. Wire `OpenAiProvider` as a secondary option via config (not automatic failover — just a manual switch)

**Estimated effort:** 2 days

---

### 8. Prompt Management

**Severity:** Low
**Blocks Beta?** No

**Description:**
Prompt versioning is well-implemented. Every `Prompt` class has a `version()` method. Every AI-produced record stores `prompt_name` and `prompt_version`. Fixture-based testing prevents real provider calls in tests. Prompt Blade views are version-controlled.

Minor gap: No tooling to compare approval rates by prompt version. The data is captured (`prompt_version` on `decisions` and `content_assets`) but no query or Filament view surfaces this comparison. It must be done via raw SQL.

**Business impact:**
Prompt changes cannot be evaluated for performance impact without manual analysis. This will matter once there are real approval/rejection signals to learn from.

**Technical impact:**
Low risk. The audit trail is complete; the reporting is manual.

**Recommendation:**
Defer prompt performance reporting to post-beta. The data is being captured; the tooling can be built once there is data to report on.

**Estimated effort:** N/A (deferred)

---

### 9. Queue Architecture

**Severity:** High
**Blocks Beta?** Yes (transitively — no production server means no workers)

**Description:**
Five queues are defined and correctly configured: `high`, `ai`, `default`, `observations`, `maintenance`. Job-to-queue assignments are correctly implemented. `ShouldBeUnique` is applied to jobs where re-queuing would be harmful (`DetectOpportunities`, `CommitDecision`).

The Supervisor configuration file referenced in Milestone 1 (`infrastructure/supervisor/atlas-worker.conf`) **does not exist** — the file is missing from the repository. The queue topology is defined in code but has no corresponding deployment artifact.

**Business impact:**
Without a Supervisor config, no queue workers will run in production. Every job dispatched goes to Redis and sits there indefinitely. The entire async pipeline is non-functional without workers.

**Technical impact:**
Queue worker configuration must be written, tested, and deployed before any production traffic. The config document (`docs/technical/Architecture.md`) specifies the exact worker groups needed.

**Recommendation:**
Write `infrastructure/supervisor/atlas-worker.conf` using the specification in `Architecture.md`. Deploy it to the production server. Add a health check that verifies at least one worker per queue is alive.

**Estimated effort:** 1 day (config + deployment)

---

### 10. Scheduler Configuration

**Severity:** High
**Blocks Beta?** Yes (transitively — no production server means no scheduler)

**Description:**
The Laravel task scheduler runs recurring jobs: `DetectOpportunities` per company, `CommitDecision`, `ExpireOpportunities`, `ApplyLearnings`, `PruneRawMetrics`, `CheckChannelHealth`, `PublishScheduledContent`. These jobs are defined and registered but require `php artisan schedule:run` executing every minute via a production cron entry.

No cron entry exists. No production server exists.

**Business impact:**
Without the scheduler, opportunities are never detected, decisions are never committed, learnings are never applied, and stale data is never pruned. The platform is operational only for actions triggered by user interaction (approval, rejection) — not for autonomous actions.

**Technical impact:**
The entire proactive pipeline depends on the scheduler. A missed cron setup is a silent failure — users see no recommendations and Atlas appears to do nothing.

**Recommendation:**
Add the cron entry (`* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`) as part of server provisioning. Verify with `php artisan schedule:list`. Consider Laravel Horizon for the queue dashboard — it visualizes queue depth and job throughput, which is essential for debugging the pipeline.

**Estimated effort:** 1 day (provisioning + verification)

---

### 11. Background Jobs

**Severity:** High
**Blocks Beta?** Partially (pipeline won't work without workers)

**Description:**
All background jobs are implemented and tested via `QUEUE_CONNECTION=sync` in the test suite. The end-to-end smoke test (`PipelineSmokeTest`) verifies the full pipeline from `ObservationRecorded` to `Recommendation`.

Two systemic defects were fixed in Milestone 9.5: the `queue()` method conflict that silently prevented all jobs from dispatching, and the duplicate event listener issue. These fixes are in place. Tests confirm the pipeline runs correctly.

The gap is not code — it is deployment. Jobs run in tests. They have never run in a real Redis queue against a real PostgreSQL database under real concurrent load.

**Business impact:**
An untested job execution path at production concurrency could expose race conditions, lock contention, or unexpected failure modes that the sync-based test suite does not catch.

**Technical impact:**
The `ShouldBeUnique` implementation for `DetectOpportunities` and `CommitDecision` uses Redis-backed unique keys. These have never been tested with a real Redis instance under concurrent load. The Redis-dependent tests are the two that are explicitly skipped.

**Recommendation:**
Before beta, run a manual end-to-end test against the production (or staging) environment with a real company, real Redis, and real database. Verify each queue worker processes jobs and the full pipeline fires from observation to recommendation.

**Estimated effort:** 1–2 days (manual integration testing on provisioned server)

---

### 12. Failure Recovery

**Severity:** High
**Blocks Beta?** No

**Description:**
Failed jobs are written to the `failed_jobs` table. `Queue::failed()` makes them inspectable. `php artisan queue:retry` can replay them. Jobs use exponential backoff (60/300/900 seconds for AI jobs, immediate for publishing jobs).

Gap: No alerting on job failures. No automatic escalation. No maximum retry limit prevents a job from retrying indefinitely with bad data. No runbook describes what to do when a job fails for a specific company.

**Business impact:**
A failed AI job for Company A is invisible until someone checks the `failed_jobs` table. If no one checks for 48 hours, Company A receives no new recommendations. The business is paying for a service that stopped working.

**Technical impact:**
The pipeline is fragile to unhandled exceptions in Analysts or Services. `MalformedAiResponseException` causes a retry; `AiResponseValidationException` causes a fail — but neither sends an alert.

**Recommendation:**
Wire `Queue::failing()` to send an alert (email, Slack, or PagerDuty). At beta scale (10 companies), a simple email to `admin@yourdomain.com` is sufficient. Add `php artisan queue:failed` to a daily manual check runbook.

**Estimated effort:** 2 hours

---

### 13. Logging

**Severity:** Medium
**Blocks Beta?** No

**Description:**
Laravel's default stack logger is configured. A separate `publishing` log channel writes to `storage/logs/publishing.log`. PHPStan-verified logging with named exceptions provides readable log output.

Gaps: Logs are local to the server filesystem. No log aggregation (no Papertrail, no Logtail, no CloudWatch). No structured logging (logs are plain text, not JSON). No log retention policy. No alerting on `ERROR` or `CRITICAL` log entries.

**Business impact:**
When something breaks for a customer at 3am, debugging requires SSH access to the server and reading raw log files. At 10 customers, this is manageable. As the team grows, it becomes untenable.

**Technical impact:**
Log files will grow unbounded without rotation. Laravel's built-in log rotation (daily or size-based) needs to be configured. Without structured logging, querying logs for a specific company's failure requires grep.

**Recommendation:**
Configure log rotation immediately. For beta, a simple daily channel with 14-day retention is sufficient. Plan to add Flare (Laravel-native) or Sentry after the first week of operation.

**Estimated effort:** 2 hours (log rotation config)

---

### 14. Monitoring

**Severity:** ⚠️ CRITICAL
**Blocks Beta?** Yes

**Description:**
There is no monitoring. No uptime monitoring, no application performance monitoring, no queue depth visibility, no error rate tracking, no dashboard showing the health of any system.

The three health endpoints (`/health`, `/health/live`, `/health/ready`) exist and return meaningful status codes. This is the only observability tool in place.

**Business impact:**
If the server goes down, Atlas is unaware. If the queue backs up with 500 failed jobs, Atlas is unaware. If the database runs out of disk, Atlas is unaware. A paying customer contacts support; the engineering team discovers the outage from the customer, not from a monitor.

**Technical impact:**
The health endpoints cannot be used without a monitoring tool polling them. A dead server means the health endpoint also returns nothing, which is indistinguishable from "no monitoring" without an external check.

**Recommendation:**
Before onboarding any paying customer:
1. Set up UptimeRobot (free) or BetterUptime to ping `/health/live` every minute
2. Configure an alert to a phone number or email when the check fails
3. Add Laravel Pulse for queue depth and job failure visibility
4. This is the minimum viable monitoring stack — it can be done in half a day

**Estimated effort:** 4 hours

---

### 15. Health Endpoints

**Severity:** Low
**Blocks Beta?** No

**Description:**
Three health endpoints are implemented (`GET /health`, `GET /health/live`, `GET /health/ready`). They test database and cache connectivity. The liveness probe always returns 200. The readiness probe returns 503 if either DB or cache is unreachable.

The endpoints are tested with 8 feature tests. They are registered without auth middleware (correct — load balancers and monitors need access without tokens).

**Business impact:**
Good. These enable external monitoring tools and Kubernetes readiness checks when infrastructure is provisioned.

**Recommendation:**
No action required. Wire these to the monitoring tool from Finding 14.

---

### 16. Security

**Severity:** High
**Blocks Beta?** No

**Description:**
Several security controls are in place: SSRF protection on `WebPageCrawler`, Filament superadmin gate, CSRF protection via Inertia (automatic), session-based auth, bcrypt password hashing, `encrypted` cast on `Integration.config` and `Channel.config`.

Gaps:
- No rate limiting on any route (login, registration, API endpoints)
- No HTTP security headers (Content-Security-Policy, X-Frame-Options, etc.)
- No `APP_DEBUG=false` enforcement for production (`.env.example` has `APP_DEBUG=true`)
- No account lockout after failed login attempts
- `Integration.config` JSON contains website URLs and potentially future API credentials — while the `encrypted` cast protects the column, there is no key rotation mechanism

**Business impact:**
An attacker can brute-force the login form without any lockout. A misconfigured production `.env` with `APP_DEBUG=true` exposes stack traces to users, which can reveal file paths, database structure, and env values.

**Technical impact:**
Laravel's `APP_DEBUG=true` in production is a well-known mistake that leaks information. Rate limiting on auth routes requires two lines of middleware config.

**Recommendation:**
1. Set `APP_DEBUG=false` in production `.env` — enforce this in the deployment checklist
2. Add rate limiting to `POST /login` and `POST /register` (Laravel's `throttle` middleware)
3. Add security headers via middleware (Laravel's default `TrustProxies` + a custom `SecurityHeaders` middleware)
4. These are low-effort, high-impact changes

**Estimated effort:** 1 day

---

### 17. SSRF Protection

**Severity:** Low
**Blocks Beta?** No

**Description:**
`SsrfValidator` is implemented and blocks 14 CIDR ranges, hardcoded hostnames, and DNS-resolved private IPs. DNS TOCTOU protection is in place (all returned A/AAAA records must pass). 13 test cases cover the implementation.

This was a critical blocker that was resolved in Milestone 9.5. The implementation is solid.

**Recommendation:**
No action required. Verify that `SsrfValidator::validate()` is called on every outbound URL — not just crawl URLs, but also any future webhook or feed URLs.

---

### 18. Secrets Management

**Severity:** High
**Blocks Beta?** No

**Description:**
Secrets are managed via `.env` files. The application key, database credentials, Redis password, and `ANTHROPIC_API_KEY` are all stored in the `.env` file on the server. `.env` is gitignored. `.env.example` contains all required keys without values.

Gaps: No key rotation mechanism. No secrets manager (AWS Secrets Manager, HashiCorp Vault). No audit log of secret access. `config()` caching (`php artisan config:cache`) is required in production but not documented in the deployment process.

**Business impact:**
If the server is compromised, all secrets in `.env` are exposed. If the Anthropic API key is leaked, it can be used to run AI calls at the company's expense until rotated. There is no automated detection of secret usage anomalies.

**Technical impact:**
For 10 beta customers, `.env` file secrets are an acceptable tradeoff. Proper secrets management (Vault, AWS Secrets Manager) adds infrastructure complexity not justified at this scale.

**Recommendation:**
1. Document that `ANTHROPIC_API_KEY` must be rotated immediately if the server is compromised
2. Restrict server SSH access to named keys only (no password auth)
3. Enable Laravel's `config:cache` in production deployment
4. Defer a proper secrets manager to when the team grows beyond 2 engineers

**Estimated effort:** 2 hours (documentation + deployment procedure)

---

### 19. Backups

**Severity:** ⚠️ CRITICAL
**Blocks Beta?** Yes

**Description:**
`docs/technical/Database.md` defines a comprehensive backup strategy:
- PostgreSQL WAL archiving + daily base backup (RPO < 5 minutes)
- S3 versioning for object storage
- Redis AOF persistence

Every item in this strategy is a bullet point on a checklist marked unchecked:
> `[ ] WAL archiving configured and verified`
> `[ ] Automated daily base backup with 30-day retention`
> `[ ] Backup restoration tested monthly in staging`
> `[ ] Redis AOF enabled`

None of these are implemented. There is no backup of any Atlas data.

**Business impact:**
If the production database is corrupted, lost, or accidentally dropped, all customer data is gone. Permanently. For a platform that promises to learn about a business over time, losing a customer's Business Brain is catastrophic to trust and potentially legally actionable.

**Technical impact:**
A single `DROP TABLE` or a botched migration rollback with no backup is a company-ending event at early stage. The risk is not hypothetical — migrations run on every deploy.

**Recommendation:**
Before onboarding the first paying customer:
1. Enable PostgreSQL WAL archiving (use Barman, or a managed PostgreSQL service that handles this automatically — DigitalOcean Managed PostgreSQL does this out of the box)
2. Test a backup restore to a fresh database
3. Document the restore procedure
4. A managed database (DigitalOcean Managed PostgreSQL, AWS RDS) handles points 1–3 automatically and is strongly recommended over a self-managed PostgreSQL instance

**Estimated effort:** 1–2 days (managed DB provisioning + restore test)

---

### 20. Disaster Recovery

**Severity:** High
**Blocks Beta?** No (but contingent on Finding 19)

**Description:**
No disaster recovery plan exists. There is no documented procedure for "the server is gone" or "the database is corrupted." The Database.md recovery targets (RTO < 2 hours for full database loss) are aspirational, not verified.

**Business impact:**
Without a tested recovery procedure, RTO under pressure is unpredictable. What takes 2 hours in a planned drill may take 8 hours under a 2am emergency with poor documentation.

**Technical impact:**
The recovery procedure depends on (a) backups existing (Finding 19), (b) a fresh server being provisionable from a script, and (c) DNS cutover being documented. None of these currently exist.

**Recommendation:**
After backups are implemented (Finding 19), write a one-page disaster recovery runbook. At minimum: "How to restore the database from backup, reprovision a server, and redirect DNS." Test it once before the first customer.

**Estimated effort:** 1 day (runbook only, after backups are in place)

---

### 21. Database Migrations

**Severity:** Low
**Blocks Beta?** No

**Description:**
All migrations are written, numbered sequentially, and run as part of the CI pipeline. PHPStan verifies that model properties match migration columns. The CI workflow runs `php artisan migrate --force` before tests.

The migration set covers all entities in the domain model. Timestamps, ULID PKs, foreign keys, and compound indexes are all correctly specified.

Minor gap: No migration test against a production database — CI runs against PostgreSQL 16, which is the target version. No staging environment means migrations have never been tested against a non-CI PostgreSQL instance.

**Recommendation:**
Run migrations against the staging server (once provisioned) before production. Add `php artisan migrate:status` to the deployment checklist.

---

### 22. Deployment

**Severity:** ⚠️ CRITICAL
**Blocks Beta?** Yes

**Description:**
No production environment exists. `APP_URL` is `http://localhost:8000`. No domain is registered or pointed to a server. No Forge, no Vapor, no bare VPS, no container. Nothing.

The Version 0.2 Roadmap documents M11 (Production Infrastructure) as the first planned milestone, covering Laravel Forge + DigitalOcean. This work has not started.

**Business impact:**
There is no Atlas for customers to use. The platform is entirely local-development-only. Onboarding a paying customer is not possible until a server exists.

**Technical impact:**
Provisioning a production server requires: domain purchase, DNS configuration, server provisioning, PHP/Nginx configuration, PostgreSQL setup, Redis setup, SSL certificate, queue workers, scheduler cron, Supervisor, and object storage. This is 2–5 days of focused work for a team familiar with the tools.

**Recommendation:**
Provision using Laravel Forge + DigitalOcean or DigitalOcean App Platform. Forge handles Nginx/PHP/SSL automatically and connects to a managed PostgreSQL. This is the recommended path for a Laravel application at this stage. Do not self-manage PostgreSQL — use DigitalOcean Managed Database.

**Estimated effort:** 3–5 days (server provisioning + configuration)

---

### 23. CI/CD

**Severity:** Medium
**Blocks Beta?** No

**Description:**
A GitHub Actions CI workflow (`ci.yml`) runs Pint, PHPStan, and PHPUnit on every push to `main` and `develop`. It provisions PostgreSQL 16 and Redis 7. Migrations run before tests. Tests run in parallel.

The workflow is well-designed. However, per STATUS.md: "CI/CD: Defined. GitHub Actions workflow written; not yet triggered (no PR opened against remote)." The CI has never actually run.

No CD (continuous deployment) pipeline exists. There is no automated deploy on merge to main. Deployment is manual.

**Business impact:**
A CI pipeline that has never run may have environment issues that would only surface when it first triggers. A broken CI is psychologically worse than no CI — it trains the team to ignore failures.

**Technical impact:**
The first CI run will verify the workflow correctness. Until it runs, it is an untested assumption.

**Recommendation:**
1. Push a commit or open a PR to trigger CI at least once before beta
2. After production is provisioned, add a simple deploy step (Forge webhook or SSH deploy) to CD the tested build on merge to main

**Estimated effort:** 4 hours (first CI run + deploy hook)

---

### 24. Test Coverage

**Severity:** Medium
**Blocks Beta?** No

**Description:**
579/581 tests passing (2 Redis-dependent tests skipped). PHPStan level 8 — 0 errors. Coverage spans: unit tests for all domain services, feature tests for all app controllers, AI analyst tests with fixture-based responses, end-to-end smoke test, health endpoint tests, and publishing pipeline tests.

Gaps: No frontend tests (Vitest was planned but deferred in Milestone 10). No integration tests against a real Redis instance. No load tests. No performance regression tests.

**Business impact:**
Front-end-only defects (Vue rendering issues, TypeScript type mismatches at runtime) are not caught by the test suite. A bug that only manifests with a real Redis queue (race conditions in unique jobs) is not caught.

**Technical impact:**
The 579 tests provide strong confidence in the backend. The 0 frontend tests mean UI regressions are caught only by manual review.

**Recommendation:**
For beta, the current test coverage is acceptable. Add a basic Vitest suite (5–10 component tests) after the first beta week, informed by real user bugs.

---

### 25. Performance

**Severity:** High
**Blocks Beta?** No

**Description:**
`BusinessBrainService::for(Company)` assembles a fresh Business Brain on every call — querying facts, knowledge entries, observations, catalog items, and recent campaigns. The spec requires a 5-minute Redis TTL per company. This cache has not been implemented.

At 10 companies, every recommendation page load triggers this assembly. On an uncached query, this is probably 5–8 database queries. At 10 companies, this is not a performance problem. At 100, it may be.

The `EvidenceEvaluator` in the Learning Engine loads all Learning records for a company+signal into PHP, then filters by discriminator. This is correct for test compatibility but inefficient for companies with thousands of Learnings.

**Business impact:**
For 10 beta customers, performance is acceptable without the cache. The risk is that sluggish response times (especially if AWS/DigitalOcean latency is high) damage first impressions.

**Technical impact:**
The cache implementation is straightforward: `Cache::remember("brain_{$company->id}", 300, fn () => ...)`. The `EvidenceEvaluator` fix requires a PostgreSQL JSON path query but can be deferred.

**Recommendation:**
Implement the `BusinessBrainService` Redis cache before beta. This is a documented technical debt item and 2 hours of work. The `EvidenceEvaluator` can be deferred to post-beta.

**Estimated effort:** 2 hours (BusinessBrain cache only)

---

### 26. Scalability

**Severity:** Low
**Blocks Beta?** No

**Description:**
A private beta with 10 customers does not require scalability planning. The queue topology supports horizontal scaling (adding more workers). Laravel's architecture supports read replicas. The single-tenant deployment model means database separation is possible in the future.

The composite score query (`ORDER BY composite_score DESC`) is indexed. The primary Business Brain query paths are indexed. No performance bottlenecks have been identified at low scale.

**Recommendation:**
No action required for beta. Revisit at 50+ active companies.

---

### 27. Caching

**Severity:** High
**Blocks Beta?** No

**Description:**
Redis is configured as the cache store, session store, and queue backend. Session caching works. Laravel's `Cache` facade is available.

The only documented caching requirement that is unimplemented is `BusinessBrainService` (see Finding 25). All other caching decisions are appropriate for the MVP scale.

**Recommendation:**
Implement BusinessBrainService cache. All other caching decisions are correct.

**Estimated effort:** 2 hours

---

### 28. Storage

**Severity:** High
**Blocks Beta?** No (but blocking for full pipeline)

**Description:**
Object storage is configured via Laravel's filesystem abstraction. The local disk is used in development. S3 or compatible storage is specified in `.env.example` (`AWS_BUCKET`, `AWS_DEFAULT_REGION`, etc.) but not provisioned.

`raw_payload_ref` on `Observation` stores an object storage path (S3 key). If S3 is not configured, observations will attempt to store raw payloads locally, which will fill the server disk over time. The retention job (`atlas:prune-payloads`) nulls `raw_payload` after 30 days but does not clean object storage objects without proper S3 configuration.

**Business impact:**
Disk-full events on the production server can bring down the application and corrupt the database. Large HTML observations (up to 5,000 characters per page × 20 pages per crawl) accumulate quickly.

**Technical impact:**
S3 provisioning is straightforward (create bucket, set IAM credentials, configure `.env`). DigitalOcean Spaces is S3-compatible and integrates with the DigitalOcean droplet.

**Recommendation:**
Provision S3 or DigitalOcean Spaces before beta and configure object storage in the production `.env`. Enable S3 lifecycle rules for 90-day expiry on raw payloads.

**Estimated effort:** 1 day

---

### 29. Email Delivery

**Severity:** ⚠️ CRITICAL
**Blocks Beta?** Yes

**Description:**
The mail driver is configured to `log` in `.env.example`. All emails — notifications, password resets, onboarding emails — are written to `storage/logs/laravel.log` and never delivered.

No transactional email provider is configured. Postmark is the documented target (`PostmarkEmailProvider` is on the M16 roadmap) but not implemented.

**Business impact:**
No email can be sent to any user. Password reset is impossible (the user receives nothing). If an email notification is added for new recommendations, it silently does nothing. A customer expecting a welcome email after signup receives nothing.

**Technical impact:**
Laravel's `Mail::to()->send()` simply logs output when the mail driver is `log`. Switching to a real provider requires: choosing a transactional provider (Postmark/Mailgun/SES), setting up an account, verifying the sending domain, and setting `MAIL_MAILER` in production.

**Recommendation:**
Configure Postmark or Mailgun before beta. Postmark has the best Laravel integration and excellent deliverability. Verifying a sending domain takes 1 hour. Configure and send a test email before any customer is onboarded.

**Estimated effort:** 4 hours (provider account + domain verification + Laravel config)

---

### 30. Domain Configuration

**Severity:** ⚠️ CRITICAL
**Blocks Beta?** Yes

**Description:**
`APP_URL` is `http://localhost:8000`. No domain is registered. No SSL certificate exists. No DNS records point to any server (because no server exists).

**Business impact:**
There is no way to access Atlas except on a developer's laptop. A beta customer cannot be given a URL to sign up.

**Technical impact:**
Requires: domain purchase (or use of existing domain), DNS configuration, server IP assignment, SSL certificate (Let's Encrypt via Certbot or Laravel Forge's automatic SSL). The Inertia.js session and CSRF protection require a real domain with proper `SESSION_DOMAIN` configuration.

**Recommendation:**
Register `getatlas.app` or similar. This is a $12–20/year expense. DNS + SSL + `APP_URL` configuration takes 2 hours once the server is provisioned.

**Estimated effort:** 2 hours (after server provisioning)

---

### 31. Analytics

**Severity:** Medium
**Blocks Beta?** No

**Description:**
The analytics pipeline is fully built: `ExecutionMetric`, `CampaignKpiSnapshot`, `MetricRetrievalLog`, `CampaignKpiService`, `RecommendationKpiService`, `DecisionEffectivenessService`. The `PostmarkWebhookHandler` is implemented.

The analytics providers are all fake (`FakeAnalyticsProvider`). No real metric ingestion exists. The customer-facing analytics pages display data, but the data only exists if it was manually seeded or if a previous `FakeAnalyticsProvider` call created it.

**Business impact:**
Beta customers with real campaigns (once email delivery is configured) will not see real analytics. The analytics pages will show empty states or whatever data was seeded.

**Technical impact:**
Real analytics requires: (a) a publishing provider that returns a real platform message ID, and (b) an analytics provider that retrieves metrics for that message ID. Without real publishing, real analytics are not possible.

**Recommendation:**
Analytics are not a beta blocker — real publishing must come first. When Postmark is configured, wire the `PostmarkWebhookHandler` to receive real delivery and open events.

---

### 32. Learning

**Severity:** Low
**Blocks Beta?** No

**Description:**
The Learning Engine is fully implemented. `Learning` records are created on every approval, rejection, edit, and execution result. `ApplyLearnings` is a scheduled job that processes unapplied learnings in tiered order. The rollback mechanism (compensating records only) is implemented. Safety invariants (company-scoped, immutable, downward adjustments require 2+ signals) are enforced.

The two documented spec/code drifts: `Learning.value` vs. spec's `payload`, and `LearningApplication` using `created_at` vs. spec's `applied_at`. These are documentation inconsistencies, not bugs.

**Recommendation:**
Reconcile the spec/code drift (update spec to match implementation) before beta. This is 30 minutes of documentation work.

---

### 33. Audit Trails

**Severity:** Low
**Blocks Beta?** No

**Description:**
The approval audit trail is complete and correct. Every `Approval` record stores `user_id`, `action`, `notes`, `edits`, and `acted_at`. Approvals are append-only. The user who approved or rejected every recommendation is permanently recorded.

Fact supersession (`is_current = false`, `superseded_by_id`) provides an audit trail of Business Brain evolution. `LearningApplication.effects` records every mutation made to the Business Brain.

**Recommendation:**
The audit trail is strong for the core loop. Add a simple `activity_logs` table or use a package like Spatie Laravel Activity Log once the team needs to audit user actions beyond approvals.

---

### 34. Customer Support

**Severity:** High
**Blocks Beta?** No

**Description:**
No customer support tooling exists. No in-app feedback mechanism. No help center. No support email address configured. No escalation path for a customer with a broken integration.

At 10 customers, support is entirely manual — a direct Slack or email thread between the founder and the customer. This is common and acceptable for early private beta.

**Business impact:**
A customer with a broken pipeline (failed crawl, no recommendations) has no self-service path. They must contact the founder directly. This is fine at 10 customers but creates a dependency on the founder's availability.

**Technical impact:**
The Filament admin panel allows manually re-triggering syncs, but only by an admin user. Customers cannot self-serve.

**Recommendation:**
Before beta, define a support channel (dedicated email, Slack workspace, or in-app form). Communicate it in the onboarding email. The M19 roadmap item covers a proper NPS + feedback tool — defer that; do not defer defining a support contact.

**Estimated effort:** 2 hours (setup email + update onboarding copy)

---

### 35. Admin Tooling

**Severity:** Low
**Blocks Beta?** No

**Description:**
Filament admin panel is implemented with resources for: Company, Opportunity, Decision, Campaign, ContentAsset, Recommendation, Execution. The superadmin gate (`is_superadmin = true`) is required for access. Approve/reject actions are available in the recommendation resource.

`RecommendationKpiService` metrics (approval rate, rejection rate, time-to-decision) are surfaced in a Filament widget. Campaign performance infolist shows expected vs. actual KPIs.

The admin panel is the primary operational tool for diagnosing issues and manually intervening.

**Recommendation:**
No critical gaps. Add a "Re-trigger sync" action to the Integration resource so support can manually kick off a crawl for a company without SSH access.

**Estimated effort:** 2 hours

---

### 36. Operational Runbooks

**Severity:** High
**Blocks Beta?** No

**Description:**
No operational runbooks exist. There is no documented procedure for common operational tasks: restarting queue workers, re-triggering a failed job, diagnosing a pipeline stall, recovering from a failed migration, rotating the Anthropic API key, or onboarding a new company.

**Business impact:**
At 2am when a customer reports no new recommendations and the founding engineer is on-call, there is no checklist to follow. Debugging is improvised, slow, and error-prone.

**Technical impact:**
Simple operational documentation prevents mistakes under pressure.

**Recommendation:**
Write a single "Operational Runbook" document before beta. It does not need to be comprehensive — a 1-page checklist for the five most likely failure scenarios is sufficient:
1. No recommendations for Company X → check `failed_jobs`, check scheduler, check opportunity_count
2. Queue worker is down → restart via Supervisor
3. AnthropicProvider rate limit hit → pause ai queue, retry after 1 hour
4. Failed migration → restore from backup, do not rollback in production
5. New company onboarded manually → trigger `SyncIntegration` from admin panel

**Estimated effort:** 1 day

---

### 37. Privacy

**Severity:** ⚠️ CRITICAL
**Blocks Beta?** Yes

**Description:**
No privacy policy exists. Atlas stores:
- Business owner personal information (name, email, password)
- Website content crawled from the business's public website
- Campaign content and approval decisions
- Analytics data

Some of this is sensitive. GDPR (if any EU customers are onboarded) requires a published privacy policy and a lawful basis for processing. CCPA (California) requires a privacy policy if any California residents are users.

**Business impact:**
Collecting personal data without a privacy policy is illegal in most jurisdictions. The first beta customers may include EU or California residents. Legal exposure begins the moment the first customer signs up.

**Technical impact:**
No data deletion mechanism exists (the right-to-erasure). No data portability mechanism exists (the right to export). Soft deletes on `companies` and `users` exist, but the cascade delete of all associated data is not implemented.

**Recommendation:**
Before any customer data is collected:
1. Publish a privacy policy (can be a simple, honest document — tools like Termly or Iubenda generate compliant drafts)
2. Add an "account deletion" path (can be "contact us to delete your account" for beta)
3. Evaluate whether any EU customers are expected (if yes, GDPR compliance is non-trivial)

**Estimated effort:** 1–2 days (policy + deletion contact path)

---

### 38. Legal

**Severity:** ⚠️ CRITICAL
**Blocks Beta?** Yes

**Description:**
No terms of service exist. No subscription agreement. No SLA. No acceptable use policy. No refund policy.

For a beta with 10 paying customers, the minimum required is a terms of service that customers agree to at signup.

**Business impact:**
Customers are paying for a service with no contractual agreement on either side. What happens if Atlas produces content that damages a customer's brand? What are the company's obligations if the service is unavailable? These questions are unanswered without a ToS.

**Recommendation:**
Use a legal tool (Termly, LegalZoom) to generate a Terms of Service and Privacy Policy. For beta, a simple statement that the service is in early access and provided as-is is sufficient, with a contact email for disputes. Have a lawyer review before public launch.

**Estimated effort:** 1 day

---

### 39. Documentation

**Severity:** Medium
**Blocks Beta?** No

**Description:**
Technical documentation is excellent. The domain model, architecture, AI abstraction, database strategy, and all specs are comprehensively documented. The codebase is well-documented by the specs themselves.

User-facing documentation is absent. No help center, no onboarding guide, no FAQ for customers, no API documentation (for future use).

**Business impact:**
Beta customers receive no guidance on how to interpret recommendations, what the confidence score means, or why they should trust Atlas's rationale. The product's own explainability (four rationale quadrants) is excellent, but meta-level guidance ("what is Atlas doing for my business?") does not exist.

**Recommendation:**
Write a single one-page "Getting Started" guide for beta customers. It should cover: what to do after onboarding, how to read a recommendation, what "Edit & Approve" does, and how to interpret the Business Brain page.

**Estimated effort:** 4 hours

---

### 40. Known Limitations

**Severity:** Medium
**Blocks Beta?** No

**Description:**
Known limitations are documented in `docs/STATUS.md` and various review documents. The technical team has full visibility. Customers are not informed of these limitations before or during onboarding.

Key limitations a beta customer should know:
1. Content is approved but currently sent to the publishing log, not to real channels
2. Analytics data requires real publishing to be meaningful
3. The Business Brain quality depends on how crawlable the business's website is
4. JavaScript-rendered pages are not fully supported by the current crawler
5. The learning engine improves recommendations over weeks, not days

**Recommendation:**
Include a "Beta Limitations" section in the customer-facing Getting Started guide. Honest disclosure of known limitations builds trust, especially with early adopters who expect rough edges.

---

## Beta Readiness Score

| Dimension | Score (0–10) | Notes |
|-----------|-------------|-------|
| Product loop completeness | 7 | End-to-end works; publishing is log-only |
| Authentication & authorization | 5 | Works; missing email verification, MFA, rate limiting |
| Multi-tenancy & data isolation | 2 | CompanyScope exists; middleware binding unverified/missing |
| AI provider resilience | 6 | Anthropic works; no fallback, no rate limiting |
| Infrastructure (server, domain, SSL) | 0 | Nothing provisioned |
| Queue & scheduler | 1 | Defined; no production runtime |
| Monitoring & alerting | 0 | Nothing |
| Backups & recovery | 0 | Nothing |
| CI/CD | 4 | CI written but never triggered; no CD |
| Test coverage | 8 | 579 tests, smoke test; no frontend tests |
| Security posture | 5 | SSRF fixed; several gaps remain |
| Email delivery | 0 | Log driver only |
| Privacy & legal | 0 | No policy, no ToS |
| Performance | 6 | Acceptable for 10 customers; BusinessBrain cache missing |
| Documentation | 4 | Technical docs excellent; user docs none |

**Weighted average: 31 / 100**

---

## Critical Blockers

In strict priority order — do these before anything else:

| Priority | Blocker | Estimated Effort |
|----------|---------|----------------|
| 1 | Provision production server (Finding 22) | 3–5 days |
| 2 | Configure domain + SSL (Finding 30) | 2 hours (after server) |
| 3 | Verify and implement `ResolveCurrentCompany` middleware (Findings 5, 6) | 2–3 days |
| 4 | Configure database backups (Finding 19) | 1–2 days |
| 5 | Configure transactional email (Finding 29) | 4 hours |
| 6 | Set up monitoring (Finding 14) | 4 hours |
| 7 | Publish privacy policy and ToS (Findings 37, 38) | 1–2 days |

**Total estimated effort for critical blockers: 3–4 weeks (with one engineer)**

---

## Recommended Order of Fixes

**Week 1 — Infrastructure:**
- Provision DigitalOcean droplet + Managed PostgreSQL
- Register domain, configure DNS, generate SSL via Forge
- Configure S3/Spaces object storage
- Deploy application (Nginx, PHP-FPM, Redis)
- Configure Supervisor + queue workers
- Set up cron for Laravel scheduler
- Configure transactional email (Postmark)
- Run CI pipeline at least once

**Week 2 — Security & Compliance:**
- Audit and implement `ResolveCurrentCompany` middleware
- Add cross-company isolation tests at service layer
- Implement email verification + password reset
- Add auth route rate limiting + security headers
- Set APP_DEBUG=false in production
- Write privacy policy + ToS

**Week 3 — Monitoring & Reliability:**
- Configure uptime monitoring (UptimeRobot)
- Add job failure alerting (email/Slack)
- Configure log rotation
- Run manual end-to-end pipeline test on staging
- Test database restore from backup
- Write operational runbook

**Week 4 — Polish:**
- Implement BusinessBrainService Redis cache
- Write customer Getting Started guide
- Add "Re-trigger sync" to Filament admin
- Add password reset flow
- Final smoke test with a real company onboarded

---

## Acceptable Technical Debt

The following items are known, documented, and acceptable to carry into beta without resolving:

| Item | Why acceptable |
|------|----------------|
| No PostgreSQL RLS | CompanyScope is the enforcement mechanism; RLS is defense-in-depth. At 10 customers, the risk is low. |
| FakeAnalyticsProvider only | Real analytics require real publishing; implement when Postmark is live. |
| No social media publishing | Frame as "coming soon" in beta communications. |
| EvidenceEvaluator PHP-side filtering | Performance issue at scale; not a concern at 10 companies. |
| No frontend unit tests | Backend feature tests cover the contract; frontend bugs found by manual QA. |
| Spec/code drift (Learning.value vs payload) | Documentation-only; no runtime impact. |
| ApplyLearnings on ai queue instead of maintenance | Low-risk misalignment; fix after beta. |
| No campaign lifecycle trail in UI | UX gap, not a safety issue; deferred as Tier 3. |
| BusinessBrainService uncached (partially) | Should be fixed in Week 4, but not a beta blocker. |
| No MFA | High-value feature post-beta; not expected by early adopters. |

---

## Go / No-Go Recommendation

**NO-GO.**

Atlas has a strong technical foundation. The domain model is correct, the AI pipeline is sound, the test suite is comprehensive, and the customer experience is thoughtfully designed. These are the difficult parts — and they are done.

What remains is the operational layer: a server, a domain, a backup strategy, a monitoring system, and a legal framework. These are not architectural problems. They are provisioning and compliance tasks that any production web application must complete before serving paying customers.

The specific combination of (1) unverified multi-tenancy middleware, (2) no production server, and (3) no legal framework makes it unsafe to onboard a paying customer today.

**Criteria to flip to GO:**
1. Production server live and accepting HTTPS traffic ✓ (when done)
2. `ResolveCurrentCompany` middleware implemented and cross-company isolation test passing ✓ (when done)
3. Database backups configured and restore tested ✓ (when done)
4. Transactional email delivering ✓ (when done)
5. Uptime monitoring alerting on failure ✓ (when done)
6. Privacy policy and ToS published at a public URL ✓ (when done)
7. End-to-end pipeline verified on the production server with a real test company ✓ (when done)

Meeting all seven criteria requires approximately 3–4 weeks of focused work. The platform is 3–4 weeks from a viable private beta.
