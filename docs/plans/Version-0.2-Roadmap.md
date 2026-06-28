# Version 0.2 Roadmap

**Status:** Planning
**Planned start:** 2026-07-01
**Goal:** Take Atlas from a fully functional local pipeline to a live, observable, customer-onboarded product with real publishing and real feedback.

---

## Where We Are

Version 0.1 delivered a complete end-to-end pipeline:

- Observe → Understand → Decide → Recommend → Prepare → Approve → Execute → Measure → Learn
- Full customer-facing dashboard (Inertia.js + Vue 3 + TypeScript)
- 581 tests, PHPStan level 8 — 0 errors, Pint clean
- Anthropic AI provider live

**What 0.1 does not have:**
- A production environment
- Any monitoring or error visibility
- Real publishing channels (only log-to-file)
- Real analytics data ingestion (only fake/log providers)
- A customer you can reliably onboard
- A demo environment for sales conversations
- A way to hear from customers in-product

Version 0.2 closes all eight of these gaps. Everything below is sequenced by dependency — not by priority ranking alone. Infrastructure must exist before you can run anything. Error visibility must exist before you trust what runs. The learning loop closes only after real publishing and real analytics both work.

---

## Milestone Overview

| # | Milestone | Goal | Dependency |
|---|-----------|------|------------|
| 11 | Production Infrastructure | First live environment | None |
| 12 | Error Reporting | See failures before customers do | M11 |
| 13 | Operational Telemetry & Monitoring | Queue health, job rates, uptime | M12 |
| 14 | Demo Environment | Shareable, seeded, sales-ready | M13 |
| 15 | Customer Onboarding Improvements | First real company onboarded smoothly | M14 |
| 16 | Real Email Publishing | First real channel | M11 |
| 17 | Real Social Publishing | Meta (Instagram + Facebook) | M16 |
| 18 | Real Analytics Integrations | Close the learning loop | M17 |
| 19 | Early Customer Feedback Tooling | Hear from customers in-product | M15 |

---

## Milestone 11 — Production Infrastructure

**Goal:** Atlas runs on a real server with real data and real traffic possible.

**Rationale:** Nothing else in V0.2 is possible without this. Error reporting has nowhere to report to. The demo environment needs a host. Real publishers need real credentials. This unblocks every other milestone.

**Recommended stack:**
- Laravel Forge + DigitalOcean (Droplet, $24/mo, 2 CPU / 4 GB RAM)
- PostgreSQL 16 managed (DigitalOcean Managed Database or on-droplet with daily backups)
- Redis 7 (on-droplet; Forge manages restart on crash)
- Supervisor managing all 5 queue workers (`high`, `ai`, `default`, `observations`, `maintenance`)
- Let's Encrypt TLS via Forge (automatic renewal)
- Zero-downtime deploys via Forge deploy scripts (`php artisan down --retry=5 --secret=X` → build → migrate → `php artisan up`)

**Deliverables:**

| Item | Notes |
|------|-------|
| Forge site provisioned | `app.projectatlas.io` (or equivalent) |
| `.env.production` | `APP_KEY`, `DB_*`, `REDIS_*`, `ANTHROPIC_API_KEY`, `MAIL_*`, `APP_URL` |
| PostgreSQL RLS applied | Row-level security on all multi-tenant tables; per `docs/technical/Database.md` spec |
| Queue workers running | All 5 queues; `restart_policy=always`; Forge notifies on worker crash |
| Health check endpoint live | `GET /api/health` returns `{"status": "ok"}` — already implemented in M9.5 |
| GitHub Actions CI triggered | First PR against `main` runs Pint + PHPStan + PHPUnit in CI |
| Deploy script committed | `scripts/deploy.sh`; reviewed for zero-downtime |
| Staging environment | Separate Forge site (`staging.projectatlas.io`); same stack; used for pre-deploy verification |

**Out of scope:** Kubernetes, auto-scaling, CDN, object storage for user files.

---

## Milestone 12 — Error Reporting

**Goal:** Every unhandled exception and job failure is visible to the team within seconds, not days.

**Rationale:** Without error reporting, production failures are invisible. You will not know Atlas is broken until a customer tells you. This should be installed the day the production environment exists.

**Recommended tool:** [Flare](https://flareapp.io) — Laravel-native, integrates with `log.php` out of the box, understands Laravel exceptions, queues, and Livewire. Lower noise than Sentry for a Laravel-first team. First 3,000 errors/month free.

Alternative: Sentry (more widely known, more ecosystem integrations, free tier).

**Deliverables:**

| Item | Notes |
|------|-------|
| Flare (or Sentry) account created | Project-level API key stored in `.env.production` |
| `FLARE_KEY` wired into `config/logging.php` | Flare channel added to `stack` in production; does not affect test or local |
| Exception grouping reviewed | `App\Exceptions\Handler::$dontReport` updated to exclude expected exceptions (`ModelNotFoundException`, `AuthorizationException`, `ValidationException`) |
| Job failure alerts | `Queue::failing()` hook or Flare's queue integration captures failed job context |
| Slack (or email) alert on first error | Team notified immediately; alert channel designated |
| `LOG_LEVEL=warning` in production | Suppress `info`-level log noise from Flare |
| `docs/technical/ErrorReporting.md` | One-page runbook: how to triage an error in Flare, how to mark as resolved, when to escalate |

---

## Milestone 13 — Operational Telemetry & Monitoring

**Goal:** The team has a live dashboard for queue health, job failure rates, slow queries, uptime, and scheduled job heartbeats.

**Rationale:** Error reporting catches individual exceptions. Telemetry catches system health: a queue that never drains, a scheduled job that silently stopped running, a database query that regresses from 50ms to 3s. These are invisible without dedicated instrumentation.

**Deliverables:**

| Item | Notes |
|------|-------|
| Laravel Pulse installed | `laravel/pulse` in `composer.json`; migrations run; dashboard at `/pulse` |
| Pulse dashboard gated | Filament superadmin gate or IP allowlist on `/pulse` — not public |
| Queue metrics visible | Pulse Queue watcher: backlog depth, throughput, wait time per queue |
| Slow query detection | Pulse DB watcher: queries over 500ms logged |
| Scheduled job heartbeat | `CheckSchedule` command or similar; Pulse (or external heartbeat service like [Better Uptime](https://betteruptime.com)) pings on each scheduled job run |
| Uptime monitoring | Uptime check on `GET /api/health`; alert if down for > 2 minutes; Oh Dear or Better Uptime |
| Queue failure rate alert | Pulse threshold alert or manual check: if `failed_jobs` table grows beyond 10 entries, alert team |
| `docs/technical/Telemetry.md` | What each metric means, where to look, when to page |

**Out of scope:** Distributed tracing, custom metrics dashboards (Grafana), log aggregation (Datadog, Papertrail).

---

## Milestone 14 — Demo Environment

**Goal:** A shareable, realistic, read-annotated Atlas environment that can be shown to prospects without touching real customer data.

**Rationale:** Before improving onboarding, you need something to show. Every sales conversation needs a working demo. Building the demo also forces you to validate that the full pipeline runs correctly against realistic data — it's an integration test for the real system.

**Approach:** A separate Forge site (`demo.projectatlas.io`) with a dedicated PostgreSQL database. Demo data is seeded from a deterministic `DemoSeeder`. The demo company is **Mountain City Comics** — a fictional auction house modeled after CBB Auctions.

**Deliverables:**

| Item | Notes |
|------|-------|
| `demo.projectatlas.io` provisioned | Separate Forge site; shares staging server or has its own $6/mo droplet |
| `database/seeders/DemoSeeder.php` | Idempotent seeder: `Company`, `CompanyMembership`, `DigitalTwin`, `Catalog`, `CatalogItem` (20 items), `Facts` (15), `Knowledge` (8), `Opportunities` (5 open), `Decision`, `Campaign`, `Recommendation` (1 pending), `ContentAsset` (3), `Execution` (2 completed), `CampaignKpiSnapshot` (1 final) |
| Demo login credentials | `demo@projectatlas.io` / `demo-password`; single owner membership; displayed on login page |
| Reset command | `php artisan demo:reset` — wipes and re-seeds; scheduled nightly to prevent state drift |
| Read-only guard (soft) | Demo user cannot change company name, create integrations, or trigger real publishing; actions return friendly "Demo only" flash message |
| Demo banner in `AppLayout.vue` | Yellow banner: "You're viewing a demo environment. Data is reset nightly." |
| Sales deck link | URL shared with prospects; no auth wall or invite required |

**Out of scope:** Personalized demos per prospect, video recording, interactive guided tours.

---

## Milestone 15 — Customer Onboarding Improvements

**Goal:** The first real company can be onboarded reliably in under 10 minutes, understands what Atlas is doing, and knows what to expect next.

**Rationale:** The current onboarding wizard works but is minimal. The status polling page is opaque. There is no email verification, no progress persistence, no clear "what happens after this?" messaging. Before acquiring any real customer, the onboarding must be hardened.

**Deliverables:**

| Item | Notes |
|------|-------|
| Email verification | Laravel's built-in `MustVerifyEmail`; `VerifyEmail` notification sent after registration; onboarding gated on verified status |
| Onboarding progress persistence | `users.onboarding_step` column (or session key); wizard resumes from last completed step on reload |
| Crawl status copy improvements | Status page explains what each phase means in plain language: "Reading your website..." → "Identifying your products..." → "Building your marketing profile..." |
| Timeout handling | If crawl takes > 5 min, show a "This is taking longer than usual" message with an estimated time |
| Welcome email | Sent after first recommendation is ready: subject "Your first Atlas recommendation is ready" |
| Integration setup guidance | Clear UI for what `website_crawl` does; link to docs; "Why do we need your website?" tooltip |
| Company slug validation | Real-time uniqueness check (debounced) in the company name step |
| Error recovery | If `SyncIntegration` fails, onboarding page shows a retry button instead of endless polling |
| Post-onboarding checklist | After first redirect to dashboard, show a dismissible "3 things to do first" prompt |
| `docs/guides/Onboarding.md` | Internal guide: what the customer sees, how to manually trigger a re-crawl, how to reset onboarding |

---

## Milestone 16 — Real Email Publishing

**Goal:** Atlas can send real marketing emails to real recipients through Postmark.

**Rationale:** Email is the lowest-friction publishing channel to integrate — no OAuth, no platform review, no content policy enforcement at publish time. Postmark is developer-friendly, has excellent deliverability, and has a sender reputation separate from shared ESPs. The webhook handler for Postmark already exists (`PostmarkWebhookHandler` from M8). Email closes the first half of the real publishing loop.

**Deliverables:**

| Item | Notes |
|------|-------|
| `PostmarkEmailProvider` implementing `EmailProvider` | HTTP to Postmark Messages API; returns `platform_id` from Postmark response |
| `PostmarkEmailProvider::ping()` | Calls Postmark `/server` endpoint with the API token to verify credentials |
| Registered in `PublisherServiceProvider` | Registered before `LogEmailProvider`; resolved when `provider_type = 'postmark'` |
| Channel credential UI in Settings | Input for Postmark API token + from-name + from-email; validation calls `ping()` before saving |
| Sandbox mode | When `APP_ENV !== 'production'`, use Postmark sandbox server token; outbound emails go to Postmark's sandbox, not real inboxes |
| `ExecutionResult` with real platform ID | `platform_id` stored on `Execution`; used as `platform_id` for analytics pull |
| Postmark webhook registration | Postmark webhooks point to `/api/analytics/webhooks/postmark`; delivery/open/click events flow into `ProcessAnalyticsWebhookEvent` |
| Rate limiting | Respect Postmark's 500req/min limit; implement exponential backoff on `429` |
| 20 new tests | `PostmarkEmailProvider`: success, credential failure, `ping()` pass/fail; `EmailPublisher` integration with Postmark provider |
| PHPStan level 8 — 0 errors | |

---

## Milestone 17 — Real Social Publishing

**Goal:** Atlas can publish posts to Instagram and Facebook business pages through the Meta Marketing API.

**Rationale:** Social is the primary channel for CBB Auctions and the exotic car dealer vertical. Instagram and Facebook share the same API surface (Meta Marketing API). Once email publishing is working and the credential management UI is proven, social is the logical next channel. It is significantly more complex than email due to OAuth, media upload requirements, and platform content policies.

**Deliverables:**

| Item | Notes |
|------|-------|
| OAuth credential flow | Server-side OAuth with PKCE; `MetaOAuthController`: `redirect()` and `callback()`; stores long-lived page token in `ChannelCredentials` (encrypted) |
| Token refresh | `CheckChannelHealth` job calls `ping()` on Meta credentials; detects expired tokens; triggers re-auth notification |
| `MetaPublisher` implementing `ChannelPublisher` | Supports `instagram` and `facebook` channel types |
| Image upload | Upload media to Meta CDN before post creation; `MetaMediaUploader` service |
| `MetaPublisher::publish()` | Creates IG/FB media object → publishes container; returns `ExecutionResult` with `platform_id` |
| `MetaPublisher::ping()` | Calls Meta Graph `/me` with page token; validates token is active |
| `MetaRenderer` | Renders `ContentAsset` for Meta: 2,200-char caption limit enforced; hashtag appendix; image URL resolved |
| Content policy error handling | `ContentPolicyException` (non-retryable) when Meta returns content policy rejection; user-facing message |
| Channel credential UI for Meta | "Connect Instagram" button triggers OAuth; shows connected page name after success; revoke action |
| Registered in `PublisherServiceProvider` and `ChannelRendererRegistry` | |
| 30 new tests | OAuth mock, credential validation, publish success, image upload, token expiry, content policy error |
| PHPStan level 8 — 0 errors | |

**Out of scope for M17:** Twitter/X, LinkedIn, TikTok, Pinterest. These can be added as additional `ChannelPublisher` implementations with minimal platform-layer changes.

---

## Milestone 18 — Real Analytics Integrations

**Goal:** Real engagement data flows back from Meta and Postmark into `ExecutionMetric` → `CampaignKpiSnapshot` → `LearningService`, closing the full learning loop.

**Rationale:** Without real analytics, the learning engine runs on fake or zero data. The scoring weights never actually improve. Atlas observes but never learns from what it observes. This milestone makes the loop real: Atlas sends a campaign, measures it, and adjusts.

**Deliverables:**

| Item | Notes |
|------|-------|
| `MetaAnalyticsProvider` implementing `AnalyticsProvider` | Pulls Insights API data for IG/FB post objects by `platform_id`; normalizes to `ExecutionMetric` keys |
| Insights fields mapped | `reach`, `impressions`, `engagement`, `clicks`, `saves`, `shares` → normalized keys |
| Window-closed detection | Meta post insights are finalized after 28 days; `isWindowClosed()` uses `published_at + 28d` |
| Polling schedule | `RetrieveExecutionMetrics` job self-reschedules at 6h → 12h → 24h → 48h → 7d → closed |
| Registered in `AnalyticsServiceProvider` | `MetaAnalyticsProvider` registered for `instagram` and `facebook` channel types |
| Postmark analytics pull | `PostmarkAnalyticsProvider`: pull message events by `platform_id` from Postmark `/messages/{id}` endpoint |
| Webhook confirmation | Verify Postmark open/click webhooks reach `ProcessAnalyticsWebhookEvent` with real test message IDs |
| `LearningService` signal generation | Real `ExecutionMetric` data generates `Learning` records with signal types: `reach_exceeded`, `engagement_low`, `click_rate_high`, etc. |
| Admin Filament view | `ExecutionMetric` sub-panel on `ExecutionResource` shows actual pull timestamps, normalized values, raw payload |
| 25 new tests | `MetaAnalyticsProvider`: success pull, window-closed detection, normalization; `PostmarkAnalyticsProvider`: pull, parse; `LearningService` signal integration |
| PHPStan level 8 — 0 errors | |

**Out of scope:** Google Analytics, TikTok Insights, Pinterest Analytics. These are future `AnalyticsProvider` implementations.

---

## Milestone 19 — Early Customer Feedback Tooling

**Goal:** Customers can share how they feel about Atlas recommendations in-product, and that signal reaches the team immediately.

**Rationale:** The only feedback loop that matters in early product is qualitative. NPS scores are fine, but the comments next to a 6 are worth more. This milestone should be installed before the third customer uses the product, so the first wave of customers is already generating signal.

**Deliverables:**

| Item | Notes |
|------|-------|
| NPS prompt trigger | Shown to `owner` and `admin` roles 24h after first recommendation is approved; not repeated for 90 days |
| In-app NPS widget | Dismissible modal or bottom sheet; 1–10 scale + optional free text; submitted via `POST /api/feedback` |
| `Feedback` model and migration | `id`, `company_id`, `user_id`, `score`, `comment`, `context` (JSON: page, last action), `created_at` |
| `FeedbackController` | `POST /api/feedback`: validates 1–10 score, optional comment ≤ 500 chars, stores, fires `FeedbackSubmitted` event |
| `FeedbackSubmitted` listener | Sends `FeedbackNotification` to founder email (or Slack webhook): score + comment + company name + user role |
| Filament `FeedbackResource` | Read-only list: score, comment, company, user, created_at; filter by score range |
| Weekly email digest | `SendFeedbackDigest` job on `maintenance` queue, Mondays at 07:00 UTC; summarizes NPS distribution and quotes notable comments from the past 7 days |
| Vue `FeedbackPrompt.vue` component | Integrated into `AppLayout.vue`; managed by `useFeedback()` composable that checks whether to show |
| 10 new tests | Controller validation, auth guard, `FeedbackSubmitted` event fired, notification dispatched, existing feedback suppresses repeat prompt |
| PHPStan level 8 — 0 errors | |

**Out of scope:** Third-party NPS tools (Delighted, Typeform), CRM integration, automated follow-up emails.

---

## Sequencing Rationale

The order above is not the user's preference list in priority order — it is the dependency-safe execution order:

1. **Infrastructure first (M11)** — every other milestone needs a host. Real publishers need credentials stored somewhere. Monitoring needs something to monitor. You cannot defer this.

2. **Error visibility before anything runs in production (M12)** — the first day Atlas runs on real infrastructure, something will go wrong. You need to know before the customer does.

3. **Telemetry before load (M13)** — once error reporting is in place, you need queue health and uptime visibility before you add real workloads (email publishing, analytics polling).

4. **Demo before customer conversations (M14)** — every meeting with CBB Auctions or any prospect needs a demo. Building it also validates the pipeline against realistic data.

5. **Onboarding before the first customer (M15)** — the demo showed the product works; now make the path from signup to first recommendation smooth enough to be repeatable.

6. **Email before social (M16 before M17)** — email requires no OAuth, no platform content policy, no media upload. It is the lowest-cost way to prove real publishing works end to end. Social builds on the same infrastructure.

7. **Social before analytics (M17 before M18)** — the analytics integration needs real `platform_id` values from real published posts. Social must publish before analytics can pull.

8. **Feedback last (M19)** — you need customers in the product before you can collect feedback from them. This is the first "measure the human" milestone, not the first "measure the machine" milestone.

---

## V0.2 Definition of Done

Version 0.2 is complete when:

- [ ] Atlas is running in production on a real host
- [ ] Every unhandled exception is reported to the team within 60 seconds
- [ ] Queue health and uptime are visible on a live dashboard
- [ ] A demo environment exists at a stable URL, reset nightly
- [ ] At least one real company has been onboarded end-to-end
- [ ] At least one real email has been sent through Postmark by Atlas
- [ ] At least one real Instagram or Facebook post has been published by Atlas
- [ ] Real engagement data from at least one published post has flowed back into the learning engine
- [ ] At least one real customer has submitted in-product feedback

---

## Out of Scope for V0.2

These are explicitly deferred:

- Billing and subscription management
- CRM or HubSpot integration
- Multi-language support
- Mobile app or PWA
- Twitter/X, TikTok, LinkedIn, Pinterest publishers
- Google Analytics or Search Console integration
- Headless browser connector (Puppeteer/Playwright) — deferred unless CBB Auctions requires it
- A/B testing campaigns
- Automated campaign scheduling (human approval is still required before every publish)

---

## Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Meta OAuth review takes longer than expected | Medium | High | Start Meta Business account verification early; submit for app review before M17 begins |
| CBB Auctions inventory is JavaScript-rendered, blocking the website crawl | High | High | Spike a headless browser connector during M15 if website crawl returns sparse facts |
| Postmark from-domain requires DKIM/SPF setup by the customer | Medium | Medium | Add DKIM/SPF setup guide to onboarding wizard; treat missing DNS as a soft warning, not a blocker |
| Informal CBB Auctions relationship prevents real-world testing | Medium | High | Formalize the design partner agreement before M16 begins; need a willing inbox and FB/IG page |
| Demo environment is misused as real customer data store | Low | High | Hard code demo company ID as read-only at middleware level; no integration to real channels |
| First production deploy introduces a migration regression | Medium | Medium | Test migration against production snapshot in staging before deploying to production |
