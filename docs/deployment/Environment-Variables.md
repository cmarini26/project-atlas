# Environment Variables — Production Reference

**Jira:** SCRUM-35 (Sprint 1) — Define required production environment variable inventory.
**Purpose:** the canonical, code-verified inventory of every environment variable Atlas's Laravel app reads, split into what a production deploy *must* set, what has a safe default worth knowing about, and what exists in the framework but isn't wired to anything Atlas actually does. Built by grepping every `env()` call in `config/*.php` (the only place they should appear, per Laravel convention) plus the one exception read directly in `bootstrap/app.php`, not by copying `.env.example` — so this catches variables `.env.example` under-documents, not just what's already there.
**Companion documents:** [`.env.example`](../../backend/.env.example) (the file this document explains), [Production-Topology.md](Production-Topology.md) (the architecture these variables configure), [Customer-1-Launch-Runbook.md](../ops/Customer-1-Launch-Runbook.md) Phase 2 (the step that sets these for real), [Critical-Production-Blockers.md](../plans/Critical-Production-Blockers.md) (why several of these exist).

---

## 1. Required — must be set to a real production value

Every variable below is either a secret, a value with no safe generic default, or a default that is *actively wrong* for production (a local-dev convenience). "Required" here means: if this is left at its `.env.example` value, production either breaks or is unsafe.

### Core application

| Variable | `.env.example` default | Required production value | Why |
|---|---|---|---|
| `APP_ENV` | `local` | `production` | Gates debug output, error verbosity, and `ProductionMailerGuard`'s misconfiguration check |
| `APP_DEBUG` | `true` | `false` | Left `true`, every error page shows a full stack trace and environment data to any visitor |
| `APP_KEY` | *(blank)* | a real generated key | `php artisan key:generate --show` — encrypts sessions/cookies; never share across environments |
| `APP_URL` | `http://localhost:8000` | the real production URL | Used for generated links (password reset emails, etc.) |
| `TRUSTED_PROXIES` | *(blank — trusts nothing)* | the real proxy's IP/CIDR, or `*` | See §4 (hosting-dependent) — required for HTTPS detection, HSTS, and IP-keyed rate limiting to work correctly behind any reverse proxy |
| `SESSION_SECURE_COOKIE` | *(blank — auto-detected)* | `true` | `config/session.php:172` reads this with no default; auto-detection depends on `TRUSTED_PROXIES` being right. Force it explicitly. **Added to `.env.example` 2026-07-16** — previously undocumented anywhere in the repo (see §2) |

### Database

| Variable | Required production value |
|---|---|
| `DB_CONNECTION` | `pgsql` (already the shipped default — correct, don't change) |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Real production PostgreSQL 16 credentials |

### Redis

| Variable | Required production value | Why |
|---|---|---|
| `REDIS_HOST`, `REDIS_PORT` | Real production Redis 7 host/port | |
| `REDIS_PASSWORD` | A real password | `.env.example` ships `null` (no auth) — [Production-Deployment-Audit.md](../reviews/Production-Deployment-Audit.md) flags this explicitly as a High Priority gap, not yet closed |

### Mail (Postmark)

| Variable | Required production value | Why |
|---|---|---|
| `MAIL_MAILER` | `postmark` | `log` (the default) silently does nothing in production — `App\Services\Mail\ProductionMailerGuard` refuses to send and logs critically if left on `log`/`array` in `APP_ENV=production` |
| `MAIL_FROM_ADDRESS` | A real, domain-verified sending address | |
| `POSTMARK_API_KEY` | Real Server API Token | |
| `POSTMARK_MESSAGE_STREAM_ID` | Real Message Stream ID | |

### AI provider (already in production use pre-launch)

| Variable | Required production value |
|---|---|
| `ANTHROPIC_API_KEY` | Real key — every observation/recommendation call depends on this |

### Error tracking (activation still pending — see §2)

| Variable | Required production value | Why |
|---|---|---|
| `ERROR_TRACKING_DRIVER` | Your chosen vendor's driver key (e.g. `sentry`) | `null` (default) binds `NullErrorTracker`, a documented no-op |
| `ERROR_TRACKING_DSN` | Real DSN | Meaningless until the driver above is also set, and until the vendor class itself is implemented — see [Critical-Production-Blockers.md](../plans/Critical-Production-Blockers.md) Blocker 5 |

### Conditional: Meta (Facebook/Instagram publishing OAuth)

| Variable | Required only if | Notes |
|---|---|---|
| `META_APP_ID`, `META_APP_SECRET`, `META_REDIRECT_URI` | Customer 1 (or any company) will connect a real Facebook/Instagram channel | Blank stubs today — "unusable until real values are set" per the file's own comment. Not required for an email/WordPress-only beta. |

---

## 2. Missing or under-documented — findings from this audit

1. **`SESSION_SECURE_COOKIE` was completely absent from `.env.example`** despite `config/session.php` reading it with no default. **Fixed** — now documented with a comment explaining the local-vs-production distinction.
2. **`DB_QUEUE_RETRY_AFTER` was undocumented**, despite `database` being the *actual, currently-configured* queue driver (`QUEUE_CONNECTION=database`) — not a hypothetical alternative. It governs how long before an in-flight job is considered abandoned and retried (default `90` seconds). An operator debugging "why did this job run twice" previously had no in-repo pointer to it. **Fixed** — now documented in `.env.example` alongside `QUEUE_CONNECTION`.
3. **`REDIS_CACHE_DB` was undocumented**, despite being the entire mechanism that keeps cache (`REDIS_CACHE_DB`, default `1`) isolated from the `default` connection (`REDIS_DB`, default `0`) that sessions use. An operator who didn't know this variable existed could accidentally reintroduce the "queue and session share a Redis DB" risk `Production-Deployment-Audit.md` already flagged, by e.g. setting a custom `REDIS_DB` without also considering `REDIS_CACHE_DB`. **Fixed** — now documented in `.env.example` alongside the other `REDIS_*` variables.
4. **`LOG_DAILY_DAYS` was undocumented**, even though [Customer-1-Launch-Runbook.md](../ops/Customer-1-Launch-Runbook.md) Phase 2 already instructs switching `LOG_STACK` from `single` to `daily` — the retention window that switch actually controls (default 14 days) was never named anywhere the operator would see it while making that change. **Fixed** — now documented alongside `LOG_STACK`.
5. **`ANTHROPIC_BASE_URL` exists in code** (`config/services.php`) **with no mention in `.env.example` at all.** Left as a finding only, not fixed — genuinely low priority (no reason to override it today), but if Anthropic is ever proxied (e.g. through a corporate egress proxy or a regional endpoint), there's currently no discoverable variable name for it without reading `config/services.php` directly.

Items 1–4 are now fixed directly in `.env.example`, each guarded by a `EnvExampleTest` regression test (§6) so none can silently disappear again — "has a safe default" and "is discoverable by an operator who needs to change it" are different claims, and this audit's job was to close the gap between them wherever it was cheap and safe to do so. Item 5 is intentionally left as a documented-but-open finding, since it's genuinely speculative (no current need) rather than a real gap already causing confusion.

---

## 3. Optional / tunable — safe defaults, override only with a reason

| Variable | Default | What it tunes |
|---|---|---|
| `LOG_STACK` | `single` (non-rotating) | Switch to `daily` in production — see Runbook Phase 2 |
| `LOG_LEVEL` | `debug` | Set `warning` in production — `debug` is too noisy at real traffic volume |
| `LOG_DAILY_DAYS` | `14` | Retention window once `LOG_STACK=daily` — see finding §2.4 |
| `CRAWLER_MAX_PAGES` | `1` | Pages crawled on a company's *first* website sync (kept low so onboarding produces a first recommendation fast) |
| `CRAWLER_RECURRING_MAX_PAGES` | `10` | Pages crawled on every *subsequent* sync |
| `CRAWLER_CONNECT_TIMEOUT` / `CRAWLER_REQUEST_TIMEOUT` | `5` / `10` seconds | How fast an unreachable/slow site fails instead of stalling |
| `INSTAGRAM_MEDIA_LIMIT` | `20` | Recent posts fetched per sync for content-intelligence Facts |
| `INSTAGRAM_CONNECT_TIMEOUT` / `INSTAGRAM_REQUEST_TIMEOUT` | `5` / `10` seconds | Same reasoning as the crawler timeouts |
| `MARKETING_HEALTH_*` (5 variables) | See `config/marketing_health.php` | Scoring-curve tuning constants for the Marketing Health dimensions — deliberately configuration, not code, per `docs/specs/Marketing-Health.md` §3. No reason to change for launch. |
| `DB_QUEUE_TABLE` / `DB_QUEUE` / `DB_QUEUE_RETRY_AFTER` | `jobs` / `default` / `90`s | See finding §2.2 — the live queue driver's own tuning, safe as shipped |
| `REDIS_CACHE_DB` | `1` | See finding §2.3 — keep separate from `REDIS_DB` (session/default, `0`) |

---

## 4. Ambiguous or hosting-dependent — cannot be given a single correct value here

| Variable | Why it's ambiguous |
|---|---|
| `TRUSTED_PROXIES` | Correct value depends entirely on the hosting choice: a specific IP/CIDR for a known load balancer, or the literal `*` if the proxy's own IP isn't fixed (the standard choice for most managed load balancers, per Blocker 7's own reasoning). There is no single right answer independent of where Atlas is hosted. |
| `SESSION_DOMAIN` | Depends on whether Atlas is served from an apex domain, a subdomain, or multiple subdomains that need to share a session — a product/hosting decision, not a code default. |
| `QUEUE_CONNECTION` | Currently `database` — a deliberate, documented middle setting (not `sync`, which would block onboarding requests for minutes; not yet `redis`, which the Supervisor topology was originally written assuming). **If ever switched to `redis`**, note that `REDIS_QUEUE_CONNECTION` defaults to `default` — the *same* Redis logical connection sessions use (`REDIS_DB`) — which would reintroduce the queue/session Redis-sharing risk the current `database` choice avoids. Not a problem today; would need its own decision if this ever changes. |
| `AWS_*` (6 variables: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_URL`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT`) | **Confirmed unused** — `grep -rn "Storage::" app/` returns zero results anywhere in the codebase. These are Laravel's stock `s3` filesystem disk config, present but not called by any Atlas code today (matches [Backup-and-Recovery.md](../operations/Backup-and-Recovery.md)'s own finding). No action needed unless/until a file-upload feature is built — at which point the *bucket region/naming/endpoint* would be a genuine hosting-provider decision, not something this document can pre-answer. |
| `META_REDIRECT_URI` | Must exactly match whatever URL is registered in the Meta Developer App console for the real domain — inherently tied to the final domain choice, can't be set until that's fixed. |

---

## 5. Present in the framework, not wired to anything Atlas does — no action needed

Confirmed by direct code inspection, not assumed:

| Variable(s) | Why safe to ignore |
|---|---|
| `SANCTUM_STATEFUL_DOMAINS`, `SANCTUM_TOKEN_PREFIX`, `AUTH_GUARD`, `AUTH_PASSWORD_BROKER`, `AUTH_PASSWORD_RESET_TOKEN_TABLE`, `AUTH_PASSWORD_TIMEOUT` | `User` has the `HasApiTokens` trait, but no Sanctum middleware group is registered anywhere in `bootstrap/app.php` or `routes/api.php` — no API token route exists to secure. Standard Laravel auth (session-based) is what's actually in use, and its own defaults are safe. |
| `INERTIA_SSR_ENABLED`, `INERTIA_SSR_ENSURE_BUNDLE_EXISTS`, `INERTIA_SSR_RUNTIME`, `INERTIA_SSR_URL`, `INERTIA_SSR_ENSURE_RUNTIME_EXISTS`, `INERTIA_SSR_THROW_ON_ERROR` | `ssr.enabled` defaults to `true`, which could look alarming (no Node SSR service is documented anywhere in this repo's deployment docs) — **but verified safe**: `ensure_bundle_exists` also defaults to `true`, and no `bootstrap/ssr/*.{js,mjs}` bundle file exists anywhere in the repo (confirmed by `find`), so Inertia's own `HttpGateway::dispatch()` returns before ever attempting an HTTP call to the SSR URL. SSR is configured but structurally inert; nothing to set. |
| `SESSION_HTTP_ONLY` (default `true`), `SESSION_SAME_SITE` (default `lax`) | Already-safe framework defaults, confirmed correct by [Production-Deployment-Audit.md](../reviews/Production-Deployment-Audit.md). |
| `BEANSTALKD_*`, `SQS_*`, `MEMCACHED_*`, `DYNAMODB_*`, `MYSQL_ATTR_SSL_CA`, `DB_SSLMODE`, `DB_ENCRYPT` | Config stubs for drivers Atlas doesn't use (`QUEUE_CONNECTION` is `database`, `CACHE_STORE`/`SESSION_DRIVER` are `redis`, `DB_CONNECTION` is `pgsql` not `mysql`/`sqlsrv`). |
| `RESEND_API_KEY`, `SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_BOT_USER_DEFAULT_CHANNEL`, `LOG_SLACK_*`, `LOG_PAPERTRAIL_HANDLER`, `PAPERTRAIL_*` | Stock Laravel service/log-channel stubs with no corresponding driver selected anywhere. |

---

## 6. Verification run this session

- `grep -rhoE "env\([...]" config/` — extracted the full, exhaustive list of every variable any config file reads (the source of truth this document is built from).
- `grep -rhoE "env\([...]" app/ bootstrap/ routes/ database/` — confirmed `TRUSTED_PROXIES` is the *only* variable read outside `config/*.php` (in `bootstrap/app.php`), consistent with Laravel convention.
- `grep -rn "Storage::" app/` — confirmed zero results, backing §4's `AWS_*` finding.
- Read `vendor/inertiajs/inertia-laravel/src/Ssr/{BundleDetector,HttpGateway}.php` directly and confirmed no SSR bundle file exists anywhere in the repo — backing §5's SSR finding with code, not assumption.
- `php artisan config:cache && php artisan config:clear` — confirmed every config file parses and caches cleanly with no errors.
- `php artisan about --only=environment,cache,drivers` — confirmed current local driver selections match this document's descriptions (`Cache: redis`, `Database: pgsql`, `Queue: database`, `Session: redis`, `Mail: log`).
- Added `SESSION_SECURE_COOKIE=`, `LOG_DAILY_DAYS=14`, `DB_QUEUE_RETRY_AFTER=90`, and `REDIS_CACHE_DB=1` to `.env.example`, each with an explanatory comment (findings §2.1–§2.3) — see `backend/tests/Feature/Deployment/EnvExampleTest.php` for the regression tests guarding all of them, plus five other launch-critical variables, staying documented.
- Full backend test suite, PHPStan, and Pint all green after the `.env.example` changes (see CHANGELOG for exact counts).
