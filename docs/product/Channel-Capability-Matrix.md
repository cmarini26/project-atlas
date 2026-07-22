# Channel Capability Matrix

**Date:** 2026-07-20
**Status:** Canonical. This is the one authoritative source of truth for "what can Atlas actually do, per channel, today." Every other doc/UI surface (`docs/STATUS.md`, `docs/reviews/Channel-Publishing-Reality-Audit.md`, `channelCapability.ts`, Settings/Publishing/Dashboard/Campaigns UI copy) must agree with this matrix. If a provider registration, connect flow, or capability default changes, update this file in the same change.
**Scope:** Verified against current code (registries, controllers, enums) — not against design docs or plans. Citations are `file:line` against `backend/`.

---

## How to read this

Atlas's core loop is **Observe → Understand → Decide → Recommend → Prepare → Approve → Execute → Measure → Learn**. This matrix covers the six stages that vary meaningfully by channel type: **Observe, Draft, Approve, Execute, Measure, Learn**. ("Understand/Decide/Recommend/Prepare" are channel-agnostic reasoning stages that happen before a channel is even chosen, so they aren't broken out per channel here.)

Every cell is tagged with exactly one of these five categories:

| Tag | Meaning |
|---|---|
| **Real** | Implemented in code, exposed in customer-facing UX, and behaves correctly when genuinely connected — not yet verified against a live production account/site, but nothing stands between a real customer and a real result. |
| **Code-only** | Implemented in code, but no customer-facing UX path exists to reach it for a real company (demo/seed data only, or a controller action with no route/UI). |
| **Simulated** | The full pipeline runs to completion (drafted → approved → "executed"), but the terminal effect is a log line, not a real external send/call. |
| **Not supported** | No code path exists at all for this stage and channel — not draftable, not observable, not connectable. |
| **N/A** | The stage doesn't apply to this channel type by nature (e.g. Website has no "Execute" — it is observation-only). |

"Production validated" is a separate, cross-cutting fact, not a seventh category: **no channel in this codebase has ever been exercised against a real, live third-party account in this repo's test suite** — every provider is tested with HTTP-mocked (Guzzle `MockHandler`) requests only. This applies equally to WordPress, Meta, Postmark, SendGrid, and Twilio. Treat "Real" above as "correct against the mocked contract," not "proven in production."

---

## Master table

| Channel type | Observe | Draft | Approve | Execute | Measure | Learn |
|---|---|---|---|---|---|---|
| `blog` (WordPress) | Not supported | Real | Real (generic) | **Real** | Not supported | Not supported |
| `email` (Postmark, SendGrid) | Not supported | Real | Real (generic) | **Real** (single + multi-recipient) | **Real** | **Real** (email-specific + generic) |
| `facebook` (Meta) | Not supported | Real | Real (generic) | **Real** | **Real** | Real (generic) |
| `instagram` (Meta) | **Real** | Real | Real (generic) | **Real** | **Real** | Real (generic) |
| `linkedin` | Not supported | Real | Real (generic) | Simulated | Simulated (empty) | Not supported |
| `x` | Not supported | Real | Real (generic) | Simulated | Simulated (empty) | Not supported |
| `sms` (Twilio, single-destination) | Not supported | Real | Real (generic) | **Real** (single-destination only) | Simulated (empty) | Not supported |
| `landing_page` | Not supported | Real | Real (generic) | Simulated | Simulated (empty) | Not supported |
| `website` (observation-only, no `Channel` equivalent) | **Real** | N/A | N/A | N/A | N/A | N/A |

Note on "Approve": every channel type passes through the same generic, content-agnostic approval gate (`RecommendationController::approve()` → `ApprovalService::approve()`) — there is no channel-specific approval logic to differentiate. "Real (generic)" means the approval mechanism itself is real and correctly gates execution for every channel; it does not mean the channel executes for real afterward (see the Execute column).

---

## Per-channel detail

### `blog` (WordPress)

- **Observe:** Not supported. No connector exists for reading back from a WordPress site (`ConnectorServiceProvider.php` registers only Website and Instagram connectors — no WordPress read path).
- **Draft:** Real. `ContentGenerationAnalyst` maps `blog` → `BlogContentPrompt` (`app/Services/Analyst/Content/ContentGenerationAnalyst.php:37`).
- **Reachability:** Every company gets a default `blog` `Channel` seeded at signup (`app/Services/Company/CompanyService.php:51-56`) so `DecisionEngine` always has at least one active channel to recommend into — this seed alone does **not** mean a company has a working WordPress connection.
- **Execute:** Real. `SettingsController::connectWordPress()` (`app/Http/Controllers/App/SettingsController.php:237-286`) pings the site live via `WordPressPublisher::ping()` (`GET /wp-json/wp/v2/users/me`, HTTP Basic Auth) before ever persisting `status: 'active'` — a bad password or unreachable site is rejected, not silently accepted. `WordPressPublisher::publish()` (`app/Services/Publishing/WordPressPublisher.php:30-67`) then does a real `POST /wp-json/wp/v2/posts`, with a best-effort featured-image upload via `WordPressMediaUploader`. Registered ahead of the `LogChannelPublisher` fallback in `PublisherServiceProvider.php:52`.
- **Measure:** Not supported. No analytics provider exists for WordPress; `AnalyticsProviderRegistry` has no `blog`/WordPress entry at all (`app/Providers/AnalyticsServiceProvider.php`), so nothing is ever pulled back for a blog `Execution`.
- **Learn:** Not supported — a direct consequence of no Measure path; `LearningService`'s generic cross-channel signals (`checkChannelOutperformed`/`checkChannelUnderperformed`) only fire for channels with `ExecutionMetric` rows, which WordPress never produces.
- **Capability badge caveat:** `MarketingChannelType` (`app/Enums/MarketingChannelType.php`) has **no** `blog`/WordPress case, so there is no per-company `MarketingChannel.supports_publishing` flag WordPress can ever link to. `channelCapability.ts`'s global fallback for `blog` is `'draft_only'` everywhere the generic badge renders (`Publishing.vue`, `Dashboard.vue`, `Campaigns/Show.vue`), **even for a company with a genuinely live WordPress connection**. `Settings.vue`'s own `wordpress_channel.status` is the only accurate per-company signal today. This is a known, documented badge-truth gap — see "Remaining truth gaps" below.

### `email` (Postmark, SendGrid)

- **Observe:** Not supported. No inbound-reading connector exists.
- **Draft:** Real. `ContentGenerationAnalyst` maps `email` → `EmailContentPrompt` (`ContentGenerationAnalyst.php:35`).
- **Execute:** Real, and still the most complete channel in the codebase.
  - `SettingsController::connectEmail()` (`SettingsController.php`) is now **provider-aware**, not Postmark-only: it validates the submitted `provider_type`, resolves the matching provider from `EmailProviderRegistry`, and only persists `status: 'active'` after a real provider `ping()` succeeds.
  - `PublisherServiceProvider` now registers **two real email providers** ahead of the log fallback: `PostmarkEmailProvider` and `SendGridEmailProvider`. `SendGridEmailProvider` makes real `POST /v3/mail/send` calls and a real `/v3/user/profile` ping against SendGrid's API, using the same `EmailProvider` contract as Postmark.
  - Single-recipient send (`Channel.config.to_email`) and **real multi-recipient audience send** both work: `EmailAudienceService` owns company-scoped contacts/audiences/membership; `ExecutionService::queueForCampaign()` snapshots the selected audience once at Execution-creation time (`EmailAudienceService::snapshotIfApplicable()`); `EmailPublisher::publishToAudience()` (`app/Services/Publishing/EmailPublisher.php:87-184`) sends one real `EmailProvider::send()` call per pending recipient, recording each outcome on its own `email_recipient_snapshots` row so one bad address never blocks or fakes success for the rest.
  - `Campaigns/Show.vue`'s "Send outcomes" block (fed by `CampaignController::show()`'s `recipient_outcomes` prop) shows real pending/accepted/failed/skipped counts to the operator, using "Accepted by provider" rather than "Delivered" — provider acceptance is genuinely all this layer tracks.
- **Measure:** Real. `PostmarkAnalyticsProvider::pull()` calls Postmark's real message-details API; `normalize()` emits both Postmark-specific keys (`delivered`, `bounces_hard`, `spam_complaints`, `unsubscribes`, `open_rate`) and the canonical cross-channel keys `normalised_reach`/`normalised_engagement`/`normalised_clicks` (fixed 2026-07-16 — previously silently aggregated as zero reach/engagement for every real Postmark send, since the two canonical keys were missing entirely).
- **Learn:** Real. `LearningService` has three email-specific signals (`checkEmailDeliverability`, `checkHighUnsubscribeRate`, `checkOptimalTiming`) plus the generic cross-channel signals every measured channel gets.
- **Capability badge:** `MarketingChannelType::Email` exists, so email fully participates in the per-company override path — `resolveChannelCapability()` returns `'connected'` for a company whose declared Email `MarketingChannel` has `supports_publishing: true`, correctly overriding the conservative global `'draft_only'` default.

### `facebook` / `instagram` (Meta)

- **Observe:** `instagram` only — real. `InstagramConnector` (`app/Services/Observatory/Connectors/Instagram/InstagramConnector.php`) fetches a real profile snapshot and up to `INSTAGRAM_MEDIA_LIMIT` recent posts via the Graph API, feeding both profile and content-derived Facts. `facebook` has no observation connector — Meta observation is Instagram-only.
- **Draft:** Real for both. `ContentGenerationAnalyst` maps `facebook`/`instagram` (and `linkedin`/`x`) → `SocialContentPrompt`.
- **Execute:** Real for both. `MetaOAuthController` runs a full PKCE OAuth flow — a fake/expired code fails the token exchange itself, so reaching the end of `callback()` is real verification, not a separate ping step. `MetaChannelPublisher` (`app/Services/Publishing/MetaChannelPublisher.php`) makes real Graph API calls (two-step container→publish for Instagram, one-step photo post for Facebook).
- **Measure:** Real for both. `MetaAnalyticsProvider::pull()` calls the real Graph Insights API and emits the canonical `normalised_reach`/`normalised_engagement`/`normalised_clicks` keys.
- **Learn:** Real (generic cross-channel signals only — no Meta-specific `LearningService` check exists today, unlike email's three dedicated checks).
- **Capability badge:** Both types are per-company-overridable. `MetaOAuthController::callback()`/`revoke()` and the recurring `CheckChannelHealth` job keep `supports_publishing` in sync with live connection state, so the badge correctly shows `'connected'` only once a company has actually connected Meta.

### `linkedin`, `x`, `landing_page`

- **Observe:** Not supported — no connector for any of the three.
- **Draft:** Real. `linkedin`/`x` share `SocialContentPrompt`; `landing_page` → `LandingPageContentPrompt`.
- **Reachability:** No real UI/controller path lets a company create a `Channel` row of any of these three types — no onboarding step, no Settings action, no OAuth flow. `linkedin`/`x` exist as `MarketingChannelType` cases (so they're declarable as marketing *presence*, e.g. "we have a LinkedIn page") but nothing links that declaration to a real, sendable `Channel`. `landing_page` has no `MarketingChannelType` case at all.
- **Execute:** Simulated only. All three fall through to `LogChannelPublisher` (`app/Services/Publishing/LogChannelPublisher.php:48-52` lists all three in its `supports()`), which writes a log line and returns a fake successful `ExecutionResult` — structurally unreachable in production regardless, since no company can end up with a channel of these types.
- **Measure:** Simulated/empty. No analytics provider matches these `provider_type`s, so `AnalyticsProviderRegistry` falls back to `LogAnalyticsProvider`, which returns an empty metrics array — not an error, just nothing to show.
- **Learn:** Not supported — no `ExecutionMetric` rows are ever produced for these types, so no learning signal can fire.

### `sms` (Twilio, single-destination)

- **Observe:** Not supported. No inbound-reading connector exists.
- **Draft:** Real. `ContentGenerationAnalyst` maps `sms` → `SmsContentPrompt`.
- **Reachability:** Real, but narrow. `SettingsController` now exposes `/app/settings/sms/connect`, `/app/settings/sms/revoke`, and `/app/settings/sms/test`; `SmsChannelService::connect()` creates/updates a real company-scoped `sms` `Channel` plus a `channel_credentials` row with `provider_type: 'twilio'`, persisting `from_number` and an optional default `to_number` in `Channel.config`.
- **Execute:** Real, but intentionally scoped to **single-destination SMS**, not a full audience/list model. `PublisherServiceProvider` registers `SmsPublisher` ahead of `LogChannelPublisher`; `SmsPublisher::publish()` resolves the real `sms` channel, loads credentials, renders the asset body, then calls the provider returned by `SmsProviderRegistry` (currently `TwilioSmsProvider`) with the configured `from_number` and either the channel's configured `to_number` or the payload fallback if present. If either number is missing, it fails loudly instead of faking success.
- **Measure:** Simulated/empty. No Twilio analytics provider or webhook handler exists yet, so an SMS execution currently has no real `ExecutionMetric` retrieval path.
- **Learn:** Not supported. No SMS-specific `LearningService` checks exist, and without Measure there is no metric-driven generic learning either.
- **Capability badge caveat:** `MarketingChannelType` still has **no** `sms` case, so the generic capability-badge system has no per-company `supports_publishing` override path for SMS yet. The Settings surface is the accurate source of truth for whether a company's Twilio connection is live; generic badge rendering elsewhere still lacks a first-class SMS-specific connected state.

### `website` (observation-only)

- **Observe:** Real. `WebsiteConnector`/`WebPageCrawler` crawl a company's site (up to `crawler.max_pages` pages), extracting body text and up to 5 representative images per page.
- **Draft / Approve / Execute / Measure / Learn:** N/A. Website has no `Channel` publishing equivalent — it exists purely as an observation source (`MarketingChannelType::Website`, `hasChannelEquivalent(): false`) that feeds Facts/Knowledge into recommendation reasoning. It is never itself a destination a campaign publishes to.

### Declared-presence-only types with zero pipeline participation

`MarketingChannelType` also includes `youtube`, `tiktok`, `google_business_profile`, `events`, and `print` — a company can *declare* having these (via onboarding or `/app/settings/marketing-presence`) for Marketing Health/coverage purposes, but none of them has a `Channel` equivalent, an observation connector, draft content generation, or any pipeline participation today. `google_business_profile` observation was designed (Milestone 14, `docs/specs/Google-Business-Intelligence.md`) but never implemented — `ConnectorServiceProvider` registers only Website and Instagram.

---

## Cross-cutting facts

- **Draft is real for all eight publishing channel types.** Content generation has always been ahead of execution in this codebase — every channel type has a working prompt, even ones that can never be reached or executed for real.
- **Approve is channel-agnostic and always real.** The approval gate itself never varies by channel; what varies is what happens *after* approval.
- **Five channel types now execute for real in code: `blog`, `email`, `facebook`, `instagram`, `sms`.** WordPress, provider-aware Email (Postmark/SendGrid), Meta, and single-destination Twilio SMS all have a real customer-reachable connect path plus a real publisher registered ahead of the log fallback. `linkedin`, `x`, and `landing_page` remain simulated-only and structurally unreachable.
- **Only `email` closes the full Observe-through-Learn loop with a genuinely rich Measure/Learn story** — three dedicated `LearningService` checks beyond the generic cross-channel ones. Meta closes Measure/Learn generically. WordPress closes neither.
- **Instagram is the only channel that is both observable and executable** — every other channel is one or the other, never both.
- **No channel has been verified against a real, live third-party account.** All publisher/analytics-provider tests use HTTP-mocked requests. Treat every "Real" tag above as "correct against the mocked contract, not yet production-validated" per Phase 6 of the production-readiness gap plan.

---

## Remaining truth gaps (not fixed by this doc)

1. **WordPress cannot show "Connected" via the generic capability badge**, even when genuinely connected — `MarketingChannelType` has no `blog` case, so there's no per-company override path. `Settings.vue`'s own `wordpress_channel.status` remains the only accurate per-company signal. Giving WordPress a `MarketingChannelType` equivalent is a real product decision (is a WordPress blog "declared" the same way Instagram is?), not a truth-audit fix — tracked as a follow-up in `docs/reviews/Channel-Publishing-Reality-Audit.md`.
2. **No per-recipient drill-down view for email** — `Campaigns/Show.vue` shows aggregate send-outcome counts only; there is no privileged view for an operator to see which specific recipient failed or why.
3. **SMS has no audience/list model, analytics, or learning loop yet** — the new Twilio path is a real single-destination publisher, not a full campaign-distribution system.
4. **Facebook has no observation connector** — only Instagram does, despite both being reachable via the same Meta OAuth flow. If Facebook Page insights/posts become valuable to observe, this is additive work, not a fix to anything broken.
5. **Google Business Profile observation remains designed, not implemented** — Milestone 14's spec exists; no connector code does.
6. **No channel has a production verification run against a real external account** — see "Cross-cutting facts" above. This is Phase 6 (production infrastructure/operational readiness) of the production-readiness gap plan, not a Phase 0 doc-truth item.
