# SCRUM-72 — Website Measure/Learn Source Decision

**Status:** decision note, needs sign-off before SCRUM-73 implementation starts
**Date:** 2026-08-27
**Blocks:** SCRUM-73 (Website Measure/Learn MVP)
**Related:** [Adobe-Competitive-Gaps-Plan.md](Adobe-Competitive-Gaps-Plan.md) §SCRUM-72/73

---

## 1. What SCRUM-72 asks for

From the Adobe gap plan:

> Define the smallest honest version of "website measurement" Atlas can support
> without becoming a full analytics platform — e.g., a lightweight first-party
> tracking snippet the customer adds to their site, or an integration with a
> metric the customer already has (e.g. existing Google Analytics / Search
> Console read, if the company has one). Capture the decision in a short design
> note before implementation starts. Confirm the chosen source keeps Atlas
> within its current product identity (autonomous marketing operator for SMBs,
> not a full CDP/analytics suite).

This note is that decision note. **No code changes with it.**

## 2. Current state (audited)

- `website` is **observation-only**. `WebsiteConnector` / `WebPageCrawler` crawl
  the site and feed Facts/Knowledge; there is no Draft/Approve/Execute/Measure/
  Learn. `MarketingChannelType::Website::hasChannelEquivalent()` is `false` —
  a campaign never publishes *to* a website.
- The analytics layer is **execution-centric**. `AnalyticsProvider::pull(string $platformId, ChannelCredentials $credentials)` retrieves metrics for one *published item*; `RetrieveExecutionMetrics` walks `Execution` rows. There is no `Execution` for "the company's website this month", so website measurement does **not** fit the existing `AnalyticsProvider` contract without distortion.
- OAuth-connect precedent exists: `MetaOAuthController` + `SettingsController::connect*()` (ping-before-persist) is the established shape for "connect an external account Atlas then reads".
- `LearningService` already has channel-specific checks (three email-specific ones) it could mirror for a website signal once real data exists.

## 3. Options

### Option A — First-party tracking snippet (Atlas-hosted beacon)

Atlas serves a small JS snippet the customer pastes into their site; it posts
pageview / conversion events back to an Atlas endpoint; Atlas stores and
aggregates them.

- **Pros:** works for any site; no dependency on the customer having analytics;
  Atlas fully controls the metric definitions.
- **Cons:** Atlas becomes a *tracking platform* — an ingest endpoint at scale,
  bot filtering, sessionisation, a metrics store, and **consent/privacy
  obligations** (GDPR/CCPA banners, IP handling, a DPA). This is precisely the
  "full analytics platform" the ticket says to avoid. Also a per-customer
  install step that will frequently not get done.
- **Identity fit:** ❌ pushes Atlas toward being a CDP/analytics suite.

### Option B — Read an analytics source the customer already has (GA4 / Search Console)

Atlas OAuths into the customer's existing Google Analytics 4 property and/or
Search Console property and periodically pulls a **small, fixed set** of
top-line metrics.

- **GA4 Data API** (`runReport`): sessions, engaged sessions, conversions,
  traffic by default channel grouping, for a rolling window. OAuth 2.0
  user-consent flow; Atlas is added as a Viewer on the property.
- **Search Console Search Analytics API**: clicks, impressions, average
  position, top queries — the search-demand view GA4 does not have.
- **Pros:** Atlas stays thin — it *reads* a metric the customer already trusts,
  no tracking infra, no consent burden (the customer's own analytics already
  handles that). Reuses the existing OAuth-connect + registry patterns.
  Honest: if the customer has no GA4, Atlas simply says so and website stays
  observation-only.
- **Cons:** only works when the customer *has* GA4/GSC (common for e-commerce
  and dealerships — our first two verticals — less so for the smallest
  businesses). Google API quotas and OAuth-token refresh to manage. Metric
  semantics are Google's, not Atlas's.
- **Identity fit:** ✅ "integrate with a metric the customer already has",
  exactly as the ticket frames the acceptable option.

### Option C — Google Business Profile metrics (via GA4, local businesses)

As of mid-2026 Google pulls seven Business Profile metrics (calls, bookings,
direction requests, website clicks, messages, menu views, interactions) into
GA4 on a rolling six-month window.

- **Pros:** high-signal for *local* businesses; comes free with Option B's GA4
  integration once that exists.
- **Cons:** only meaningful for businesses with a Business Profile; not a
  general website-measurement answer on its own.
- **Identity fit:** ✅ as an *extension* of Option B, not a standalone source.

### Option D — Do nothing; keep website observation-only

- **Pros:** zero risk; the matrix stays accurate.
- **Cons:** leaves the concrete Adobe-comparison gap open — Atlas can never
  learn whether its website-informed recommendations correlate with real
  visitor behaviour.
- **Identity fit:** ✅ but concedes the gap.

## 4. Recommendation

**Option B, GA4 Data API first, gated on the company already having GA4.**
Search Console (still Option B) as an immediate follow-on in the same
integration. Option C rides along later for local businesses. Not Option A.

Rationale:

- It is the smallest thing that closes the loop without making Atlas own a
  tracking pipeline or a consent surface — the ticket's explicit constraint.
- It reuses patterns Atlas already has (OAuth connect + ping-before-persist,
  provider registry, periodic pull job, `LearningService` channel checks).
- It is honest by construction: no GA4 → the Website row stays
  `Measure: N/A`, and the UI says "connect Google Analytics to measure website
  results" rather than inventing numbers.
- Our first two verticals (CBB Auctions e-commerce, exotic car dealers) almost
  always run GA4 already, so coverage is good where it matters first.

## 5. Data-model implications (for SCRUM-73, not decided here)

- **Do not force website metrics through `AnalyticsProvider`/`ExecutionMetric`.**
  There is no `Execution`. Two honest shapes:
  1. **Facts** — e.g. `website.traffic.sessions_28d`, `website.search.clicks_28d`,
     with confidence + `observed_at`, feeding the Business Brain the same way
     crawl observations already do. No schema change. Recommended starting point.
  2. A dedicated `website_metrics` table if/when we need time series rather than
     a current snapshot. Defer until Facts prove insufficient.
- A new connector under `app/Services/Observatory/Connectors/Website/` (sibling
  of `WebsiteConnector`) fits better than the publishing-oriented analytics
  namespace, since this is an *observation* source.
- `LearningService` gains one website signal once real rows exist (mirroring the
  existing email-specific checks), e.g. "recommendations in months with rising
  sessions correlate with …". Keep the first version descriptive, not causal.

## 6. Open questions for sign-off

- Agree Option B (GA4 read), not Option A (first-party snippet)?
- GA4 **and** Search Console in the first SCRUM-73 slice, or GA4 only first?
- OAuth client: reuse a single Atlas Google Cloud project + consent screen
  (also needed for any future Google Business Profile work), or a dedicated one?
- Is a Google Cloud project / OAuth verification effort acceptable now, given
  the API surface is read-only and the scopes are `analytics.readonly` /
  `webmasters.readonly`?
- Where should the "connect Google Analytics" entry point live — Settings
  Marketing Presence, or the onboarding channel step?
