# Northwind Local Dry Run — 2026-08-04

## Outcome

**PASS (controlled draft-only path)** — the synthetic tenant completed the
Observe → Understand → Decide → Recommend → Prepare → Approve → Execute loop.
Execution was deliberately simulated and logged internally because no live
publishing provider was connected.

## Environment

- Application environment: `local`
- Application URL: `http://127.0.0.1:8088`
- Profile: `northwind`
- Company: Northwind Skin Studio
- Owner: `northwind-owner@atlas.test`
- External publishing performed: **none**
- External provider credentials entered: **none**
- Controlled observation URL:
  `https://cmarini26.github.io/project-atlas/`

## Passed checks

- `php artisan atlas:seed-staging` completed successfully.
- `php artisan atlas:verify-staging` passed.
- The synthetic owner could authenticate locally.
- The dashboard loaded in the Northwind tenant.
- Company name and industry matched the seed packet.
- Marketing Presence rendered five declared channels:
  - Website
  - Email Newsletter
  - Instagram
  - Facebook
  - Google Business Profile
- Unconnected channels were labeled as declared, not publishable.
- Settings showed WordPress, email, SMS, Shopify, Mailchimp, Instagram, and Meta
  connection paths without claiming that any were connected.
- Four active audiences rendered with one synthetic contact each:
  - Facial clients
  - Membership prospects
  - New leads
  - Reactivation candidates
- Redis responded to `PING`.
- The controlled GitHub Pages prototype was publicly reachable and identified
  itself as Northwind Skin Studio Prototype.
- Website discovery completed and produced:
  - 1 observation
  - 8 facts
  - 3 knowledge records
  - 3 opportunities
  - 1 decision
  - 1 campaign
  - 1 content asset
  - 1 recommendation
- The recommendation explained why now, why this campaign, why the selected
  channel, and why Atlas expected it to work.
- The owner selected the draft-only blog asset and approved it through the UI.
- Approval produced a simulated execution with `metadata.publisher: log`; no
  request was made to WordPress or another publishing provider.
- The execution and content asset completed successfully, with no queued or
  failed jobs remaining.

## Intentionally not exercised

WordPress and email remain unconnected. No real send or publication was
attempted, so provider delivery, provider analytics, and outcome-driven
learning remain outside this rehearsal. Those checks require controlled test
accounts and explicit approval to use their credentials.

## Findings

1. The local PHP runtime does not have the Redis extension even though the
   Redis server responds. Queue and discovery commands therefore used the
   database queue with `CACHE_STORE=array`; production must include and verify
   its configured Redis client.
2. The local database initially had a pending campaign-brief migration. Running
   the committed migration restored the PrepareCampaign stage.
3. An existing seeded website integration retained its old encrypted URL when
   the seed profile URL changed. The synthetic row was repaired using Laravel
   encryption before discovery.
4. The first draft-only publish attempt exposed a product-truth defect: the UI
   promised internal simulation, but publisher priority selected WordPress and
   failed while looking for credentials. The publishing job now explicitly
   selects the log publisher unless the linked Marketing Channel has verified
   publishing support. A successful retry proved the corrected behavior.
5. A successful retry retained the prior `last_error`. Completion now clears
   that stale field so operational status cannot simultaneously say completed
   and display an obsolete failure.

## Required next evidence

- Connect a controlled WordPress site and repeat approval through real draft or
  publication, with an explicit external-action checkpoint.
- Connect a controlled email provider and test audience, then verify delivery
  and normalized analytics without contacting real customers.
- Collect provider outcomes and verify the Measure → Learn portion of the loop.
