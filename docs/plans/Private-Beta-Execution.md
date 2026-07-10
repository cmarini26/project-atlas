# Private Beta Execution Checklist

**Purpose:** An operator's checklist for running Stage A (Private Beta, 5–10 customers) as defined in [Version-1.0-Roadmap.md](Version-1.0-Roadmap.md). This is not a roadmap and not a sprint plan — those are [Version-1.0-Roadmap.md](Version-1.0-Roadmap.md) (strategy) and [Private-Beta-Plan.md](Private-Beta-Plan.md) (the build-out sprint plan). This document is what you check off, verify, and repeat — before the first invite goes out, and every day the beta is running.

**Use it like this:** Section 1 is verified once, before Customer 1. Section 2 is verified once per new customer. Section 3 runs daily for as long as the beta is live. Section 4 is the single gate that must pass before any invite is sent. Section 5 is the cadence for the first week once customers start coming in.

**Source documents:** [Beta-Readiness-Audit.md](../reviews/Beta-Readiness-Audit.md), [Product-Polish-Audit.md](../reviews/Product-Polish-Audit.md), [Private-Beta-Plan.md](Private-Beta-Plan.md), [STATUS.md](../STATUS.md).

---

## 1. Production Infrastructure Checklist

Verify every item below before Section 4's Go/No-Go gate is even attempted. Each item should be checked by actually doing the verifying action described, not by reading code that implies it should work.

### Hosting

- [ ] Application server is provisioned and running (not a local machine, not a laptop).
- [ ] Application is deployed and the deployed commit matches what's on `main`.
- [ ] A second, successful deploy has been performed (proves the deploy process is repeatable, not a one-time fluke).
- [ ] `APP_DEBUG=false` in the production environment — verified by requesting a URL that throws an error and confirming no stack trace is shown to the browser.
- [ ] `APP_ENV=production` set.
- [ ] Server has enough disk headroom for at least 90 days of expected crawl/log growth at 10-customer volume (check today, don't estimate).

### Domain

- [ ] A registered domain resolves to the production server.
- [ ] `APP_URL` in production config matches the real domain (not `localhost`).
- [ ] DNS has fully propagated (verified from at least two independent networks/locations, not just the office Wi-Fi).

### SSL

- [ ] HTTPS is active and serves a valid certificate — verified by visiting the site in a normal browser and confirming no certificate warning.
- [ ] HTTP requests redirect to HTTPS (test by explicitly requesting the `http://` URL).
- [ ] Certificate auto-renewal is configured — verified by checking the renewal mechanism's next scheduled run, not just that it's "probably fine."

### Database

- [ ] Production PostgreSQL instance is provisioned, separate from any local/dev database.
- [ ] Migrations have been run against production and the schema matches what `main` expects.
- [ ] Connection pooling / max-connections is set appropriately for the expected worker + web process count — verified, not assumed.
- [ ] A test write and read against production has been performed and confirmed correct.

### Backups

- [ ] Automated database backups are configured and running on a schedule.
- [ ] At least one backup has actually completed successfully (checked in the provider's dashboard, not just "should be running").
- [ ] **A restore has been performed at least once**, to a separate/scratch database, and the restored data was spot-checked for correctness. This is the single most commonly skipped item on this list — "we have backups" and "we have restored from a backup" are different claims, and only the second is acceptable before Customer 1.
- [ ] The restore procedure is written down somewhere a second person could follow without asking the person who did it.

### Monitoring

- [ ] Uptime monitoring is configured against a real health endpoint, polling at a reasonable interval (e.g. every 60 seconds).
- [ ] A test alert has been triggered deliberately (e.g. by stopping the app briefly or blocking the health check) and the alert was actually received.
- [ ] Someone is the named recipient of alerts — not "the team," a specific person who owns responding.

### Error Tracking

- [ ] An error-tracking service is installed and receiving events from the production environment.
- [ ] A deliberately-thrown test exception in production has appeared in the error tracker within a few minutes.
- [ ] Someone checks the error tracker at least once daily during the beta (see Section 3).

### Email

- [ ] A real transactional email provider (not the `log` driver) is configured in production.
- [ ] A real test email has been sent and received in an actual inbox (not just "the API call returned 200").
- [ ] The sending domain has proper SPF/DKIM records configured (verify sent mail doesn't land in spam for at least one major mail provider).
- [ ] Password reset email has been tested end-to-end: request reset → receive email → click link → set new password → log in.

### Queue Workers

- [ ] Queue workers are running under process supervision (not `php artisan queue:work` in a terminal that will die when the SSH session ends).
- [ ] All queues the application dispatches to (`high`, `ai`, `default`, `observations`, `maintenance`) have a running worker.
- [ ] A worker crash/restart has been tested — confirm the supervisor actually restarts a killed worker process.
- [ ] Current queue depth has been checked and is not silently backing up.

### Scheduler

- [ ] The Laravel scheduler's cron entry is installed on the production server and running every minute — verified by checking the last-run timestamp of a scheduled command, not by reading `routes/console.php`.
- [ ] The recurring integration sync (`atlas:sync-due-integrations` or equivalent) has fired at least once on schedule and produced the expected effect.
- [ ] Opportunity expiration has fired at least once on schedule.

### Log Retention

- [ ] Log rotation is configured (a fixed retention window, not "logs grow forever until disk fills").
- [ ] Confirmed the retention window is long enough to debug an issue reported a few days late, but short enough not to fill disk before Section 1's disk-headroom check becomes a problem.
- [ ] The dedicated publishing log channel (`storage/logs/publishing.log`) is included in the rotation policy, not forgotten because it's a separate channel from the main app log.

---

## 2. Customer Onboarding Checklist

Run through this checklist yourself, end-to-end, as a real test account, before inviting a single real customer — and again for the first real customer while watching closely. This mirrors the actual product flow; every step should be verified working, not assumed working because the code exists.

### Account Creation

- [ ] Registration form accepts a new user and creates an account.
- [ ] Email verification (if enabled) sends and the verification link works.
- [ ] Login works immediately after registration.
- [ ] Password reset works for this account (request → email → reset → login).
- [ ] Rate limiting on login/register does not block a legitimate user doing normal things (test a few real attempts, not just confirm the throttle exists).

### Company Creation

- [ ] The onboarding wizard's company-profile step (name, industry) saves correctly.
- [ ] A `Company`, `Catalog`, and `DigitalTwin` are all created in the expected initial state — check this in Filament, don't just trust the UI redirected successfully.
- [ ] The new company is correctly isolated — confirm it does not appear in another test company's data anywhere (dashboard, Filament, API).

### Website Scan

- [ ] Entering a real website URL triggers a crawl within the expected time window.
- [ ] The crawl completes and produces at least the minimum expected number of Facts.
- [ ] Knowledge synthesis runs after facts are extracted and produces at least one Knowledge entry.
- [ ] The Digital Twin transitions from `initializing` to `active`.
- [ ] A crawl failure (test with a deliberately broken or slow URL) produces the correct, honest failure state in the UI — not a silent hang.
- [ ] The AI-analysis-failed state (distinct from crawl failure) has been tested and shows the right message.

### Marketing Presence

- [ ] The "Where do your customers find you?" onboarding step renders and accepts channel selections.
- [ ] Submitting the step creates the expected declared `MarketingChannel` rows for the company — verify in Filament or via the Settings page, not just that the wizard advanced.
- [ ] Skipping/selecting nothing on this step does not block reaching the status page (confirm it's genuinely optional).
- [ ] The Settings → Marketing Presence page correctly shows what was declared, and add/edit/disable each work for at least one channel.

### First Recommendation

- [ ] After Digital Twin activation, at least one Opportunity is detected within a reasonable time.
- [ ] A Decision is committed with all four rationale fields populated (why now / why this / why this channel / why it will work) and a non-null expected impact.
- [ ] The Recommendation appears in the customer-facing UI, is readable, and the rationale reads as genuinely specific to the test company (not generic filler).
- [ ] The "channel mix" section on the Recommendation detail page renders correctly and doesn't claim a draft-only or excluded channel will be published.
- [ ] Time from website URL entry to visible first Recommendation has been measured on the actual production environment (not local dev) — this is the number to compare against the 10-minute north-star metric.

### Approval Flow

- [ ] Approve works, and the confirmation dialog correctly and honestly describes what will happen (which channel, and that delivery is simulated/logged unless a real publisher is live for that channel).
- [ ] Edit & Approve works: edited content is saved and reflected in what gets queued.
- [ ] Reject ("Not This Time") works, with optional notes, and the Opportunity/Decision state updates correctly.
- [ ] Only owner/admin roles can approve or reject (test with a `member`-role account and confirm it's denied).
- [ ] An `Approval` record exists before any `Execution` is created — no path exists where content is queued without an approval on file.

### Publishing Expectations

- [ ] For every channel type this beta customer could end up with, confirm and document whether it is: (a) genuinely publishing externally, (b) logging internally only (simulated), or (c) not reachable at all yet. As of this writing, no channel type does (a) — every "Published" state in the UI is (b) or unreachable. **This must be re-verified at beta-launch time, not assumed from this document, since a real publisher may ship before then.**
- [ ] The Publishing page's language matches this reality exactly — no claim of a live send where none exists.
- [ ] If a real publisher (e.g., email via Postmark) has shipped by the time of onboarding, confirm at least one real send has actually been observed landing in a real inbox for a real (or realistic test) recipient — not just that the provider API returned success.
- [ ] The customer has been told, in writing (invite email or Getting Started guide), exactly which channels are real and which are simulated, before they approve their first campaign.

---

## 3. Internal Support Checklist (Daily, During Beta)

Run this every day the beta is active, ideally at a consistent time. Each item should take minutes, not hours, once the habit is established.

### Daily Health Checks

- [ ] Uptime monitor shows no unresolved downtime since the last check.
- [ ] Health endpoint(s) return healthy when checked manually.
- [ ] Disk space on the production server is not approaching capacity.
- [ ] Queue depth across all five queues is at a normal baseline, not growing unbounded.

### Failed Job Review

- [ ] Check the failed-jobs list (Filament or `failed_jobs` table) for anything new since the last check.
- [ ] For each new failure: identify which company it affects, whether it's a transient error (retry) or a real bug (needs a fix), and whether the affected customer needs proactive outreach.
- [ ] Confirm no failed job has been silently sitting for more than 24 hours without a decision made on it.

### AI Provider Monitoring

- [ ] Check for any AI provider errors, rate-limit responses, or unusual latency since the last check.
- [ ] Spot-check that AI usage looks proportional to actual customer activity (a runaway loop or unexpected spend spike would show up here first, since there is no automated cost ceiling yet).
- [ ] Confirm the AI pipeline actually produced output for every company that had new observations since the last check — a silent stall here is the same failure mode the recurring-sync fix (Product Polish Audit, item A1) was built to prevent, and it's worth confirming it's still working, not just that it was fixed once.

### Customer Issue Triage

- [ ] Check the designated support channel (email alias or Slack) for anything unanswered.
- [ ] Every new issue gets a same-day acknowledgment, even if the fix takes longer (the beta's committed response SLA is 24 hours — track against it explicitly).
- [ ] Recurring issues (the same complaint from more than one customer) get flagged for a real fix, not repeated one-off support replies.

### Backup Verification

- [ ] Confirm the most recent scheduled backup actually completed (check the provider dashboard — don't assume from "it's scheduled").
- [ ] On a regular cadence (at minimum, weekly during the beta), do a spot restore-and-check of a recent backup, not just at the one-time pre-launch verification in Section 1.

---

## 4. Go / No-Go Criteria

This is the single gate. **Every item below must be checked before the first invite is sent to Customer 1.** This is deliberately stricter than "probably fine" — a beta customer's trust, once lost to a preventable operational failure, is expensive to earn back.

- [ ] **Infrastructure (Section 1) is fully checked.** Every hosting, domain, SSL, database, backup, monitoring, error-tracking, email, queue, scheduler, and log-retention item above is verified true in production — not "should be true," verified.
- [ ] **A restore from backup has actually been performed and the data checked.** Non-negotiable; the single most consequential item on this whole document.
- [ ] **A full onboarding run-through (Section 2) has been completed successfully by a real test account on the production environment**, including a genuine crawl of a real website, a real recommendation, and a real approval.
- [ ] **Multi-tenancy is proven, not assumed.** At minimum: two test companies have been onboarded side by side and neither can see the other's data anywhere in the product or in Filament. If time allows, this should include a deliberate concurrent-request test, since that is the specific failure mode the original Beta Readiness Audit flagged as most severe.
- [ ] **Publishing reality is documented and the customer-facing copy matches it exactly** — no UI text anywhere claims a live send where none exists (per the Channel Publishing Reality Audit).
- [ ] **Privacy policy and terms of service are published at real, reachable URLs**, and the registration flow requires agreement to them.
- [ ] **A support channel is defined, documented, and someone is actually watching it.**
- [ ] **An operational runbook exists** covering at least the handful of most likely failure scenarios (crawl failure, AI provider outage, queue worker down, failed job spike).
- [ ] **The founding team has agreed on who the first 10 customers are**, starting with CBB Auctions as Customer 1, and each fits the target profile (active website, comfortable with early software, a vertical Atlas already understands, no enterprise/procurement expectations).
- [ ] **Beta communications are ready**: invite email drafted, Getting Started guide written, both stating plainly what is and isn't real yet (per the Publishing Expectations checks in Section 2).

**If any box above is unchecked, the answer is No-Go.** There is no partial credit — an infrastructure gap or an unverified backup is exactly as disqualifying the week before launch as it was the month before.

---

## 5. First-Week Operating Cadence

Once Customer 1 is invited, the cadence changes from "prepare" to "watch and respond." This section is what to do, and what to look at, every day for the first week — after that, Section 3's daily checklist continues, but the intensity of manual attention can taper as confidence builds.

### Daily tasks (Days 1–7)

- Run the full Section 3 checklist (health, failed jobs, AI monitoring, support triage, backup check).
- Personally verify, for every customer onboarded so far: did they reach a first recommendation, and how long did it take end to end? Compare against the 10-minute north star.
- Check for any new customer since the last check and watch their onboarding in as close to real time as practical — the first hour of a new customer's experience is the highest-leverage moment to catch a problem before it becomes a support ticket.
- Read every approval, edit, and rejection that happened in the last 24 hours. This is free product signal — don't let it go unread.
- Confirm no cross-company data issue has occurred (spot-check, not just "no one complained").

### Metrics to review daily

| Metric | What it tells you |
|---|---|
| Number of customers onboarded so far vs. target (10) | Pace against the beta's scope |
| Time from URL entry to first recommendation, per customer | Whether the 10-minute promise is holding in production, not just in a demo |
| Uptime since last check | Whether the operational floor is actually holding |
| Failed job count, and how many are still unresolved | Whether problems are being caught and closed, or piling up |
| Number of unanswered support messages, and oldest unanswered one's age | Whether the 24-hour SLA is being met |
| Approval vs. rejection count for the day | Early signal on recommendation quality — don't over-read a single day's noise, but a company with mostly rejections needs a look |

### End-of-week review

- Tally: how many of the invited customers completed onboarding without any engineering intervention? (This is the beta's primary success signal per the roadmap's Stage A metric.)
- Read back through every failed job and support issue from the week — are the same root causes repeating? If so, that's the top priority for the following week, ahead of any new feature work.
- Confirm backups completed every day this week and at least one additional restore spot-check was performed.
- Decide, explicitly, whether to invite the next customer(s) or pause and fix something first. Don't let onboarding pace outrun the team's ability to watch each new company closely — a beta with 3 customers going well is a better outcome than one with 10 customers half-watched.
- Update `docs/STATUS.md` with what happened this week: customers onboarded, issues found, issues fixed, and any change to the Go/No-Go posture for continuing.

---

*This checklist should be run in full before Customer 1, and its daily/weekly sections should keep running for as long as the private beta is active. When Stage A's success metrics (defined in [Version-1.0-Roadmap.md](Version-1.0-Roadmap.md)) are met, move to Stage B planning — don't keep operating Stage A's cadence indefinitely once its job is done.*
