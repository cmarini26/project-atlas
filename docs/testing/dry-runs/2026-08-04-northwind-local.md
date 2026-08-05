# Northwind Local Dry Run — 2026-08-04

## Outcome

**PARTIAL PASS** — all local seed, verification, authentication, tenant, and
truth-surface checks passed. Observation and the downstream recommendation
pipeline remain blocked at the intentional external-request boundary.

## Environment

- Application environment: `local`
- Application URL: `http://127.0.0.1:8088`
- Profile: `northwind`
- Company: Northwind Skin Studio
- Owner: `northwind-owner@atlas.test`
- External publishing performed: **none**
- External provider credentials entered: **none**

## Passed checks

- `php artisan atlas:seed-staging` completed successfully.
- `php artisan atlas:verify-staging` passed.
- The synthetic owner could authenticate locally.
- The dashboard loaded in the Northwind tenant and reported zero pending
  recommendations, opportunities, campaigns, and learnings.
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
- The local `jobs` and `failed_jobs` tables were empty at inspection time.

## Blocked checks

### Website observation

The seed profile declares `https://northwindskinstudio.com`. The local HTML
prototype in `docs/testing/site-prototype/` is not what Atlas would crawl.
Running the discovery worker would therefore make an external request to a
domain that has not been established as a controlled Atlas test property.

Discovery was created but deliberately not executed past that boundary:

- run stage: `discovering`
- website attempt: `pending`
- attempt count: `0`
- observations: `0`
- facts: `0`
- opportunities: `0`
- recommendations: `0`

### Connected-channel execution

WordPress and email are unconnected, as expected. No real send or publication
was attempted. Provider execution, analytics, and learning cannot be validated
until controlled test accounts exist.

## Findings

1. The synthetic tenant and customer-facing capability truth are internally
   consistent.
2. The prior workflow documentation did not make clear that
   `--start-discovery` schedules an external crawl rather than serving the local
   prototype. The workflow has been corrected in this dry-run slice.
3. A controlled, publicly reachable Northwind test URL is the next dependency
   for completing Observe → Recommend locally without involving a customer.

## Required next evidence

- Host the supplied Northwind prototype at a URL owned and controlled for Atlas
  testing.
- Update `northwind-skin-studio-seed.json` to that URL.
- Reseed and run discovery through recommendation creation.
- Connect controlled WordPress and email test accounts before validating
  approval-gated execution and measurement.
