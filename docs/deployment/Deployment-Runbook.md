# Deployment Runbook

**Jira:** SCRUM-47 (Sprint 1) — Create the first repeatable deployment runbook.
**Scope:** the procedure to run for **every** deploy of Atlas to production — not a one-time launch checklist. For the one-time "get to Customer 1" work (external accounts, legal pages, ownership, first-week cadence), see [Customer-1-Launch-Runbook.md](../ops/Customer-1-Launch-Runbook.md), whose Phase 3/4/5 now defer to this document for the repeatable steps themselves.
**Grounded in Sprint 1's own findings, not generic Laravel advice:** every environment variable named below is the exact set from [Environment-Variables.md](Environment-Variables.md) (SCRUM-35); every queue worker command is the exact, bug-fixed form from [Queue-Workers.md](Queue-Workers.md) (SCRUM-41) — **do not** copy commands from older docs, blog posts, or Laravel's generic deployment guide without cross-checking both.

---

## 0. Pre-deploy prerequisites

Verify these exist and are reachable **before** starting — this runbook deploys code to infrastructure, it does not provision infrastructure (see [Production-Topology.md](Production-Topology.md) and [Customer-1-Launch-Runbook.md](../ops/Customer-1-Launch-Runbook.md) Phases 1–2 for that, one-time, prior work):

- [ ] A real production server is reachable via SSH, with PHP 8.3+, PostgreSQL 16 client tools, and Redis client tools installed.
- [ ] A real, non-`.env.example` `.env` file already exists at the deploy path, `chmod 600`, matching [Environment-Variables.md](Environment-Variables.md)'s Required section (§1 below is a quick-reference, not a replacement for that document).
- [ ] `psql`/`redis-cli` from the app server can reach the real production database/Redis — confirmed reachable, not assumed.
- [ ] [`infrastructure/supervisor/atlas-worker.conf`](../../infrastructure/supervisor/atlas-worker.conf) and [`infrastructure/cron/atlas-scheduler`](../../infrastructure/cron/atlas-scheduler) are already installed from a prior deploy (first-deploy-only setup — see §6/§7 below if this is genuinely the first deploy ever).
- [ ] You know which git ref (branch/tag/commit SHA) you're deploying, and it has already passed `.github/workflows/ci.yml` (Pint → PHPStan → PHPUnit) on `main`. **This repo has no CD/deploy automation today** — there is no pipeline that does this for you; see §9.

## 1. Required environment/config assumptions (quick reference)

The full, code-verified inventory is [Environment-Variables.md](Environment-Variables.md) — this is only a checklist that the *categories* are covered in the real `.env`, not a substitute for reading it:

| Category | Must be real, not a placeholder |
|---|---|
| Core | `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`, `APP_URL`, `TRUSTED_PROXIES` |
| Sessions | `SESSION_SECURE_COOKIE=true` |
| Database | `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` |
| Redis | `REDIS_HOST`/`REDIS_PORT`/`REDIS_PASSWORD` |
| Mail | `MAIL_MAILER=postmark`, `POSTMARK_API_KEY`, `POSTMARK_MESSAGE_STREAM_ID` |
| AI | `ANTHROPIC_API_KEY` |
| Error tracking | `ERROR_TRACKING_DRIVER`, `ERROR_TRACKING_DSN` (once a vendor is wired — see [Environment-Variables.md](Environment-Variables.md) §1) |

If any of these is still a blank/example value in the real `.env`, stop — do not proceed to §2.

## 2. Build / install

Run from the deploy path, on the already-checked-out real production ref (a git pull/checkout to the target commit is assumed to have already happened before this step):

```bash
composer install --no-dev --optimize-autoloader
npm install --ignore-scripts
npm run build
```

**Do not run `composer setup`.** Its script exists for a *fresh local environment* — it copies `.env.example` to `.env` if `.env` doesn't already exist, and unconditionally chains `migrate --force` + `npm run build` with no chance to review anything in between. A real deploy needs the individually-sequenced steps below, not that shortcut.

### Verification — §2

- [ ] `composer install` and `npm run build` both exit `0` with no errors.
- [ ] `public/build/manifest.json` (Vite's build manifest) exists and was just updated (`ls -la public/build/manifest.json`) — confirms the frontend assets this deploy will actually serve are the ones you just built, not a stale prior build.

## 3. Migrations

```bash
php artisan migrate:status   # inspect BEFORE running — know what's about to change
php artisan migrate --force  # --force is required outside `local`/`testing`
php artisan migrate:status   # confirm every migration now shows [Ran]
```

**If a migration in this batch is destructive or hard to reverse**, put the app into maintenance mode first so no request runs against a half-migrated schema:

```bash
php artisan down --secret="<a-throwaway-token>" --retry=60
# ... run migrate --force ...
php artisan up
```

`--secret` lets you (and only you, via `https://<domain>/<secret>`) bypass maintenance mode to smoke-test before reopening to everyone.

### Verification — §3

- [ ] `php artisan migrate:status` shows no `Pending` rows.
- [ ] If maintenance mode was used, `php artisan up` was actually run — check `curl -I https://<domain>` doesn't return a 503 to a normal (non-bypassed) request.

## 4. Cache/config commands

```bash
php artisan optimize
```

This is Laravel's own shortcut for exactly four steps (`config:cache`, `event:cache`, `route:cache`, `view:cache`), run in one command. If you need to see or debug them individually, they're equivalent to:

```bash
php artisan config:cache
php artisan event:cache
php artisan route:cache
php artisan view:cache
```

Then reload PHP-FPM so already-running workers/web processes pick up the new cached config:

```bash
sudo systemctl reload php8.3-fpm   # exact unit name is hosting-dependent — see §9
```

### Verification — §4

- [ ] `php artisan about --only=cache` shows `Config: CACHED`, `Events: CACHED`, `Routes: CACHED`.
- [ ] `curl https://<domain>/api/health` still returns `{"status":"ok",...}` immediately after the FPM reload (confirms the reload didn't drop connections/break anything).

## 5. Queue worker restart — do not skip this

**A code deploy alone does not change what already-running queue workers execute.** Each Supervisor-managed `queue:work` process holds the *old* application code in memory (PHP workers don't hot-reload) until they're told to restart. Skipping this step means every job processed until the next natural worker cycle runs against stale code — a silent, easy-to-miss deploy failure mode.

```bash
php artisan queue:restart
```

This signals every running worker to finish its *current* job, then exit — Supervisor's `autorestart=true` (already configured in [`atlas-worker.conf`](../../infrastructure/supervisor/atlas-worker.conf)) immediately restarts each one, now running the code you just deployed. No manual `supervisorctl restart` is needed for a routine deploy; `queue:restart` is the graceful, in-flight-job-safe way to do this.

### Verification — §5

- [ ] `supervisorctl status` shows all 5 `atlas-worker-*` process groups `RUNNING`, each with a **recent** uptime (confirms they actually cycled — a process that's been up for days despite a deploy just happening means the restart signal didn't reach it).
- [ ] Dispatch one real, harmless job (e.g. trigger a settings sync from the UI) and confirm it completes — proves the restarted workers are actually alive and processing, not just restarted-and-crash-looping.
- [ ] `--max-time=3600` on every worker command (already in `atlas-worker.conf`) means even a missed `queue:restart` self-heals within an hour — but don't rely on this as a substitute for actually running the command above.

## 6. Scheduler — first deploy only, otherwise nothing to do here

The scheduler is a crontab entry (installed once, per [`infrastructure/cron/atlas-scheduler`](../../infrastructure/cron/atlas-scheduler)), not a long-running process tied to a specific deployed commit — a routine deploy doesn't need to touch it. Only re-verify if this is the *first* deploy to a server, or if the deploy path changed:

```bash
crontab -l   # confirm the atlas-scheduler line is present and points at the real deploy path
```

### Verification — §6 (routine deploys)

- [ ] `php artisan schedule:list` runs without error and shows all 7 scheduled entries (`atlas:sync-due-integrations`, `ExpireOpportunities`, `PublishScheduledContent`, `CheckChannelHealth`, `PruneRawMetrics`, `ApplyLearnings`, `SendFeedbackDigest`) — confirmed by running the command directly, not counted by hand — confirms the newly-deployed code's `routes/console.php` still parses correctly.

## 7. Post-deploy verification checklist

Run every item below, in order, before considering the deploy complete:

- [ ] `curl https://<domain>/api/health` → `{"status":"ok",...}`
- [ ] `curl https://<domain>/api/ready` → HTTP 200, `{"status":"ok","checks":{"database":{"status":"ok"},"cache":{"status":"ok"},"queue":{"status":"ok"}}}` (503 means stop and investigate — do not consider the deploy done)
- [ ] `curl https://<domain>/api/live` → `{"status":"ok"}`
- [ ] §5's queue worker checks above are green
- [ ] `supervisorctl status` — all 5 worker groups `RUNNING`
- [ ] One real, end-to-end smoke action performed against production (e.g. log in as a real test account, view an existing page) — the health endpoints prove infrastructure connectivity, not that the actual product works
- [ ] No new entries in the error tracker (or `storage/logs/laravel.log`, if no real vendor is wired yet — see [Environment-Variables.md](Environment-Variables.md)) attributable to this deploy in the first few minutes

## 8. Rollback / failure-handling

**No automated rollback pipeline exists** (see §9) — every rollback below is a manual, deliberate action.

### Application code rollback

```bash
git checkout <previous-known-good-sha>
# re-run §2 (build/install), §4 (cache), §5 (queue restart)
```

Prefer rolling back to a known-good commit over attempting a hot-fix under pressure — a second successful deploy of the *previous* commit re-proves the deploy process itself still works, which a hot-fix doesn't.

### Migration rollback

```bash
php artisan migrate:rollback   # reverses the most recently-run batch only
```

**Check the migration's `down()` method before rolling back, not just its `up()`.** Not every migration's `down()` is a clean inverse — e.g. `2026_07_05_000100_add_retrying_status_to_observations.php`'s `down()` performs a lossy data remap (collapses a `retrying` status back into `failed`, discarding the distinction) before restoring the prior constraint. A rollback here is not a no-op; know what you're actually reversing.

### If a deploy is failing mid-way (e.g. migration succeeded, cache step failed)

1. Put the app in maintenance mode (`php artisan down`) if not already.
2. Diagnose from `storage/logs/laravel.log` and (once wired) the error tracker.
3. Do not run `queue:restart` until the code is in a known-good state — restarting workers into a broken deploy just makes broken code run faster.
4. Once fixed (either forward-fixed or rolled back), re-run §3–§7 in full before `php artisan up`.

## 9. Remaining blockers to a truly repeatable deploy

Called out explicitly, per this ticket's own request — these are real gaps, not resolved by this document:

- **No deploy automation exists at all.** `.github/workflows/ci.yml` is test-only (Pint → PHPStan → PHPUnit); there is no Dockerfile, `Envoy.blade.php`, or CD workflow anywhere in the repo. Every step in this runbook is a manual SSH session today. This is Blocker 7's own documented, still-open scope (see [Critical-Production-Blockers.md](../plans/Critical-Production-Blockers.md)) — a real follow-on project, not something this document can complete by writing more steps.
- **The exact PHP-FPM reload command (§4) is hosting-dependent** — `systemctl reload php8.3-fpm` assumes systemd and a specific PHP version unit name; a managed platform (e.g. Forge) has its own equivalent. This document can't give a single correct command independent of the hosting choice made in [Customer-1-Launch-Runbook.md](../ops/Customer-1-Launch-Runbook.md) Phase 1.
- **No zero-downtime/blue-green deploy story.** `php artisan down` (§3/§8) is a real maintenance window, however brief — there is currently no mechanism to deploy a new release alongside the old one and cut over without one. Acceptable at Customer-1 scale; worth revisiting before a public, self-serve launch.
- **`queue:restart` relies on Supervisor's `autorestart=true` to actually bring workers back** — confirmed configured in `atlas-worker.conf`, but if Supervisor itself isn't running (rare, but possible after a server-level incident), `queue:restart` alone does nothing observable; §5's verification checklist is what actually catches this, not the restart command's own exit code (it always reports success — it only sets a cache flag workers check on their next loop).
- **No documented deploy frequency/change-window policy.** This is an operational/team decision (when deploys are allowed, who approves them) this document deliberately doesn't make.
