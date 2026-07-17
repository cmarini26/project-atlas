# Production Configuration Sanity Check

**Jira:** SCRUM-37 (Sprint 1) — Run a configuration sanity check on production settings.
**Scope:** cross-checks Sprint 1's own artifacts ([Environment-Variables.md](Environment-Variables.md), [Queue-Workers.md](Queue-Workers.md), [Scheduler-Operations.md](Scheduler-Operations.md), [Deployment-Runbook.md](Deployment-Runbook.md)) against each other and against the real, current `config/*.php`/`bootstrap/app.php` — not a re-audit from scratch. Where this document and any of those four disagree, **this document is wrong and should be fixed**, since it was written last, specifically to catch drift.
**How to use this:** §1 is a runnable pre-deploy checklist (real commands, real expected output) — run it before/after every deploy, in addition to (not instead of) [Deployment-Runbook.md](Deployment-Runbook.md)'s own verification steps. §2 is this specific audit's findings, kept as a dated record.

---

## 1. Runnable pre-deploy configuration sanity check

| # | Area | Command | Expected result |
|---|---|---|---|
| 1 | App env/debug/URL | `php artisan tinker --execute="echo config('app.env').' '.var_export(config('app.debug'), true).' '.config('app.url');"` | `production false https://<real-domain>` — never `local`/`true`/`localhost` |
| 2 | Session security | `grep SESSION_SECURE_COOKIE .env` | `SESSION_SECURE_COOKIE=true` — a blank value silently falls back to auto-detection (see §2.1) |
| 3 | Trusted proxies | `php artisan tinker --execute="echo env('TRUSTED_PROXIES') ?: 'UNSET — HTTPS DETECTION WILL BE WRONG';"` | A real IP/CIDR or `*` — never blank in production |
| 4 | Queue connection | `php artisan tinker --execute="echo config('queue.default');"` | `database` (the deliberate current choice — see [Environment-Variables.md](Environment-Variables.md) §4) |
| 5 | Queue worker commands | `grep "queue:work" ../infrastructure/supervisor/atlas-worker.conf` | Every line uses `--queue=<name>`, **none** pass a bare queue name as a positional argument (the SCRUM-41 bug) |
| 6 | Cache/session Redis isolation | `php artisan tinker --execute="echo config('database.redis.default.database').' / '.config('database.redis.cache.database');"` | `0 / 1` — must never be equal |
| 7 | Log rotation (main) | `grep LOG_STACK .env` | `daily` in production, never `single` |
| 8 | Log rotation (publishing channel) | `php artisan tinker --execute="echo config('logging.channels.publishing.driver');"` | `daily` — **was `single` and hardcoded until this audit; see §2.2** |
| 9 | Mail mailer | `php artisan tinker --execute="echo config('mail.default');"` | `postmark` in production, never `log`/`array` (`ProductionMailerGuard` will refuse to send and log critically if it is) |
| 10 | Error tracking | `php artisan tinker --execute="echo config('services.error_tracking.driver');"` | Your real vendor's driver key once wired — `null` is the safe-but-inert default until then |
| 11 | Health endpoints | `curl -s <domain>/api/health`, `curl -s <domain>/api/ready`, `curl -s <domain>/api/live`, `curl -s <domain>/up` | First three return Atlas's own JSON; `/up` returns Laravel's default HTML page — **all four exist and behave differently, see §2.3** |
| 12 | Scheduler definitions | `php artisan schedule:list` | Exactly 7 entries (see [Scheduler-Operations.md](Scheduler-Operations.md)) |
| 13 | Config cache freshness | `php artisan about --only=cache` | `Config: CACHED`, `Events: CACHED`, `Routes: CACHED`, `Views: CACHED` — confirms `php artisan optimize` was actually run this deploy, not stale from a prior one |

## 2. Findings from this audit

### 2.1 — Confirmed consistent, no action needed

Cross-checked directly against `config/*.php`/`bootstrap/app.php`, not re-assumed from the docs that already documented them:

- `APP_ENV`/`APP_DEBUG`/`APP_URL` — `.env.example` defaults are the documented-unsafe-for-production local values; [Environment-Variables.md](Environment-Variables.md) §1 and [Deployment-Runbook.md](Deployment-Runbook.md) §1 agree on the required production values.
- `TRUSTED_PROXIES` — `bootstrap/app.php` reads it via `TrustedProxyResolver`, fails closed (trusts nothing) when unset; consistent across all four Sprint 1 docs.
- `SESSION_SECURE_COOKIE` — `config/session.php:172` reads it with no default (`env('SESSION_SECURE_COOKIE')`, no second argument); `.env.example` now documents it (fixed under SCRUM-35); consistent everywhere it's referenced.
- Queue connection/worker commands — `config/queue.php`'s `default` resolves to `database`; every job dispatches via `onQueue()` only; `atlas-worker.conf`'s commands now correctly use `--queue=` (SCRUM-41 fix, re-verified still present in the current file).
- `REDIS_CACHE_DB`/`REDIS_DB` isolation — `config/database.php`'s `redis.default.database` (`0`) and `redis.cache.database` (`1`, via `REDIS_CACHE_DB`) are distinct, matching [Environment-Variables.md](Environment-Variables.md) §2/§3.
- Scheduler entry count — `php artisan schedule:list` returns exactly 7, matching [Scheduler-Operations.md](Scheduler-Operations.md) (which itself corrected a stale "6" count found in two other docs during that ticket).

### 2.2 — Fixed during this audit

**The `publishing` log channel (`storage/logs/publishing.log` — every WordPress/Meta/Postmark send attempt) used the `single` driver, which never rotates, and a hardcoded `debug` level — independent of whatever `LOG_STACK`/`LOG_LEVEL` production is actually configured with.** This is not a new discovery — `Production-Deployment-Audit.md` (2026-07-10) flagged the hardcoded level explicitly, and `Private-Beta-Execution.md`'s Log Retention checklist already assumed this channel was "included in the rotation policy" — but nothing had actually fixed it, and none of Sprint 1's newer docs (`Environment-Variables.md`, `Deployment-Runbook.md`) caught that the fix was still outstanding. Switching `LOG_STACK=single` → `daily` in production (per `Customer-1-Launch-Runbook.md` Phase 2) rotated the *main* app log but left `publishing.log` growing forever.

**Fixed**: `config/logging.php`'s `publishing` channel now uses `'driver' => 'daily'` with `'days' => env('LOG_DAILY_DAYS', 14)` (sharing the main channel's retention window rather than inventing a second env var for the same concern) and `'level' => env('LOG_LEVEL', 'debug')` (no longer hardcoded). Guarded by new `LoggingConfigTest`.

### 2.3 — Documented, not fixed (a real gap in Sprint 1's own docs, not in the app)

**A fourth health-related endpoint, Laravel's built-in `GET /up` (`bootstrap/app.php`'s `health: '/up'` routing option), was never mentioned in any Sprint 1 document** — every one of `Environment-Variables.md`, `Queue-Workers.md`, `Scheduler-Operations.md`, `Deployment-Runbook.md`, and `Customer-1-Launch-Runbook.md` only reference Atlas's own custom `/api/health`/`/api/ready`/`/api/live`. The older `Production-Deployment-Audit.md` (2026-07-10) did mention `/up` exists, but that fact didn't carry forward into any of the newer, more detailed operational docs.

**This matters because `/up` and `/api/health` behave differently during maintenance mode — confirmed live, not assumed:**

```
$ php artisan down --secret=probe-test
$ curl -o /dev/null -w "%{http_code}" /up            → 200
$ curl -o /dev/null -w "%{http_code}" /api/health     → 503
```

Laravel's `/up` is specifically designed to bypass maintenance mode (so orchestration/load-balancer checks don't flap during planned downtime); Atlas's own `/api/health` correctly returns 503 (it's actually checking whether the app is serving real traffic, and during maintenance it isn't). **Both behaviors are individually correct — the gap is that no doc told an operator which one to point uptime monitoring at, or that using `/api/health` for continuous uptime alerting means every deliberate `php artisan down` window (per `Deployment-Runbook.md` §3) will fire a false "site is down" alert unless the monitor is paused first.**

**Not fixed here — a decision for whoever configures the real uptime monitor** (Customer-1-Launch-Runbook.md Phase 0/7): either (a) monitor `/up` for pure liveness and treat `/api/ready` as a manual post-deploy check only, never continuous alerting, or (b) monitor `/api/health` and remember to pause the monitor before every planned maintenance window. This document does not choose for you — see §3.

### 2.4 — Hosting-dependent / cannot be resolved by this document

- **`APP_MAINTENANCE_DRIVER=file`** (the shipped default, confirmed in `.env.example` and unaltered by this audit) stores the maintenance flag at `storage_path('framework/down')` — **a local file on whichever single server runs `php artisan down`.** Correct and sufficient for Atlas's current single-server topology ([Production-Topology.md](Production-Topology.md)); the moment a second app server is ever added, this stops working as expected (one server would go into maintenance, the other would keep serving traffic) unless switched to `APP_MAINTENANCE_DRIVER=cache` (a shared store all servers can see). Same category of "dormant until multi-server" caveat [Scheduler-Operations.md](Scheduler-Operations.md) already documented for `onOneServer()` — noted here for the same reason, not re-litigated.
- **The exact uptime-monitor pause/resume mechanism (§2.3)** depends entirely on which monitoring vendor is chosen (Customer-1-Launch-Runbook.md Phase 0) — some support a documented "maintenance window" API call, others require manually silencing alerts. Not resolvable independent of that choice.
- **`ERROR_TRACKING_DRIVER`/`ERROR_TRACKING_DSN`'s real values** are vendor-specific and can't be pre-filled by this document (already noted in `Environment-Variables.md`).

## 3. Open decision for a human

**Who decides §2.3's uptime-monitoring target (`/up` vs `/api/health`) and how the monitor is paused during maintenance windows?** This is a real, unresolved operational decision — not a code gap — that should be settled and recorded (in `Customer-1-Launch-Ownership.md`'s monitoring row, or a short addendum here) before the first `php artisan down` is ever run against a monitored production environment.

## 4. Verification run for this document

- `php artisan tinker` — directly inspected `config('app.env')`, `config('queue.default')`, `config('database.redis.{default,cache}.database')`, `config('logging.channels.publishing.*')`, `config('services.error_tracking.driver')` against real resolved values, not source-read assumptions.
- `grep` — confirmed `atlas-worker.conf`'s commands still use `--queue=` (SCRUM-41 fix intact), confirmed `SESSION_SECURE_COOKIE`/`LOG_STACK`/`LOG_DAILY_DAYS` are documented in `.env.example`.
- **Live reproduction**: ran `php artisan down --secret=probe-test`, hit `/up` (200) and `/api/health` (503) with real HTTP requests via `php artisan serve`, confirmed the difference, then `php artisan up` to restore normal state.
- Confirmed `SecurityHeaders` middleware applies to `/up` identically to every other route (global `append`, not group-scoped) — not a gap.
- `php artisan schedule:list` — confirmed 7 entries, matching [Scheduler-Operations.md](Scheduler-Operations.md).
- Read `FileBasedMaintenanceMode.php`/`CacheBasedMaintenanceMode.php` source directly to confirm the single-server file-driver behavior rather than assume it.
- `php artisan test --filter=LoggingConfigTest` (2/2), full backend suite, PHPStan, Pint — all green after the `config/logging.php` fix (see CHANGELOG for exact counts).
