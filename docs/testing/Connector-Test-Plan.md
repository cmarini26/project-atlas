# Connector Test Plan

**Date:** 2026-07-29
**Status:** Draft — execution-ready, grounded in current code and docs
**Purpose:** define how Atlas should test every currently implemented connector and integration without confusing future channel ambitions for current product reality.

This document is the operational test plan for Atlas connectors. It is intentionally narrower than the long-term product vision. It covers what Atlas can **actually connect to, observe from, publish to, or measure against today**, plus a clearly separated future-work section for connectors not yet implemented.

---

## 1. Core testing principles

### 1.1 Product truth comes first
Atlas must not claim a connector is supported just because the product vision mentions it. This plan follows the current codebase and canonical truth docs:

- `docs/product/Channel-Capability-Matrix.md`
- `docs/reviews/Channel-Publishing-Reality-Audit.md`
- `backend/app/Providers/ConnectorServiceProvider.php`
- `backend/app/Providers/PublisherServiceProvider.php`
- `backend/app/Http/Controllers/App/SettingsController.php`

### 1.2 Not all connectors are equal
Atlas has three different connector families:

1. **Observation / import connectors**
   - pull data into Atlas
2. **Execution / publishing connectors**
   - send content out of Atlas
3. **Measurement / analytics connectors**
   - pull performance data back into Atlas

A connector may exist in only one family. Example:
- WordPress is real for execution, but not for observation or analytics
- Mailchimp is real for audience sync, but not as a Mailchimp-native send channel
- SMS is real for narrow execution, but not for a full measure/learn loop yet

### 1.3 Every real connector should be tested at 3 levels

#### Level 1 — Repo contract tests
- mocked HTTP / automated test coverage
- validates code behavior and regression safety

#### Level 2 — Sandbox verification
- safe external test assets
- validates real credentials, connect flows, and expected provider contracts

#### Level 3 — Live validation
- real-world, customer-like test run
- validates Atlas side effect + external side effect + evidence trail

---

## 2. Current connector inventory

## 2.1 Observation / import connectors

| Connector | Status | Reachable today | Purpose |
|---|---|---:|---|
| Website | Real | Yes | Crawl site content and images into observations/facts |
| Instagram | Real | Yes | Pull profile + media for observation/business brain |
| Shopify | Real | Yes | Import store metadata + product snapshot |
| Mailchimp | Real | Yes | Import audience/contacts into Atlas email audiences |

### Evidence
- `backend/app/Providers/ConnectorServiceProvider.php`
- `backend/app/Http/Controllers/App/SettingsController.php`

## 2.2 Execution / publishing connectors

| Connector | Provider(s) | Status | Reachable today | Purpose |
|---|---|---:|---:|---|
| WordPress / Blog | WordPress app password | Real | Yes | Publish blog posts |
| Email | Postmark, SendGrid | Real | Yes | Send test emails and campaign/audience email |
| Meta social | Facebook, Instagram | Real | Yes | Publish social content |
| SMS | Twilio | Real but limited | Yes | Single-destination SMS send |

### Evidence
- `backend/app/Providers/PublisherServiceProvider.php`
- `backend/app/Http/Controllers/App/SettingsController.php`
- `docs/product/Channel-Capability-Matrix.md`

## 2.3 Measurement / analytics connectors

| Connector | Status | Notes |
|---|---:|---|
| Postmark analytics | Real | retrieval + webhook path exists |
| Meta analytics | Real | insights provider exists |
| SMS analytics | Not yet real | no full analytics/learning loop |
| WordPress analytics | Not supported | no analytics provider |

---

## 3. Explicitly out of scope for current real-connector QA

These are **not** current “real connector validation” targets and should not be tested as if they are broken current features:

| Connector / channel | Current state | How to treat it |
|---|---|---|
| Squarespace | Not implemented | Future connector discovery/spec work |
| LinkedIn | Simulated execution only | Future connector work |
| X | Simulated execution only | Future connector work |
| Landing page | Simulated execution only | Future connector work |
| Google Business Profile | Designed, not implemented | Future connector work |
| YouTube | Declared presence only | Out of current connector scope |
| TikTok | Declared presence only | Out of current connector scope |
| Events | Declared presence only | Out of current connector scope |
| Print | Declared presence only | Out of current connector scope |

### Important note on Squarespace
Squarespace should appear in Jira as a **future connector track** or discovery item, not as a failing current QA target.

---

## 4. Test levels and required evidence

## 4.1 Level 1 — Repo contract tests

### Goal
Verify that Atlas code behaves correctly against mocked provider/API contracts.

### Evidence required
- passing automated tests
- explicit test file coverage inventory
- identified coverage gaps

### Core suites
From `backend/`:

```bash
php artisan test tests/Feature/Discovery
php artisan test tests/Feature/Publishing
php artisan test tests/Feature/Analytics
php artisan test tests/Feature/App/SettingsControllerTest.php
```

### Suggested targeted suites
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

## 4.2 Level 2 — Sandbox verification

### Goal
Verify real provider connections in a low-risk environment.

### Evidence required
- provider credentials successfully connect
- real external resource responds as expected
- Atlas stores/updates the expected records
- failure states are visible and intelligible

## 4.3 Level 3 — Live validation

### Goal
Prove a real customer-like flow works end to end.

### Evidence required
For every live run, capture:
- date/time
- operator
- environment
- connector/provider
- test asset/account used
- external proof handle
- Atlas proof handle
- final result: pass / pass with caveat / fail / blocked

Examples of acceptable external proof:
- WordPress post URL or post ID
- email provider message ID
- Meta post/container ID
- Twilio message SID
- Shopify sync count or imported product evidence
- Mailchimp imported audience/contact counts

Examples of acceptable Atlas proof:
- Integration ID
- Execution ID
- recipient snapshot counts
- metric rows
- UI screenshot of connected state / execution state

---

## 5. Connector-by-connector test matrix

## 5.1 Website connector

### Scope
Observation only.

### Level 1 — automated
- verify `WebsiteConnector` tests pass
- confirm crawl limits and extraction behavior are covered
- confirm observations/facts/business-brain integration paths are covered

### Level 2 — sandbox/manual
- connect a safe test website
- trigger observation/crawl
- confirm pages are crawled
- confirm representative images are captured if expected
- verify new observations/facts appear in Atlas

### Level 3 — live
- run against a real business site
- verify Atlas stores useful observations, not just transport success
- verify duplicate reruns are sane and don’t create obvious garbage

### Failure cases
- unreachable site
- timeout
- malformed HTML / crawl dead-end
- excessive page count

---

## 5.2 Instagram observation connector

### Scope
Observation only.

### Level 1 — automated
- verify Instagram connector/profile/media fetcher tests pass
- verify profile + media observations are transformed correctly
- verify sync pipeline tests cover Instagram integration flow

### Level 2 — sandbox/manual
- connect a test Instagram account/token
- trigger sync from Settings
- verify profile metadata imports
- verify recent media imports
- verify business-brain/fact generation occurs

### Level 3 — live
- validate against a real account representative of beta use
- verify token expiry/revocation behavior
- verify reconnect path after credential replacement

### Failure cases
- invalid token
- expired token
- private/permission-limited account
- provider response missing expected fields

---

## 5.3 Shopify connector

### Scope
Observation/import only.

### Level 1 — automated
- verify Shopify connector tests pass
- verify connection service and sync path are covered
- verify store metadata and product snapshot mapping are covered

### Level 2 — sandbox/manual
- connect a Shopify dev store
- validate Admin API token works
- trigger sync
- verify store metadata imported
- verify product sample imported

### Level 3 — live
- verify against a real store with enough products to matter
- confirm imported data is useful for recommendations, not just technically present

### Failure cases
- wrong shop domain
- invalid Admin API token
- store reachable but product import partial/empty
- permissions too narrow

---

## 5.4 Mailchimp connector

### Scope
Audience import/sync only.

### Level 1 — automated
- verify Mailchimp connector tests pass
- verify contacts/audiences sync into Atlas models correctly
- verify duplicate/merge behavior is covered

### Level 2 — sandbox/manual
- connect a test Mailchimp audience
- trigger sync
- verify audience imported into Atlas email audiences
- verify contact counts and sample membership

### Level 3 — live
- validate against a real but low-risk audience
- confirm repeated sync behavior is sane
- confirm disconnect/reconnect behavior preserves truth

### Failure cases
- wrong server prefix
- invalid API key
- wrong audience ID
- contact import partial/empty

---

## 5.5 WordPress connector

### Scope
Execution only.

### Level 1 — automated
- verify WordPress publisher tests pass
- verify media uploader tests pass
- verify SettingsController connect/disconnect coverage exists
- verify ping-before-persist behavior is covered

### Level 2 — sandbox/manual
- connect a WordPress test site using application password
- confirm bad credentials are rejected
- confirm successful credentials persist connected state
- publish a safe test post
- verify post exists externally
- if media path is used, verify media upload behavior

### Level 3 — live
- publish to a real controlled WordPress site
- capture post URL / post ID
- verify Atlas execution shows success
- verify reconnect after password rotation

### Failure cases
- wrong app password
- wrong username
- valid connect but publish failure
- media upload failure with post success
- disconnect/reconnect correctness

### Non-goals today
- no WordPress analytics validation path

---

## 5.6 Email connectors — Postmark and SendGrid

### Scope
Execution + measurement + learning (deepest connector family currently in Atlas).

### Level 1 — automated
- verify Email provider registry tests pass
- verify Postmark provider tests pass
- verify email publisher tests pass
- verify analytics provider + webhook tests pass
- verify audience send behavior is covered
- verify SettingsController connect/test-send coverage exists

### Level 2 — sandbox/manual
For **Postmark**:
- connect with test token
- run test send
- verify provider accepts message
- verify Atlas stores correct channel/credential state
- verify analytics retrieval or webhook ingestion path

For **SendGrid**:
- connect with test token
- run test send
- verify provider accepts message
- validate sender identity assumptions
- verify Atlas stores correct provider type and connection state

### Level 3 — live
- connect real provider
- send a single-recipient test email
- send an audience-backed campaign email
- verify external provider message acceptance
- verify Atlas recipient outcome counts
- verify metrics retrieval/webhook evidence
- verify learning inputs appear where supported

### Failure cases
- invalid API token
- invalid sender identity
- one bad audience recipient among many
- metrics missing after successful send
- webhook rate limit / replay / malformed payload

### Required evidence
- message ID or provider-side send proof
- Atlas execution ID
- recipient outcomes summary
- metric rows or webhook receipt evidence

---

## 5.7 Meta connectors — Facebook and Instagram execution

### Scope
Execution + measurement.

### Level 1 — automated
- verify Meta OAuth tests pass
- verify Meta publisher tests pass
- verify Meta renderer tests pass
- verify Meta analytics provider tests pass

### Level 2 — sandbox/manual
- run OAuth with test app/assets
- verify connected state
- publish a safe test post to supported surface
- verify resulting external object exists
- pull metrics/insights

### Level 3 — live
- connect a real controlled account
- publish a real marked test post
- capture container/post IDs
- verify insights retrieval
- verify revoke/disconnect behavior

### Failure cases
- expired OAuth token
- revoked permissions
- publish succeeds but metrics fail
- wrong account/page/IG asset binding

---

## 5.8 SMS connector — Twilio

### Scope
Execution only, currently narrow.

### Level 1 — automated
- verify SMS channel service and publisher tests when present
- verify SettingsController connect/test-send coverage exists
- verify provider registry behavior for Twilio path

### Level 2 — sandbox/manual
- connect Twilio credentials
- verify bad credentials are rejected
- run test SMS send
- verify configured `from_number` and destination handling

### Level 3 — live
- send to a real controlled destination
- capture Twilio SID
- verify Atlas execution/test-send result
- verify disconnect/reconnect behavior

### Failure cases
- invalid SID/token
- missing from number
- invalid destination number
- provider accepted/rejected mismatch handling

### Non-goals today
- no full SMS analytics / learn loop validation
- no audience/list campaign model validation

---

## 6. Cross-connector failure-mode matrix

These should be tested wherever applicable.

| Failure mode | Applies to |
|---|---|
| bad credentials on first connect | all real connectors |
| expired/revoked credentials after prior success | Instagram, Meta, WordPress, Email, SMS, Shopify, Mailchimp |
| partial success | Email audience sends, possibly import connectors |
| queue worker unavailable | sync + publish flows that depend on jobs |
| scheduler dependency / recurring drift | recurring sync/health-check paths |
| provider rate limiting | Instagram, Meta, Shopify, Mailchimp, Email providers |
| disconnect/reconnect correctness | all real connectors |
| external side effect absent despite “success” | all execution connectors |

For each connector execution run, explicitly record:
- expected Atlas UI/system behavior
- expected provider behavior
- where errors should surface

---

## 7. Sandbox assets required

Before executing the full plan, provision or confirm the following test assets:

| Asset | Needed for |
|---|---|
| WordPress test site + application password | WordPress |
| Shopify dev store + Admin API token | Shopify |
| Mailchimp test audience | Mailchimp |
| Instagram test account/token | Instagram observation |
| Meta test app/business assets | Facebook + Instagram publish/insights |
| Postmark test/sandbox account | Email |
| SendGrid test sender identity + token | Email |
| Twilio account + test sender/recipient | SMS |
| one real safe website target | Website crawl |

Each asset should have:
- named owner
- credential location
- least-privilege scope
- clear revocation/reset path
- “TEST ONLY” usage conventions where applicable

---

## 8. Recommended execution priority

To stay aligned with Atlas’s current **depth over breadth** strategy, test in this order:

1. **Email** — deepest real closed loop
2. **WordPress** — real publish path
3. **Meta** — real publish + measure path
4. **Website** — foundational observation path
5. **Instagram observation**
6. **Mailchimp**
7. **Shopify**
8. **SMS**
9. **Future connectors discovery/specs** — Squarespace, GBP, etc.

---

## 9. Suggested Jira breakdown

Recommended umbrella workstream:

### Epic / umbrella
- **Connector Validation & External Integrations QA**

### Suggested child tickets
- observation connectors validation — Website / Instagram / Shopify / Mailchimp
- WordPress validation
- Email validation — Postmark + SendGrid
- Meta validation — Facebook + Instagram
- SMS / Twilio validation
- analytics + webhook validation
- missing automated coverage backlog
- future connector discovery — Squarespace / Google Business Profile / others

---

## 10. Definition of done

This connector test plan is successfully executed when:

- [ ] every currently real connector has completed Level 1 review
- [ ] every currently real connector has a sandbox run or an explicit blocker
- [ ] every beta-critical connector has a live validation result with evidence
- [ ] unsupported/future connectors are tracked separately, not misrepresented as broken current features
- [ ] Atlas-side and provider-side evidence exist for each “pass” claim

---

## 11. Immediate next step

Start by filling an **automated coverage audit table** for the currently real connectors using the existing test suite, then prioritize sandbox validation for:

1. Email
2. WordPress
3. Meta

Those three will give the most beta value fastest.

---

## 12. Automated coverage audit — current state

This section is a grounded inventory of the automated test coverage visible in the repo during this planning pass. It is intentionally practical: the goal is to identify which connector families already have strong mocked-contract regression protection, and which still need higher-confidence app-flow or failure-mode coverage.

### 12.1 Coverage legend

| Rating | Meaning |
|---|---|
| Strong | Multiple targeted tests exist across connect/service/provider/publish or sync layers |
| Moderate | Core behavior is covered, but one or more important app-flow or failure-mode areas remain thinner |
| Light | Some coverage exists, but not enough to trust the connector without deeper follow-up |
| None found | No meaningful automated coverage found in this audit pass |

### 12.2 Coverage matrix

| Connector family | Current automated coverage | Evidence found | Rating | Biggest current gap |
|---|---|---|---|---|
| Website observation | Dedicated connector tests plus broader sync/tenant-isolation discovery tests | `WebsiteConnectorTest.php`, `SyncPipelineTest.php`, `TenantIsolationTest.php` | Strong | more explicit end-to-end evidence that Atlas facts/knowledge outputs remain stable across real crawl shapes |
| Instagram observation | Dedicated connector tests for profile/media mapping; Settings index snapshot coverage present | `InstagramConnectorTest.php`, `SettingsControllerTest.php` | Moderate | lighter confirmed coverage of the Settings connect flow and integration lifecycle than WordPress/Email/SMS |
| Shopify observation | Dedicated connector tests for store/product mapping; Settings connect path dispatch coverage | `ShopifyConnectorTest.php`, `SettingsControllerTest.php` | Moderate | stronger negative-path coverage for failed connect/ping and partial import scenarios |
| Mailchimp audience sync | Dedicated connector tests for imported contacts/audience behavior; Settings connect path dispatch coverage | `MailchimpConnectorTest.php`, `SettingsControllerTest.php`, `EmailAudienceServiceTest.php` | Strong | clearer app-level coverage for repeated sync/re-sync lifecycle and disconnect behavior |
| WordPress publishing | Dedicated renderer/uploader/publisher tests plus strong Settings connect/reconnect tests | `WordPressPublisherTest.php`, `WordPressMediaUploaderTest.php`, `WordPressRendererTest.php`, `SettingsControllerTest.php` | Strong | more explicit app-level publish-job and external-side-effect failure-path coverage |
| Email publishing (Postmark/SendGrid) | Deepest family: provider registry, provider tests, channel service, audience service, publisher, renderer, analytics/webhooks, Settings provider tests | `EmailChannelServiceTest.php`, `EmailPublisherTest.php`, `EmailAudienceServiceTest.php`, `PostmarkEmailProviderTest.php`, `EmailProviderRegistryTest.php`, `PostmarkAnalyticsProviderTest.php`, `PostmarkWebhookHandlerTest.php`, `AnalyticsWebhookControllerTest.php`, `SettingsConnectorProvidersTest.php` | Strong | explicit SendGrid-provider parity tests and more full-stack app-flow coverage from Settings connect → campaign execution → metrics |
| Meta execution + analytics | OAuth/controller/service tests, publisher tests, renderer tests, analytics provider tests | `MetaOAuthControllerTest.php`, `MetaOAuthServiceTest.php`, `MetaChannelPublisherTest.php`, `MetaRendererTest.php`, `MetaAnalyticsProviderTest.php` | Strong | more integrated app-flow coverage linking connected state, publish execution, and post-publish insights retrieval |
| SMS / Twilio | Channel service, publisher, Settings provider tests | `SmsChannelServiceTest.php`, `SmsPublisherTest.php`, `SettingsConnectorProvidersTest.php` | Strong (for current narrow scope) | analytics/measurement is still intentionally absent, so there is no deeper loop coverage to add yet beyond current scope |
| Postmark analytics/webhooks | Dedicated provider, handler, controller, rate-limit, processing, registry tests | `PostmarkAnalyticsProviderTest.php`, `PostmarkWebhookHandlerTest.php`, `AnalyticsWebhookRateLimitTest.php`, `ProcessAnalyticsWebhookEventTest.php`, `AnalyticsWebhookControllerTest.php`, `AnalyticsProviderRegistryTest.php` | Strong | more end-to-end assertions tying a sent email to subsequent metric rows in one cohesive flow |
| Squarespace / future connectors | Not implemented by design | no connector/provider files found | None found | connector does not exist yet; treat as future discovery/spec work |

### 12.3 High-confidence observations from this audit

1. **Email is the most thoroughly tested connector family in the repo.** It has service-layer, provider-layer, audience-layer, publish-layer, and analytics/webhook coverage.
2. **WordPress is well protected at the connect/ping boundary.** The Settings tests explicitly cover ping-before-persist and preserving the last known-good connection on failed reconnect.
3. **Meta has strong provider/publisher/OAuth coverage, but still benefits from a more cohesive app-flow test story.**
4. **Shopify and Instagram observation are real and meaningfully covered, but their app-level connect/sync lifecycle coverage is not as obviously deep as Email/WordPress.**
5. **SMS coverage is strong relative to current scope, but current scope is intentionally small.** The main gap is not “missing tests” so much as “feature not yet broader.”
6. **Squarespace should remain out of the current QA pass.** No implemented connector surfaced in code during this audit.

### 12.4 Highest-value automated test gaps to add next

Recommended order:

1. **SendGrid parity tests**
   - Ensure SendGrid has explicit provider-level test depth comparable to Postmark, not just provider-selection/connect coverage.
2. **Integrated app-flow tests for Meta**
   - Connected state → publish execution → analytics retrieval.
3. **Integrated app-flow tests for Shopify and Mailchimp**
   - Connect in Settings → dispatch sync → verify imported artifacts.
4. **Instagram Settings connect lifecycle tests**
   - Connect, re-connect, invalid token, and sync-dispatch behavior.
5. **Cross-connector failure-mode tests**
   - Revoked credentials after prior success, provider outage, queue interruption during sync/publish.

### 12.5 What this coverage audit does *not* prove

Even the strongest automated coverage here is still mostly **mocked-contract confidence**, not live-environment proof. This section does **not** prove:

- real provider credentials work today
- real external side effects are occurring correctly
- production OAuth/app configuration is complete
- rate limits, sandbox quirks, or live webhook delivery behave as expected

That is why this document still requires **Level 2 sandbox verification** and **Level 3 live validation** for every beta-critical connector.
