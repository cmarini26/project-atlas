# Customer 1 Launch Runbook

**Purpose:** the concrete, ordered, operator-executed sequence to take Atlas from "code-complete, nothing provisioned" (per [Private-Beta-Go-No-Go-Review-2026-07-16.md](../reviews/Private-Beta-Go-No-Go-Review-2026-07-16.md)) to a verified private-beta environment ready for CBB Auctions (Customer 1). This is not a product document — every step here is infrastructure/operator work. No product feature is proposed, added, or implied anywhere below.

**Companion documents** (this runbook sequences them into one path — it does not replace them):
- [Production-Readiness-Checklist.md](Production-Readiness-Checklist.md) — the go/no-go tracking table (Owner/Status columns); check off rows here as you complete phases below.
- [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md) — the detailed verification procedure and the canonical §4 Go/No-Go gate.
- [Production-Topology.md](../deployment/Production-Topology.md) — the architecture this runbook provisions (reverse proxy → app server → {database, Redis, queue workers, scheduler}).
- [Backup-and-Recovery.md](../operations/Backup-and-Recovery.md) — full backup strategy behind Phase 6.
- [Environment-Variables.md](../deployment/Environment-Variables.md) (SCRUM-35) — the canonical, code-verified env var inventory Phase 2 below summarizes.
- [Queue-Workers.md](../deployment/Queue-Workers.md) (SCRUM-41) — the full queue/job/retry model and a critical Supervisor-config bug found and fixed there; Phase 4 below depends on it.
- [Deployment-Runbook.md](../deployment/Deployment-Runbook.md) (SCRUM-47) — the **repeatable** deploy procedure (build/migrate/cache/queue-restart/verify/rollback) for every deploy *after* this one — Phases 3–5 below are this document's first-time application of it.

**How to use this:** work top to bottom, in order — later phases assume earlier ones are real and verified, not "should work." Do not skip a verification checklist because the step before it "looked fine." Fill in an owner and a completion date for each phase as you go.

---

## Phase 0 — External accounts to create (before touching a server)

Create these accounts first; several later phases need working credentials from them.

| Account | Why | What you need from it |
|---|---|---|
| **Domain registrar** | A real, reachable domain for Atlas | A registered domain name |
| **DNS provider** | May be the registrar or separate (e.g. Cloudflare) | Ability to add A/CNAME records |
| **Hosting provider** | Runs the app server, database, Redis | A provisioned server (see Phase 1) — Laravel Forge, a managed VPS (DigitalOcean/Hetzner/Linode), or equivalent |
| **Postmark or SendGrid** | Real transactional email (password reset, test sends) | Real provider credentials plus sender/domain verification for the chosen provider |
| **Twilio** *(only if SMS is in Customer 1 scope)* | Real SMS connect/test/send path | Account SID, auth token, a sending number, and a real phone number you control for verification |
| **Error tracking vendor** (Sentry or equivalent) | Real production exception visibility — code-side abstraction already exists (`App\ErrorTracking\ErrorTracker`), no vendor package installed yet | A project DSN |
| **Uptime monitor** (e.g. UptimeRobot, Better Uptime, Pingdom) | External polling of the health endpoint | Nothing yet — configure in Phase 7 once the domain resolves |

**Do not proceed to Phase 1 without your chosen email provider and error-tracking accounts already created** — Phase 2's `.env` needs real values from both, and provisioning a server before you have them just means editing `.env` twice. Add Twilio too if SMS is explicitly part of Customer 1's beta promise.

---

## Phase 1 — Provision the server, database, Redis, domain, DNS, SSL

1. Provision a server (or managed platform) sized for PHP-FPM + a Laravel app, per [Production-Topology.md](../deployment/Production-Topology.md)'s shape: reverse proxy → app server → PostgreSQL + Redis + queue workers + scheduler, all reachable from the app server.
2. Provision PostgreSQL 16 (per [STATUS.md](../STATUS.md)'s stack table) — either on the same server or a managed instance. Note the host, port, database name, username, password.
3. Provision Redis 7 — same placement question as above. Note host, port, password.
4. Register the domain (or confirm you already own one) and point DNS at the server:
   - An `A` (or `AAAA`) record for the apex/subdomain you'll serve Atlas from.
5. Set up TLS. If using Forge/a managed load balancer, this is typically automatic (Let's Encrypt); if hand-rolling nginx, install Certbot and issue a certificate.
6. Confirm the reverse proxy forwards `X-Forwarded-Proto`, `X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Port` — required for `TRUSTED_PROXIES` (Phase 2) to work correctly.

### Verification checklist — Phase 1

- [ ] `ping <domain>` resolves to the server's real IP, checked from **two independent networks** (not just your office Wi-Fi).
- [ ] `psql -h <db-host> -p <db-port> -U <db-user> -d <db-name>` connects successfully from the app server.
- [ ] `redis-cli -h <redis-host> -p <redis-port> ping` returns `PONG` from the app server.
- [ ] `curl -I https://<domain>` (once anything is deployed, even a placeholder) returns a valid TLS handshake with no certificate warning.
- [ ] `curl -I http://<domain>` redirects to `https://`.
- [ ] The certificate's auto-renewal mechanism is confirmed configured (check its next scheduled run — Certbot's systemd timer, or the managed platform's own renewal dashboard).

---

## Phase 2 — Configure the production environment file

**Do not run `composer setup`** on the server — its `setup` script copies `.env.example` verbatim into `.env` *only if `.env` doesn't already exist*, which is safe, but the script also runs `migrate --force` and `npm run build` in one shot with no chance to review `.env` first. Instead:

1. Create `.env` on the server by hand (or via your platform's secrets manager), starting from `.env.example` as a **template of variable names only** — every value below must be a real, production value, never copied from the example.
2. Set exactly these variables (grouped by concern; every name here is taken directly from `backend/.env.example`):

```bash
# Core
APP_NAME=Atlas
APP_ENV=production
APP_KEY=                      # generate with: php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://<your-real-domain>

# Reverse proxy trust — REQUIRED. Left unset, HTTPS detection, HSTS,
# client IP resolution, and IP-keyed rate limiting all silently misbehave.
# Set to the proxy's IP/CIDR, or "*" only if the proxy's own IP genuinely
# isn't fixed (e.g. most managed load balancers).
TRUSTED_PROXIES=<proxy-ip-or-*>

# Logging — switch off the non-rotating default before real traffic
LOG_CHANNEL=stack
LOG_STACK=daily                # NOT "single" — single grows unbounded
LOG_LEVEL=warning               # debug is too noisy for production
LOG_DEPRECATIONS_CHANNEL=null

# Database
DB_CONNECTION=pgsql
DB_HOST=<real-db-host>
DB_PORT=5432
DB_DATABASE=<real-db-name>
DB_USERNAME=<real-db-user>
DB_PASSWORD=<real-db-password>

# Sessions — force secure cookies explicitly; do not rely on auto-detection
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=true      # NOT in .env.example today — set it anyway
SESSION_DOMAIN=<your-real-domain-or-null>

# Queue — database-backed today (a deliberate, documented middle setting;
# see Production-Deployment-Audit.md — not a blocker)
QUEUE_CONNECTION=database

# Cache / Redis
CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=<real-redis-host>
REDIS_PORT=6379
REDIS_PASSWORD=<real-redis-password>   # NOT "null" — set a real password

# Email — real provider credentials from Phase 0
MAIL_MAILER=postmark            # or your chosen live provider path
MAIL_FROM_ADDRESS=<real-sending-address>
MAIL_FROM_NAME="${APP_NAME}"
POSTMARK_API_KEY=<real-postmark-server-token>
POSTMARK_MESSAGE_STREAM_ID=<real-message-stream-id>
# If using SendGrid instead of Postmark, set the real SendGrid credential(s)
# required by the app's provider-aware email connect flow.

# Error tracking — real vendor from Phase 0 (see Phase 7 for the one
# additional code step this still requires)
ERROR_TRACKING_DRIVER=sentry           # or your chosen vendor's driver key
ERROR_TRACKING_DSN=<real-dsn>

# AI provider (already in use — carry forward from wherever dev/staging
# currently sources this; not new to this launch)
ANTHROPIC_API_KEY=<real-key>
ANTHROPIC_MODEL=claude-sonnet-4-6

# Meta (Facebook/Instagram) — only needed if Customer 1 will connect Meta
# during the beta; leave blank otherwise, per docs/product/Channel-
# Capability-Matrix.md's real-vs-simulated distinction
META_APP_ID=
META_APP_SECRET=
META_REDIRECT_URI=

# Twilio — only if SMS is explicitly part of Customer 1 scope
TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
```

3. `chmod 600 .env` and confirm it's owned by the deploy user, not world-readable.

### Verification checklist — Phase 2

- [ ] Every variable above is set to a **real** value — grep the file for any leftover blank credential (`grep -E '=$' .env` should show nothing you didn't intend to leave blank, e.g. Meta if unused).
- [ ] `php artisan tinker --execute="echo config('app.env');"` prints `production`.
- [ ] Requesting a URL that deliberately throws (e.g. a nonexistent route with `APP_DEBUG` misconfigured) shows a generic error page, never a stack trace.
- [ ] `.env` is not committed and not world-readable (`ls -la .env` shows restrictive permissions).

---

## Phase 3 — Deploy the application

1. Clone the repository to the server at the deploy path (matching `infrastructure/supervisor/atlas-worker.conf`'s assumed path, `/var/www/atlas/backend`, or update that file to match your real path) — this is the one first-time-only step; every subsequent deploy starts from an existing checkout.
2. Run [Deployment-Runbook.md](../deployment/Deployment-Runbook.md) §2 (build/install), §3 (migrations), and §4 (cache/config) in full — this is the first real execution of that repeatable procedure, and every deploy after this one follows the same document.
3. Create the first superadmin account (needed for Phase 7's `FailedJobResource` Filament panel and any admin-only visibility): `php artisan tinker --execute="\App\Models\User::where('email','<your-real-admin-email>')->update(['is_superadmin' => true]);"` — the user must already exist (register normally first, then run this). One-time only; not part of the repeatable deploy procedure.

### Verification checklist — Phase 3

- [ ] [Deployment-Runbook.md](../deployment/Deployment-Runbook.md) §2/§3/§4's own verification checklists are all green.
- [ ] Visiting `https://<domain>/admin` shows the Filament login page, and your superadmin account can log in.
- [ ] **A second, independent deploy has been performed** (re-run Deployment-Runbook.md's steps with no changes) — proves the process is repeatable, not a one-time fluke. This is the literal thing SCRUM-47 exists to make possible.

---

## Phase 4 — Install queue workers

1. Install Supervisor if not already present (`apt install supervisor` or platform equivalent).
2. Copy [`infrastructure/supervisor/atlas-worker.conf`](../../infrastructure/supervisor/atlas-worker.conf) to `/etc/supervisor/conf.d/atlas-worker.conf`, adjusting the `command=` paths if your deploy path differs from `/var/www/atlas/backend`.
3. Create the log directory it expects: `mkdir -p /var/log/atlas && chown www-data:www-data /var/log/atlas`.
4. `supervisorctl reread && supervisorctl update && supervisorctl start all`.

**A critical bug in this exact file was found and fixed under SCRUM-41**: every command previously passed the queue name as `queue:work`'s connection argument instead of via `--queue=`, which would have meant these workers never processed a single job. If you're looking at a checked-out copy older than that fix, pull latest before installing — see [Queue-Workers.md](../deployment/Queue-Workers.md) for the full story and the exact, corrected worker layout (5 process groups, one per queue, not one shared pool).

On every deploy *after* this first install, workers are restarted via `php artisan queue:restart` — [Deployment-Runbook.md](../deployment/Deployment-Runbook.md) §5, not a Supervisor reinstall.

### Verification checklist — Phase 4

- [ ] `supervisorctl status` shows all 5 `atlas-worker-*` process groups as `RUNNING`.
- [ ] Dispatch a real, harmless job (e.g. trigger a settings sync) and confirm it's picked up and completes — check via Filament or `php artisan queue:monitor high,ai,default,observations,maintenance`.
- [ ] **Kill a worker process directly** (`kill <pid>` of one `atlas-worker-*` child) and confirm Supervisor restarts it automatically within seconds (`supervisorctl status` shows it `RUNNING` again with a new PID/uptime).
- [ ] Current queue depth is checked and at baseline, not backed up (`php artisan queue:monitor`).

---

## Phase 5 — Install the scheduler

1. Copy the entry from [`infrastructure/cron/atlas-scheduler`](../../infrastructure/cron/atlas-scheduler) into the deploy user's crontab (`crontab -e` as the user matching Supervisor's `user=www-data`), or drop it into `/etc/cron.d/` per the file's own comment block.
2. Confirm the path inside the entry matches your real deploy path.

This is a one-time install, per server — see [Deployment-Runbook.md](../deployment/Deployment-Runbook.md) §6 for why a routine deploy doesn't need to touch this again.

### Verification checklist — Phase 5

- [ ] Wait at least 2 minutes, then confirm `schedule:run` actually fired — check `storage/logs/laravel.log` for scheduler activity, or add a temporary log line to confirm, then remove it.
- [ ] `php artisan schedule:list` shows all 7 scheduled entries.
- [ ] The recurring integration sync (`atlas:sync-due-integrations`) has fired at least once on schedule and produced its expected effect (check a real or test company's `Integration.last_run_at`).
- [ ] Opportunity expiration has fired at least once on schedule.
- [ ] Confirm this is installed on **exactly one** server if you ever run more than one app instance — a duplicated cron entry across servers would double-fire every scheduled job (not a concern for a single-server Customer 1 launch, but verify now if that's not your topology).

---

## Phase 6 — Configure backups and perform a real restore drill

This is, per [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md), the single most commonly skipped step — "we have backups" and "we have restored from a backup" are different claims, and only the second is acceptable before Customer 1.

1. Schedule [`infrastructure/backup/atlas-db-backup.sh`](../../infrastructure/backup/atlas-db-backup.sh) via cron, e.g. nightly:
   ```bash
   0 3 * * * DB_HOST=<host> DB_PORT=<port> DB_DATABASE=<db> DB_USERNAME=<user> DB_PASSWORD=<pass> \
     /var/www/atlas/backend/../infrastructure/backup/atlas-db-backup.sh /var/backups/atlas
   ```
   Set `BACKUP_RETENTION_DAYS` and, if off-site storage is provisioned, `BACKUP_OFFSITE_COMMAND` (e.g. an `aws s3 cp {file} s3://...` template) as additional env vars on the same line.
2. Confirm the first scheduled backup actually completes (check the destination directory or off-site bucket — not just that cron fired).
3. Verify the dump isn't corrupt: `./infrastructure/backup/atlas-db-verify.sh /var/backups/atlas/<latest-dump>`.
4. **Perform a real restore drill against a scratch database**, not production:
   ```bash
   createdb atlas_restore_drill
   DB_HOST=<host> DB_PORT=<port> DB_DATABASE=atlas_restore_drill DB_USERNAME=<user> DB_PASSWORD=<pass> \
     ./infrastructure/backup/atlas-db-restore.sh /var/backups/atlas/<latest-dump> --yes --confirm-database=atlas_restore_drill
   ```
5. Spot-check the restored data: connect to `atlas_restore_drill` and confirm row counts / a known record match production at backup time.
6. Drop the scratch database once confirmed: `dropdb atlas_restore_drill`.
7. Schedule a recurring drill (at minimum weekly during the beta, per Private-Beta-Execution.md §3) — repeat steps 3–6 on that cadence.

### Verification checklist — Phase 6

- [ ] A real, scheduled backup has completed against the real production database (checked in the destination, not assumed).
- [ ] `atlas-db-verify.sh` passed against that real backup.
- [ ] **A real restore into a scratch database has been performed and the data spot-checked correct** — this is the non-negotiable item.
- [ ] The restore procedure above is written down somewhere a second person could follow without asking you — this document is that; confirm a teammate can actually execute it standalone.
- [ ] Off-site backup storage (if applicable) is confirmed receiving files, not just configured.

---

## Phase 7 — Monitoring, error tracking, uptime

1. **Error tracking vendor activation** (the one remaining code step, per the Go/No-Go review): `composer require sentry/sentry-laravel` (or your chosen vendor), implement `App\ErrorTracking\SentryErrorTracker implements ErrorTracker` wrapping the vendor SDK's capture call, add a `'sentry' => new SentryErrorTracker(...)` arm to `AppServiceProvider::register()`'s `ErrorTracker` binding `match`, then set `ERROR_TRACKING_DRIVER=sentry` + the real DSN in `.env` (already done in Phase 2 if you had the DSN by then).
2. Deploy that one small code change (its own deploy — don't bundle with an unrelated change).
3. Set up uptime monitoring (Phase 0's account) against `https://<domain>/api/health`, polling every ~60 seconds. Configure it to alert a **named person**, not a shared/team inbox.
4. Configure the error tracker's alerting to also notify a named person.

### Verification checklist — Phase 7

- [ ] Deliberately throw a test exception in production (a temporary debug route, removed immediately after) and confirm it appears in the error tracker's dashboard within a few minutes.
- [ ] Deliberately trigger the uptime monitor's alert (e.g. briefly block the health endpoint or stop PHP-FPM for a moment) and confirm the alert is actually received by the named person, not just "should have sent."
- [ ] Visit `/admin/failed-jobs` (Filament `FailedJobResource`) as the superadmin and confirm the panel loads — it will be empty until a job actually fails, which is correct.

---

## Phase 8 — Transactional email / outbound messaging verification

Code and config for this were completed in Phase 2 (real chosen provider credentials). This phase is pure verification.

1. Confirm the chosen email provider's sender/domain verification is complete (SPF/DKIM for Postmark; equivalent sender/domain verification for SendGrid).
2. Trigger a real password-reset email to a real inbox you control: register (or use an existing) test account, request a password reset from the real production URL, and confirm the email **arrives in the inbox, not spam**, within a few minutes.
3. Complete the reset end-to-end: click the link, set a new password, log in.
4. Send a real test email via Settings → Email → "Send test" to a second real inbox and confirm delivery.
5. If SMS/Twilio is part of Customer 1 scope, connect Twilio in Settings and send one real test SMS to a phone you control.

### Verification checklist — Phase 8

- [ ] The chosen email provider's sender/domain verification is confirmed.
- [ ] Password reset email received in a real inbox, not spam, within minutes.
- [ ] Reset flow completed end-to-end (request → email → link → new password → login).
- [ ] Settings → Email test-send received in a real inbox.
- [ ] If SMS is in scope, Settings → SMS test-send is received on a real phone.
- [ ] `ProductionMailerGuard` is confirmed active by design (already true if `APP_ENV=production` and `MAIL_MAILER=postmark`, per Phase 2) — no separate action, just confirm the env values are what Phase 2 set.

---

## Phase 9 — Legal pages and support runbook

Both are Go/No-Go gate items with zero code dependency — do in parallel with earlier phases if convenient, but confirm before Phase 10.

1. Publish a real privacy policy at `https://<domain>/privacy` and terms of service at `https://<domain>/terms`. **Neither exists in this codebase today** — this is net-new operator content (writing/adapting the legal text itself is outside engineering scope; involve whoever handles that for the business).
2. Wire the registration flow to require agreement to both before account creation.
3. Write a short operational runbook (a plain doc, not code) covering at minimum: crawl failure, AI provider outage, a queue worker down, a failed-job spike. For each: what it looks like (which alert/log fires), first response action, and escalation if unresolved in N minutes.
4. Define the support channel (a real email alias or Slack channel) and confirm someone is actually watching it.

### Verification checklist — Phase 9

- [ ] `curl -I https://<domain>/privacy` and `/terms` both return 200.
- [ ] Registration genuinely blocks without checking the agreement checkbox (test it).
- [ ] A teammate who did **not** write the runbook can use it to correctly diagnose one of the four scenarios above (pick one and deliberately induce it as a drill, e.g. kill a queue worker and have them find + fix it using only the runbook).
- [ ] The support channel is confirmed reachable and someone responds to a test message within the committed SLA (24 hours, per Private-Beta-Execution.md).

---

## Phase 10 — Post-deploy verification (full onboarding run-through)

Run this yourself, as a real test account, on the **real production environment** — not local dev.

1. Register a new test account and company.
2. Enter a real website URL and confirm the crawl completes within the expected window, producing Facts and Knowledge, and the Digital Twin reaches `active`.
3. Complete the Marketing Assets / Marketing Presence onboarding step.
4. Confirm at least one Recommendation is generated with all four rationale fields populated.
5. Approve it — confirm the confirmation dialog's per-channel language is accurate for whatever capability state each asset's channel is actually in (per [Channel-Capability-Matrix.md](../product/Channel-Capability-Matrix.md); this was fixed in the recommendation/publishing UI work, verify it still holds on the real deploy).
6. **Measure elapsed time from URL entry to visible first Recommendation** on production — this is the number to compare against the 10-minute north-star metric.
7. **Prove multi-tenancy, don't assume it**: onboard a second test company side by side and confirm neither can see the other's data anywhere — dashboard, Filament, API.
8. If any real channel (WordPress/Meta/provider-aware Email/Twilio SMS) will be connected for Customer 1, connect one for real and confirm the connect flow's live ping-before-persist verification actually rejects a deliberately wrong credential, then accepts the real one.

### Verification checklist — Phase 10

- [ ] Full onboarding run-through completed successfully on production (real crawl, real recommendation, real approval).
- [ ] Time-to-first-recommendation measured and recorded.
- [ ] Two test companies confirmed fully isolated from each other.
- [ ] At least one real channel connect flow verified live (not just HTTP-mocked in a test) if Customer 1 will use one.
- [ ] Publishing/capability UI language matches reality exactly on the real deploy — no claim of a live send where none exists, no "Connected" badge for a channel that isn't.

---

## Final Go/No-Go Gate

Before inviting CBB Auctions, confirm **every** checkbox in every phase above is checked, then confirm the items below (restating [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md) §4's gate — that document is the authority if these ever diverge):

- [ ] Infrastructure (Phases 1–5) fully verified true in production, not "should be true."
- [ ] A restore from a real backup has actually been performed and the data checked (Phase 6).
- [ ] A full onboarding run-through succeeded on production (Phase 10).
- [ ] Multi-tenancy proven with two side-by-side test companies (Phase 10).
- [ ] Publishing reality documented and customer-facing copy matches exactly (Phase 10).
- [ ] Privacy policy and terms of service published and required at registration (Phase 9).
- [ ] A support channel is defined, documented, and actually watched (Phase 9).
- [ ] An operational runbook exists and a second person has successfully used it (Phase 9).
- [ ] Beta communications are ready: invite email and Getting Started guide drafted, both stating plainly which channels are real and which are simulated for CBB Auctions specifically.

**If any box anywhere in this document is unchecked, the decision is No-Go.** No partial credit — per Private-Beta-Execution.md's own words, an unverified item is exactly as disqualifying the week before launch as the month before.

Once every box is checked: send the CBB Auctions invite.

---

## First week: incident and rollback notes

Run [Private-Beta-Execution.md](../plans/Private-Beta-Execution.md) §3 (daily checklist) and §5 (first-week cadence) starting the day of the invite. The notes below are specific to this launch, not a restatement of that section.

### Rollback

- **Application code:** redeploy the previous known-good commit (`git checkout <prev-sha>` on the server, repeat Phase 3's steps 2–6). No automated rollback pipeline exists yet — this is manual. Verify Phase 3's checklist again after rolling back, same as any deploy.
- **Database migration:** every migration has a `down()` method; `php artisan migrate:rollback` reverses the most recent batch. **Check the migration's `down()` before rolling back** if it's a data-remapping migration, not just a schema change — a lossy `down()` can discard information a naive rollback wouldn't warn you about.
- **A bad deploy discovered fast:** prefer rolling back the code over trying to hot-fix in production. A second successful deploy was already proven possible in Phase 3 — use that same muscle to go backward, not just forward.

### Incident response, first week

- **Site down / health check failing:** check Supervisor (`supervisorctl status`) and PHP-FPM first — most likely cause is a crashed process, not the database. `/api/ready`'s per-component breakdown tells you which dependency (database/cache/queue) is actually unhealthy if the app itself is up.
- **Queue backing up:** check `php artisan queue:monitor` and Supervisor logs (`/var/log/atlas/worker-*.log`) for the specific queue. A stuck `ai` queue usually means an Anthropic outage or rate-limit — check `/admin/failed-jobs` for the actual exception before assuming infrastructure.
- **A failed job spike:** `/admin/failed-jobs` shows queue, job class, and exception summary per row. Retry individually — no bulk action exists by design (a bulk retry risks re-triggering whatever caused the original failure all at once).
- **A customer reports data they shouldn't see, or can't see their own:** treat as a P0 regardless of how it initially looks — re-verify the tenant-isolation checks from Phase 10 immediately, do not wait for the daily checklist.
- **Escalation:** the person who owns error-tracking alerts (Phase 7) and the person who owns the support channel (Phase 9) may not be the same person — make sure both know who to loop in for anything they can't resolve within the 24-hour SLA themselves.

### End of week 1

- Confirm backups completed every day and at least one additional restore spot-check was performed (beyond Phase 6's initial drill).
- Read back through every failed job and support issue from the week — repeating root causes become next week's top priority, ahead of new feature work.
- Decide explicitly whether to invite the next beta customer(s) or pause and fix something first, per Private-Beta-Execution.md §5's own guidance: a beta with fewer customers going well beats more customers half-watched.
- Update [STATUS.md](../STATUS.md) with what happened this week.

---

## Addendum — 2026-07-20: channel breadth widened, launch posture stayed narrow

Atlas's local worktree now supports:

- provider-aware email (`postmark` + `sendgrid`)
- a real Twilio connect/test flow
- a real single-destination SMS publisher in code

This runbook still recommends the narrowest honest Customer 1 posture:

- treat **email + WordPress** as the primary real outbound channels to verify first
- include **SMS** only if the single-destination scope is explicitly acceptable for Customer 1
- do not broaden the Customer 1 promise just because more connector code exists locally
