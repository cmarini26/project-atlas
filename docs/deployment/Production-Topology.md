# Production Topology

**Purpose:** document the production deployment shape the codebase is built to run behind, so an operator provisioning real infrastructure (Critical Production Blocker 7's remaining, infrastructure-only work — see [Critical-Production-Blockers.md](../plans/Critical-Production-Blockers.md)) knows exactly what to stand up and how the pieces connect. This document describes the *expected* topology; it does not itself provision anything — no server, domain, or DNS record exists as a result of writing this file.

---

## Components

```
                    ┌─────────────────────┐
   Internet ───────▶│  Reverse proxy / LB │  (nginx, Forge, or a managed
                    │  terminates TLS     │   load balancer — terminates
                    └──────────┬──────────┘   HTTPS, forwards plain HTTP)
                               │  X-Forwarded-Proto, X-Forwarded-For
                               ▼
                    ┌─────────────────────┐
                    │ Application server  │  PHP-FPM + Laravel (this repo)
                    │ (web requests)      │  reads TRUSTED_PROXIES to trust
                    └──────────┬──────────┘  the proxy's forwarded headers
                               │
        ┌──────────────────────┼──────────────────────┐
        ▼                      ▼                      ▼
┌───────────────┐    ┌──────────────────┐   ┌──────────────────┐
│  PostgreSQL    │    │      Redis        │   │  Queue workers    │
│  (database)    │    │ (cache, session,   │   │ supervisor-run,   │
│                │    │  queue backing     │   │ 5 named queues —  │
│                │    │  store if used)    │   │ see below         │
└───────────────┘    └──────────────────┘   └──────────────────┘
                                                        ▲
                                                        │
                                              ┌──────────────────┐
                                              │    Scheduler      │
                                              │ cron → schedule:run│
                                              │ every minute       │
                                              └──────────────────┘
```

### Reverse proxy / load balancer

Terminates TLS and forwards plain HTTP to the application server, setting `X-Forwarded-Proto`, `X-Forwarded-For`, `X-Forwarded-Host`, and `X-Forwarded-Port`. This is a single, standard hop (nginx on the same box via Forge, or a managed load balancer) — not a multi-hop CDN chain. Nothing in this repository terminates TLS itself; that is the proxy's job.

### Application server

Runs PHP-FPM serving this Laravel application. Must have `TRUSTED_PROXIES` set to the reverse proxy's IP/CIDR (or `*` if the proxy's own IP isn't fixed) — see `.env.example` and `bootstrap/app.php`. Without this, `$request->secure()`, HSTS, `$request->ip()`, and every IP-keyed rate limiter (e.g. the `analytics-webhook` limiter from Blocker 2) silently read the proxy's own scheme/IP instead of the real client's.

### Database (PostgreSQL)

`DB_CONNECTION=pgsql`. A single primary today — no read replica configuration exists (a separately-tracked High Priority audit item, not this blocker's concern). Backups are Blocker 8's scope, not this one's.

### Redis

Backs the `cache` and `session` stores (`CACHE_STORE=redis`, `SESSION_DRIVER=redis` in `.env.example`). `QUEUE_CONNECTION=database` is the current default (queued jobs live in the `jobs`/`failed_jobs` tables, not Redis) — a workable, deliberate middle setting flagged in the Production Deployment Audit as fine to keep for now, not a blocker.

### Queue workers

Five named queues (`high`, `ai`, `default`, `observations`, `maintenance`), each with a dedicated worker process group — see [`infrastructure/supervisor/atlas-worker.conf`](../../infrastructure/supervisor/atlas-worker.conf) for the exact Supervisor configuration (process counts, sleep intervals, and per-queue tuning are documented there, not repeated here).

### Scheduler

`php artisan schedule:run` must be invoked every minute for the six recurring jobs in `routes/console.php` (integration sync, opportunity expiration, scheduled content publishing, channel health checks, metric pruning, learning application) to ever run in production — see [`infrastructure/cron/atlas-scheduler`](../../infrastructure/cron/atlas-scheduler) for the deployable crontab entry (added in Blocker 4).

---

## What an operator still has to do (Blocker 7's remaining, infrastructure-only scope)

Everything above describes the shape the code is *ready* for. None of it exists yet. Per [Critical-Production-Blockers.md](../plans/Critical-Production-Blockers.md)'s Blocker 7, an operator still has to:

1. Provision a real server, database, and Redis instance.
2. Register a domain and point it at the server, with valid SSL (typically terminated at the reverse proxy).
3. Set `TRUSTED_PROXIES` (this repo's contribution — see above) to match whatever proxy is actually chosen.
4. Deploy the application with a real, production-appropriate `.env` — never a copy of `.env.example`.
5. Install [`infrastructure/supervisor/atlas-worker.conf`](../../infrastructure/supervisor/atlas-worker.conf) and [`infrastructure/cron/atlas-scheduler`](../../infrastructure/cron/atlas-scheduler) on the real server.
6. Run `config:cache`, `route:cache`, and `event:cache` as part of the deploy process.

The full, detailed operator checklist for all of this is [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md)'s "Production Infrastructure Checklist" — this document explains the *shape*; that one is the step-by-step verification procedure.
