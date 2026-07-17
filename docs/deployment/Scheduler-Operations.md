# Scheduler Operations Guide

**Jira:** SCRUM-46 (Sprint 1) — Document scheduler ownership and failure checks.
**Scope:** exactly what Atlas's scheduler runs, how each task actually depends on queue workers, and how an operator verifies and troubleshoots it in production. Built from reading `routes/console.php` and every scheduled job's `handle()` method directly, plus a live `php artisan schedule:list` run — not generic Laravel scheduler advice.
**Companion documents:** [Queue-Workers.md](Queue-Workers.md) (SCRUM-41 — the queue model this document assumes), [Deployment-Runbook.md](Deployment-Runbook.md) (SCRUM-47 §6 — the one-time cron install step), [Environment-Variables.md](Environment-Variables.md) (SCRUM-35), [Customer-1-Launch-Ownership.md](../ops/Customer-1-Launch-Ownership.md) (the contact roles §6 below assumes exist).

---

## 1. Every scheduled task, verified live

`php artisan schedule:list` output, run against current code:

```
*/15 * * * *  atlas:sync-due-integrations       Next Due: ...
0 * * * *     App\Jobs\ExpireOpportunities      Next Due: ...
*/5 * * * *   App\Jobs\PublishScheduledContent  Next Due: ...
*/30 * * * *  App\Jobs\CheckChannelHealth       Next Due: ...
0 0 1 * *     App\Jobs\PruneRawMetrics          Next Due: ...
0 2 * * *     App\Jobs\ApplyLearnings           Next Due: ...
0 7 * * 1     App\Jobs\SendFeedbackDigest       Next Due: ...
```

**Seven entries, confirmed by running the command — not seven counted by hand from `routes/console.php`** (an earlier draft of a companion doc miscounted this as six; fixed there, and cross-checked here as the source of truth).

One critical structural distinction `schedule:list` doesn't make explicit: only the first entry is `Schedule::command(...)` — it runs **synchronously in the scheduler's own process**. All six others are `Schedule::job(...)` — the scheduler process only **enqueues** them; a queue worker must actually run them. This distinction is the entire subject of §3 below.

| # | Task | Type | Cadence | Overlap protection |
|---|---|---|---|---|
| 1 | `atlas:sync-due-integrations` | `Schedule::command` (inline) | Every 15 min | `withoutOverlapping()` + `onOneServer()` |
| 2 | `ExpireOpportunities` | `Schedule::job` (queued) | Hourly | `withoutOverlapping()` + `onOneServer()` |
| 3 | `PublishScheduledContent` | `Schedule::job` (queued) | Every 5 min | `withoutOverlapping()` + `onOneServer()` |
| 4 | `CheckChannelHealth` | `Schedule::job` (queued) | Every 30 min | `withoutOverlapping()` + `onOneServer()` |
| 5 | `PruneRawMetrics` | `Schedule::job` (queued) | Monthly (1st, midnight) | `withoutOverlapping()` + `onOneServer()` |
| 6 | `ApplyLearnings` | `Schedule::job` (queued) | Daily, 02:00 | `withoutOverlapping()` only — `ShouldBeUnique` (once per company per day) makes `onOneServer()` redundant |
| 7 | `SendFeedbackDigest` | `Schedule::job` (queued) | Weekly, Mon 07:00 | `withoutOverlapping()` + `onOneServer()` |

## 2. What each task does

1. **`atlas:sync-due-integrations`** — the recurring half of the Observe → Understand loop. Queries every active `Integration` whose `next_run_at` has passed and dispatches `SyncIntegration` for each (deduplicated via `SyncIntegration`'s own `ShouldBeUnique`, so a still-running prior sync for the same integration is never double-dispatched). `SyncIntegration` sets `next_run_at = now()+24h` on success, settling each integration into a daily cadence; integrations in `error` status are skipped until a manual reconnect from Settings.
2. **`ExpireOpportunities`** — flips any `Opportunity` past its `expires_at` from `open` to `expired`. Necessary because the opportunity engine's own dedupe logic ignores expired rows — expiry is what allows the same opportunity type to be re-detected later.
3. **`PublishScheduledContent`** — finds `Execution` rows with `status='queued'` and a `scheduled_at` that has passed, and dispatches `PublishContent` (onto the `high` queue) for each. Immediate (non-scheduled) executions are dispatched directly by `PublishCampaign` and never touch this job.
4. **`CheckChannelHealth`** — pings every non-revoked `ChannelCredentials` row via its provider's real `ping()` (WordPress/Meta/Postmark), updates `status` to `active`/`error` based on the real result, and keeps the declared `MarketingChannel.supports_publishing` capability-truth flag in sync. On an active→error transition, notifies the company owner via `ChannelNeedsReauth` — see §3's second-order dependency note.
5. **`PruneRawMetrics`** — nulls out the `raw` JSON payload column on `ExecutionMetric` rows older than a year (storage hygiene; the normalized/summarized fields are untouched).
6. **`ApplyLearnings`** — runs `LearningEngine::apply()` for every company, wrapped in a per-company try/catch so one company's failure never blocks the rest.
7. **`SendFeedbackDigest`** — compiles the last 7 days of `Feedback` into an NPS distribution (promoters/passives/detractors) and up to 5 notable comments, and notifies every superadmin via `FeedbackDigestReady`. No-ops silently (by design) if there's no feedback or no superadmin recipients — **absence of the weekly email is not itself a failure signal**, see §5.

## 3. Queue dependency — all seven, not just the six that look queued

**Every one of the 7 scheduled tasks ultimately depends on a queue worker actually running, including the one that looks like it doesn't.**

- **Direct dependency (6 tasks):** `ExpireOpportunities`, `PublishScheduledContent`, `CheckChannelHealth`, `PruneRawMetrics`, `ApplyLearnings`, `SendFeedbackDigest` are all `Schedule::job(...)` — the scheduler process's only role is to enqueue them (onto `maintenance`, per [Queue-Workers.md](Queue-Workers.md)). If the `maintenance` queue worker is down, these jobs sit in the `jobs` table indefinitely; the scheduler tick itself still "succeeds" every time (enqueuing never fails just because no worker is consuming).
- **Indirect dependency (1 task, easy to miss):** `atlas:sync-due-integrations` runs synchronously in the scheduler process — but its entire body of work is `SyncIntegration::dispatch($integration)` for every due integration, onto the `observations` queue. **If the `observations` worker is down, this command will report `"Dispatched N due integration sync(s)."` every 15 minutes, look completely healthy in every log, and yet nothing about any company's Business Brain will ever actually update.** This is the single easiest scheduler failure mode to miss, precisely because the command that's actually scheduled never itself fails.

### Second-order queue dependencies (worth knowing, not obvious from `routes/console.php` alone)

- **`PublishScheduledContent`**, once it runs on `maintenance`, itself dispatches `PublishContent` onto **`high`** — a second queue in the chain. If `maintenance` is up but `high` is down, scheduled content will be correctly identified as due but never actually sent.
- **`CheckChannelHealth`**'s owner notification (`ChannelNeedsReauth`) and **`SendFeedbackDigest`**'s digest notification (`FeedbackDigestReady`) both implement `ShouldQueue` with no explicit `->onQueue()` call — confirmed by reading both classes directly — so they queue onto **`default`** (not `maintenance`). If `maintenance` is up but `default` is down, channel health checks and the weekly digest will both run their real logic (status flips correctly, digest data is computed correctly) but the actual notification email will queue and never send.
- **`atlas:sync-due-integrations`**'s downstream chain fans out further still: a successful `SyncIntegration` fires `ObservationRecorded`, whose listener (`DispatchObservationProcessing`, not itself queued — it runs inline as part of the `SyncIntegration` job) dispatches `ProcessObservation` onto **`ai`**. A full recurring sync therefore touches `observations` → `ai` → (eventually) `default` in sequence — this is the same Observe→Understand→Decide chain [Queue-Workers.md](Queue-Workers.md) describes for the AI pipeline generally, not something specific to the scheduler.

**Practical implication: a healthy-looking scheduler tells you almost nothing about whether Atlas's core loop is actually running.** §4/§5 below verify actual downstream effects, not just that `schedule:run` executed.

## 4. Verifying the scheduler is actually running in production

Layered, because each layer can be true while the one below it is false:

1. **Is cron installed and firing `schedule:run` at all?** `crontab -l` (or `cat /etc/cron.d/atlas-scheduler`) shows the entry from [`infrastructure/cron/atlas-scheduler`](../../infrastructure/cron/atlas-scheduler), pointed at the real deploy path. This only proves the *entry exists*, not that it's firing.
2. **Is `schedule:run` actually being invoked every minute?** There is no built-in "heartbeat" for this today — see §7. The practical proxy: watch `storage/logs/laravel.log` for a full minute and confirm no cron-related errors appear (a misconfigured PHP path or permissions issue in the crontab entry fails silently to any log Atlas itself writes, since the failure happens before Laravel ever boots — check the *system* cron log, e.g. `/var/log/syslog` or `journalctl -u cron`, for that specific failure mode).
3. **Are the scheduled *definitions* what you expect, on this deployed commit?** `php artisan schedule:list` — confirms `routes/console.php` parses and shows all 7 entries with sane "Next Due" times. Run this after every deploy per [Deployment-Runbook.md](Deployment-Runbook.md) §6.
4. **Is each task's actual effect happening?** This is the layer that matters — see the per-task checks in §5.

## 5. Failure checks and first-response troubleshooting

### Per-task effect verification

| Task | How to confirm it's genuinely working |
|---|---|
| `atlas:sync-due-integrations` | Pick a real `Integration` with `next_run_at` in the past before a tick; confirm `next_run_at` advanced to ~24h in the future afterward. **If it doesn't move, the failure is almost certainly in the `observations` queue worker, not this command** — see §3. |
| `ExpireOpportunities` | An `Opportunity` with `expires_at` in the past should flip to `status='expired'` within the hour. |
| `PublishScheduledContent` | An `Execution` with `status='queued'` and a past `scheduled_at` should transition (via `PublishContent` on `high`) within ~5–10 minutes. |
| `CheckChannelHealth` | `ChannelCredentials.last_used_at` should advance every 30 minutes for every non-revoked row; `status` should reflect the channel's real reachability. |
| `PruneRawMetrics` | Low-urgency — check once a month that old `ExecutionMetric.raw` values are actually nulled, not on every tick. |
| `ApplyLearnings` | Check `storage/logs/laravel.log` for `ApplyLearnings: failed for company` entries daily just after 02:00 — the per-company try/catch means a real failure never surfaces as a job failure, only as a log line (see the gap in §7). |
| `SendFeedbackDigest` | **A missing Monday email is not itself an alarm** — the job no-ops silently if there's no feedback or no superadmin recipients (§2). Confirm via `/admin/failed-jobs` that the job didn't actually fail, before assuming "no email" means "broken." |

### General troubleshooting flow

1. **Check `/admin/failed-jobs`** (the Filament `FailedJobResource`, per [Queue-Workers.md](Queue-Workers.md)) first, for any of the 6 job classes. A job that exhausted its retries lands here with the real exception.
2. **Check `supervisorctl status`** for all 5 `atlas-worker-*` groups — a scheduled job stuck in `jobs` with no corresponding failure in `failed_jobs` almost always means the relevant worker isn't running at all (nothing to fail, nothing consuming), not that the job itself errored.
3. **Check for a stuck `withoutOverlapping()`/`onOneServer()` mutex.** Both rely on a real cache-backed lock (`Illuminate\Console\Scheduling\CacheSchedulingMutex`, confirmed by reading its source) on the default cache store (`CACHE_STORE=redis`) — **self-expiring after 3600 seconds (1 hour), not indefinite.** A worker or scheduler process killed ungracefully (an OOM kill or `kill -9`, not `queue:restart`'s cooperative signal) can leave a lock held until that TTL passes, silently skipping every tick for that task in the meantime (up to 4 skipped ticks for a 15-minute task, 12 for a 5-minute one). Recovery, faster than waiting out the hour: `php artisan schedule:clear-cache` clears all scheduling mutex locks — safe to run any time, since it only clears the *lock*, not any actual in-flight work.
4. **Check `Integration.status`.** `atlas:sync-due-integrations` silently skips any integration in `error` status by design (§2) — a company stuck with a stale Business Brain might simply have a broken integration needing manual reconnect from Settings, not a scheduler problem at all.

## 6. Ownership and operational expectations for beta

Per [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md)'s existing daily-checklist cadence and [Customer-1-Launch-Ownership.md](../ops/Customer-1-Launch-Ownership.md)'s named-role convention — this section doesn't invent new roles, it applies the existing ones to scheduler-specific checks:

- **Daily** (folded into the existing daily health-check routine, not a separate ritual): a glance at `/admin/failed-jobs` for any of the 6 queued job classes, and a spot-check that `atlas:sync-due-integrations` is actually advancing `next_run_at` for at least one real company (§5) — the single highest-value check, since it's the one whose failure is otherwise invisible.
- **Weekly**: confirm `SendFeedbackDigest` actually ran (not just "an email arrived" — check `/admin/failed-jobs` didn't silently swallow a real failure the same week feedback happened to be empty).
- **Named owner**: the same person who owns queue-worker/error-tracking alerts per [Customer-1-Launch-Ownership.md](../ops/Customer-1-Launch-Ownership.md) — the scheduler has no separate on-call role in a single-operator beta; splitting it out is a decision for whenever the team grows past one engineer.
- **After every deploy**: [Deployment-Runbook.md](Deployment-Runbook.md) §6's `schedule:list` check, not a separate scheduler-specific deploy step — the scheduler (a crontab entry) isn't redeployed per commit the way workers are restarted.

## 7. Gaps, risks, and ambiguous/hosting-dependent items

- **No heartbeat/dead-man's-switch monitoring exists.** Confirmed by grep — no `->pingBefore()`/`->pingOnSuccess()`/`->pingOnFailure()` call anywhere in `routes/console.php`, no third-party service (Healthchecks.io, Cronitor, etc.) wired in. Today, "is cron actually firing" can only be inferred from downstream effects (§4/§5), never confirmed directly. A real, relatively cheap follow-up: wire `->pingOnFailure($url)` on at least `atlas:sync-due-integrations` (the highest-value, easiest-to-silently-break entry) to an external monitor.
- **`ApplyLearnings`'s per-company try/catch means a real failure never becomes a `failed_jobs` row** — it's swallowed and logged per-company so one company's bug can't block the rest, which is the right behavior for the job itself, but it means `/admin/failed-jobs` is *not* a complete picture for this specific task; the log line is the only signal (§5).
- **`SendFeedbackDigest`'s silent no-op on empty feedback is intentional, not a bug** — but it means "no digest email this week" is not distinguishable from "the job never ran" without also checking `/admin/failed-jobs`. Documented explicitly in §5 so it isn't mistaken for a scheduler failure during a quiet week.
- **The exact system-cron failure log location (§4, step 2) is hosting-dependent** — `/var/log/syslog` (Debian/Ubuntu) vs. `journalctl -u cron` (systemd-based) vs. a managed platform's own log viewer (e.g. Forge). This document can't give one correct path independent of the hosting choice made in [Customer-1-Launch-Runbook.md](../ops/Customer-1-Launch-Runbook.md) Phase 1.
- **`onOneServer()`'s guarantee only matters once Atlas ever runs on more than one app server** — currently a single-server topology per [Production-Topology.md](Production-Topology.md), so this is dormant protection, not yet exercised. Worth a deliberate re-check the day a second app server is ever added, per [Deployment-Runbook.md](Deployment-Runbook.md) Phase 5's existing note.
