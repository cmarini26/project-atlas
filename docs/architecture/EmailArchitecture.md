# Email Architecture — Path to Production

**Status:** Architecture proposal for Phase 1 of `backend/.hermes/plans/2026-07-15_094741-atlas-production-readiness-gap-plan.md`.
**Scope:** Design only. This document does not change any code.

---

## 0. A finding that shapes this whole document

Before proposing anything new, this section reports what already exists, because it changes the shape of the work more than a from-scratch reading of the plan would suggest.

Project Atlas already has a **real, working Postmark email provider** — not a stub:

- `App\Services\Publishing\Email\PostmarkEmailProvider::send()` makes a real `POST /email` call to Postmark's API, with real 429-retry backoff, and returns a real Postmark `MessageID`.
- `App\Services\Analytics\PostmarkAnalyticsProvider` pulls real per-message delivery/open/click/bounce/complaint/unsubscribe events from Postmark's message-details endpoint and normalizes them.
- `App\Services\Analytics\Webhooks\PostmarkWebhookHandler` already verifies Postmark's HMAC webhook signature and parses `Delivery`, `Open`, `Click`, `Bounce`, and `SpamComplaint` events, routed through a real generic multi-provider webhook endpoint (`POST /api/analytics/webhooks/{provider}` → `AnalyticsWebhookController` → `WebhookHandlerRegistry`).
- `App\Jobs\RetrieveExecutionMetrics`, `App\Services\Analytics\CampaignKpiService`, and the `execution_metrics`/`campaign_kpi_snapshots` tables already store and aggregate real metrics.
- `App\Jobs\PublishContent` (4 tries, `[60s, 300s, 900s]` backoff) and `App\Jobs\PublishScheduledContent` (every 5 minutes, drives `Execution.scheduled_at`) already provide a real, generic, retrying, idempotent send pipeline that `EmailPublisher` already plugs into.
- `App\Enums\MarketingChannelType::Email` already exists and already participates in the capability-truth system this session's earlier slices wired up (`MarketingPresenceService::markPublishingVerified()`, `CheckChannelHealth`) — unlike `blog`/WordPress, email has no missing domain-model piece here.

**What's actually missing is narrower than "build email from scratch":**

1. No product UX or controller action exists to let a real company connect a real email provider. `ChannelCredentials` rows with `provider_type: 'postmark'` are only ever created by `DemoSeeder`.
2. `App\Domain\Publishing\ValueObjects\EmailPayload` supports exactly **one recipient** (`toEmail`/`toName`, singular) — there is no recipient list, audience, or subscriber model at all. A "campaign" today can send to one address.
3. Postmark's `SubscriptionChange` webhook event is already *counted* (`ProcessAnalyticsWebhookEvent` increments a `webhook_unsubscribes` counter on the metric row) but nothing *suppresses future sends* to that address — there is no unsubscribe/bounce suppression list anywhere in the schema.
4. Only one provider (Postmark) is implemented; the interface is real but has one real implementation.
5. No domain/sender verification UX (SPF/DKIM guidance, sender identity confirmation) exists.

This document is written against that reality. Where a section describes something that already exists, it says so and scopes the work to *extending* it, not replacing it — per the constraint to avoid premature abstraction and favor the existing channel architecture.

---

## 1. Goals

- A real company can connect a real email-sending provider from Settings, with credentials verified live before being reported as connected (matching the pattern already shipped for WordPress and Meta).
- An approved email campaign sends for real, to a real recipient or recipient list, through the connected provider.
- Send outcomes (delivered, bounced, opened, clicked, complained, unsubscribed) are captured and folded into Atlas's existing analytics and learning loop — not a parallel one.
- Atlas never sends to an address that has bounced (hard) or unsubscribed — this is a correctness requirement, not a nice-to-have, both for deliverability and for law (CAN-SPAM / CASL / GDPR all require unsubscribe honoring).
- The existing product-truth system (`resolveChannelCapability()`, `ChannelCapabilityBadge`) can show `'connected'` for a company's real email channel, the same way it now can for Meta.
- Human approval remains required before any external send — nothing in this document proposes auto-sending an unapproved campaign.

## 2. Non-goals

- **Not building a general-purpose ESP/CRM.** No list-building UI beyond what a single company's campaign audience needs, no drip/automation builder, no A/B testing engine.
- **Not supporting every provider on day one.** Three initial providers (§3), not eight.
- **Not building OAuth for email in this phase.** No major transactional-email provider (Postmark, Resend, SES, SMTP) requires OAuth for API-key-based sending; a Gmail/Workspace "send as this Google account" OAuth flow is a distinct, later feature (see §3, Future).
- **Not building recurring/RRULE scheduling in this phase** — see §9.
- **Not redesigning `ChannelPublisher`, `EmailProvider`, `EmailProviderRegistry`, or the `PublishContent`/`ExecutionService` pipeline.** They work; this phase extends them.
- **Not inventing a second capability or credentials system.** `ChannelCredentials`, `MarketingChannel.supports_publishing`, and `resolveChannelCapability()` are the only truth sources; email plugs into them exactly like Meta does.

## 3. Supported providers

**Initial (Phase 1):**

| Provider | Why | Auth shape |
|---|---|---|
| **Postmark** | Already implemented end-to-end (send, ping, analytics pull, webhook parsing). The only work is the connect UX (§5) and the recipient/suppression model (§6–7). Lowest-risk, highest-leverage first target. | Single server API token |
| **Resend** | Modern, small-business-friendly, generous free tier, simple API (`POST /emails`), webhook model very close to Postmark's (JSON, HMAC-signed). Good second provider to prove the abstraction generalizes beyond Postmark's exact shape. | Single API key |
| **SMTP** | Every business either already has a mailbox provider (Google Workspace, Microsoft 365, a hosting-provided SMTP relay) or can get one trivially, with zero new vendor relationship. This is the "I don't want to sign up for another service" fallback. | Host/port/username/password/encryption |

Note on scope: the task's brief listed Resend/SES/SMTP as the initial set with Postmark deferred to "future." Given Postmark is already ~90% built in this codebase, **the recommendation here is to make Postmark the first live provider** (fastest real path to "email genuinely sends"), and treat Resend + SMTP as the next two — both worth having initially because they cover "modern API-key ESP" and "the business's own mailbox" as the two realistic small-business starting points. SES is pushed to Future below because it requires AWS IAM credential handling (a materially different credential shape and a sending-domain-verification flow gated by AWS's own sandbox/production-access approval process) — real complexity that doesn't buy much over Resend+SMTP for a first slice.

**Future:**

| Provider | Why deferred |
|---|---|
| **Amazon SES** | Real value (cheapest at scale) but AWS IAM key handling + SES sandbox-to-production approval is its own workstream, not a drop-in `EmailProvider` implementation. |
| **Mailgun** | Materially similar to Resend/Postmark; adds provider count without adding a new *kind* of integration. Good "provider #4" once the abstraction has proven itself twice. |
| **SendGrid** | Same reasoning as Mailgun — real demand exists, but no new architectural shape to prove. |
| **Google Workspace / Microsoft 365 via OAuth "send as"** | Real business value (send from the owner's actual business email, no separate ESP signup) but requires an OAuth flow (like Meta's), Gmail/Graph API sending scopes, and materially different rate limits/deliverability behavior. Worth its own design pass once API-key providers are proven. |

## 4. Provider abstraction

**Keep the existing interface exactly as it is** — it already correctly separates concerns and every new provider (Resend, SMTP) implements it unchanged:

```php
interface EmailProvider
{
    public function send(EmailPayload $payload, ChannelCredentials $credentials): string; // returns provider message ID
    public function ping(ChannelCredentials $credentials): PingResult;                     // live credential check, no send
    public function supports(string $providerType): bool;                                  // 'postmark' | 'resend' | 'smtp'
}
```

Responsibilities, unchanged from today's `PostmarkEmailProvider`:

- **`send()`** is the only place that talks to the provider's transport. It receives an already-rendered `EmailPayload` (subject/from/body — rendering is `EmailRenderer`'s job, untouched) and already-resolved `ChannelCredentials` (fetching credentials is `ChannelCredentialsRepository`'s job, untouched). It throws `PublishingException` with an explicit `retryable` flag on failure — `PublishContent`'s existing retry logic already reads that flag; providers must set it correctly (5xx/429/timeout → retryable; 4xx validation/auth errors → not retryable), exactly as `PostmarkEmailProvider` does today.
- **`ping()`** performs one cheap, side-effect-free API call proving the credentials are live (Postmark: `GET /server`; Resend: `GET /api-keys` or equivalent; SMTP: an actual `EHLO`/`AUTH` handshake with no `DATA` sent). This is what the connect flow (§5) calls before ever reporting "connected," and what `CheckChannelHealth` already calls on a recurring schedule for every non-revoked credential row, for every channel type — email needs no changes there, only a working `ping()` per provider.
- **`supports()`** is a plain string match against `ChannelCredentials.provider_type`, exactly as today.

`EmailProviderRegistry` needs **zero changes** — it's already a plain first-match-wins list; adding Resend/SMTP is `$registry->register(new ResendEmailProvider(...))` in `PublisherServiceProvider::boot()`, nothing else.

**`EmailPayload` needs one real change** (not a redesign): today it carries a single `toEmail`/`toName` pair. A recipient-list send needs the provider's `send()` called once per recipient (or, for providers with real batch-send APIs, once per batch) — see §6 for why this is a sending-pipeline change, not a `send()` signature change: `send()` keeps its single-recipient shape, and something above it (a new `RecipientResolver` step in `PublishContent`, or a new per-recipient `Execution`) is responsible for calling it multiple times. This keeps the interface simple and keeps batching a pipeline concern, not a provider concern — providers that *do* have a real batch API (Postmark's `POST /email/batch`) can still expose it as a second, provider-specific optimization later without touching the interface.

SMTP is the one provider whose credentials genuinely don't fit `{access_token}`/`{server_token}`-shaped JSON — its `credentials` JSON blob is `{host, port, username, password, encryption}` instead. No interface change needed; `SmtpEmailProvider::send()` just decodes a differently-shaped JSON blob, exactly as `WordPressPublisher::decode()` and `MetaChannelPublisher::decode()` already each decode their own provider-specific shape from the same `ChannelCredentials.credentials` column today. This is the existing, correct pattern — not a new one.

## 5. Company connection model

**Credentials storage: no schema change.** `channel_credentials` already has `company_id`, `channel_type` (`'email'`), `provider_type` (`'postmark'|'resend'|'smtp'`), an encrypted `credentials` JSON blob, and `status` (`active|expired|error|revoked`) — exactly what email needs, with the existing `unique(company_id, channel_type)` constraint meaning **one active email provider per company at a time** (matches how Meta/WordPress already work; a company reconnecting with a different provider replaces the row, it doesn't add a second one — no premature multi-provider-per-company support).

**OAuth vs. API keys:** all three Phase 1 providers are **API-key-based**, not OAuth — this is a real, deliberate simplification for this phase (see §2/§3). The connect UX is a plain form, structurally identical to `SettingsController::connectWordPress()`:

```
POST /app/settings/email/connect
  provider_type: 'postmark' | 'resend' | 'smtp'
  + provider-specific fields:
      postmark/resend: { api_key }
      smtp:            { host, port, username, password, encryption }
```

**Verification flow — mirrors the WordPress fix from earlier this session exactly:**

1. Validate input shape (required fields per provider).
2. Build the credentials JSON, but do **not** persist as `active` yet.
3. Call `$provider->ping($credentials)` for the submitted provider type.
4. If unreachable: persist `ChannelCredentials` with `status: 'error'`, return a field-level validation error with the real reason (`PingResult->error`) — never report "connected" for unverified credentials. (Exactly `SettingsController::connectWordPress()`'s current behavior.)
5. If reachable: persist `status: 'active'`, create/update the `email` `Channel` row, and — the piece Meta's OAuth connect added this session — link and mark the declared `MarketingChannel` (`type: 'email'`, already a valid `MarketingChannelType`) as `supports_publishing: true` via `MarketingPresenceService::link()` + `markPublishingVerified()`. No new capability-resolution code; email uses the exact mechanism Meta already uses.

**Test email — new, small, concrete requirement not covered by `ping()`:** `ping()` proves the credentials are valid; it does not prove a message can actually reach an inbox (a valid API key can still fail to deliver — wrong sender domain, unverified sender identity, provider account restrictions). Add one small additional action:

```
POST /app/settings/email/test
  to_email: string
```

This calls the already-connected provider's `send()` with a fixed, small, non-campaign `EmailPayload` ("This is a test email from Atlas for {{company.name}}.") and reports success/failure inline — reusing `EmailPublisher`'s existing render → send path, not a parallel one. This is the one genuinely new small piece of send-path plumbing this phase needs beyond wiring the connect controller.

**Sender identity / domain verification:** out of scope for Phase 1 beyond capturing a `from_email`/`from_name` on the `Channel.config` (same pattern as WordPress's `config.site_url`). Real domain verification (SPF/DKIM/DMARC record guidance, provider-side domain-verification status) is provider-specific UX best deferred to Phase 2 — Postmark, Resend, and SES all have different verification-status APIs, and getting deliverability advice wrong is worse than not giving it yet.

## 6. Sending pipeline

**Already real, unchanged:** `Campaign` (approved) → `PrepareCampaign`/`GenerateContent` produce a `ContentAsset` → `Execution` row (`status: queued`, `idempotency_key`) → either `PublishCampaign` dispatches `PublishContent` immediately (`scheduled_at: null`) or `PublishScheduledContent` (every 5 min) dispatches it once `scheduled_at` has passed → `PublishContent::handle()` re-loads the `Execution` fresh (idempotency guard against a job running twice), resolves the channel's publisher via `ChannelPublisherRegistry`, calls `publish()`, and records the result via `ExecutionService` — 4 tries, `[60s, 300s, 900s]` backoff, `PublishingException->retryable` deciding whether a failure is worth retrying. **Email needs none of this rebuilt.**

**What email adds: the recipient dimension.** Today one `Execution` = one `ContentAsset` = (implicitly) one recipient, because `EmailPayload` only carries one `toEmail`. A real email campaign needs to reach a list. Two shapes were considered:

- **Rejected: one `Execution` per recipient.** Would multiply `Execution` rows by list size (thousands for a real newsletter), overloading a table designed around "one row per channel-send," and forcing every existing execution-listing UI (`Publishing.vue`, `Campaigns/Show.vue`) to paginate campaign-sized recipient lists instead of channel sends.
- **Recommended: one `Execution` per campaign-channel send (unchanged), with a new `email_recipients` table (§10) resolved and iterated *inside* `EmailPublisher::publish()`.** `EmailPublisher` resolves the company's audience for this send (a stored list, or — Phase 1 minimum — a single configured recipient/test address on `Channel.config`, same shape as today) and calls `$provider->send()` once per recipient, aggregating successes/failures into the single `Execution`'s `result` JSON column (`{sent: [...messageIds], failed: [...]}`). A batch partially failing is **not** a full `Execution` failure — `Execution.status` reflects "did the send attempt run," not "did every single recipient succeed"; per-recipient outcomes live in `result` and, once webhooks arrive, in per-recipient `ExecutionMetric`-adjacent rows (§7/§10).

This keeps `PublishContent`/`ExecutionService`/the retry model completely untouched — the recipient loop is entirely inside `EmailPublisher`, which is exactly where provider-specific behavior already lives (WordPress's featured-image upload loop is the existing precedent for "a publisher doing multiple sub-requests inside one `publish()` call").

**Failure handling, unchanged behavior, extended data:** `PublishingException->retryable` still decides retry eligibility at the `Execution` level (e.g., the provider was rate-limited — retry the whole batch) versus per-recipient failures (e.g., one bad address in an otherwise-good list — don't retry the whole `Execution`, just don't re-send to that one address, and let the suppression list in §7 stop it going forward).

**Audit logging:** `App\Models\ExecutionAttempt` (via `Execution::attemptLogs()`, already used by `ExecutionService::logAttempt()`) already logs every attempt. Extend it with a `recipient_count`/`recipient_failures` summary field so a support engineer can see "sent to 480/500, 20 rejected" without opening `result` JSON — small addition, not a new logging system.

## 7. Tracking

**Already real:** `AnalyticsWebhookController` (`POST /api/analytics/webhooks/{provider}`, HMAC-verified, rate-limited) → `WebhookHandlerRegistry` → `PostmarkWebhookHandler::parse()` already turns Postmark's `Delivery`/`Open`/`Click`/`Bounce`/`SpamComplaint` payloads into normalized `WebhookEvent`s → `ProcessAnalyticsWebhookEvent` job increments per-type counters on the matching `ExecutionMetric` row (matched by `platform_id` = provider message ID).

**Real gap: Unsubscribe/suppression.** `SubscriptionChange` events are already counted (`webhook_unsubscribes`) but nothing acts on them. Add:

1. A `email_suppressions` table (§10) — `company_id`, `email_address`, `reason` (`unsubscribed|hard_bounce|complaint`), `suppressed_at`.
2. `ProcessAnalyticsWebhookEvent` (or a new small listener on the same event) inserts a suppression row when `eventType` is `bounce` with a hard-bounce metadata flag, `complaint`, or a new `unsubscribe` event type (Postmark's `SubscriptionChange`, Resend's `email.contact.unsubscribed`).
3. `EmailPublisher`'s recipient loop (§6) filters against `email_suppressions` before ever calling `$provider->send()` for an address — this is the actual legal/deliverability requirement, not just data collection.
4. A real unsubscribe **link** in the rendered email (`EmailRenderer` gains a footer with a signed, per-recipient unsubscribe URL) hitting a new unauthenticated `GET /email/unsubscribe/{token}` route that inserts the same suppression row — necessary because not every provider's webhook reliably fires an unsubscribe event for a one-click unsubscribe link *you* control (List-Unsubscribe header behavior varies by provider and mailbox client).

**Per-provider webhook handlers:** each new provider needs its own `AnalyticsWebhookHandler` implementation (signature verification + event-shape parsing) registered in `WebhookHandlerRegistry` — Resend and SES both have materially different payload shapes and signing schemes than Postmark, so this is real per-provider work, not free, but it's additive to an existing, proven registry pattern — no new dispatch/routing infrastructure.

## 8. Analytics model

**Already real:** `ExecutionMetric` (`execution_id`, `channel_type`, `provider_type`, `platform_id`, `raw` JSON, `metrics` JSON, `is_final`) stores one row per `Execution`, populated by `RetrieveExecutionMetrics` (pulls once, then re-polls on a provider-defined interval — Postmark's is a fixed 7-day window per `PostmarkAnalyticsProvider::isWindowClosed()`) and incrementally updated by webhook events. `CampaignKpiService::aggregate()` sums `normalised_reach`/`normalised_engagement`/`normalised_clicks` across a campaign's `ExecutionMetric` rows into `CampaignKpiSnapshot`, which `Campaigns/Show.vue`'s "Results" section already renders.

**A concrete, already-present bug this phase must fix, not just a future extension:** `MetaAnalyticsProvider::normalize()` emits `normalised_reach`/`normalised_engagement` — the exact keys `CampaignKpiService::aggregate()` reads. `PostmarkAnalyticsProvider::normalize()` emits a completely different, non-overlapping vocabulary today (`delivered`, `bounces_hard`, `spam_complaints`, `unsubscribes`, `open_rate`, `normalised_clicks`) — it never produces `normalised_reach` or `normalised_engagement` at all. The practical effect: even once a real Postmark send produces a real `ExecutionMetric` row, `CampaignKpiService::aggregate()` would compute **zero reach and zero engagement** for it, because `$m['normalised_reach'] ?? 0` and `$m['normalised_engagement'] ?? 0` silently fall back to zero for every email metric row. This must be fixed as part of Phase 1a (§13), not deferred — an email campaign whose KPI snapshot always shows 0 reach is a worse product-truth violation than not showing a KPI at all. Fix: `PostmarkAnalyticsProvider::normalize()` should additionally map `delivered → normalised_reach` (a delivered email is the email-channel equivalent of "reached") and `(opens + clicks) → normalised_engagement` (or a similarly justified mapping) — a small, mechanical addition to an already-correct method, not a redesign.

**What changes once recipient-list sending (§6) lands:** `ExecutionMetric.metrics` needs list-aware fields, since one `Execution` now represents a batch, not a single message. Add to the normalized shape (no schema change — `metrics` is already a JSON column):

```json
{
  "recipients_sent": 480,
  "recipients_failed": 20,
  "delivered": 460,
  "opens": 184,          // unique-recipient opens, not raw open events
  "open_rate": 0.40,     // opens / delivered
  "clicks": 62,
  "click_rate": 0.135,
  "bounces_hard": 15,
  "bounces_soft": 5,
  "spam_complaints": 1,
  "unsubscribes": 3
}
```

`CampaignKpiService` needs no structural change — it already reads whatever keys are in `metrics` by name; it just needs the new key names (`open_rate`/`click_rate` are genuinely new, list-relative concepts that don't exist for a single-recipient send).

**Dashboard queries:** existing pattern, no new query layer — `Campaigns/Show.vue`'s KPI section, `Analytics/Show.vue`, and `RecommendationKpiService` all already read `CampaignKpiSnapshot`/`ExecutionMetric` directly; email's list metrics flow through the same read path once populated correctly.

## 9. Scheduling

- **Immediate:** already real (`Execution.scheduled_at: null` → `PublishCampaign` dispatches `PublishContent` directly). No change.
- **Scheduled:** already real (`Execution.scheduled_at` set → picked up by `PublishScheduledContent`, every 5 minutes). No change needed for email specifically — it inherits this for free, the same way WordPress/Meta already do.
- **Recurring (future):** genuinely not designed today — `Campaign` has no cadence/RRULE concept, and building one is a real feature (define a recurrence rule, generate future `Campaign`+`ContentAsset`+`Execution` rows on schedule, handle skip/pause/edit-the-series semantics). Correctly out of scope for this phase; flagged as a distinct future document, not sketched further here to avoid premature design.

## 10. Database changes

**New tables:**

```
email_recipients
  id (ulid, pk)
  company_id (fk → companies, cascade)
  channel_id (fk → channels, cascade)        -- which email Channel this recipient belongs to
  email (string)
  name (string, nullable)
  status (string: 'active'|'suppressed')     -- denormalized convenience; email_suppressions is the source of truth
  created_at, updated_at
  unique(company_id, channel_id, email)
  index(company_id, channel_id, status)

email_suppressions
  id (ulid, pk)
  company_id (fk → companies, cascade)
  email (string)
  reason (string: 'unsubscribed'|'hard_bounce'|'complaint')
  source (string: 'webhook'|'unsubscribe_link'|'manual')
  suppressed_at (timestamp)
  created_at, updated_at
  unique(company_id, email)
  index(company_id)
```

**Relationships:** `email_recipients.channel_id → channels.id` (a company's one `email` `Channel`, matching the existing one-email-channel-per-company model from §5); no FK from `email_suppressions` to `email_recipients` — suppression is checked by raw email address so it correctly blocks an address even if it was never explicitly added as a "recipient" (e.g., it bounced from a one-off test send).

**No changes needed to:** `channels`, `channel_credentials`, `executions`, `execution_metrics`, `campaign_kpi_snapshots`, `marketing_channels` — every existing table already has the columns this design needs (`Channel.config` for `from_email`/`from_name`; `ExecutionMetric.metrics` is schemaless JSON already).

**Indexes called out above** are the two that matter: `email_recipients` needs fast "give me this channel's active recipients" (`company_id, channel_id, status`) for the send-loop in §6, and a uniqueness constraint preventing duplicate recipient rows; `email_suppressions` needs a fast single-address lookup (`company_id, email` — already the unique index) since it's checked once per recipient per send, potentially thousands of times per campaign.

## 11. Security

- **Credential encryption:** already correct and requires no change — `ChannelCredentials.credentials` is cast `encrypted` (Laravel's `Crypt` facade, AES-256-CBC keyed by `APP_KEY`), the same mechanism already protecting WordPress Application Passwords and Meta OAuth tokens. SMTP passwords and Resend/SES API keys get the same protection automatically by using the same column/cast — no new encryption code.
- **Secret rotation:** `APP_KEY` rotation is an existing, application-wide concern (rotating it invalidates every encrypted column, not just email's) — out of scope to solve uniquely for email. What email-specific rotation *does* need: a "reconnect" flow when a company rotates their own Postmark/Resend API key on the provider's side — this is already handled by `connectEmail()` (§5) being the same `updateOrCreate` pattern WordPress/Meta already use; resubmitting the form with a new key replaces the old one after re-verification.
- **Least privilege:** recommend documenting (in the connect UX copy, not enforcing in code) that companies should create a Postmark **server-scoped** token or a Resend **restricted** API key (send-only, no account-management scope) rather than a full-access key, mirroring how WordPress Application Passwords are already scoped per-application rather than using the account password.
- **Webhook authenticity:** already correctly enforced for Postmark (`PostmarkWebhookHandler::verify()` does constant-time HMAC comparison via `hash_equals`) — every new provider's webhook handler must implement equivalent signature verification before this document's Phase 1 is considered complete; a webhook handler that skips `verify()` (or, like the current handler, silently no-ops when no secret is configured) is a real spoofing risk once a second provider is live, since a spoofed "bounce" or "unsubscribe" webhook could suppress an address that never actually unsubscribed. Recommend making `webhook_secret` a hard requirement (fail closed, not open) once a provider handler ships for real use, rather than the current soft/optional check.
- **PII handling:** recipient email addresses in `email_recipients` are the first table in this codebase storing a list of a company's *customers'* contact data (not just the company's own credentials) — recommend this table is included in whatever data-export/data-deletion story the plan's Phase 6.4 (legal/policy) produces, since a business's customer list is exactly the kind of data a privacy policy needs to account for.

## 12. Production readiness checklist

- [ ] `PostmarkAnalyticsProvider::normalize()` emits `normalised_reach`/`normalised_engagement` (§8) — verified against a real `CampaignKpiSnapshot` showing non-zero values for a real sent-and-opened test email, not just that metrics rows exist.
- [ ] `EmailProvider::ping()` verified live (not just unit-tested against a mock) for Postmark, Resend, and SMTP against real accounts.
- [ ] Test-email send (§5) verified end-to-end for all three providers, confirming actual inbox delivery, not just a 200 response.
- [ ] Webhook signature verification is fail-closed (no "skip verification if secret is blank") for every provider before it's enabled for real companies.
- [ ] Suppression list (§7) verified to actually block sends in `EmailPublisher`'s recipient loop — a false negative here is a legal exposure, not just a bug.
- [ ] Rate-limit behavior confirmed against each provider's real documented limits (Postmark, Resend, and typical SMTP relays all differ) — `PublishingException->retryable` and backoff timing tuned per provider, not copy-pasted from Postmark's `[500ms, 1500ms]`.
- [ ] `channelCapability.ts` badge shows `'connected'` correctly once a company's email is genuinely verified, `'draft_only'`/`'not_configured'` otherwise — verified against real connect/revoke/health-check flows (same test pattern as `MetaOAuthControllerTest`/`CheckChannelHealthTest` from the capability-truth slice).
- [ ] `docs/reviews/Channel-Publishing-Reality-Audit.md` updated the same day email goes live for any real company — the standing "product truth" rule this repo already enforces.
- [ ] Bounce/complaint thresholds monitored (most ESPs suspend accounts that exceed ~5-10% bounce rate or ~0.1% complaint rate) — an operational alert, not just a data point, since provider suspension would silently break every company's email at once.
- [ ] Sender domain guidance (SPF/DKIM) documented for company owners, even if verification-status UX is deferred (§5).
- [ ] Data-export/deletion story for `email_recipients`/`email_suppressions` reviewed against the plan's Phase 6.4 legal checklist before any real company's customer list is imported.

## 13. Recommended implementation phases

**Phase 1a — Make Postmark reachable (smallest possible real win).**
`SettingsController::connectEmail()` (API-key form, `ping()`-validated, mirrors `connectWordPress()` exactly) + link/verify into `MarketingChannel` + test-email endpoint (§5), plus the `PostmarkAnalyticsProvider::normalize()` field-name fix (§8) — that fix is small but must land in this phase, since it's the difference between a real send producing an honest KPI snapshot versus a silently-wrong zero one. Single-recipient sending only (today's `EmailPayload` shape, unchanged) — a company can already connect and send one real email at the end of this phase, exercising the entire existing pipeline (§6–8) for the first time with a real account. Smallest possible slice that produces a genuinely real, demoable channel.

**Phase 1b — Recipient list + suppression.**
`email_recipients`, `email_suppressions` tables; `EmailPublisher`'s per-recipient send loop (§6); unsubscribe link + route; suppression-checked before every send. This is the phase that turns "can send one email" into "can run an actual email campaign."

**Phase 2 — Second and third providers.**
Resend and SMTP, each: `EmailProvider` implementation, connect-form variant, webhook handler (Resend only — SMTP has no webhook concept, so bounce/complaint tracking for SMTP-connected companies is a documented known limitation, not a gap to solve, since raw SMTP has no standard feedback-loop protocol).

**Phase 3 — Deliverability and trust polish.**
Domain/sender verification status surfaced in Settings; bounce/complaint-rate monitoring and alerting (production-readiness checklist item); rate-limit tuning per provider.

**Phase 4 — SES, then recurring scheduling.**
Amazon SES (own credential/verification workstream, §3) once the three-provider abstraction has proven itself; recurring campaigns (§9) as a separate, later design effort once the one-off sending path has real production usage to learn from.
