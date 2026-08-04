# Atlas Connector Test Plan

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** Create a realistic, execution-ready test plan for all Atlas connectors and channel integrations so the team can validate what is implemented today, avoid testing unsupported flows as if they were real, and progressively prove each connector from mocked contract → sandbox → live account.

**Architecture:** Organize testing by **connector class**, not by marketing idea. Separate Atlas capabilities into three buckets: **observation connectors** (Website, Instagram, Shopify, Mailchimp), **execution connectors** (WordPress, Email providers, Meta, SMS), and **analytics/measurement connectors** (Postmark, Meta; SMS currently limited). For each connector, define three confidence levels: **repo contract tests**, **sandbox verification**, and **live production-like validation**. The plan must preserve Atlas’s product-truth rule: only claim “real” where the code and a reachable customer path both exist.

**Tech Stack:** Laravel 13, PHPUnit feature/unit tests, Inertia/Vue settings flows, provider registries, queued jobs, HTTP-mocked provider tests, manual live-account validation.

---

## Current context / grounded assumptions

### Repo truth as of this planning pass

Based on current code and docs:

#### Observation connectors with real code paths
- `website` — real observation connector
- `instagram` — real observation connector
- `shopify` — real observation/import connector
- `mailchimp` — real audience-sync/import connector

Evidence:
- `backend/app/Providers/ConnectorServiceProvider.php`
- `backend/app/Http/Controllers/App/SettingsController.php`
- `docs/product/Channel-Capability-Matrix.md`

#### Execution connectors with real customer-reachable connect flows
- `wordpress` / `blog` — real WordPress publishing via Settings connect flow
- `email` — real provider-aware email connect flow for `postmark` and `sendgrid`
- `meta` — real Facebook/Instagram execution via OAuth path
- `sms` — real Twilio connect/test path

Evidence:
- `backend/app/Providers/PublisherServiceProvider.php`
- `backend/app/Http/Controllers/App/SettingsController.php`
- `docs/product/Channel-Capability-Matrix.md`

#### Analytics / measurement connectors with real code
- `postmark` analytics + webhook handling
- `meta` analytics provider

#### Connectors/channels that must NOT be treated as “real connector test targets” yet
- Squarespace — no implemented connector found
- LinkedIn — simulated execution only
- X — simulated execution only
- Landing page — simulated execution only
- Google Business Profile — designed, not implemented
- YouTube / TikTok / Events / Print — declared-presence only, no connector path

### Important product-truth constraint
Do **not** write a plan that implies “test all connectors” means “all channels in the marketing vision.” The plan must explicitly distinguish:
1. implemented and reachable now,
2. implemented but only partially validated,
3. not implemented yet.

### Current repo state caution
`backend/` already has local modifications in:
- `app/Http/Controllers/App/SettingsController.php`
- `app/Services/Publishing/WordPressPublisher.php`
- `tests/Feature/App/SettingsControllerTest.php`

Any future implementation of this test plan must avoid mixing unrelated edits into those in-progress changes.

---

## Proposed plan structure

Build the connector test plan in **five layers**:

1. **Connector inventory and classification**
2. **Baseline automated contract coverage audit**
3. **Manual sandbox verification plan per connector**
4. **Live account end-to-end validation plan**
5. **Regression suite and operational runbook follow-up**

The deliverable should become a durable repo doc plus follow-up Jira tickets, not just a one-off chat checklist.

---

## Connector inventory to test

### A. Observation / import connectors

| Connector | Current status | Reachable via product today | What to prove |
|---|---|---|---|
| Website | Real | Yes | crawl works, observations persist, facts/brain updates |
| Instagram | Real | Yes | token-based connect works, profile/media sync works |
| Shopify | Real | Yes | Admin API connect works, store + product snapshot imports |
| Mailchimp | Real | Yes | audience validation works, contacts import into Atlas audiences |

### B. Execution / publishing connectors

| Connector | Provider(s) | Current status | Reachable via product today | What to prove |
|---|---|---|---|---|
| WordPress | WordPress app password | Real | Yes | connect, ping, post publish, optional media upload |
| Email | Postmark, SendGrid | Real | Yes | connect, test send, campaign send, audience send, metrics retrieval |
| Meta social | Facebook, Instagram | Real | Yes | OAuth, publish, measurement retrieval |
| SMS | Twilio | Real but narrow | Yes | connect, test send, single-destination execution |

### C. Measurement / analytics connectors

| Connector | Current status | What to prove |
|---|---|---|
| Postmark analytics/webhooks | Real | provider acceptance → analytics retrieval / webhook ingestion |
| Meta analytics | Real | published execution gets insights mapped into canonical metrics |
| SMS analytics | Limited / absent | explicitly document current non-goal |
| WordPress analytics | Not supported | explicitly document current non-goal |

### D. Explicitly out of scope for current “real connector” testing

These should be listed in the plan as **future connector candidates**, not current execution targets:
- Squarespace
- LinkedIn
n- X
- Landing page
- Google Business Profile
- YouTube
- TikTok

For Squarespace specifically: include as a **planned future connector track** with preconditions, not as a failing current connector.

---

## Test levels to define for every connector

Each connector should be tested at **three levels**, and the plan must define all three:

### Level 1 — Repo contract tests
Purpose: prove Atlas code behaves correctly against mocked provider contracts.

Artifacts to inspect/use:
- `backend/tests/Feature/Discovery/*`
- `backend/tests/Feature/Publishing/*`
- `backend/tests/Feature/Analytics/*`
- provider registry tests
- controller tests for Settings connect flows

Expected output:
- pass/fail inventory of existing automated tests
- missing automated coverage list

### Level 2 — Sandbox / test-account verification
Purpose: prove credentials, connect flows, and non-production external calls work against provider sandboxes or low-risk test properties.

Examples:
- WordPress test site
- Postmark sandbox/test server
- SendGrid test sender identity
- Meta test app / test business assets
- Twilio test credentials / test destination behavior where possible
- Shopify dev store
- Mailchimp test audience
- Instagram test account/token

Expected output:
- runbook per connector
- credentials/preconditions list
- exact success criteria

### Level 3 — Live end-to-end validation
Purpose: prove Atlas can complete a real customer-like cycle in an environment that matters.

This includes:
- connect in Settings
- trigger actual sync/publish path
- verify external side effect happened
- verify Atlas recorded the result
- verify measure/learn path where supported

Expected output:
- signed-off validation checklist for each real connector
- evidence requirements (URL, post ID, message ID, screenshot, webhook receipt, metric rows)

---

## Step-by-step plan

### Task 1: Create a canonical connector test-plan doc

**Objective:** Create a durable doc that inventories every connector/integration Atlas has today and marks each as real, partial, simulated, or future.

**Files:**
- Create: `docs/testing/Connector-Test-Plan.md`
- Reference: `docs/product/Channel-Capability-Matrix.md`
- Reference: `docs/reviews/Channel-Publishing-Reality-Audit.md`
- Reference: `backend/app/Providers/ConnectorServiceProvider.php`
- Reference: `backend/app/Providers/PublisherServiceProvider.php`

**Contents required:**
- connector inventory table
- classification by observation / execution / measurement
- explicit out-of-scope/future connectors table
- evidence citations to code/docs

**Verification:**
- Doc includes every currently registered connector/provider
- Doc explicitly calls out Squarespace as future-state, not failing-current

---

### Task 2: Audit existing automated test coverage by connector

**Objective:** Map which connectors already have automated tests and where the gaps are.

**Files:**
- Read: `backend/tests/Feature/Discovery/*`
- Read: `backend/tests/Feature/Publishing/*`
- Read: `backend/tests/Feature/Analytics/*`
- Append/update: `docs/testing/Connector-Test-Plan.md`

**Coverage matrix to build:**
- connect flow tests
- provider registry tests
- publish/sync tests
- error-path tests
- analytics/webhook tests

**Verification commands:**
From `backend/`:
```bash
php artisan test tests/Feature/Discovery
php artisan test tests/Feature/Publishing
php artisan test tests/Feature/Analytics
```

**Expected outcome:**
- a connector-by-connector automated coverage matrix
- a prioritized “missing automated tests” list

---

### Task 3: Define sandbox prerequisites for each real connector

**Objective:** Document the minimum external accounts/assets needed to safely verify every connector before live customer use.

**Files:**
- Modify: `docs/testing/Connector-Test-Plan.md`

**Sandbox assets to define:**
- WordPress test site with app password
- Shopify dev store + Admin API token
- Mailchimp test audience
- Instagram test account/token
- Meta test app/business assets for Facebook + Instagram publish and insights
- Postmark test/sandbox configuration
- SendGrid sender identity + API key
- Twilio account + sending number + test destination

**For each asset include:**
- owner
- where credentials live
- least-privilege principle
- reset/revocation expectations
- safe content rules (e.g. “TEST ONLY” prefix)

**Verification:**
- every real connector in the inventory has a mapped sandbox asset or an explicit blocker

---

### Task 4: Write manual verification scripts/checklists per observation connector

**Objective:** Define exact manual test procedures for Website, Instagram, Shopify, and Mailchimp.

**Files:**
- Modify: `docs/testing/Connector-Test-Plan.md`

**Per connector checklist must include:**
1. setup prerequisites
2. connect steps in Atlas
3. sync trigger path
4. evidence of external/API success
5. evidence inside Atlas (integrations row, observations, facts, audiences, etc.)
6. failure-mode checks
7. disconnect / reconnect test

**Required Atlas evidence examples:**
- Integration status becomes active
- `last_run_at` updates
- expected observations or contact rows exist
- duplicate sync behavior is sane
- stale credential failure is visible

**Verification:**
- each observation connector section is step-by-step enough for another operator to execute without guessing

---

### Task 5: Write manual verification scripts/checklists per execution connector

**Objective:** Define exact manual test procedures for WordPress, Email, Meta, and SMS.

**Files:**
- Modify: `docs/testing/Connector-Test-Plan.md`

**Per connector checklist must include:**
1. connect flow
2. ping/test-send verification
3. draft → approve → execute flow
4. external side-effect verification
5. Atlas-side execution verification
6. negative tests (bad credentials, revoked token, invalid recipient, etc.)
7. disconnect/reconnect behavior

**Examples of required proof:**
- WordPress: resulting post URL / post ID
- Email: provider message ID, recipient acceptance, metrics retrieval or webhook evidence
- Meta: published post/container ID and insights retrieval
- SMS: Twilio message SID and Atlas execution result

**Important nuance to document:**
- SMS is real for single-destination execution, but does not yet have a full analytics/learning loop
- WordPress has no current measurement path
- Meta has execution + measurement
- Email has the deepest closed loop today

---

### Task 6: Define cross-connector failure-mode tests

**Objective:** Cover behaviors that should be consistent across connectors, regardless of provider.

**Files:**
- Modify: `docs/testing/Connector-Test-Plan.md`

**Failure-mode categories:**
- bad credentials on first connect
- expired/revoked credentials after prior success
- rate limit / remote API error
- partial success (e.g. one audience recipient fails)
- queue worker unavailable during sync/publish
- scheduler dependency for recurring syncs/checks
- analytics missing after publish
- disconnect/revoke path correctness

**Verification:**
- each category points to the connectors it applies to
- expected Atlas behavior is specified (UI error, retry, failed job, status change, etc.)

---

### Task 7: Define evidence and sign-off requirements

**Objective:** Make connector validation auditable, not anecdotal.

**Files:**
- Modify: `docs/testing/Connector-Test-Plan.md`

**For every live validation run require:**
- date/time
- operator
- environment
- connector/provider
- test asset used
- external proof handle (URL, post ID, message ID, SID, audience import count, etc.)
- Atlas proof handle (execution ID, integration ID, metric rows, screenshot)
- result: pass / pass with caveat / fail / blocked

**Verification:**
- the plan includes a reusable result template/table

---

### Task 8: Convert the plan into Jira execution tickets

**Objective:** Break the connector plan into actionable Jira work after the doc is approved.

**Suggested ticket split:**
- Connector test plan doc approval
- Observation connector sandbox run
- Execution connector sandbox run
- Analytics/webhook validation run
- Live external-account validation run
- Missing automated tests backlog
- Future connectors discovery/spec work (Squarespace, GBP, etc.)

**Jira recommendation:**
Create one umbrella ticket or epic like:
- `Connector Validation & External Integrations QA`

Then sub-items by connector family:
- Website/Instagram/Shopify/Mailchimp
- WordPress
- Email (Postmark + SendGrid)
- Meta (Facebook + Instagram)
- SMS/Twilio
- Future connectors (Squarespace etc.)

---

## Likely files to change

### Documentation
- `docs/testing/Connector-Test-Plan.md` **(new)**
- possibly `docs/product/Channel-Capability-Matrix.md` if plan-writing reveals truth drift
- possibly `docs/reviews/Channel-Publishing-Reality-Audit.md` only if needed for historical pointer updates

### No code changes required for the initial planning slice
This initial request is planning/documentation only.

---

## Tests / validation to reference in the plan

### High-signal existing automated suites
From `backend/`:
```bash
php artisan test tests/Feature/Discovery
php artisan test tests/Feature/Publishing
php artisan test tests/Feature/Analytics
php artisan test tests/Feature/App/SettingsControllerTest.php
```

### Suggested targeted suites by connector family
```bash
php artisan test tests/Feature/Discovery/WebsiteConnectorTest.php
php artisan test tests/Feature/Discovery/InstagramConnectorTest.php
php artisan test tests/Feature/Discovery/ShopifyConnectorTest.php
php artisan test tests/Feature/Discovery/MailchimpConnectorTest.php
php artisan test tests/Feature/Publishing/WordPress
php artisan test tests/Feature/Publishing/Email
php artisan test tests/Feature/Publishing/Meta
php artisan test tests/Feature/Analytics
```

### Manual validation environments to define
- local mocked-contract environment
- sandbox/test-asset environment
- live beta environment

---

## Risks / tradeoffs / open questions

### Risks
1. **Connector sprawl vs current strategy** — Atlas’s stated priority is depth over breadth. The plan must not flatten all connectors into equal priority.
2. **Mocked tests can overstate readiness** — docs already warn that “Real” means mocked-contract-correct, not production-validated.
3. **Some connectors are intentionally partial** — SMS, Mailchimp, Shopify, and WordPress do not all have the same Observe→Execute→Measure→Learn depth.
4. **Provider accounts may be the real blocker** — the best-written plan still depends on actual sandbox/live credentials.
5. **Repo truth may drift quickly** — connector state is changing fast; capability matrix should remain the canonical truth anchor.

### Tradeoffs
- Better to explicitly mark Squarespace and other missing connectors as **future validation tracks** than to pretend they are current failures.
- Better to test a smaller number of real connectors deeply than to spread effort over unsupported channels.
- Better to require evidence artifacts for each live test than to rely on verbal confirmation.

### Open questions the plan should leave visible
1. Which provider accounts already exist for Postmark, SendGrid, Twilio, Shopify, Mailchimp, Meta, and WordPress?
2. Which connectors must be validated before Customer 1 vs which can wait?
3. Do you want one consolidated connector test doc, or one doc plus per-connector runbooks?
4. Should Shopify and Mailchimp be treated as “connector QA” only, or also as customer-facing beta gates?
5. Do you want future-state connector placeholders (Squarespace, GBP) represented in Jira immediately, or only after this current real-connector pass?

---

## Recommended priority order

Given Atlas’s current product strategy, execute connector testing in this order:

1. **Email** (Postmark + SendGrid) — deepest real loop, highest beta value
2. **WordPress** — first real publish destination after email
3. **Meta** (Instagram/Facebook execution + analytics)
4. **Website observation** — foundational observation path
5. **Instagram observation**
6. **Mailchimp audience sync**
7. **Shopify observation**
8. **SMS/Twilio**
9. **Future connectors** (Squarespace, GBP, etc.) as separate discovery/spec tickets

---

## Definition of done for this planning task

This planning task is done when:
- [ ] a connector test-plan doc exists in `docs/testing/`
- [ ] every currently implemented connector/provider is inventoried
- [ ] unsupported/future connectors are explicitly separated
- [ ] each real connector has repo-contract, sandbox, and live validation sections
- [ ] evidence/sign-off requirements are defined
- [ ] follow-up Jira ticket structure is proposed

---

## Suggested next move after this plan

Implement **Task 1 + Task 2 first**:
1. write the canonical connector test-plan doc
2. fill the automated coverage matrix from the existing test suite

That gets Atlas from “we should test connectors” to “we have a grounded test program” without needing provider credentials on day one.
