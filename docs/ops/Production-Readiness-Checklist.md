# Production Readiness Checklist

**Purpose:** the single, concise go/no-go checklist an operator runs before Customer 1 — and re-runs before every subsequent significant infrastructure or deploy-process change. This document does not re-explain *why* each item matters or *how* to perform the deeper procedures; it points at the documents that already do, and exists so no single item gets missed in the noise of the longer documents.

**This is not a substitute for:**
- [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md) — the detailed, step-by-step verification procedure and the canonical Go/No-Go gate (Section 4). If this checklist and that document ever disagree, that document wins; update this one to match.
- [Production-Topology.md](../deployment/Production-Topology.md) — the expected deployment shape (reverse proxy → app server → database/Redis/queue workers/scheduler) and exactly what an operator still has to provision.
- [Critical-Production-Blockers.md](../plans/Critical-Production-Blockers.md) — the 8-blocker execution plan; every "code-complete" claim below traces back to one of those 8 blockers.
- [Backup-and-Recovery.md](../operations/Backup-and-Recovery.md) — full backup/restore strategy and scripts.

**How to use this:** work top to bottom. Each row is one verifiable fact, checked by actually doing the verifying action — not by reading code that implies it should work. Fill in **Owner** with a real name (not "the team") and update **Status** as work proceeds. Do not mark a row ✅ until it is true of the *real* production environment, not local dev or a plan to do it later.

**Status legend:** ✅ done and verified in production · 🟡 in progress · ⬜ not started · 🚫 blocked (note the blocker in Notes)

---

## 1. Deployment

| Item | Owner | Status | Notes |
|---|---|---|---|
| Production server is provisioned and reachable (not a laptop, not local) | | ⬜ | Blocker 7 — genuinely unprovisioned as of this writing |
| Application is deployed and the deployed commit matches `main` | | ⬜ | |
| A **second**, independent deploy has been performed (proves the process is repeatable) | | ⬜ | One successful deploy proves nothing about the next one |
| `config:cache`, `route:cache`, and `event:cache` run as part of the deploy process | | ⬜ | Per Blocker 7's acceptance criteria |
| Server has enough disk headroom for ≥90 days of expected crawl/log growth at target customer volume | | ⬜ | Check today's actual usage, don't estimate |

## 2. Production environment / secrets

| Item | Owner | Status | Notes |
|---|---|---|---|
| Real, production-appropriate `.env` exists — **never** a copy of `.env.example` | | ⬜ | `.env.example` ships local-dev defaults (`APP_ENV=local`, `APP_DEBUG=true`) deliberately |
| `APP_ENV=production` | | ⬜ | |
| `APP_DEBUG=false` — verified by requesting a URL that throws and confirming no stack trace reaches the browser | | ⬜ | Don't just check the config value; provoke a real error |
| `APP_URL` matches the real domain, not `localhost` | | ⬜ | |
| Every credential in `.env.example` with a placeholder has a real value set (`POSTMARK_API_KEY`, `POSTMARK_MESSAGE_STREAM_ID`, `ERROR_TRACKING_DRIVER`/`_DSN`, DB/Redis credentials) | | ⬜ | Grep `.env.example` for every unset var before deploy, don't rely on memory |
| Secrets are stored in a real secrets manager or the hosting provider's env-var store — never committed to the repo | | ⬜ | |

## 3. Domain / SSL / proxy

| Item | Owner | Status | Notes |
|---|---|---|---|
| Registered domain resolves to the production server | | ⬜ | |
| DNS has fully propagated — verified from ≥2 independent networks | | ⬜ | Not just the office Wi-Fi |
| HTTPS active with a valid certificate — verified in a real browser, no cert warning | | ⬜ | |
| HTTP requests redirect to HTTPS | | ⬜ | Explicitly request the `http://` URL and confirm |
| Certificate auto-renewal configured — check the renewal mechanism's next scheduled run | | ⬜ | |
| `TRUSTED_PROXIES` is set to the real proxy's IP/CIDR (or `*` only if the proxy's own IP genuinely isn't fixed) | | ⬜ | Code-complete (`App\Services\Http\TrustedProxyResolver`, Blocker 7) — **fails closed** if unset: no proxy trusted, so HSTS/client-IP/rate-limiting will visibly misbehave until this is set. Verify it's actually set, don't assume |
| Security headers present on a real response (`Strict-Transport-Security` when secure, `X-Frame-Options`, `X-Content-Type-Options`, `Content-Security-Policy`) | | ⬜ | ✅ Code-complete (`SecurityHeaders` middleware, Blocker 3) — verify headers actually appear on the deployed site, don't just trust the middleware is registered |

## 4. Queue workers

| Item | Owner | Status | Notes |
|---|---|---|---|
| Workers run under process supervision, not a terminal `queue:work` that dies with the SSH session | | ⬜ | ✅ Code-complete: [`infrastructure/supervisor/atlas-worker.conf`](../../infrastructure/supervisor/atlas-worker.conf) is the artifact to install |
| All 5 real queues have a running worker: `high`, `ai`, `default`, `observations`, `maintenance` | | ⬜ | These are the actual queues jobs dispatch to (`PublishContent`→`high`, `ProcessObservation`→`ai`, `CreateRecommendation`→`default`, `RetrieveExecutionMetrics`→`observations`, `CheckChannelHealth`→`maintenance`) — verify against `app/Jobs/*.php`'s `onQueue()` calls if this list ever looks stale |
| A worker crash/restart has been tested — kill a worker process and confirm the supervisor actually restarts it | | ⬜ | |
| Current queue depth checked and not silently backing up | | ⬜ | |

## 5. Scheduler

| Item | Owner | Status | Notes |
|---|---|---|---|
| Cron entry installed and running `php artisan schedule:run` every minute | | ⬜ | ✅ Code-complete: [`infrastructure/cron/atlas-scheduler`](../../infrastructure/cron/atlas-scheduler) is the artifact to install — verify by the last-run timestamp of a scheduled command, not by reading `routes/console.php` |
| All 7 scheduled entries have `->withoutOverlapping()` (and `->onOneServer()` where not already `ShouldBeUnique`) | | ✅ | Code-complete, Blocker 4 — see [Scheduler-Operations.md](../deployment/Scheduler-Operations.md) (SCRUM-46) for the full task inventory and queue-dependency analysis |
| Recurring integration sync (`atlas:sync-due-integrations`) has fired at least once on schedule with the expected effect | | ⬜ | |
| Opportunity expiration has fired at least once on schedule | | ⬜ | |

## 6. Database

| Item | Owner | Status | Notes |
|---|---|---|---|
| Production PostgreSQL instance provisioned, separate from any local/dev database | | ⬜ | Blocker 7 |
| Migrations run against production; schema matches what `main` expects | | ⬜ | |
| Connection pooling / max-connections sized for the expected worker + web process count — verified, not assumed | | ⬜ | |
| A real test write and read against production has been performed and confirmed correct | | ⬜ | |
| Tenant isolation is proven, not assumed: two test companies onboarded side by side, neither sees the other's data anywhere (product or Filament) | | ⬜ | ✅ Code-complete (`current_company_id` container binding + `CompanyScope`, Blocker 1) — this row is the **live verification**, not the code fix |

## 7. Backups and restore drill

| Item | Owner | Status | Notes |
|---|---|---|---|
| Backup/verify/restore scripts exist and are tested | | ✅ | Code-complete: [`infrastructure/backup/atlas-db-backup.sh`](../../infrastructure/backup/atlas-db-backup.sh), `atlas-db-verify.sh`, `atlas-db-restore.sh` — see [Backup-and-Recovery.md](../operations/Backup-and-Recovery.md) |
| A local/scratch restore drill has round-tripped real data | | ✅ | `tests/Feature/Backup/BackupRestoreDrillTest.php` — runs on every test suite execution |
| Automated backups configured and **actually running** against the real production database | | ⬜ | Not done — no production database exists yet |
| At least one backup **restored** to a separate/scratch database and spot-checked for correctness | | ⬜ | "We have backups" and "we have restored from a backup" are different claims — only the second is acceptable before Customer 1 |
| Restore procedure is written down well enough a second person could follow it unassisted | | ✅ | [Backup-and-Recovery.md](../operations/Backup-and-Recovery.md) |
| Off-site backup storage provisioned | | ⬜ | |
| Weekly restore-drill cadence scheduled for the duration of the beta | | ⬜ | Per [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md) §3 |

## 8. Monitoring / error tracking

| Item | Owner | Status | Notes |
|---|---|---|---|
| `ErrorTracker` abstraction wired into exception handling | | ✅ | Code-complete: `App\ErrorTracking\Contracts\ErrorTracker` + `NullErrorTracker`, wired in `bootstrap/app.php`'s `withExceptions()` (Blocker 5) |
| A real error-tracking vendor (Sentry or equivalent) is installed and configured | | ⬜ | Not done — see Blocker 5's "what remains for production activation": `composer require sentry/sentry-laravel`, implement `SentryErrorTracker`, add the `match` arm, set `ERROR_TRACKING_DRIVER`/`_DSN` |
| A deliberately-thrown test exception in production appears in the error tracker within minutes | | ⬜ | |
| `failed_jobs` visibility and Retry/Discard recovery workflow | | ✅ | Code-complete: `FailedJobResource` Filament panel (`/admin/failed-jobs`), superadmin-gated |
| Uptime monitoring configured against a real health endpoint (`/api/health`, `/api/ready`, `/api/live`), polling ~every 60s | | ⬜ | |
| A test alert has been deliberately triggered and actually received | | ⬜ | |
| A named person (not "the team") owns responding to alerts | | ⬜ | |
| Someone checks the error tracker at least once daily during the beta | | ⬜ | Ongoing, per [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md) §3 |

## 9. Transactional email

| Item | Owner | Status | Notes |
|---|---|---|---|
| Postmark mailer fully wired in code (`config/mail.php`, `symfony/postmark-mailer` installed) | | ✅ | Code-complete, Blocker 6 |
| `ProductionMailerGuard` refuses delivery and logs critically if `APP_ENV=production` with `MAIL_MAILER=log`/`array` | | ✅ | Code-complete — verify it's not silently satisfied by an unset `MAIL_MAILER` falling through to `log` in real production |
| `POSTMARK_API_KEY` / `POSTMARK_MESSAGE_STREAM_ID` set to real values in production | | ⬜ | |
| A real test email sent and received in an actual inbox (not just "the API call returned 200") | | ⬜ | |
| Sending domain has SPF/DKIM configured — verify mail doesn't land in spam for at least one major provider | | ⬜ | |
| Password reset tested end-to-end in production: request → receive email → click link → set new password → log in | | ⬜ | |

## 10. Log retention

| Item | Owner | Status | Notes |
|---|---|---|---|
| Log rotation configured — a fixed retention window, not "grows forever until disk fills" | | ⬜ | |
| Retention window is long enough to debug an issue reported a few days late, short enough not to fill disk | | ⬜ | |
| The dedicated `storage/logs/publishing.log` channel is included in the rotation policy | | ⬜ | Easy to forget since it's a separate channel from the main app log |

## 11. Legal pages

| Item | Owner | Status | Notes |
|---|---|---|---|
| Privacy policy published at a real, reachable URL | | ⬜ | **No privacy policy page exists in this codebase today** — confirmed, not assumed |
| Terms of service published at a real, reachable URL | | ⬜ | **No terms-of-service page exists in this codebase today** — confirmed, not assumed |
| Registration flow requires agreement to both before account creation | | ⬜ | |
| Data deletion/export policy documented if promised publicly | | ⬜ | |

## 12. Support / runbook

| Item | Owner | Status | Notes |
|---|---|---|---|
| A support channel (email alias or Slack) is defined, documented, and someone is actually watching it | | ⬜ | |
| An operational runbook exists covering at least: crawl failure, AI provider outage, queue worker down, failed-job spike | | ⬜ | **No dedicated runbook document exists yet** — this is a real gap, not a link to fill in |
| 24-hour response SLA is defined and tracked | | ⬜ | Per [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md) §3/§5 |
| Beta communications ready: invite email and Getting Started guide, both stating plainly what is/isn't real yet (which channels genuinely send vs. simulate) | | ⬜ | Per the [Channel Capability Matrix](../product/Channel-Capability-Matrix.md) — never let this drift from current code truth |

## 13. Post-deploy verification

| Item | Owner | Status | Notes |
|---|---|---|---|
| Full onboarding run-through completed successfully on production by a real test account (real crawl, real recommendation, real approval) | | ⬜ | |
| Publishing reality re-verified against current code and matches customer-facing copy exactly | | ⬜ | Re-check [Channel-Publishing-Reality-Audit.md](../reviews/Channel-Publishing-Reality-Audit.md) and the [Channel Capability Matrix](../product/Channel-Capability-Matrix.md) at deploy time — a real publisher may have shipped since these were last verified |
| Multi-tenancy re-confirmed on the live deploy (not just in Section 6, on this specific build) | | ⬜ | |
| `/api/health`, `/api/ready`, `/api/live` all return healthy against the live deploy | | ⬜ | |
| A second team member has independently walked through this checklist and agrees on its Status column | | ⬜ | One person's checklist is not a verification |

---

## Go / No-Go

This document tracks readiness; it is not itself the gate. **The actual Go/No-Go decision is [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md) §4** — every item there must be checked before Customer 1 is invited, with no partial credit. Use this checklist to get there; use that document's gate to decide.

## Keeping this current

Re-run this checklist in full:
- Before Customer 1.
- Before any change to hosting provider, domain, or deploy process.
- Whenever a Critical Production Blocker's status changes in [Critical-Production-Blockers.md](../plans/Critical-Production-Blockers.md) — update the corresponding row's Status here in the same change, don't let the two documents drift.
- At minimum, monthly during the private beta, even with no known changes — infrastructure drifts silently (an expired cert, a disk filling up, a backup that quietly stopped running).
