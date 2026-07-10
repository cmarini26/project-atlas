# Marketing Presence — Design Specification

**Version:** 1.0
**Status:** Approved — authoritative specification for Milestone 11
**Depends on:** `specs/core/domain-model.md`, `specs/core/opportunity-engine.md`, `specs/core/campaign-blueprint.md`, `specs/core/publishing-engine.md`
**See also:** `docs/reviews/Channel-Publishing-Reality-Audit.md`, `docs/product/UserFlows.md`, `docs/design/System.md`

When this document conflicts with others, this document wins for anything related to Marketing Presence, the `MarketingChannel` entity, and the distinction between declared business context and technical publishing capability. Update the others.

**A note on source material:** `specs/core/business-brain.md` does not exist as a standalone file today. The Business Brain is currently specified inside `specs/core/domain-model.md` (the "Business Brain" entity section) and implemented in `App\Domain\BusinessBrain\BusinessBrain` / `App\Services\Brain\BusinessBrainService`. This document treats those as authoritative for Business Brain integration (Section 8) and does not attempt to re-specify the Business Brain itself.

---

## Milestone 11 Implementation Scope

This document specifies the domain model, lifecycle, and integration points for **Marketing Presence** — a new first-class Atlas concept. The companion implementation plan is `docs/plans/Milestone-11-Marketing-Presence.md`.

**This document is a specification only.** Per the instruction that produced it, no application code is implemented alongside it. The plan document sequences the implementation into phases for a future milestone.

### Why Marketing Presence Exists

Atlas today only knows about a "channel" once it can technically act on it: `App\Models\Channel` is a publishing destination, created only along code paths that already exist (onboarding auto-creates one `blog` Channel; nothing else). The [Channel Publishing Reality Audit](../../docs/reviews/Channel-Publishing-Reality-Audit.md) established that most channel types (Facebook, Instagram, LinkedIn, X, SMS, landing pages) have **no way to even be declared** by a real company, let alone published to.

This conflates two different questions that Atlas needs to answer separately:

1. **Where does this business market today?** — a fact about the business, true regardless of what Atlas can technically do about it. A comic book auction house that posts to Instagram three times a week and mails a print newsletter is marketing on both, whether or not Atlas can publish to either.
2. **Where can Atlas technically publish or pull analytics?** — an engineering capability, currently true only for two channel types (`blog`, `email`), and only in simulated form (per the Reality Audit).

**Marketing Presence answers question 1.** It is business context — the kind of thing a human strategist would ask a new client in the first meeting: "Where do you market today? How active are you there? What's it for?" Publishing and analytics integrations (question 2) remain optional capabilities that a Marketing Presence entry may or may not have. A business's marketing presence is real and useful to Atlas's reasoning **even when Atlas cannot act on it directly.**

This split lets Atlas build a complete picture of a business's marketing footprint immediately — during onboarding, with zero API integrations — while technical publishing capability is added channel by channel over time, without redesigning how Atlas understands the business.

---

## 1. Marketing Presence Domain Model

**Definition:** Marketing Presence is the aggregate understanding of every channel, platform, or medium a Company uses (or plans to use, or has stopped using) to market itself — regardless of whether Atlas can publish to or measure that channel.

**Representation:** Marketing Presence is not a single database row. It is the set of `MarketingChannel` records belonging to a Company, read as a whole. There is no `marketing_presence` table — the aggregate is a query (`MarketingChannel::where('company_id', ...)`) and, at the Business Brain layer, a `Collection` (Section 8).

**Relationship to the `MarketingChannel` entity:** One `MarketingChannel` row is one declared channel. Marketing Presence is the collection of all of a company's `MarketingChannel` rows, in the same sense that a company's "catalog" is the collection of its `CatalogItem` rows through the `Catalog` parent — except Marketing Presence has no equivalent parent row. This is a deliberate simplification: unlike Catalog (which carries `item_schema` and sync metadata), there is no company-level configuration for Marketing Presence to hold. If that changes (Section 12), a `marketing_presence` parent table can be introduced without touching `MarketingChannel`.

### Where Marketing Presence Sits in the Atlas Loop

```
Observe → Understand → Decide → Recommend → Prepare → Approve → Execute → Measure → Learn
```

Marketing Presence is declared during **Observe** (onboarding, Settings) and is not itself observed by a crawl — it is stated by the business owner, the same way `Company.industry` or `Company.brand.voice` is stated rather than inferred. It is consumed during **Understand** (Business Brain, Section 8) and **Decide** (Opportunity/Decision Engine channel selection, Section 9), and displayed during **Recommend** (Section 11). It has no role in Execute or Measure in this milestone — that remains the existing `Channel`/publishing pipeline (Section 6).

---

## 2. The `MarketingChannel` Entity

**Definition:** A single declared marketing channel for a Company — a fact about where and how the business markets, independent of Atlas's technical ability to act on it.

**Purpose:** Gives the Business Brain, Opportunity Engine, and Campaign Blueprint a complete picture of the business's marketing footprint, so that recommendations reference real channels the business actually uses — not just the one or two channel types Atlas happens to be able to publish to today.

**Table:** `marketing_channels`

| Column | Type | Notes |
|---|---|---|
| `id` | ulid | PK |
| `company_id` | ulid | FK `companies.id` |
| `channel_id` | ulid | FK `channels.id`, nullable — see Section 5 |
| `type` | enum | See Section 3 |
| `display_name` | string | Required. e.g. `"CBB Auctions Instagram"`, `"Monthly Postcard Mailer"` |
| `handle_or_url` | string | Nullable. `@handle`, a URL, or blank for offline channels (Events, Print) |
| `status` | enum | `active`, `occasional`, `planned`, `inactive` — see Section 4.1 |
| `importance` | enum | `primary`, `secondary`, `experimental` — see Section 4.2 |
| `objective` | json | Array of 1+ values from Section 4.3; first item is the dominant objective |
| `audience` | text | Nullable. Plain-language description, same spirit as `CampaignBlueprint.audience` |
| `posting_frequency` | enum | Nullable. `daily`, `weekly`, `biweekly`, `monthly`, `quarterly`, `rarely`, `unknown` |
| `notes` | text | Nullable. Free-form, business-owner-authored |
| `is_connected` | boolean | Default `false` — see Section 5 |
| `supports_publishing` | boolean | Default `false` — see Section 5 |
| `supports_analytics` | boolean | Default `false` — see Section 5 |
| `metadata` | json | Nullable. Type-specific extension bag (e.g. `{follower_count, venue_name, circulation}`) |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Relationships:**
- `belongsTo` Company
- `belongsTo` Channel (nullable, via `channel_id`)

**Laravel notes:**
- Model: `App\Models\MarketingChannel`
- Use `HasUlids`, `App\Domain\Shared\Concerns\BelongsToCompany` (same tenancy pattern as `Opportunity`, `Fact`, `Knowledge` — **not** the nullable-`company_id`/no-global-scope pattern used by `Channel`, since a `MarketingChannel` is always company-specific; there is no "global template" concept here)
- Cast `objective` and `metadata` to `array`
- No soft deletes. A channel the business has stopped using transitions to `status: inactive` — it is not deleted. The history (what a business used to market on) is itself useful context; see Section 4.1.
- Index: `(company_id, status)`, `(company_id, importance)`, `(company_id, type)`
- No uniqueness constraint on `(company_id, type)` — a company may have two Instagram accounts, two print placements, or multiple "Other" entries. Duplicate-`handle_or_url` prevention is a soft validation in `MarketingPresenceService`, not a database constraint.

### Why Not Extend `Channel` Instead?

Adding `status`, `importance`, `objective`, etc. directly to the `channels` table was considered and rejected:

1. **`Channel` rows are created only when Atlas has a technical reason to create one.** Onboarding, Settings, and every other real code path that creates a `Channel` today does so because a `ChannelPublisher`/`ContentGenerationAnalyst` mapping exists for that type. Declaring "we also run a print newsletter" would force `Channel`'s `type` enum to grow with types (`events`, `print`, `youtube`, `tiktok`, `google_business_profile`, `website`, `other`) that have no publisher, no renderer, and never will need one in the same sense a social platform does. That conflates "declared" with "actionable" — precisely the problem this spec exists to fix.
2. **`Channel.config` is an encrypted credentials blob** (`specs/core/publishing-engine.md` §9). A declared-but-unconnected marketing channel has no credentials. Storing business context fields (`audience`, `objective`, `notes`) alongside an encrypted-credentials column is an awkward fit and risks future confusion about what's encrypted and why.
3. **Every existing consumer of `Channel`** (`DecisionEngine::resolveChannelIds()`, `ContentGenerationAnalyst`, `ChannelPublisherRegistry`, `ChannelCredentialsRepository`) assumes a `Channel` row means "Atlas can act here." Widening that meaning would require auditing and likely branching every one of those call sites. A new, separate entity with an explicit, nullable link to `Channel` (Section 5) achieves the same goal with zero changes to existing publishing code.

---

## 3. Channel Types

`marketing_channels.type` is a distinct, broader enum than `channels.type`. It describes how a business owner would naturally answer "where do you market?" — including channels with no digital publishing equivalent at all.

| Type | Description | Has a `channels.type` equivalent? |
|---|---|---|
| `website` | The business's own website, used as a content/marketing surface (distinct from the `website_crawl` Integration — see callout below) | No |
| `email` | Email newsletter or campaigns | Yes — `email` |
| `instagram` | Instagram | Yes — `instagram` |
| `facebook` | Facebook | Yes — `facebook` |
| `linkedin` | LinkedIn | Yes — `linkedin` |
| `x` | X (Twitter) | Yes — `x` |
| `youtube` | YouTube | No |
| `tiktok` | TikTok | No |
| `google_business_profile` | Google Business Profile / Maps listing | No |
| `events` | In-person events, trade shows, open houses | No |
| `print` | Print advertising, mailers, local newspaper | No |
| `other` | Anything not covered above — `display_name` carries the specific label (e.g. `"Local Radio Ad"`) | No |

**`website` is not the same as the `website_crawl` Integration.** `Integration.type = 'website_crawl'` is an *observation source* — how Atlas reads the business's website to extract Facts. `MarketingChannel.type = 'website'` is a *declaration* that the business considers its website a marketing channel in its own right (e.g., it runs a blog, publishes case studies, drives SEO traffic). The two may reference the same URL and typically will, but there is **no FK relationship between them in this milestone** — see Section 6. A company can decline to declare its website as a marketing channel even while Atlas crawls it for Facts, and vice versa (though the latter would be unusual).

**Why types without a `channels.type` equivalent are still first-class here.** `events` and `print` will likely never have a `ChannelPublisher` — there is no API to "publish" a trade show booth. That does not make them less real as marketing context. Knowing a comic book auction house exhibits at conventions three times a year is exactly the kind of fact that should inform a Decision Engine's `audience` and `supporting_points` (Section 10), even though no `Execution` will ever be created for it.

---

## 4. Channel Attributes

### 4.1 Channel Status

Distinct from `Channel.is_active` (a blunt on/off boolean). `MarketingChannel.status` describes the business's actual current relationship to the channel:

| Status | Meaning |
|---|---|
| `active` | Currently and regularly used |
| `occasional` | Used, but not on a consistent cadence |
| `planned` | Not yet used, but the business intends to start |
| `inactive` | Previously used; no longer active |

**Why `inactive` is a status, not a deletion.** A business that used to run print ads and stopped is meaningfully different from a business that never advertised in print. The Opportunity Engine's future `re_engagement`-style reasoning (Section 9) benefits from knowing "this channel went quiet" versus "this channel was never used" — the former is a signal (something changed); the latter is not.

### 4.2 Channel Importance

| Importance | Meaning |
|---|---|
| `primary` | The channel(s) the business considers its main marketing effort |
| `secondary` | Actively used, but supporting rather than central |
| `experimental` | Being tried; the business is not yet committed to it |

Importance is business-declared, not Atlas-computed. It directly informs channel-selection preference (Section 9) — a `primary` channel should be preferred over a `secondary` one when both are otherwise equally suitable for a campaign, all else equal.

**Cardinality:** More than one channel may be `primary` (e.g. an auction house that treats Instagram and email as co-equal primary channels). This spec does not enforce a maximum count of `primary` channels — that is a UX/validation concern for Phase 2/4 of the implementation plan, not a domain constraint.

### 4.3 Channel Objectives

`objective` is a JSON array of one or more values:

| Objective | Meaning |
|---|---|
| `awareness` | Getting the business noticed |
| `leads` | Generating inquiries or contact |
| `sales` | Directly driving purchases/bids |
| `retention` | Keeping existing customers engaged |
| `trust` | Building credibility/reputation |
| `seo` | Search visibility |
| `community` | Building an engaged following/community |

**Why an array, not a single value.** A real channel usually serves more than one purpose — an Instagram account simultaneously builds `awareness` and `community`. Modeling `objective` as a single enum would force an artificial choice and lose information the Campaign Blueprint's `audience`/`core_message` seeding (Section 10) can use. The first element of the array is the *dominant* objective when a single value is needed (e.g., a compact UI label); the full array is preserved for anything that can use it.

**Validation:** At least 1 objective required; no maximum. Each value must be one of the seven listed above.

### 4.4 Posting Frequency

Nullable, one of: `daily`, `weekly`, `biweekly`, `monthly`, `quarterly`, `rarely`, `unknown` (default when not specified). Modeled as a constrained enum rather than free text so the Opportunity Engine and prompts can reason about it structurally (e.g., "this is a `weekly` channel with no content in 21 days" is a clean signal; parsing "we post kind of a lot" is not). Businesses that don't know or don't want to specify leave it `unknown` — never required.

---

## 5. Channel Lifecycle

Unlike `status` (business cadence) or `importance` (strategic weight), **lifecycle is not a stored enum column.** It is derived from three booleans plus the presence of a linked `Channel`:

```
Declared
   │  MarketingChannel row exists. is_connected = false.
   │  This is the state of every channel created during onboarding (Phase 3).
   ▼
Connected
   │  is_connected = true. A `channel_id` link to a real `channels` row may exist,
   │  or the business has otherwise confirmed the account/channel is theirs
   │  (e.g. verified in Settings). Does not imply Atlas can publish or measure yet.
   ▼
Publishing enabled
   │  supports_publishing = true (implies is_connected = true and channel_id is set).
   │  A real ChannelPublisher exists for this channel_id's type AND this company's
   │  credentials are valid for it. Per the Channel Publishing Reality Audit, this
   │  is true for NO channel type today — every current "publish" is simulated.
   ▼
Analytics enabled
   supports_analytics = true (implies is_connected = true and channel_id is set).
   Real metric ingestion exists for this channel. True for no channel type today.
```

**Publishing enabled and Analytics enabled are independent, not sequential.** A channel could theoretically support analytics ingestion via a webhook (e.g. email opens) before it supports Atlas-initiated publishing, or vice versa. The diagram above shows the typical order but the booleans are independent flags, not a strict state machine — do not enforce `supports_analytics → requires supports_publishing` or the reverse.

**Why three booleans instead of one `lifecycle` enum column.** An enum column would need to encode "connected but publishing broken," "publishing works but analytics don't," etc. — every real-world combination becomes a case in the enum. Three independent booleans express the actual state directly and let each capability graduate independently, which is exactly how it will happen in practice (email sending will likely ship before email analytics; social publishing and social analytics may ship together via the same API).

---

## 6. Relationship to the Existing `Channel` Model

| | `Channel` (existing) | `MarketingChannel` (new) |
|---|---|---|
| **Answers** | "Can Atlas technically act here?" | "Does this business market here?" |
| **Created when** | A code path with a real (even if simulated) publisher exists | The business tells Atlas about it — any time, any channel type |
| **`type` enum** | 8 values, publishing-oriented (`facebook`, `instagram`, `linkedin`, `x`, `email`, `sms`, `blog`, `landing_page`) | 12 values, business-oriented, includes non-digital (`website`, `email`, `instagram`, `facebook`, `linkedin`, `x`, `youtube`, `tiktok`, `google_business_profile`, `events`, `print`, `other`) |
| **On/off signal** | `is_active` (boolean) | `status` (4-state business cadence) |
| **Strategic weight** | None | `importance`, `objective` |
| **Company scope** | Nullable (`null` = global template) | Always company-scoped |

**The link:** `marketing_channels.channel_id` is a nullable FK to `channels.id`. It is populated only when both are true:

1. `MarketingChannel.type` has a real `channels.type` equivalent (Section 3's second column) — today: `email`, `instagram`, `facebook`, `linkedin`, `x`. Types with no equivalent (`website`, `youtube`, `tiktok`, `google_business_profile`, `events`, `print`, `other`) can **never** have `channel_id` set in the current `channels.type` enum — that enum would need to grow first, which is out of scope for this milestone.
2. A real `Channel` row exists for this company and that type (today, in practice, this means: onboarding's auto-seeded `blog` channel, or a future company-created channel via Settings).

When `channel_id` is set, `MarketingChannel.supports_publishing` and `supports_analytics` should reflect that linked `Channel`'s actual capability (per the [Channel Publishing Reality Audit](../../docs/reviews/Channel-Publishing-Reality-Audit.md)'s capability classification: today, every linked channel is "Draft only," so both flags stay `false` even when connected — see Section 12 for how this changes without redesign).

**This link is exactly how "declared channels later upgrade without redesign" works** (Section 12). Nothing about the `MarketingChannel` row's shape changes when a real publisher ships for a channel type — only `channel_id`, `is_connected`, `supports_publishing`, and `supports_analytics` change value.

---

## 7. Relationship to Integrations

`Integration` (website crawl, RSS feed, API, etc.) and `MarketingChannel` are **not directly related in this milestone.** No FK, no shared table, no cross-validation.

They can describe the same real-world thing (a company's website is both an `Integration.type = 'website_crawl'` observation source and, if declared, a `MarketingChannel.type = 'website'`), but:

- An `Integration` exists because Atlas needs to *observe* something.
- A `MarketingChannel` exists because the business *declared* something.

A future enhancement (Section 12) could correlate the two — e.g., auto-suggesting "You have a website Integration connected — is it also a marketing channel?" during onboarding — but building that correlation is explicitly out of scope here. The two remain independent facts that happen to sometimes point at the same URL.

---

## 8. Relationship to Business Brain

`App\Domain\BusinessBrain\BusinessBrain` (per `specs/core/domain-model.md`) gains one new property:

```php
readonly class BusinessBrain
{
    public function __construct(
        public Company $company,
        public DigitalTwin $twin,
        public Collection $activeFacts,
        public Collection $activeKnowledge,
        public Collection $recentObservations,
        public ?Catalog $catalog,
        public Collection $featuredItems,
        public Collection $recentCampaigns,
        public ?MarketingPresenceSummary $marketingPresence = null,   // NEW — synthesized, not raw MarketingChannel rows
    ) {}
}
```

**Superseded design note (Milestone 11 Phase 5 implementation):** an earlier draft of this section specified `public Collection $marketingPresence` — the company's raw, unfiltered `MarketingChannel` rows, mirroring how `recentCampaigns` carries every `Campaign` regardless of status. The Phase 5 implementation task explicitly overrode that: *raw `MarketingChannel` rows must never reach a prompt.* `App\Services\Brain\MarketingPresenceSynthesizer` instead turns a company's `MarketingChannel` rows into an `App\Domain\BusinessBrain\MarketingPresenceSummary` — plain lists of display-name strings (`primaryChannels`, `secondaryChannels`, `inactiveChannels`, `primaryObjectives`) plus a single composed `summary` sentence — and that synthesized object is what `BusinessBrain` carries. This is deterministic string composition, not an AI call (Founding Principle 1: bucketing a handful of enum-valued rows into a few sentences is not a task that benefits from a probabilistic model), and it keeps `BusinessBrainService::assemble()` a pure aggregation step, consistent with how it already treats `activeFacts`/`activeKnowledge` (themselves already synthesized upstream, before ever reaching the brain).

**Bucketing rule.** `status: inactive` takes precedence over `importance` — a channel the business has stopped using is described as inactive regardless of how important it once was. Of the remaining (non-inactive) channels, `importance: primary` channels are listed separately from `secondary`/`experimental` ones (folded together as "secondary" — this milestone's task calls out exactly three channel buckets, not four). `primaryObjectives` is the deduplicated union of `objective` values across the company's primary channels, falling back to all non-inactive channels if none are marked primary, so the summary isn't empty just because nobody set importance carefully. A company with zero declared channels gets a fixed sentence: *"No marketing channels have been declared yet."*

**Cache invalidation.** Following the existing pattern for `FactExtracted` and `KnowledgeSynthesized` (`AppServiceProvider::boot()`), the already-shipped `MarketingPresenceUpdated` event (Phase 2; carries `public readonly MarketingChannel $marketingChannel`, not a bare `companyId`) is fired by `MarketingPresenceService` after any create, update, status change, or link, and a listener calls `BusinessBrainService::invalidate($event->marketingChannel->company_id)`. One coarse event, not one per CRUD verb: the only consumer (cache invalidation) doesn't care what changed, only that it changed. Follow Founding Principle 7 — add more granular events later only if a second consumer needs to distinguish create from update from delete.

```php
Event::listen(MarketingPresenceUpdated::class, function (MarketingPresenceUpdated $event): void {
    BusinessBrainService::invalidate($event->marketingChannel->company_id);
});
```

Invalidation is synchronous but cheap — `BusinessBrainService::invalidate()` only clears an in-process memo entry (`unset()` on a static array); it does not rebuild anything. The next `BusinessBrainService::for($company)` call lazily reassembles the brain, including a fresh `MarketingPresenceSynthesizer::synthesize()` pass. No queued job is introduced for this — the memo is per-process, so a queued invalidation running in a different worker wouldn't even reach the same memo.

**How prompts use it.** Any `Analyst` receiving the `BusinessBrain` (rationale generation, campaign preparation, content generation) can reference `$brain->marketingPresence->summary` (or the individual bucket lists) once it chooses to. This milestone adds the data path only — no existing prompt-building class (`CampaignPreparationPrompt`, `RationaleGenerationPrompt`) was modified to include it in generated prompt text, since none of those are required to change for the data to be available, and touching them is adjacent to Opportunity/Decision Engine territory this milestone explicitly leaves alone. A future session can fold `$brain->marketingPresence->summary` into a prompt's `user()` text with no further plumbing — the value already flows through the same `BusinessBrain` object those prompts already receive.

---

## 9. Relationship to the Opportunity Engine

**Detection is unaffected.** The four MVP `OpportunityDetector` implementations (`FeaturedItemDetector`, `UrgencyDetector`, `NewArrivalDetector`, `ReEngagementDetector`) do not change. Marketing Presence does not introduce a new Opportunity `type` in this milestone, and no detector needs to read `$brain->marketingPresence` to do its job — none of the four current triggers are about *which channel*, they are about *what's happening with the catalog or the campaign cadence*.

**Channel selection is what changes**, and it happens downstream of detection, inside `DecisionEngine::resolveChannelIds()`. Today (`app/Services/Decision/DecisionEngine.php`), channel selection works from `Channel` rows only, with a hardcoded type-affinity list per `campaign_type`:

```php
private function resolveChannelIds(Collection $activeChannels, string $campaignType): array
{
    $affinity = match ($campaignType) {
        'urgency_promotion' => ['email', 'facebook', 'instagram'],
        'featured_item' => ['facebook', 'instagram', 'blog', 'landing_page'],
        're_engagement' => ['email'],
        default => [],
    };
    // ...falls back to all active channels if no affinity match
}
```

This selects only from `channels` — i.e., only from channel types Atlas can already (simulate-)publish to. **This is not changed by this specification.** The `Execution`/`ContentAsset` pipeline only knows how to act on real `Channel` rows; Marketing Presence introduces no new Execution path. What Marketing Presence adds is a second, upstream signal this method (or its future replacement) can weight:

1. **Prefer `Channel` rows whose linked `MarketingChannel` has `importance = 'primary'`** over `secondary`/`experimental`, when more than one candidate `Channel` matches a `campaign_type`'s affinity list.
2. **Do not select a `Channel` whose linked `MarketingChannel.status = 'inactive'`**, unless the campaign type or opportunity explicitly justifies it (e.g., a future `re_engagement`-on-a-specific-channel opportunity type whose entire point is reviving a gone-quiet channel — no such type exists in this milestone, so in practice this means: never, for now).
3. **A `MarketingChannel` with no linked `channel_id`** (declared but not technically connected — e.g., `print`, `events`, or a declared-but-unlinked `instagram`) **cannot** be added to `channel_ids`/`channel_strategy` at all — there is no `Channel` row to create a `ContentAsset`/`Execution` against. This is the concrete meaning of "recommend it as draft/prepared content only" (below).

**"Declared but not publishable → draft/prepared content only."** Two distinct cases exist, and they resolve differently:

- **A `MarketingChannel` type has no `channels.type` equivalent at all** (`print`, `events`, `youtube`, `tiktok`, `google_business_profile`, `website`, `other`). There is no `ContentAsset`/`Execution` path for these today — full stop. They cannot appear in `channel_ids`. Their *influence* is limited to informing `CampaignBlueprint.audience` and `supporting_points` context (Section 10) — e.g., "this business also exhibits at conventions" can shape *what the campaign says*, even though nothing gets prepared *for* that channel.
- **A `MarketingChannel` type has a `channels.type` equivalent but is not linked** (declared Instagram, no `channel_id` yet). Also cannot appear in `channel_ids` — same reason, no `Channel` row exists. Once linked (Section 5/12), it becomes selectable through the existing affinity mechanism, and the resulting `ContentAsset` is prepared exactly as every `ContentAsset` is prepared today: as a draft, subject to the same simulated/real publish outcome as any other channel (per the Channel Publishing Reality Audit, currently simulated for all channels regardless).

**In effect, today, this rule changes nothing about execution behavior** — every channel is draft-only right now regardless of Marketing Presence. Its value is (a) preventing Marketing-Presence-only channel types from ever being mistakenly treated as executable, and (b) establishing the exact enforcement point (`DecisionEngine::resolveChannelIds()`, or its extraction into a dedicated channel-selection service — Phase 6 of the implementation plan) so that the day a real publisher ships for one channel type and not others, `supports_publishing` is already the literal gate the engine consults — no redesign, just a boolean starting to matter.

---

## 10. Relationship to Campaign Blueprint

**The Blueprint schema is unchanged. No version bump.** `CampaignBlueprint.channel_strategy` remains keyed by `channel.type`, and each entry still requires exactly `format`, `angle`, `constraints`, `priority` (`specs/core/campaign-blueprint.md` §3.10). Marketing Presence does not add a field to the Blueprint schema in this milestone.

**What does change** is the *content* `CampaignPreparationAnalyst` produces, because it now has richer input:

- `Decision.channel_ids` is still resolved as described in Section 9 — only real, linked, executable channels ever appear here, so `channel_strategy` composition is unaffected in shape.
- The analyst's prompt context (`specs/core/campaign-blueprint.md` §6, "What the Analyst Receives in Context") gains the company's `marketingPresence` from the Business Brain, so `audience`, `core_message`, and `supporting_points` can reference the business's real, complete marketing footprint — including channels that will never get a `channel_strategy` entry. Example: a `featured_item` campaign's `supporting_points` can now legitimately say "this item hasn't been mentioned in the monthly print mailer either" even though print will never receive a `ContentAsset`.

**Capability display is a rendering concern, not a schema concern.** Whether a given `channel_strategy` entry's channel is "Connected," "Draft only," or otherwise is computed at display time from the linked `MarketingChannel`'s flags (Section 11), using the existing `resources/js/lib/channelCapability.ts` lookup. No Blueprint field is needed to carry this — it would immediately go stale if the channel's capability changed after the Blueprint was generated (Blueprints are immutable once stored, per `specs/core/campaign-blueprint.md` §5).

---

## 11. Relationship to Publishing Capability Labels

The [Channel Publishing Reality Audit](../../docs/reviews/Channel-Publishing-Reality-Audit.md) introduced four capability labels — **Connected**, **Draft only**, **Coming later**, **Not configured** — implemented in `resources/js/lib/channelCapability.ts` and rendered via `Components/UI/ChannelCapabilityBadge.vue`. That implementation computes capability from a bare channel-type string, globally, for every company (e.g., `blog` is always "Draft only" for everyone).

**Marketing Presence is what eventually makes capability per-company instead of purely per-type.** Two companies could reasonably have different capability for "the same" channel type once real integrations exist — one has connected their Instagram credentials, another hasn't declared Instagram at all. This spec does not implement that change (per the instruction not to change existing publishing orchestration beyond labeling), but defines the mapping so the implementation plan's Phase 7 can wire it directly:

| `MarketingChannel` state | Capability label |
|---|---|
| `channel_id` set, `supports_publishing = true` | **Connected** |
| `channel_id` set, `supports_publishing = false` (today: always, per the Reality Audit) | **Draft only** |
| `channel_id` null, but `type` has a `channels.type` equivalent (Section 3) | **Not configured** |
| `channel_id` null, and `type` has no `channels.type` equivalent at all | **Coming later** |

This table is a strict refinement of the existing global `CHANNEL_CAPABILITY` map in `channelCapability.ts` — it does not contradict it. Where no `MarketingChannel` exists yet for a type (e.g., before onboarding declares anything), the existing global, type-only lookup remains the correct fallback.

---

## 12. How Connected Channels Upgrade Without Redesign

This is the central design promise of this specification, restated plainly: **going from "declared" to "the business can actually publish here" is a data change, not a schema change.**

Concretely, when a real publisher ships for (say) Instagram in a future milestone:

1. A real `InstagramPublisher` is implemented and registered (`specs/core/publishing-engine.md` §8) — out of scope here, and for a while yet.
2. The company connects their Instagram account (OAuth flow, out of scope here); a real `channels` row and `channel_credentials` row are created for them, as already specified in `specs/core/publishing-engine.md`.
3. `MarketingPresenceService` links the company's existing, already-declared `MarketingChannel(type: 'instagram')` row to that new `channels` row: sets `channel_id`, `is_connected = true`, `supports_publishing = true` (and `supports_analytics = true` once analytics ingestion also exists for Instagram).
4. **Nothing else changes.** The onboarding step, the Settings UI, the capability badge, `BusinessBrain.marketingPresence`, and `DecisionEngine`'s channel-selection logic already read these fields. They simply start returning different values — a `Channel` that used to be filtered out of `channel_ids` (no `channel_id` link) is now eligible; a badge that used to render "Not configured" now renders "Connected."

No migration, no new table, no UI redesign, no change to how the business declared the channel in the first place. This is the acceptance test for whether this domain model is doing its job (Section 13).

---

## 13. Acceptance Criteria

These criteria define "done" for the Marketing Presence **specification and its eventual implementation.** They are written to be verifiable by automated tests once Milestone 11 is implemented per `docs/plans/Milestone-11-Marketing-Presence.md`.

### Domain Model

- [ ] `marketing_channels` table exists with all fields specified in Section 2
- [ ] `MarketingChannel` model uses `HasUlids` and `BelongsToCompany` (global scope enforced — no cross-company leakage)
- [ ] `objective` and `metadata` cast to `array`
- [ ] `channel_id` is nullable and has no default link at creation
- [ ] No soft deletes; `status: inactive` is the mechanism for "no longer used"

### Declaration (Onboarding + Settings)

- [ ] A company can declare a `MarketingChannel` without any API credentials, OAuth flow, or technical connection of any kind
- [ ] Onboarding's "Where do you market today?" step creates one `MarketingChannel` row per selection, with `is_connected = false`, `supports_publishing = false`, `supports_analytics = false`, `channel_id = null`
- [ ] Declaring a channel never requires or triggers a credentials prompt
- [ ] A company can add, edit, and set a `MarketingChannel` to `inactive` from Settings without deleting the row

### Business Brain Integration

- [ ] `BusinessBrain.marketingPresence` contains all of a company's `MarketingChannel` rows regardless of `status` (not pre-filtered)
- [ ] `MarketingPresenceUpdated` fires on create, update, and delete of a `MarketingChannel`
- [ ] `BusinessBrainService::invalidate()` is called in response to `MarketingPresenceUpdated`, mirroring the existing `FactExtracted`/`KnowledgeSynthesized` pattern
- [ ] A prompt-building `Analyst` can read `$brain->marketingPresence` without an additional database query

### Opportunity / Decision Engine Integration

- [ ] `DecisionEngine`'s channel-selection step never adds a `MarketingChannel` with no `channel_id` to `Decision.channel_ids`
- [ ] `DecisionEngine`'s channel-selection step never selects a `Channel` whose linked `MarketingChannel.status = 'inactive'`, absent an explicit, tested exception
- [ ] When two otherwise-equal `Channel` candidates exist for a `campaign_type`, the one whose linked `MarketingChannel.importance = 'primary'` is preferred over `secondary`/`experimental`
- [ ] The four existing `OpportunityDetector` implementations require no changes and continue to pass their existing tests unmodified

### Campaign / Recommendation UI

- [ ] The Recommendation/Campaign UI never presents a `MarketingChannel` without `supports_publishing = true` as something that "will publish" — it is labeled per Section 11 (Draft only / Coming later / Not configured)
- [ ] A `MarketingChannel` with no `channels.type` equivalent is never shown as a possible destination for a `ContentAsset`

### Tenant Isolation

- [ ] A `MarketingChannel` created for Company A is never visible, selectable, or countable from Company B's context (standard global-scope test, following the pattern used for `Opportunity`, `Fact`, `Knowledge`)
- [ ] `BusinessBrainService::for()` never mixes `marketingPresence` rows across companies, including under the per-process cache (`BusinessBrainService::$memo`)

### What This Milestone Does Not Claim

- [ ] No test or code path asserts that Atlas can publish to Instagram, Facebook, LinkedIn, X, SMS, YouTube, TikTok, Google Business Profile, print, or events as a result of this work
- [ ] No test or code path asserts real analytics ingestion for any new channel type
- [ ] `LogChannelPublisher`/`LogEmailProvider` behavior (per the Channel Publishing Reality Audit) is unchanged

---

## 14. Future Extensibility

### A `marketing_presence` Parent Row

If Marketing Presence ever needs company-level configuration (e.g., a declared "primary content pillar," or a schema describing expected `metadata` shape per `type`, mirroring `Catalog.item_schema`), a `marketing_presences` table with a `hasMany` to `MarketingChannel` can be introduced without touching the `MarketingChannel` schema. Not needed today because there is no such configuration to hold.

### Per-Company Capability Resolution

Section 11's mapping table is the seed of a future `MarketingChannel`-aware replacement for the current type-only `channelCapability()` lookup — accepting a `MarketingChannel` (or `channel_id`) instead of a bare type string, so two companies can show different capability for the same channel type. Not implemented in this milestone; the current global lookup remains correct as a fallback.

### `Integration` ↔ `MarketingChannel` Correlation

A future onboarding or Settings enhancement could detect that a company's declared `website` `MarketingChannel` and their `website_crawl` `Integration` share a URL, and offer to link them — purely a UX convenience; no domain model change required, since the correlation would be computed at read time (matching URLs), not stored as a new FK.

### Channel Gap Detection (Opportunity Engine)

A future `OpportunityDetector` could use `$brain->marketingPresence` directly: e.g., a `channel_gap` opportunity type firing when a `primary`-importance, `active`-status channel has had no associated `Campaign` in longer than its `posting_frequency` would suggest. This is a natural extension of the existing `ReEngagementDetector` pattern but scoped to a specific channel rather than the whole company, and is explicitly **not** part of this milestone (see boundaries in the implementation plan).

### Vertical-Specific Channel Types

New verticals may introduce channel types this enum doesn't anticipate (e.g., a restaurant's third-party delivery-app storefront as a marketing channel). Per Founding Principle 5, this should be handled the same way `CatalogItem.metadata` handles vertical-specific fields: prefer `type: 'other'` with a descriptive `display_name` and vertical detail in `metadata`, rather than growing the `type` enum per vertical. Only add a new enum value when the type recurs across multiple verticals (the same bar `events` and `print` cleared to be added here).

### Analytics Ingestion Awareness

Once `supports_analytics` becomes true for a real channel (Milestone 7 territory), `BusinessBrain.marketingPresence` becomes a natural place to attach lightweight, channel-level performance summaries (e.g., "this channel's last 3 campaigns averaged X engagement") without waiting for the full `CampaignKpiService` aggregation layer. Not specified further here — this is Phase 7/8 territory per the Roadmap, noted only so the `MarketingChannel` schema is not accidentally designed in a way that blocks it (it isn't — `metadata` and the `channel_id` link both already accommodate this).
