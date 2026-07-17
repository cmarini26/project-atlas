# Queue Workers — Production Reference

**Jira:** SCRUM-41 (Sprint 1) — Document required queue worker processes by queue name.
**Purpose:** exactly what queue worker processes Atlas needs in production, why, and how they should be supervised. Built entirely from reading the actual dispatch code, `config/queue.php`, and every job's own `$tries`/`$backoff`/`$timeout` — not from generic Laravel defaults or assumption.
**Companion documents:** [Production-Topology.md](Production-Topology.md) (the architecture this fits into), [`infrastructure/supervisor/atlas-worker.conf`](../../infrastructure/supervisor/atlas-worker.conf) (the deployable artifact this document explains), [Environment-Variables.md](Environment-Variables.md) (SCRUM-35, the env vars referenced below), [Customer-1-Launch-Runbook.md](../ops/Customer-1-Launch-Runbook.md) Phase 4 (the install step).

---

## 🔴 A real, previously-shipped bug was found and fixed while producing this document

**`infrastructure/supervisor/atlas-worker.conf` would never have processed a single production job.** Every worker command was written as `php artisan queue:work high` (and `ai`/`default`/`observations`/`maintenance`). `queue:work`'s **first positional argument is a connection name, not a queue name.** Since no job in this codebase ever calls `->onConnection(...)` — every job dispatches via `->onQueue(...)` alone — every job is actually pushed onto whatever `QUEUE_CONNECTION` resolves to (the `database` connection, per `.env.example`'s default), with its queue column set to `high`/`ai`/etc. `config/queue.php` separately defines four connections *also* named `high`/`ai`/`observations`/`maintenance` (using the `redis` driver) — a different, currently-unreferenced routing path. `queue:work high` was therefore telling the worker to use that Redis-backed `high` **connection**, which nothing ever pushes a job onto. The worker would sit listening on an empty Redis list forever, while every real job piled up, unprocessed, in the Postgres `jobs` table.

**This was proven, not just reasoned about**: a real job was dispatched exactly as every real job in this codebase dispatches (`onQueue('high')` only), then:
1. `php artisan queue:work high --once` (the exact form previously shipped) was run — it did not touch the job. Confirmed the job was still sitting in the `jobs` table afterward.
2. `php artisan queue:work --queue=high --once` (the corrected form) was run against the same job — it processed it, and the `jobs` table row was gone.

**Fixed** in this repo: every command in `atlas-worker.conf` now uses `--queue=<name>` with no connection argument. `backend/tests/Feature/Deployment/QueueWorkerConfigTest.php` guards against this exact regression — it parses the Supervisor file and fails if any `command=` line ever passes a bare queue name as a connection argument again, and separately proves end-to-end that a job dispatched the real way is only reachable by the corrected worker form.

**This also means the scheduled jobs matter here.** `routes/console.php`'s `Schedule::job(...)` entries (`ExpireOpportunities`, `PublishScheduledContent`, `CheckChannelHealth`, `PruneRawMetrics`, `ApplyLearnings`, `SendFeedbackDigest`) don't run inline in the scheduler process — they're pushed onto the queue exactly like any other job, so they were equally affected. Only `Schedule::command('atlas:sync-due-integrations')` runs synchronously in the scheduler's own process, unaffected by this bug.

---

## 1. Queue connection(s) Atlas actually uses

**One connection, in practice: `database`** (`QUEUE_CONNECTION=database` in `.env.example`, a deliberate choice — not `sync`, which would block onboarding requests for minutes; not yet `redis`, see §7). Every job dispatch resolves to this connection, because no job ever calls `onConnection()`.

`config/queue.php` additionally defines four `redis`-driver connections literally named `high`/`ai`/`observations`/`maintenance` (a block explicitly commented "Atlas queue topology — one connection per named queue"). **These are not currently reachable by any dispatch path in the app** — see the bug above. They exist in config but nothing pushes a job onto them today. Treat them as unused unless/until something in the app is changed to call `onConnection()` explicitly (see §7).

## 2. Queue names actually used

Exactly five, all confirmed by grepping every `onQueue()` call in `app/Jobs/*.php` — no job uses any other queue name, and no job omits `onQueue()` entirely:

| Queue | Purpose |
|---|---|
| `high` | Real, time-sensitive external publishing (WordPress/Meta/Postmark sends) |
| `ai` | Every AI-provider (Anthropic) call in the pipeline |
| `default` | Lightweight orchestration between pipeline stages |
| `observations` | Website/Instagram sync and post-publish metric retrieval |
| `maintenance` | Recurring, non-urgent scheduled housekeeping |

## 3. Every job, its queue, and its retry/backoff/timeout configuration

Extracted directly from each job class — not inferred from the queue name alone. "CLI default" means the job has no `$tries`/`$backoff` of its own, so it inherits whatever the worker process's `--tries`/default backoff is (only `SendFeedbackDigest` is in this position today). A job's own `$tries`/`maxTries()` always wins over the worker's `--tries` flag when both are present — confirmed directly in Laravel's `Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts()`.

| Job | Queue | `$tries` | `$backoff` | `$timeout` | Notes |
|---|---|---|---|---|---|
| `PublishContent` | `high` | 4 (1 attempt + 3 retries) | `[60, 300, 900]` (method) | default | The actual external-channel send |
| `PublishCampaign` | `high` | 1 | default | default | Orchestrates `PublishContent` per execution — itself not retried, since re-running it would re-dispatch child jobs |
| `CommitDecision` | `ai` | 3 | 60s | default | `ShouldBeUnique` |
| `GenerateContent` | `ai` | 3 | 30s | default | |
| `PrepareCampaign` | `ai` | 3 | 60s | default | |
| `ProcessObservation` | `ai` | 3 | `[30, 120]` | default | |
| `CreateRecommendation` | `default` | 3 | 30s | default | |
| `DetectOpportunities` | `default` | 3 | default | default | `ShouldBeUnique` |
| `SyncIntegration` | `observations` | 3 | 60s | default | `ShouldBeUnique` |
| `RetrieveExecutionMetrics` | `observations` | 3 | default | **60s** | Self-reschedules onto `observations` again with a delay (a poll loop, not a retry) |
| `ProcessAnalyticsWebhookEvent` | `observations` | 3 | 30s | default | |
| `CheckChannelHealth` | `maintenance` | 3 | 60s | default | Scheduled every 30 min |
| `ExpireOpportunities` | `maintenance` | 1 | default | default | Scheduled hourly |
| `PruneRawMetrics` | `maintenance` | 3 | 300s | default | Scheduled monthly |
| `PublishScheduledContent` | `maintenance` | 3 | 60s | default | Scheduled every 5 min; dispatches `PublishContent` onto `high` |
| `ApplyLearnings` | `maintenance` | 3 | default | default | `ShouldBeUnique` (once per company per day) — scheduled daily at 02:00 |
| `SendFeedbackDigest` | `maintenance` | **CLI default** (no own `$tries`) | CLI default | default | Scheduled weekly — the *only* job actually affected by the `maintenance` worker's `--tries=1` flag |

---

## 4. Recommended production worker process layout

**Five process groups, one per queue — not a single shared pool.** See §5 for why.

| Process group | Command | Concurrency | Sleep | Tries (CLI fallback) | Max runtime |
|---|---|---|---|---|---|
| `atlas-worker-high` | `php artisan queue:work --queue=high --sleep=1 --tries=3 --max-time=3600` | 1 | 1s | 3 | 1h (auto-restart) |
| `atlas-worker-ai` | `php artisan queue:work --queue=ai --sleep=3 --tries=3 --max-time=3600` | 2 | 3s | 3 | 1h |
| `atlas-worker-default` | `php artisan queue:work --queue=default --sleep=3 --tries=3 --max-time=3600` | 2 | 3s | 3 | 1h |
| `atlas-worker-observations` | `php artisan queue:work --queue=observations --sleep=5 --tries=3 --max-time=3600` | 1 | 5s | 3 | 1h |
| `atlas-worker-maintenance` | `php artisan queue:work --queue=maintenance --sleep=30 --tries=1 --max-time=3600` | 1 | 30s | 1 | 1h |

Exact, deployable form: [`infrastructure/supervisor/atlas-worker.conf`](../../infrastructure/supervisor/atlas-worker.conf) — install via Supervisor, one `[program:...]` block per row above. **Critical: never pass the queue name as a bare argument to `queue:work` — always `--queue=<name>`** (see the bug above). No connection argument should ever appear.

**Queue ordering:** none of the five queues need cross-queue priority ordering — each has its own dedicated worker process(es), so `high` never waits behind `ai`/`observations`/`maintenance` regardless of how backed up those get. This is the entire reason for separate process groups instead of one worker polling a comma-joined queue list (see §5).

**`--sleep` values** are already tuned per queue's realistic latency tolerance: `high` (real customer-facing sends) polls almost immediately (1s); `maintenance` (housekeeping, nothing time-sensitive) polls only every 30s to avoid needless load.

**`numprocs` (concurrency)**: `ai` and `default` run 2 processes each — `ai` because it's the highest-volume queue (every Anthropic call in the pipeline funnels through it) and `default` because it sits between pipeline stages where a backlog would visibly stall recommendation generation. The other three run a single process — `high` and `observations` are lower-volume per company, and `maintenance` is explicitly non-urgent.

## 5. One worker pool or multiple specialized pools?

**Multiple specialized pools — already the deliberate design, confirmed by `config/queue.php`'s own comment** ("Supervisor processes each independently so AI and observation jobs never block notification or maintenance work"). Concretely:

- A single worker polling all five queues via `--queue=high,ai,default,observations,maintenance` (Laravel processes comma-joined queues **in strict left-to-right priority order**, only checking `ai` when `high` is empty, etc.) would mean a slow AI call could starve `high` (real publishing sends) — the opposite of the actual priority Atlas needs, since `ai` is the highest-*volume* queue, not the highest-*priority* one.
- Five separate process groups guarantee `high` always gets its own dedicated worker cycle regardless of how backed up `ai`/`observations`/`maintenance` get.
- This is also why `ai` gets 2 processes (highest volume) while `maintenance` gets 1 with a long sleep (lowest urgency) — a single shared pool can't express that per-queue distinction at all.

**`composer dev`'s local script uses the single comma-joined-queue form** (`queue:work --queue=high,ai,default,observations,publishing,analytics,maintenance`) — this is fine for local development (low volume, no real priority contention) but must **not** be copied into production, both because it forfeits the priority isolation above and because it lists two queue names (`publishing`, `analytics`) that no job in this codebase ever dispatches to — see §7.

## 6. Verification run for this document

- `grep -rhoE "onQueue\('[^']+'\)" app/Jobs/*.php` and `grep -rn "onConnection(" app/` — confirmed exactly 5 real queue names and zero `onConnection()` calls anywhere.
- `grep -nE "public (int|array) \\\$(tries|backoff|timeout)|function backoff\(" app/Jobs/*.php` — extracted every job's actual retry configuration directly, not inferred.
- Read `vendor/laravel/framework/.../Queue/Console/WorkCommand.php` directly — confirmed the first positional argument to `queue:work` is a connection name (`$this->argument('connection')`), and that `getQueue()` falls back to `config("queue.connections.{$connection}.queue")` only when `--queue` isn't passed.
- Read `vendor/laravel/framework/.../Queue/Worker.php` directly — confirmed a job's own `$tries`/`maxTries()` overrides the worker's `--tries` CLI flag, not the other way around.
- **Live, real reproduction**: dispatched a real job with `onQueue('high')` (mirroring every real job's dispatch pattern) against a real local Postgres `jobs` table, confirmed it landed with `queue='high'` under the `database` connection, ran `queue:work high --once` (the previously-shipped form) and confirmed it did not touch the job, then ran `queue:work --queue=high --once` (the corrected form) and confirmed it processed the job and removed it from the table.
- `php artisan test --filter=QueueWorkerConfigTest` — 2/2 passing, guarding this finding permanently.
- Full backend suite, PHPStan, Pint all green after the fix (see CHANGELOG for exact counts).

## 7. Ambiguous, risky, or hosting-dependent

- **The four `redis`-driver named connections in `config/queue.php` (`high`/`ai`/`observations`/`maintenance`) are dead code today.** Not removed in this change — deleting config is a separate, judgment-call decision (someone may intend to wire `onConnection()` calls to them later) outside this ticket's scope. Flagged here so it isn't mistaken for "the real routing mechanism" by a future reader of `config/queue.php` alone.
- **`QUEUE_CONNECTION=database` vs. `redis`** is an explicit, documented, deferred decision (see [Production-Deployment-Audit.md](../reviews/Production-Deployment-Audit.md)) — not this ticket's to resolve. If it's ever switched to `redis`, note `REDIS_QUEUE_CONNECTION` (in `config/queue.php`'s generic `redis` connection, distinct from the four dead named ones above) defaults to the *same* Redis connection sessions use — a real, worth-revisiting interaction at that point, already flagged in [Environment-Variables.md](Environment-Variables.md) §4.
- **`composer.json`'s local dev script lists two queue names (`publishing`, `analytics`) nothing dispatches to** — harmless locally (idle listeners), but do not copy that queue list into any production configuration; the five-queue list in this document is the verified-correct one.
- **`numprocs`/concurrency values in `atlas-worker.conf` are starting recommendations, not load-tested figures** — real Customer 1 traffic volume will tell whether `ai`'s 2 processes are enough; this is explicitly a "revisit once real usage exists" number, not a hard requirement.
- **Worker process placement (single server vs. dedicated worker host) is a hosting decision** this document doesn't make — [Production-Topology.md](Production-Topology.md) describes the shape but not a specific provider.
