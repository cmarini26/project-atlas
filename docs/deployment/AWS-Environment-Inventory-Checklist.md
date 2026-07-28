# AWS Environment Inventory Checklist

**Jira:** SCRUM-32 (Sprint 1) — Choose hosting topology and provision core services.
**Purpose:** turn an already-provisioned AWS server into a fully-inventoried Atlas production target. This checklist is specifically for the current situation: **the server already exists**, but we need to determine what is already wired, what is missing, and what must be done before the first real Atlas deploy.

**Use this before provisioning anything else.** The goal is to avoid guessing whether PostgreSQL, Redis, DNS, TLS, or runtime dependencies already exist. Complete this checklist top-to-bottom, filling in the facts from the real AWS environment.

**Companion docs:**
- [Production-Topology.md](Production-Topology.md)
- [Environment-Variables.md](Environment-Variables.md)
- [Queue-Workers.md](Queue-Workers.md)
- [Scheduler-Operations.md](Scheduler-Operations.md)
- [Deployment-Runbook.md](Deployment-Runbook.md)
- [Configuration-Sanity-Check.md](Configuration-Sanity-Check.md)

---

## 0. Target state this checklist is driving toward

Atlas beta needs, at minimum:

- 1 reachable Linux app server
- PostgreSQL reachable from that app server
- Redis reachable from that app server
- a real production `.env`
- nginx + PHP-FPM
- Supervisor-managed Atlas workers
- cron running `php artisan schedule:run` every minute
- DNS + TLS for the real app domain
- successful health checks on `/up`, `/api/health`, `/api/ready`, and `/api/live`

This checklist does **not** assume AWS-specific managed services must be used, but it does require us to prove what exists.

---

## 1. Server identity and access

Record the real facts about the already-provisioned AWS server.

| Item | How to verify | Record the result |
|---|---|---|
| Public IP / hostname | AWS console or `ssh` target you already use | |
| EC2 instance ID | AWS console | |
| Region / AZ | AWS console | |
| OS / distro | `cat /etc/os-release` | |
| CPU / RAM / disk size | `nproc && free -h && df -h /` | |
| SSH access confirmed | actually SSH in | |
| SSH user | the real login user (`ubuntu`, `ec2-user`, etc.) | |
| sudo access confirmed | `sudo -v` | |
| app deploy path chosen | e.g. `/var/www/atlas/backend` | |

### Exit criteria — §1
- [ ] You can SSH into the box
- [ ] You know the OS and package-management family
- [ ] You know where Atlas will live on disk
- [ ] You know whether this is sized realistically for web + workers + scheduler

---

## 2. Runtime baseline on the server

Atlas cannot be deployed until the server has the right runtime pieces.

| Component | How to verify | Expected / note |
|---|---|---|
| PHP | `php -v` | PHP 8.3+ preferred; must match project requirements |
| Composer | `composer --version` | installed and runnable |
| Node | `node -v` | required if building assets on-box |
| npm | `npm -v` | required if building assets on-box |
| nginx | `nginx -v` | installed if this box terminates HTTP/TLS |
| PHP-FPM | `systemctl status php8.3-fpm` (or actual unit) | installed and manageable |
| Supervisor | `supervisorctl version` | installed for workers |
| cron | `systemctl status cron` or `crond` | installed and running |
| git | `git --version` | installed |
| unzip | `unzip -v` | installed |
| psql client | `psql --version` | installed for DB connectivity verification |
| redis-cli | `redis-cli --version` | installed for Redis verification |

### Required PHP extensions to verify
Run `php -m` and confirm these are present if required by the app/runtime:

- `pdo_pgsql`
- `pgsql`
- `redis`
- `mbstring`
- `xml`
- `curl`
- `openssl`
- `fileinfo`
- `tokenizer`
- `ctype`
- `json`

### Exit criteria — §2
- [ ] The server has the full Atlas runtime installed
- [ ] Missing packages/extensions are known explicitly
- [ ] You know whether frontend assets will be built on-box or elsewhere

---

## 3. Application dependencies outside the EC2 box

This is the most important discovery section. Atlas is not ready with "just a server."

### 3.1 PostgreSQL

| Item | How to verify | Record the result |
|---|---|---|
| PostgreSQL exists | AWS console / provider console | |
| Service type | RDS / self-managed / other | |
| Hostname | real DB host | |
| Port | usually 5432 | |
| Database name exists | check real DB | |
| App user exists | check real DB user | |
| Security-group access from app server exists | test from app box | |
| Connectivity proven | `psql -h <host> -U <user> -d <db> -c '\conninfo'` | |

### 3.2 Redis

| Item | How to verify | Record the result |
|---|---|---|
| Redis exists | AWS console / provider console | |
| Service type | ElastiCache / self-managed / other | |
| Hostname | real Redis host | |
| Port | usually 6379 | |
| Auth required | yes/no | |
| Connectivity proven | `redis-cli -h <host> -p <port> ping` (plus auth if needed) | |
| App server can reach Redis | verify from EC2 box, not your laptop | |

### Critical interpretation — §3
If either PostgreSQL or Redis is **not** already provisioned and reachable, `SCRUM-32` is **not** close to done. Those are not optional nice-to-haves.

### Exit criteria — §3
- [ ] PostgreSQL is proven reachable from the AWS server
- [ ] Redis is proven reachable from the AWS server
- [ ] Any missing managed service is now a known blocker, not an assumption

---

## 4. Domain, DNS, and TLS

Atlas needs a real public entrypoint.

| Item | How to verify | Record the result |
|---|---|---|
| Production domain chosen | human decision | |
| DNS provider | Route 53 / other | |
| App hostname chosen | e.g. `app.example.com` | |
| DNS record already created | DNS console | |
| DNS resolves to the server / LB | `dig +short <domain>` | |
| TLS termination plan | nginx on-box / ALB / other | |
| Certificate plan | Let's Encrypt / ACM / other | |
| HTTP→HTTPS redirect plan | nginx or proxy config | |

### Important Atlas-specific note
You will need a final real domain before some settings can be finalized correctly, including:
- `APP_URL`
- `META_REDIRECT_URI`
- monitoring targets
- browser-visible cookie/security behavior

### Exit criteria — §4
- [ ] Real app domain chosen
- [ ] DNS ownership/path is known
- [ ] TLS termination approach is known
- [ ] You know whether the app box is directly public or sits behind an AWS load balancer/proxy

---

## 5. Network and security baseline

| Item | How to verify | Record the result |
|---|---|---|
| SSH ingress locked down | security group rules | |
| HTTP/HTTPS ingress present as intended | security group rules | |
| DB ingress restricted to app source only | security groups / DB rules | |
| Redis ingress restricted to app source only | security groups / Redis rules | |
| Outbound internet available if needed for builds/APIs | test from server | |
| Reverse proxy IP/CIDR known for `TRUSTED_PROXIES` | infra knowledge / AWS config | |

### Exit criteria — §5
- [ ] You know what must go into `TRUSTED_PROXIES`
- [ ] DB/Redis are not broadly exposed
- [ ] The app server can reach external dependencies it actually needs

---

## 6. Production environment file readiness

Use [Environment-Variables.md](Environment-Variables.md) as the source of truth.

Confirm whether you already have real values for:

### Core
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_KEY`
- `APP_URL`
- `TRUSTED_PROXIES`

### Sessions
- `SESSION_SECURE_COOKIE=true`
- `SESSION_DRIVER`
- `SESSION_DOMAIN` if needed

### Database
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

### Redis
- `REDIS_HOST`
- `REDIS_PORT`
- `REDIS_PASSWORD`
- `REDIS_DB`
- `REDIS_CACHE_DB`

### Queue / scheduler related
- `QUEUE_CONNECTION`
- `DB_QUEUE_RETRY_AFTER`

### Logs / monitoring
- `LOG_STACK`
- `LOG_LEVEL`
- `LOG_DAILY_DAYS`
- `ERROR_TRACKING_DRIVER`
- `ERROR_TRACKING_DSN`

### Mail / external providers
- `MAIL_MAILER`
- `POSTMARK_API_KEY`
- `POSTMARK_MESSAGE_STREAM_ID`
- `ANTHROPIC_API_KEY`
- any live WordPress / Meta values actually needed for the first beta path

### Exit criteria — §6
- [ ] You know whether a real production `.env` can be assembled today
- [ ] Missing credentials are identified explicitly
- [ ] Placeholder values are not being confused for real readiness

---

## 7. First-deploy host setup (only once per server)

Once §§1–6 are satisfied, the app box still needs the Atlas-specific operational artifacts installed.

### Queue workers
Install and adapt:
- `infrastructure/supervisor/atlas-worker.conf`

Verify the exact corrected queue worker pattern from [Queue-Workers.md](Queue-Workers.md):
- use `queue:work --queue=<name>`
- do **not** pass bare queue names as positional arguments

### Scheduler
Install:
- `infrastructure/cron/atlas-scheduler`

### Required verification
- `supervisorctl status`
- `crontab -l`
- `php artisan schedule:list`

### Exit criteria — §7
- [ ] Worker config is installed on the real server
- [ ] Cron entry exists on the real server
- [ ] You have a real location for logs/process status

---

## 8. First deploy readiness gate

You are ready for the first real Atlas deploy only if all of the following are true:

- [ ] SSH access works
- [ ] runtime dependencies are installed
- [ ] PostgreSQL is reachable from the app server
- [ ] Redis is reachable from the app server
- [ ] domain + TLS plan is known
- [ ] production `.env` can be assembled with real values
- [ ] worker + scheduler installation path is known
- [ ] deploy path on disk is chosen
- [ ] `TRUSTED_PROXIES` value can be set correctly

If any item above is false, stop and resolve it before attempting the deploy runbook.

---

## 9. Recommended next execution order after this checklist

If this inventory shows the prerequisites exist, the next tickets should execute in this order:

1. **SCRUM-33** — Create production network and access baseline
2. **SCRUM-34** — Verify app-to-database and app-to-Redis connectivity
3. **SCRUM-36** — Load production secrets into the hosting environment
4. **SCRUM-38 / SCRUM-39 / SCRUM-40** — domain, SSL, proxy correctness
5. **SCRUM-42 / SCRUM-43** — worker supervision install + prove jobs process
6. **SCRUM-44 / SCRUM-45 / SCRUM-46** — scheduler install + verify + documented checks
7. **SCRUM-48** — first production deploy
8. **SCRUM-49** — second repeat deploy validation

---

## 10. What to report back after filling this out

When this checklist is completed, summarize in exactly this format:

- **Server OS / size:**
- **DB status:** exists / missing / reachable / blocked
- **Redis status:** exists / missing / reachable / blocked
- **Domain/DNS status:**
- **TLS status:**
- **Runtime missing pieces:**
- **Can we assemble production `.env` today?:** yes/no
- **Biggest blocker remaining:**
- **Recommended next Jira ticket to execute:**

That summary is enough to decide whether `SCRUM-32` should stay open, be split further, or move toward completion.
