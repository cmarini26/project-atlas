# Google Business Intelligence — Design Specification

**Milestone:** 14
**Status:** Design only — no code exists yet
**Read first:** `docs/specs/Marketing-Intelligence.md`, `docs/specs/Marketing-Health.md`, `specs/core/domain-model.md`, `docs/plans/Version-1.0-Roadmap.md`
**Companion:** `docs/plans/Milestone-14-Google-Business-Plan.md` — sequences this spec into implementation phases; read this document first.

---

## 1. What This Is

Google Business Profile (GBP — formerly Google My Business) becomes Atlas's second real-world Marketing Source, after Instagram (Milestone 12). For most of Atlas's target verticals — comic auction houses, exotic car dealerships, any local/regional small business — the Google Business Profile is arguably a *more* important signal than Instagram: it's where local search visibility, star ratings, and reviews live, and it's usually the first thing a prospective customer sees before ever visiting a website or social account.

This milestone is **observation and understanding only**. Atlas learns what a business's Google Business Profile says about it — its category, hours, photos, and (critically, and newly for Atlas) its reputation via ratings and reviews — and turns that into Facts, Knowledge, Marketing Health evidence, and new Opportunity types. **Atlas does not publish to, reply on, or edit the profile in any way.** That boundary is deliberate and explicit (§6) — it is architecturally identical to how Milestone 12 kept Instagram Content Intelligence strictly read-only.

---

## 2. Google Business Profile as a Marketing Source

### 2.1 Architectural pattern — reused exactly, not reinvented

Every piece of this milestone reuses the exact connector/analyst architecture Instagram Observation (Milestone 12 Phase 1) already established, because that architecture was already built to be source-agnostic:

- **`GoogleBusinessConnector implements Connector`** (`app/Services/Observatory/Connectors/GoogleBusiness/GoogleBusinessConnector.php`), resolved by the existing `ConnectorRegistry` — no registry change beyond adding this one instance, exactly as `InstagramConnector` was added.
- **`GoogleBusinessAnalyst implements ObservationAnalyst`** (not the AI-calling `Analyst` marker interface — see §4 for why), resolved by the existing `AnalystRegistry` — no interface change, no `ProcessObservation` change beyond nothing (it already resolves analysts generically).
- New `Fact` keys under the `google_business.*` namespace, consistent with `instagram.*`/`business.*`/`catalog.*`.
- New `Observation.source_type` values (see §3.2) — the same mechanism used to add `'social'` and `'social_content'` in Milestone 12: base migration updated for fresh databases, a Postgres-only constraint-rewrite migration for already-migrated databases.

### 2.2 Connection model — beta scope

Google's Business Profile APIs require OAuth 2.0 with a verified Google Cloud project and (for most endpoints) a business owner who has granted API access — a meaningfully heavier lift than Instagram Basic Display's simple long-lived token. Following the exact same beta-scope discipline Milestone 12 Phase 1 applied to Instagram (manually-entered token, no in-app OAuth, one account per company, no historical import), this milestone's connection model is: **a company connects Google Business Profile from Settings by pasting an access token obtained externally** (Google OAuth Playground or a Google Cloud service account flow, documented for the beta user, not built as an in-app flow). A real in-app OAuth connect experience — mirroring the PKCE flow already built for Meta publishing (`MetaOAuthService`, `MetaOAuthController`) — is explicit future-phase work (§10), not designed here.

This is a fundamentally different connection path from Meta's OAuth publishing work (`App\Services\Publishing\MetaOAuthService` et al.) — that subsystem exists to *publish*, gated behind `Channel`/`ChannelCredentials`. Google Business Intelligence is *observation only* and uses the `Integration`/`Observation` model, exactly like Instagram Observation does, not the `Channel`/`ChannelCredentials` publishing model. The two should not be confused or merged.

### 2.3 Marketing Presence tie-in

`MarketingChannelType::GoogleBusinessProfile` (`'google_business_profile'`) already exists in the enum (Milestone 11 — a company could always *declare* a Google Business Profile as part of their marketing footprint, with zero technical connection). This milestone is what finally lets that declaration become a real, connected data source — following the exact linkage precedent `specs/core/marketing-presence.md` already describes for Instagram/Facebook: once a real `Integration` exists for a company, `MarketingPresenceService` can link the company's already-declared `MarketingChannel(type: google_business_profile)` row to it (`is_connected = true`). This spec does not change `MarketingPresenceService`'s linking logic — it is generic per `specs/core/marketing-presence.md` §12 and already designed to handle exactly this case.

---

## 3. Observable Data

### 3.1 What's captured

| Data | Availability (real API) | Notes |
|---|---|---|
| **Business profile** | Reliable — Business Information API | Name, primary category, additional categories, phone, address, website URL, description, service area (if applicable) |
| **Hours** | Reliable — Business Information API | Regular hours per day-of-week, plus special/holiday hours where the API exposes them |
| **Categories** | Reliable — Business Information API | Primary + additional category list (Google's fixed taxonomy) |
| **Reviews** | Reliable — Business Profile APIs (My Business API v4's reviews endpoint, being consolidated into newer Business Profile APIs) | Star rating, review text, reviewer name/attribution, created/updated timestamp, existing owner-reply text (read-only — Atlas never writes a reply) |
| **Ratings** | Reliable — derived from Reviews | Average rating and total review count are aggregate values Atlas computes deterministically from the fetched review list, not a separate API call |
| **Photos** | Reliable — Business Information API media endpoints | Photo URLs + category (e.g. `COVER`, `PROFILE`, `INTERIOR`) + upload timestamp, where exposed |
| **Q&A** | **Uncertain — flagged, not assumed** | Google restricted public API access to the Questions & Answers surface for most developers around 2021–2023; this spec designs for it (§3.3) but the connector must degrade gracefully (treat as an optional, possibly-empty capability) rather than assume it will be available. This is an honest, verify-before-relying-on-it constraint, the same posture this codebase already takes toward every third-party API surface it hasn't been tested against a real account (Meta OAuth, WordPress, Postmark) |

### 3.2 Two Observation source types, not one

Mirroring Milestone 12 Phase 2's exact reasoning for splitting `'social'` from `'social_content'`: profile/hours/categories/photos and reviews have incompatible shapes and different natural refresh cadences (a business's category rarely changes; reviews arrive continuously), so they are captured as two source types:

- **`google_business`** — profile snapshot: name, categories, phone, address, website, hours, photos. Low-churn; a full refresh is cheap and low-value to run more than daily.
- **`google_business_reviews`** — the review list (and photos are arguably closer to this cadence for some businesses, but are grouped with the profile snapshot for simplicity, per §3.1's "reliable" pairing with Business Information API — revisit if real usage shows otherwise). High-churn; new reviews can arrive at any time and are the freshest, most actionable signal this milestone adds.

Both new values added to `observations.source_type` and `integrations.type` (a single `'google_business'` Integration type covers both Observation source types, the same way one `'instagram'` Integration produces both `'social'` and `'social_content'` Observations today) — same base-migration-plus-Postgres-constraint-rewrite mechanism as every prior addition to these enums.

### 3.3 Per-review capture (for `google_business_reviews`)

Mirrors `InstagramMediaItemData`'s per-post value object shape:

```php
readonly class GoogleBusinessReviewData
{
    public function __construct(
        public string $reviewId,
        public int $starRating,        // 1-5
        public ?string $comment,       // review text, nullable — star-only reviews exist
        public string $reviewerDisplayName,
        public DateTimeImmutable $createTime,
        public ?DateTimeImmutable $updateTime,
        public ?string $ownerReplyText,   // read-only — existing reply, if any; never written by Atlas
        public ?DateTimeImmutable $ownerReplyTime,
    ) {}
}
```

Q&A, if the connecting account's API scope actually exposes it, is captured as a similarly-shaped but explicitly optional `GoogleBusinessQuestionData` list, folded into the `google_business` profile Observation payload (not its own source type — Q&A volume is low enough not to need reviews' independent refresh cadence) and simply omitted from the payload (not an empty array masquerading as "checked, found none") when the API scope doesn't grant access, so downstream code can distinguish "no Q&A capability" from "Q&A capability confirmed, currently zero questions."

---

## 4. Observation → Fact → Knowledge

### 4.1 `GoogleBusinessAnalyst` — deterministic, not AI-calling

Exactly like `InstagramAnalyst`, `GoogleBusinessAnalyst` implements `ObservationAnalyst` only — profile/hours/categories/ratings are already structured fields, so mapping them to Facts is deterministic key/value translation. `supports()` matches both `'google_business'` and `'google_business_reviews'`; `analyze()` branches on `$observation->source_type`, mirroring `InstagramAnalyst::analyze()`'s dispatch to `analyzeProfile()`/`analyzeContent()`.

**One deliberate scope boundary:** review *text* is captured verbatim (§3.3) but this analyst does not run sentiment analysis, theme extraction, or any other NLP over review content — that would require an AI call, and per this milestone's read-only, deterministic posture (matching `InstagramAnalyst`'s own precedent, not `WebsiteAnalyst`'s AI-calling one), review text is evidence to be *shown*, *counted*, and *aggregated* (star ratings are already structured numbers), never *interpreted*. AI-assisted review theme summarization is explicit future-phase work (§10), the same category as Marketing Health's own deferred "AI-generated narrative summaries."

### 4.2 Facts produced

From a `'google_business'` (profile) Observation:

| Fact key | Type | Meaning |
|---|---|---|
| `google_business.name` | string | Business name as listed on the profile |
| `google_business.primary_category` | string | Google's primary category classification |
| `google_business.categories` | json | Full category list (primary + additional) |
| `google_business.phone` | string | Listed phone number |
| `google_business.address` | string | Formatted address |
| `google_business.website` | string | Listed website URL (useful cross-check against `Company.website_url`) |
| `google_business.hours` | json | `{monday: {open, close}, ...}` per day, or `closed` |
| `google_business.photo_count` | integer | Total photos on the profile |
| `google_business.has_qanda_access` | boolean | Whether this connection's API scope actually exposes Q&A — omitted (not merely `false`) when never checked, present and `false` when checked and unavailable, per §3.3's distinction |

From a `'google_business_reviews'` Observation:

| Fact key | Type | Meaning |
|---|---|---|
| `google_business.rating_average` | float | Mean star rating across all fetched reviews |
| `google_business.rating_count` | integer | Total review count fetched |
| `google_business.rating_distribution` | json | Count per star value, `{1: n, 2: n, 3: n, 4: n, 5: n}` |
| `google_business.review_recency` | json | `{most_recent_at, days_since_most_recent}` |
| `google_business.owner_reply_rate` | float | Percentage of fetched reviews with a non-null `ownerReplyText` — a read-only observation of existing behavior, not something this milestone helps the business do |
| `google_business.rating_trend` | json | `{trend, older_average, newer_average}` — comparing the average of the older half vs. newer half of fetched reviews chronologically, exactly mirroring `instagram.engagement_trend`'s trend-computation shape from Milestone 12 Phase 2 |

Every Fact supersedes the prior value on each sync via the existing `FactService` mechanism — no special handling, per the established convention.

### 4.3 Business Brain

No change, per the exact same reasoning as Milestone 12: `BusinessBrainService::assemble()` already pulls `activeFacts` by `company_id` alone. The new `google_business.*` keys appear in `BusinessBrain.activeFacts` automatically.

### 4.4 Knowledge synthesis

No new code needed. `KnowledgeService::synthesizeForCompany()` already groups Facts by their top-level domain key (`explode('.', $fact->key)[0]`) and synthesizes one Knowledge entry per domain — `google_business` Facts automatically get their own synthesized Knowledge entry (subject: `google_business`) the moment they exist, through the exact same code path `instagram.*` and `business.*` Facts already go through. The one thing worth calling out explicitly: `KnowledgeService::buildBody()`'s array-value rendering (fixed during Milestone 12 Phase 2 to handle nested arrays via JSON fallback, see that milestone's CHANGELOG entry) already handles `google_business.hours`/`.rating_distribution`/`.rating_trend`'s nested-array shapes correctly — this milestone doesn't need to touch that method again.

---

## 5. Contribution to Marketing Health

Milestone 13's own spec (`docs/specs/Marketing-Health.md` §12) explicitly named **Review/Reputation Signal** as a future dimension, deferred specifically because "it needs a reviews connector" — this milestone is that connector. Two contributions, one to an existing dimension and one proposing a new one:

### 5.1 Existing dimension: Marketing Presence Coverage

Once a company's Google Business Profile `MarketingChannel` is linked (§2.3), `PresenceCoverageScorer` (already shipped, Milestone 13 Phase 1) automatically includes it in its weighted active-channel ratio — **zero code change**, because that scorer already reads all `MarketingChannel` rows for a company generically, exactly the source-agnostic discipline `docs/specs/Marketing-Health.md` §7 designed for. This is a concrete, immediate proof that discipline works, not a hypothetical.

### 5.2 Proposed new dimension: Reputation & Reviews

A new eighth `MarketingHealthScorer`, `ReputationScorer` (dimension key `reputation`), reading:
- `google_business.rating_average` and `.rating_count` — a business with a high average across many reviews scores well; low `rating_count` caps *confidence*, not the score itself (per Marketing Health's existing confidence-as-evidence-depth convention, §5.1 of that spec) — a 5.0 average from 2 reviews is a real but low-confidence signal, not a false 100.
- `.rating_trend` — a declining trend measurably lowers the score even at a high absolute average, the same "direction matters, not just level" reasoning `instagram.engagement_trend` already encodes for Social Activity.
- `.owner_reply_rate` — a business that engages with its reviews (even though Atlas doesn't help it do so, per §6) demonstrates active reputation management; folded in as a secondary, lower-weighted evidence input.

Formula sketch (constants configurable, per Marketing Health's own `config/marketing_health.php` precedent): `score = weighted(rating_score, trend_adjustment)`, where `rating_score` maps a 1.0–5.0 average onto 0–100 (e.g. `(average - 1) / 4 * 100`), and `trend_adjustment` nudges the score down for a declining trend and up (capped) for an improving one — deliberately not a separate multiplicative "modifier" layer, keeping this scorer's internal math self-contained the same way every other `MarketingHealthScorer` is. N/A (not zero) when the company has no `google_business_reviews` Observation yet, per Marketing Health's existing N/A discipline.

**This spec proposes the dimension; it does not implement it.** Adding an eighth dimension to a shipped Milestone 13 MVP is implementation work sequenced in `docs/plans/Milestone-14-Google-Business-Plan.md` (a phase, not a foregone architectural change) — the composite-score formula (`docs/specs/Marketing-Health.md` §4.2) already generalizes to any number of dimensions without modification, since it sums over whatever the registry contains.

---

## 6. New Opportunity Types

Both new types are **company-level** (no `subject_type`/`subject_id`, following `ReEngagementDetector`'s existing precedent for company-wide-signal opportunities, not catalog-item-level ones like `FeaturedItemDetector`), and both — like every existing detector — only *select a candidate deterministically*; the actual campaign copy is still generated by the existing AI content-generation pipeline (`ContentGenerationAnalyst` et al.), unchanged.

### 6.1 `review_milestone`

Fires when `google_business.rating_average`/`.rating_count` crosses a configurable, meaningful threshold (e.g. crossing 4.5+ average with 25+ reviews, or a round-number review-count milestone like 50/100/250) that hasn't already been used for a recent campaign (deduplication via the existing `hasDuplicateRecommendation`/cooldown mechanism, same as every other type). Recommends featuring the achievement as social proof — "50 five-star reviews and counting" — a natural fit for the existing Campaign pipeline's `featured_item`-adjacent framing, though it needs its own `campaign_type` (see `docs/plans/Milestone-14-Google-Business-Plan.md` for the schema implication).

### 6.2 `reputation_risk`

Fires when `google_business.rating_trend` shows a meaningful decline (configurable threshold, e.g. newer-half average at least 0.5 stars below older-half average) — mirroring `UrgencyDetector`'s "something time-sensitive and concerning" framing, but for reputation instead of inventory. This is **not** a recommendation to reply to reviews (explicitly out of scope, §7) — it's a signal that the business's public-facing story needs active, positive reinforcement through other channels (e.g. a testimonial-driven or customer-appreciation campaign) while the underlying issue (whatever is driving the rating decline) is presumably being addressed by the business owner through channels Atlas has no visibility into or role in.

### 6.3 What was deliberately not proposed

A `profile_incompleteness` opportunity type (missing hours/categories/photos) was considered and rejected for this spec: it doesn't fit the existing Opportunity → Decision → Campaign → Content Asset pipeline, which always produces marketing *content*, not an operational checklist item ("go fill in your hours"). That signal is better expressed as Marketing Health evidence (§5) — visible, explained, but not forced into an Opportunity type that doesn't have a natural campaign action.

---

## 7. Explicitly Out of Scope (Beta)

- **Publishing to Google Business Profile.** No posts, no updates, no offers — Atlas never writes to the profile.
- **Replying to reviews.** Owner-reply text is captured read-only (§3.3); Atlas never drafts or sends a reply. `google_business.owner_reply_rate` is an observed fact about existing behavior, not a feature Atlas provides.
- **Editing the profile** (hours, categories, photos, description) in any way.
- **In-app OAuth connect flow.** Beta scope is a manually-obtained, manually-pasted access token (§2.2), exactly matching Instagram Phase 1's own beta scope decision.
- **AI-based review sentiment/theme analysis.** Review text is captured and shown, never interpreted (§4.1).
- **Q&A writing** (Atlas answering questions on the business's behalf) — moot in beta since Q&A *reading* itself is uncertain-availability (§3.1), but stated explicitly for clarity.
- **Any other Google product** (Search Console, GA4, Google Ads) — named in Milestone 13's own future-dimensions list as separate, later connectors; not designed here.
- **Implementing the proposed eighth Marketing Health dimension** (§5.2) — proposed, not built, in this spec.

---

## 8. Sequence Diagrams

### 8.1 Connector sync (profile + reviews as two Observations, mirroring Instagram's two-ConnectorResult pattern)

```
GoogleBusinessConnector::sync($integration)
        │
        ├──▶ GoogleBusinessProfileFetcher::fetchProfile($accessToken)
        │        ──▶ ConnectorResult(source_type: 'google_business', payload: {name, categories, hours, photos, qanda?})
        │
        └──▶ GoogleBusinessReviewFetcher::fetchReviews($accessToken)
                 ──▶ ConnectorResult(source_type: 'google_business_reviews', payload: {reviews: [...]})
```

### 8.2 Observation → Fact → Knowledge (identical shape to Milestone 12)

```
Observation (google_business | google_business_reviews)
        ──▶ ProcessObservation ──▶ AnalystRegistry::resolve() ──▶ GoogleBusinessAnalyst::analyze()
                 │
                 ├──▶ FactService::storeExtracted()   (google_business.* Facts, superseding prior values)
                 ├──▶ KnowledgeService::synthesizeForCompany()   (unchanged — groups by 'google_business' automatically)
                 └──▶ MarketingHealthService::recompute($company)   (unchanged call site — ReputationScorer, once it exists, picks up the new Facts automatically)
```

### 8.3 Opportunity detection consuming the new Facts

```
OpportunityEngine::scan($company, $brain)
        │
        ├──▶ existing detectors (unchanged)
        ├──▶ ReviewMilestoneDetector (new) ──▶ reads google_business.rating_average/.rating_count
        ├──▶ ReputationRiskDetector (new) ──▶ reads google_business.rating_trend
        │
        └──▶ OpportunityScorer::score()   (unchanged formula, unchanged 0.30/0.25/0.25/0.20 weights)
```

---

## 9. Migration Strategy

- `observations.source_type` gains `'google_business'`, `'google_business_reviews'` — base migration updated for fresh databases, Postgres-only constraint-rewrite migration for existing ones, per the established two-migration pattern.
- `integrations.type` gains `'google_business'` — same two-migration pattern.
- `opportunities.type` gains `'review_milestone'`, `'reputation_risk'` — same pattern (this table's enum has been extended once already, for context: it currently holds `featured_item, urgency, new_arrival, re_engagement, seasonal, milestone`).
- `campaigns.campaign_type` gains two new values to represent the content angle these opportunity types imply (exact naming — e.g. `social_proof`, `reputation_response` — decided at implementation time in `docs/plans/Milestone-14-Google-Business-Plan.md`); `DecisionEngine::evaluate()`'s opportunity-type-to-campaign-type `match` and its `COOLDOWN_DAYS` map both need one new entry each per new type, the same single-line-per-type change every prior opportunity type addition has required.
- No new tables required for §3/§4 (profile/reviews are Observations + Facts, exactly like Instagram). If §5.2's proposed `ReputationScorer` is implemented in a later phase, it needs zero new tables either — `marketing_health_scores`/`(marketing_health_snapshots, if ever added)` already generalize to any dimension key.
- No changes to `channels`/`channel_credentials` — this milestone never touches the publishing subsystem (§2.2).

---

## 10. Future Phases (not designed here)

- Real in-app OAuth connect flow for Google Business Profile, mirroring `MetaOAuthService`'s PKCE pattern.
- Implementing the proposed `ReputationScorer` / eighth Marketing Health dimension (§5.2) — this spec proposes it; a later milestone phase builds it.
- AI-assisted review theme/sentiment summarization (e.g. "reviews frequently mention slow response times") — an AI-calling analyst addition, the same category as `WebsiteAnalyst`, not `InstagramAnalyst`/`GoogleBusinessAnalyst`'s deterministic style.
- Google Business Profile Performance API metrics (search views, map views, customer actions) — a distinct, lower-priority data source from profile/reviews; not designed here.
- Any write capability (publishing, review replies, profile edits, Q&A answers) — a fundamentally different, higher-risk subsystem than observation, explicitly deferred past Version 1.0's "one real publisher (email) first" priority ordering (`docs/plans/Version-1.0-Roadmap.md` §3, Integrations category).
- Search Console, GA4, Google Ads — separate connectors, named but not designed by Milestone 13 or this milestone.
