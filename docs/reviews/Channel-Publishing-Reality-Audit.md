# Channel Publishing Reality Audit

**Date:** 2026-07-07
**Trigger:** Requested audit of every UI claim that says "publish," "published," "send," or names a specific channel (blog, email, Instagram, Facebook, LinkedIn, X, SMS, landing page), to check the claim against what the backend actually does.
**Scope:** Read-only investigation of the publishing pipeline + a copy/labeling pass on the frontend. **No new publishers were implemented.**

---

## Headline finding

**No channel type in Project Atlas currently publishes anything to a real external platform.** Every channel — blog, email, Facebook, Instagram, LinkedIn, X, SMS, landing page — resolves to a publisher that only writes a structured log line to `storage/logs/publishing.log` and returns a fake success result. The `Execution` still transitions through `queued → executing → completed`, the `ContentAsset` and `Campaign` still flip to `published`, and the UI renders a green "Published" badge — for content that never left the application.

This isn't a bug in any one publisher; it's the intended shape of the current build (`LogChannelPublisher`, `LogEmailProvider` — the class names say so). The problem is that the UI language around this pipeline ("Approve & publish," "Atlas will handle the publishing," "Publishing Activity," a "Published" badge) doesn't yet reflect that reality to the person approving content. This audit documents the gap and applies a copy/labeling fix — not new publishing capability.

---

## How a "publish" actually resolves today

```
PublishContent job
  → ChannelPublisherRegistry::for($channel->type)
       'email'                                            → EmailPublisher
       'facebook','instagram','linkedin','x',
       'sms','blog','landing_page'                        → LogChannelPublisher (fallback, registered last)

EmailPublisher::publish()
  → ChannelCredentialsRepository::for($companyId, 'email')   (throws if no credentials row exists)
  → EmailProviderRegistry::for($credentials->provider_type)
       only 'log' is ever registered (PublisherServiceProvider) → LogEmailProvider
  → LogEmailProvider::send()  — writes to Log::channel('publishing'), returns a fake message id

LogChannelPublisher::publish()
  → renders the content, writes to Log::channel('publishing'), returns a fake platform id
```

Both terminal publishers (`LogChannelPublisher`, `LogEmailProvider`) return a successful `ExecutionResult` unconditionally. `PublishContent` has no way to tell the difference between "really sent" and "logged" — from the job's perspective, and therefore the database's perspective, they're identical outcomes. `ping()` on both always returns `reachable: true` too, so a future "channel health" UI would report every channel as healthy regardless of whether any real integration exists.

No code anywhere in `app/` calls the Facebook Graph API, Instagram API, LinkedIn API, X API, an SMS gateway (e.g. Twilio), or a real email-sending provider (Postmark/Mailgun/SES). The only real third-party wiring that exists is `PostmarkWebhookHandler`, which *receives* analytics webhooks (opens/bounces) — but since nothing actually sends mail through Postmark, that handler has no real traffic to receive today. It's dormant, correctly-built infrastructure for a sending integration that doesn't exist yet.

---

## Can a user even reach each channel type today?

Separately from "does it really publish," most channel types can't currently be created by a real user at all:

- **`blog`** — the only channel type the product creates automatically. `OnboardingController` seeds one `blog` Channel for every new company.
- **`email`** — created only by `DemoSeeder` (with `provider_type: 'log'`, i.e. the seed data itself acknowledges it's fake). No onboarding step, no Settings UI, creates one for a real company.
- **`facebook`, `instagram`, `linkedin`, `x`, `sms`, `landing_page`** — valid values in the `channels.type` enum, and the AI content-drafting side fully supports them (`ContentGenerationAnalyst` maps each to a real prompt: `SocialContentPrompt`, `SmsContentPrompt`, `LandingPageContentPrompt`), but **there is no code path — onboarding, seeder, or UI — that ever creates a Channel row of these types for a real company.** (Confirmed in the earlier [Product-Polish-Audit.md](Product-Polish-Audit.md): no Channels management UI exists yet.) `DecisionEngine` only ever selects from a company's *existing* active channels, so these types are structurally unreachable in production right now, not merely unpublished.

---

## Per-channel-type capability table

| Channel type | Draft content generation | Can a real user create this channel today? | External publish | Classification |
|---|---|---|---|---|
| `blog` | ✅ `BlogContentPrompt` | ✅ Yes — auto-created at onboarding | ❌ None | **Logs internally only** |
| `email` | ✅ `EmailContentPrompt` | ⚠️ Only via `DemoSeeder`; no real onboarding/Settings path | ❌ None (`LogEmailProvider` is the only registered provider) | **Logs internally only** |
| `facebook` | ✅ `SocialContentPrompt` | ❌ No creation path exists | ❌ None | **Not supported** (unreachable + no publisher) |
| `instagram` | ✅ `SocialContentPrompt` | ❌ No creation path exists | ❌ None | **Not supported** |
| `linkedin` | ✅ `SocialContentPrompt` | ❌ No creation path exists | ❌ None | **Not supported** |
| `x` | ✅ `SocialContentPrompt` | ❌ No creation path exists | ❌ None | **Not supported** |
| `sms` | ✅ `SmsContentPrompt` | ❌ No creation path exists | ❌ None | **Not supported** |
| `landing_page` | ✅ `LandingPageContentPrompt` | ❌ No creation path exists | ❌ None | **Not supported** |

Definitions used, per the four categories requested:
- **Truly publishes externally** — not true for any channel today.
- **Logs internally only** — the full pipeline runs (content drafted → campaign approved → execution "completed"), but the only real effect is a line in `storage/logs/publishing.log`. This is `blog` and `email`, the two channel types a company can actually end up with.
- **Prepares draft content only** — not a distinct state today; every channel that can be reached also completes the fake-publish cycle (there's no channel type that stops at "drafted, never executed"). Noted for completeness since the requested framework includes it.
- **Not supported** — `facebook`, `instagram`, `linkedin`, `x`, `sms`, `landing_page`: real code exists for content generation and for the same fake "publish," but no company can ever end up with a channel of this type, so in practice these are unreachable.

---

## Every UI/copy location that made a publishing claim

| Location | Before | Problem | After |
|---|---|---|---|
| `ApproveActions.vue` — confirmation dialog effect line | *"Publish the blog post "X" to your blog channel."* | States as fact that content will be published to the channel. | *"Queue the blog post "X" for Blog — Draft only: logged internally, not yet sent live."* (capability-aware, see below) |
| `ApproveActions.vue` — confirmation button | *"Approve & publish"* | Names an action ("publish") the system doesn't perform. | *"Approve"* |
| `ApproveActions.vue` — helper text under the buttons | *"Approving queues this content for publishing."* | Same overclaim. | *"Approving queues this content for delivery. Until a live channel is connected, delivery is simulated and logged internally — nothing is sent to a real platform yet."* |
| `ApproveActions.vue` — dialog fallback (no assets yet) | *"Atlas will queue this campaign's content for publishing."* | Same overclaim. | *"Atlas will queue this campaign's content for internal processing. No live channels are connected yet, so nothing is sent externally."* |
| `ApproveActions.vue` — dialog footer note | *"Publishing starts right after you approve. You can follow progress on the Publishing page."* | Implies real publishing begins. | *"Processing starts right after you approve. You can follow progress on the Publishing page — entries there are currently simulated, not live sends."* |
| `RecommendationController::approve()` — flash message | *"Approved. Atlas will handle the publishing."* | Same overclaim, from the backend. | *"Approved. Atlas will process this campaign — publishing is currently simulated until a live channel is connected."* |
| `Dashboard.vue` — "Recent Publishing Activity" empty state | *"Publishing activity appears here once campaigns are running."* | Implies real publishing. | *"Simulated publishing activity appears here once campaigns run — no live channels are connected yet."* |
| `Publishing.vue` — page title / empty state | *"Publishing Activity"* / *"No publishing activity yet"* / *"Content executions appear here once campaigns are approved and running."* | Page reads as a live-send log. | Added a page-level notice: *"Atlas doesn't publish to live external channels yet — every entry below is a simulated, internally logged send."* Empty-state description updated to match. |
| `Campaigns/Show.vue` — "Publishing" section | *"No publishing activity"* / *"Executions appear here as content is scheduled and published."* | Same. | Description updated: *"Executions appear here as content is scheduled and processed (simulated — not yet sent to a live channel)."* |
| Everywhere a raw `channel.type` string is rendered (`ApproveActions`, `Campaigns/Show.vue`, `Publishing.vue`, `Dashboard.vue`) | Raw enum value, e.g. `blog`, `facebook` | No indication of capability; a lowercase enum value isn't a product-quality label either. | Replaced with a friendly display name (`Blog`, `Facebook`, …) plus a new **capability badge** (below). |

`Analytics/Show.vue`'s "Metrics appear here as content is published and measured" was left as-is: analytics only render once real `ExecutionMetric` rows exist, and that page doesn't claim an action is happening — it's a passive empty state describing when data would appear, in a section already downstream of the executions this audit flags. Revisit if/when a real publisher and real analytics ingestion ship together.

---

## New capability labels

Added `resources/js/lib/channelCapability.ts` (display names + capability lookup) and `Components/UI/ChannelCapabilityBadge.vue`, implementing the four requested states so future channel types slot in without new copy work:

| Label | Meaning | Used today for |
|---|---|---|
| **Connected** | Live — content sent to a real external platform. | *(none yet — no channel type qualifies)* |
| **Draft only** | Atlas drafts and queues content, but delivery is simulated/logged internally, not sent live. | `blog`, `email` |
| **Coming later** | Not yet available as a product feature — no way to create or publish to this channel type. | `facebook`, `instagram`, `linkedin`, `x`, `sms`, `landing_page` |
| **Not configured** | Reserved for a future state: a supported channel type this company hasn't connected yet. | *(none yet — no connectable channel exists)* |

The badge is now shown wherever a channel type is displayed to a user: the approval confirmation dialog, `Campaigns/Show.vue`, `Publishing.vue`, and `Dashboard.vue`'s recent-executions list.

---

## What this audit deliberately did not do

- **No new publisher integrations.** Facebook/Instagram/LinkedIn/X/SMS/real-email sending are still not implemented — that's a P1/P2 roadmap item ([Product-Polish-Audit.md](Product-Polish-Audit.md) item 12 area), not this task.
- **No change to the `Execution`/`ContentAsset`/`Campaign` status machine.** `completed`/`published` remain the correct internal state names — they accurately describe "Atlas's internal process completed." Renaming the state machine to avoid the word "published" would be a much larger, riskier change for a copy audit; the fix here is scoped to what the *user* is told, not to internal state names other code and tests depend on.
- **No Channels management UI.** Still tracked as a separate, larger P1 item.

## Recommended follow-ups (not implemented here)

1. When the first real publisher ships (most likely email via Postmark/Mailgun, since the `EmailProvider` contract and a webhook receiver already exist), promote that channel's capability from "Draft only" to "Connected" in `channelCapability.ts` — this is the one line the whole badge system was designed around.
2. `LogChannelPublisher::ping()` / `LogEmailProvider::ping()` unconditionally return `reachable: true`. If a Channels health UI is ever built on top of `CheckChannelHealth`, this will falsely report every channel as healthy. Worth a follow-up ticket before that UI ships.
3. Consider a company-wide banner (e.g. in `AppLayout`) while zero channels are "Connected," so the simulated state is visible everywhere, not only on the pages touched here.
