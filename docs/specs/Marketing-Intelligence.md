# Marketing Intelligence — Milestone 12

This document is the canonical spec for Milestone 12, "Marketing Intelligence" — the effort that turns Atlas's Instagram connection from a single profile snapshot into an ongoing source of business understanding. It follows `specs/core/domain-model.md`'s conventions and terminology; where this doc is silent, that one governs.

---

## Phase 1 — Instagram Observation (Beta) — shipped

*Recap of what already exists, for context. Not being changed by Phase 2.*

A company connects Instagram from Settings via a manually-pasted access token (beta scope: no OAuth, one account per company, no historical import). `InstagramConnector` (`app/Services/Observatory/Connectors/Instagram/InstagramConnector.php`) implements the existing `Connector` contract and is resolved by the existing `ConnectorRegistry` — no new observation infrastructure. On sync, `InstagramProfileFetcher` calls the Instagram Graph API (`GET {base_url}/me`) for a single current profile snapshot (id, username, name, biography, website, profile_picture_url, followers_count, follows_count) and the connector records it as one `Observation` (`source_type: 'social'`).

`InstagramAnalyst` implements `ObservationAnalyst` (not the AI-calling `Analyst` marker interface — profile fields are already structured, so mapping them to Facts is deterministic key/value translation, not extraction) and is resolved by `AnalystRegistry` exactly like `WebsiteAnalyst`. It produces `instagram.username`, `instagram.display_name`, `instagram.bio`, `instagram.website`, `instagram.follower_count`, `instagram.following_count` Facts, and as a side effect keeps a typed `InstagramAccount` row in sync via `InstagramAccountService`. `ProcessObservation` and `BusinessBrainService` required zero changes — both are already source-agnostic.

---

## Phase 2 — Instagram Content Intelligence — this spec

### Goal

Atlas should understand not just *that* a company has an Instagram account, but *how they actually use it*: how often they post, what kind of content, whether they use hashtags and calls-to-action, and (where the API provides it) whether engagement is trending up or down. This is observation and understanding only — **no publishing, scheduling, or write access to Instagram of any kind.**

### Out of scope (explicitly, for this phase)

Publishing, scheduling, Stories, Comments, DMs, Ads, competitor analysis, any platform other than Instagram.

### 1. Connector — recent post retrieval

`InstagramConnector` gains a second collaborator, `InstagramMediaFetcher` (mirrors `InstagramProfileFetcher`'s shape and Guzzle-injection convention exactly). On `sync()`, alongside the existing profile `ConnectorResult`, it now also fetches up to a configurable number of recent posts (default 20, `config('instagram.media_limit')` / `INSTAGRAM_MEDIA_LIMIT`) via `GET {base_url}/me/media` with fields `id,caption,timestamp,media_type,permalink,like_count,comments_count`, and returns a **second** `ConnectorResult` with a new source type, `'social_content'`.

Two source types are used — not one — because the profile snapshot and the recent-posts payload have incompatible shapes, and `InstagramAnalyst::analyze()` needs to know which one it's looking at without sniffing the payload. `'social_content'` is added to `observations.source_type` the same way `'social'` was added in Phase 1: the base `create_observations_table` migration updated for fresh databases, plus a Postgres-only constraint-rewrite migration for already-migrated databases.

Per-post capture, as `InstagramMediaItemData` (mirrors `InstagramProfileData`):
- `caption`, `timestamp`, `media_type`, `permalink` (all as returned by the API — permalink may be absent for some media types)
- `like_count`, `comments_count` — nullable; not every account/media type exposes these
- `hashtags` — extracted from the caption at fetch time via `#(\w+)`
- `mentions` — extracted from the caption at fetch time via `@(\w+)`

### 2. Storage

Recorded as an ordinary `Observation` (`source_type: 'social_content'`) via the existing `ObservationService`/`ConnectorResult` pipeline — no new table. The raw payload is the array of post objects (each with the fields above) plus the fetch timestamp and the limit used.

### 3. Analyst — deterministic content facts

`InstagramAnalyst` is extended, not replaced: `supports()` now matches both `'social'` and `'social_content'`; `analyze()` branches on `$observation->source_type` and dispatches to the existing profile logic (unchanged) or a new `analyzeContent()` method. Both remain deterministic — no AI call, for the same reason Phase 1's profile mapping isn't one: the input is already structured.

Facts produced from a `'social_content'` observation (all under the `instagram.` namespace, all recomputed and superseding the prior value on each sync, via the existing `FactService` supersession mechanism — no special handling needed):

| Fact key | Type | Meaning |
|---|---|---|
| `instagram.posting_cadence` | float | Average posts per week, derived from the span between the oldest and newest fetched post. Omitted if fewer than 2 posts. |
| `instagram.media_mix` | json | Count per `media_type` (e.g. `{"IMAGE": 12, "VIDEO": 5, "CAROUSEL_ALBUM": 3}`). |
| `instagram.hashtag_usage` | json | `{avg_per_post, top: [{tag, count}]}` (top 5). |
| `instagram.cta_usage` | float | Percentage of posts whose caption matches a known call-to-action phrase (e.g. "link in bio", "shop now", "dm us"). |
| `instagram.content_distribution` | json | Post count per day-of-week, derived from each post's timestamp. |
| `instagram.engagement_trend` | json | `{avg_likes, avg_comments, trend}` — only produced when at least one fetched post has non-null engagement counts; `trend` compares the average of the earlier half of posts to the later half (`increasing`/`decreasing`/`flat`). |

An empty post list (a real, valid state — the account just hasn't posted) produces no facts and is not an error. A payload missing the `posts` key entirely is malformed and throws `FactExtractionFailedException`, consistent with Phase 1's existing error convention.

### 4. Business Brain

No change. `BusinessBrainService::assemble()` already pulls `activeFacts` by `company_id` alone; the new `instagram.*` keys appear in `BusinessBrain.activeFacts` automatically, in the same collection as every other Fact, the moment they're stored.

### 5. UI — read-only "Instagram Insights"

A new, read-only section on the existing Marketing Presence page (`resources/js/Pages/App/Settings/MarketingPresence/Index.vue`), fed by a new `instagram_insights` prop from `MarketingPresenceController::index()` (null when the company has no Instagram Facts yet). Shows:
- Last sync (`Integration.last_run_at` for the company's `instagram` integration — the same field every integration already updates, not something new)
- Recent posting frequency (`instagram.posting_cadence`)
- Content mix (`instagram.media_mix`)
- Top observations: top hashtags (`instagram.hashtag_usage.top`)

No edit affordances — this section is strictly informational, unlike the rest of the Marketing Presence page.

### Test plan

Guzzle `MockHandler` for `InstagramMediaFetcher` (mirroring `InstagramProfileFetcherTest`); `InstagramConnectorTest` additions for the second `ConnectorResult`; `InstagramAnalystTest` additions for each derived fact, the empty-posts case, and the malformed-payload case; a `InstagramContentBusinessBrainIntegrationTest` mirroring Phase 1's brain-integration test; `MarketingPresenceController`/`Index.vue` tests for the new insights prop and section. Tenant isolation verified the same way every other company-scoped test in this codebase does: two companies, assert no cross-contamination.

### Verification

`./vendor/bin/pint --test`, `./vendor/bin/phpstan analyse --memory-limit=1G`, `php artisan test`, `npx vue-tsc --noEmit`, `npx vitest run`, `npm run build`.

---

## Future phases (not specified yet)

Historical backfill, Stories/Reels-specific handling, competitor observation, and any other social platform are all deliberately left for later milestones and are not designed here.
