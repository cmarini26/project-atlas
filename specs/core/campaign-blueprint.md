# Campaign Blueprint — Design Specification

**Version:** 1.0  
**Status:** Approved — authoritative specification for Milestone 5  
**Depends on:** `specs/core/domain-model.md`, `specs/core/decision-engine.md`  
**See also:** `specs/core/opportunity-engine.md`, `docs/technical/AI.md`

When this document conflicts with others, this document wins for anything related to Campaign Blueprints, `CampaignPreparationAnalyst`, `ContentGenerationAnalyst`, ContentAsset generation, and the Blueprint→Asset→Renderer pipeline. Update the others.

---

## Milestone 5 Implementation Scope

Milestone 5 implements the Campaign Engine: the path from a committed Decision to a fully prepared Campaign that is ready for human approval.

The output of Milestone 5 is:

```
DecisionCommitted
→ Campaign Blueprint generated
→ ContentAssets generated per channel
→ Recommendation surfaced to user
→ User approves or rejects
```

Milestone 5 must not implement:

- Real publishing to any platform (Facebook, Instagram, email providers, SMS gateways)
- Analytics data retrieval
- Learning record creation from execution results
- Billing or subscription logic

Publishing begins in Milestone 6. Measurement begins in Milestone 7. Learning begins in Milestone 8.

---

## 1. What Is a Campaign Blueprint?

A **Campaign Blueprint** is the strategic creative brief that Atlas generates from a committed Decision before any channel-specific content is written.

It is not content. It is the plan for content.

The Blueprint answers: *What is this campaign trying to say, to whom, in what tone, and through which channels?* It gives every downstream content generation step — one per channel — a consistent strategic foundation to build from. Without a Blueprint, each channel gets content generated in isolation from different starting points, producing an incoherent campaign. With a Blueprint, all channel variants share a strategy, a message, and a voice.

**What the Blueprint is not:**

- It is not copy. It does not contain the final social post, email body, or any publishable text.
- It is not a Decision. The Decision commits Atlas to an Opportunity. The Blueprint specifies how to act on that commitment.
- It is not a Recommendation. The Recommendation is the user-facing presentation of the full Campaign. The Blueprint is an internal strategic document that the AI uses to generate assets.

**Where it lives:**

The Blueprint is a structured JSON object persisted in the `campaigns.blueprint` column. It is assembled by `CampaignPreparationAnalyst` and stored before any `GenerateContent` jobs are dispatched. Once stored, it is read-only — if the content needs to be regenerated, a new Campaign is created, not a new Blueprint on the same Campaign.

---

## 2. Relationship to Decision

Every Blueprint derives from exactly one Decision. Every Decision produces exactly one Campaign and therefore exactly one Blueprint.

| Decision field | How it maps to the Blueprint |
|---------------|------------------------------|
| `campaign_type` | Sets `goal` and informs `tone` |
| `rationale.why_this` | Becomes `core_message` seed |
| `rationale.why_works` | Becomes `supporting_points` seed |
| `rationale.why_channel` | Informs `channel_strategy` per channel |
| `rationale.expected_impact` | Populates `success_metrics` |
| `channel_ids` | Determines which channels get a `channel_strategy` entry |
| `opportunity.subject_type/id` | The CatalogItem (or Company) the Blueprint is about |
| `opportunity.description` | Provides the evidence context for `core_message` |
| `company.brand.voice` | Sets the `tone` baseline |

The Blueprint does not replace the rationale — it extends it into actionable creative direction. The rationale explains *why*. The Blueprint specifies *what to say and how*.

### Pipeline Position

```
BusinessBrain
    ↓
DecisionEngine::evaluate()
    ↓
Decision (pending)
    ↓ [DecisionCommitted event]
PrepareCampaign (ai queue)
    ↓
CampaignPreparationAnalyst
    ↓
Campaign (draft) + Blueprint stored
    ↓ [one GenerateContent job per channel]
ContentGenerationAnalyst × N
    ↓
ContentAsset (draft) × N
    ↓ [all assets ready]
RecommendationService::create()
    ↓
Recommendation (pending)
    ↓ [user action]
Approval
```

---

## 3. Required Fields

The Blueprint is a structured JSON object. All required fields must be present and non-empty before any `GenerateContent` job is dispatched. Missing or empty required fields cause `BlueprintGenerationFailedException` to be thrown and the Campaign to remain in `draft` status without progressing.

---

### 3.1 `goal`

**Type:** `string` (enum)  
**Required:** Yes

The marketing objective this campaign is designed to achieve. Derived from the Decision's `campaign_type`.

| `campaign_type` | `goal` value |
|----------------|-------------|
| `featured_item` | `"awareness"` — surface a specific item to the audience |
| `urgency_promotion` | `"conversion"` — drive immediate action before a deadline |
| `re_engagement` | `"re_engagement"` — re-establish contact with an audience that has gone quiet |
| `seasonal` | `"awareness"` — leverage a calendar or seasonal moment |

The goal is used by `ContentGenerationAnalyst` to calibrate content intensity. Conversion-goal campaigns produce stronger CTAs and more urgency language. Awareness-goal campaigns are more editorial in tone.

**Validation:** Must be one of: `awareness`, `conversion`, `re_engagement`.

---

### 3.2 `audience`

**Type:** `string`  
**Required:** Yes

A plain-language description of the audience this campaign is targeting. Not a demographic profile — a behavioural and motivational description that a content generator can act on.

The Blueprint audience is derived from:
1. The opportunity subject (a CatalogItem audience is different from a company-level re-engagement audience)
2. The company's active Knowledge about its customer base
3. The `campaign_type` (urgency campaigns target buyers ready to act; re-engagement campaigns target dormant followers)

**Bad example:** `"People who like cars."`  
**Good example:** `"Exotic car collectors and high-net-worth enthusiasts who follow the dealership for marquee inventory. They respond to exclusivity and rarity signals more than pricing."`

**Validation:** Non-empty string, 20+ characters.

---

### 3.3 `core_message`

**Type:** `string`  
**Required:** Yes

The single idea the campaign communicates. Every piece of content generated from this Blueprint must be traceable to this core message. If a content generator cannot map its output back to the core message, the content is off-brief.

The core message is seeded from the Decision's `rationale.why_this`, compressed into one sentence. The AI may rewrite it in campaign language, but it must preserve the factual basis from the rationale.

**Bad example:** `"This car is for sale."`  
**Good example:** `"The 1967 Ferrari 275 GTB — the rarest piece in the current inventory — has been waiting for the right buyer for 45 days. This is the moment to bring it to the front."`

**Validation:** Non-empty string, 30+ characters.

---

### 3.4 `supporting_points`

**Type:** `string[]`  
**Required:** Yes (minimum 1, maximum 5)

Secondary claims that reinforce the core message. Used by `ContentGenerationAnalyst` to populate body copy, add depth to social captions, or provide bullet points in email campaigns.

Supporting points are seeded from:
- `rationale.why_works` (the mechanism of action)
- `rationale.expected_impact` (what outcome is expected)
- Active Facts about the subject item (price, rarity, age, auction proximity)
- Active Knowledge about audience engagement patterns

Each point should be discrete and independently usable — a content generator might use 1 or 3, depending on the format.

**Bad example:** `["It's a great car", "People like cars"]`  
**Good example:**
```json
[
    "One-owner, numbers-matching example — the strongest provenance in the current inventory",
    "Ferrari 275 GTBs rarely appear in public sale; this one has not been promoted once in 45 days",
    "Featured vehicle campaigns in this segment drive 2–4× higher inquiry rate than standard inventory posts"
]
```

**Validation:** Array of non-empty strings; 1–5 items.

---

### 3.5 `call_to_action`

**Type:** `string`  
**Required:** Yes

The exact action the campaign asks the audience to take. This is not a description of the CTA — it is the CTA text itself, ready to be inserted into content.

The CTA is derived from:
- `goal`: conversion goals use transactional language; awareness goals use curiosity language
- The subject type: CatalogItem CTAs direct to the item page; company-level CTAs direct to the website or social follow
- The channel strategy: different channels may render the same CTA differently (a button label vs. inline text), but the underlying action is the same

**Examples by goal:**

| Goal | Example CTA |
|------|------------|
| `conversion` (urgency) | `"Bid now — auction closes in 48 hours"` |
| `conversion` (featured) | `"Inquire about the Ferrari 275 GTB"` |
| `awareness` | `"See the full inventory at CBB Auctions"` |
| `re_engagement` | `"We're back — see what's new this week"` |

**Validation:** Non-empty string. Must not be generic filler (`"Click here"`, `"Learn more"` unqualified).

---

### 3.6 `offer`

**Type:** `string | null`  
**Required:** No (nullable)

An incentive attached to the campaign, if any. A discount, exclusive access, early viewing, or special term.

For most organic campaigns, this will be null. It becomes relevant when:
- The company has a promotional discount in their active Facts
- The opportunity type is `urgency_promotion` and a price reduction is part of the strategy
- The company's Knowledge includes a preference for offer-led campaigns

When present, the offer is woven into content copy by `ContentGenerationAnalyst`. When null, the content is generated without an offer angle.

**Examples:** `"25% off reserve price for early bidders"`, `"Private viewing available by appointment"`, `null`

**Validation:** String or null. If a string, must be non-empty.

---

### 3.7 `tone`

**Type:** `object`  
**Required:** Yes

Campaign-specific tone settings that modulate how content is written. Derived from the company's brand voice, adjusted for the campaign goal.

**Required structure:**

```json
{
    "voice": "string — the brand's established voice (e.g., 'authoritative', 'warm', 'collector-to-collector')",
    "modifier": "string — how this campaign modulates the base voice (e.g., 'urgent', 'celebratory', 'inviting')",
    "avoid": ["string", "..."] 
}
```

The `voice` field is pulled directly from `company.brand.voice`. The `modifier` is derived from the campaign goal and type. The `avoid` array lists tones, styles, or language patterns to exclude — pulled from company brand settings or accumulated Learning.

**Example:**
```json
{
    "voice": "collector-to-collector, knowledgeable, precise",
    "modifier": "exclusive, now",
    "avoid": ["salesy", "discount language", "FOMO clichés"]
}
```

**Validation:** All three keys required. `voice` and `modifier` must be non-empty strings. `avoid` may be an empty array.

---

### 3.8 `landing_page`

**Type:** `string | null`  
**Required:** No (nullable)

The URL or canonical reference where the campaign drives traffic. Used by channel renderers to produce correct link-in-bio instructions, email buttons, and ad destination URLs.

Sources, in priority order:
1. `CatalogItem.canonical_url` — if the Opportunity subject is a CatalogItem
2. `Integration.config.url` — the company's primary website URL
3. `null` — for pure brand-awareness campaigns where no link is required

**Validation:** Valid URL string or null.

---

### 3.9 `success_metrics`

**Type:** `object`  
**Required:** Yes

A structured definition of what success looks like for this campaign, used on the Recommendation card to give the user a basis for approval. Mirrors and extends the Decision's `expected_impact`.

**Required structure:**

```json
{
    "primary_metric": "string — the single most important outcome to track",
    "secondary_metrics": ["string", "..."],
    "baseline": "string — current state or absence of activity to measure against",
    "timeframe": "string — the window over which to measure results"
}
```

**Example:**
```json
{
    "primary_metric": "Direct inquiries or contact form submissions about the Ferrari 275 GTB",
    "secondary_metrics": [
        "Instagram post reach",
        "Email open rate",
        "Link clicks to catalog item page"
    ],
    "baseline": "0 inquiries generated by this item in the past 45 days",
    "timeframe": "7 days from campaign launch"
}
```

**Validation:** All four keys required. `secondary_metrics` may be an empty array. All string values must be non-empty.

---

### 3.10 `channel_strategy`

**Type:** `object` (keyed by channel type)  
**Required:** Yes

Per-channel creative direction. One entry per active channel in the campaign's `channel_ids`. This is what makes the Blueprint a multi-channel document — the core message, audience, and tone are shared, but the channel strategy tells each content generator how to adapt them for its specific format and audience behaviour.

**Required structure:**

```json
{
    "<channel_type>": {
        "format": "string — the primary content format for this channel",
        "angle": "string — the specific angle or hook for this channel's version of the campaign",
        "constraints": "string — character limits, format requirements, platform rules",
        "priority": "integer — 1 = primary channel, 2 = supporting, 3 = amplification"
    }
}
```

Each entry is keyed by `channel.type` (e.g., `"instagram"`, `"email"`).

**Example:**
```json
{
    "instagram": {
        "format": "single image post with caption",
        "angle": "Lead with the visual — a hero shot of the Ferrari. The caption tells the collector story. CTA in the last line and link-in-bio.",
        "constraints": "Caption under 2,200 characters. No more than 5 hashtags. No emoji in first line.",
        "priority": 1
    },
    "email": {
        "format": "single-feature announcement email",
        "angle": "Private-feeling outreach. 'We thought you'd want to know about this one.' Collector-to-collector voice. CTA is a direct link to the item page.",
        "constraints": "Subject line under 50 characters. Preview text under 100 characters. Single CTA button.",
        "priority": 2
    }
}
```

**Validation:** Must contain at least one entry. Each entry must include all four keys. `priority` must be a positive integer. `format` and `angle` must be non-empty strings.

---

## 4. Blueprint Schema

The complete Blueprint as stored in `campaigns.blueprint`:

```json
{
    "version": "1.0",
    "generated_at": "ISO 8601 timestamp",
    "prompt_version": "string — version of CampaignPreparationPrompt used",
    "goal": "awareness | conversion | re_engagement",
    "audience": "string",
    "core_message": "string",
    "supporting_points": ["string"],
    "call_to_action": "string",
    "offer": "string | null",
    "tone": {
        "voice": "string",
        "modifier": "string",
        "avoid": ["string"]
    },
    "landing_page": "string | null",
    "success_metrics": {
        "primary_metric": "string",
        "secondary_metrics": ["string"],
        "baseline": "string",
        "timeframe": "string"
    },
    "channel_strategy": {
        "<channel_type>": {
            "format": "string",
            "angle": "string",
            "constraints": "string",
            "priority": 1
        }
    }
}
```

---

## 5. Versioning

### Blueprint Version

The `version` field inside the Blueprint JSON identifies the schema version. The current schema is `1.0`. A version increment indicates a breaking or additive change to the required fields or structure.

Version increments require:
- An update to this spec
- A migration if stored data from prior versions must be backfilled or transformed
- A new `CampaignPreparationPrompt` version at minimum

### Prompt Version

`prompt_version` inside the Blueprint records the version of `CampaignPreparationPrompt` used to generate it. This is separate from the Blueprint schema version.

This enables:
- Auditing which prompt version produced which campaigns
- Measuring approval rates by prompt version (Phase 8)
- Rolling back or A/B testing prompt versions without schema changes

**Storage:** `prompt_version` is stored on the Blueprint JSON object and on the `campaigns` table as a separate column if aggregate queries are needed.

### Immutability

Once a Blueprint is stored on a Campaign, it is not modified. If regeneration is required (e.g., a user requests a new attempt, or the rationale generation fails and retries), a new Campaign record is created with a fresh Blueprint. The failed Campaign record is set to `cancelled`.

This ensures the Blueprint is always a faithful record of what the AI generated — no in-place edits blur the audit trail.

---

## 6. AI Generation Contract

`CampaignPreparationAnalyst` is the only component that generates Blueprints. It is an `Analyst` implementation — no other class may call `AiProvider` to produce Blueprint content.

### Inputs

```php
public function analyze(
    Decision $decision,
    BusinessBrain $brain,
): CampaignBlueprint
```

The analyst receives the committed Decision (with its complete rationale) and the full BusinessBrain. It does not receive raw Opportunities — the Decision is the resolved, authoritative input.

### What the Analyst Receives in Context

The prompt passed to the AI provider includes:

- **Decision:** `campaign_type`, all five rationale fields including `expected_impact`, `channel_ids`, `confidence_score`
- **Opportunity:** `type`, `title`, `description`, all four score components
- **Subject entity** (if `subject_type = 'catalog_item'`): `title`, `status`, `price`, `expires_at`, `promoted_at`, `description`, `metadata`
- **Company identity:** `name`, `industry`, `brand.voice`, `brand.tone`, `website_url`
- **Active Facts:** key-value pairs from `BusinessBrain.activeFacts`
- **Active Knowledge:** synthesised insights from `BusinessBrain.activeKnowledge`
- **Available channels:** channel type and name for each ID in `decision.channel_ids`

The analyst does not receive:
- Other open Opportunities (the Decision has already selected one)
- User account or membership data
- Historical approval or rejection records (these are Phase 8 inputs)

### Output

The analyst returns a `CampaignBlueprint` value object, which `CampaignPreparationService` validates before persisting it on the Campaign:

```php
readonly class CampaignBlueprint
{
    public function __construct(
        public string $version,
        public string $promptVersion,
        public string $goal,
        public string $audience,
        public string $coreMessage,
        public array $supportingPoints,
        public string $callToAction,
        public ?string $offer,
        public array $tone,
        public ?string $landingPage,
        public array $successMetrics,
        public array $channelStrategy,
    ) {}
}
```

### Prompt Design

| Setting | Value | Rationale |
|---------|-------|-----------|
| Version | `1.0` | Stored in `prompt_version` on every Blueprint |
| Temperature | `0.5` | More creative latitude than rationale (0.4); less than full content generation (0.7+) |
| Output format | Structured JSON via tool-use (Anthropic) or JSON mode (OpenAI) | Consistent with all other AI outputs in Atlas |
| Tone constraint | Must use `company.brand.voice` as baseline; campaign goal as modifier | Blueprint language must feel like the brand, not generic AI copy |
| Grounding instruction | All claims must be traceable to provided Facts, Knowledge, or rationale fields | No invented statistics or fabricated specifics |
| Channel constraint | Must produce one `channel_strategy` entry per channel ID provided | No channels may be skipped; no channels outside the provided list may be added |

### Failure Handling

**AI provider unavailable:** `PrepareCampaign` retries up to 3× with exponential backoff (60s → 180s → 540s). After 3 failures, the job fails and is sent to the failed job queue. Campaign status remains `draft`. An alert is logged.

**Malformed JSON:** `StructuredResponseParser` throws `InvalidArgumentException`. Job fails immediately — this is a prompt issue, not a transient error. No retry.

**Missing required keys:** `BlueprintGenerationFailedException` is thrown by `CampaignPreparationService`. Campaign remains `draft`. The Decision is not reverted — it remains in `pending` status and the Campaign preparation is retried on the next cycle.

**`channel_strategy` missing a channel:** Treated as a missing required key — `BlueprintGenerationFailedException` thrown.

---

## 7. Validation Rules

Blueprint validation runs in `CampaignPreparationService` before any write. It does not run at the Eloquent model level — the service layer is the enforcement point.

```
goal
    must be one of: 'awareness', 'conversion', 're_engagement'

audience
    must be non-empty string
    must be >= 20 characters

core_message
    must be non-empty string
    must be >= 30 characters

supporting_points
    must be array
    must have 1–5 items
    each item must be non-empty string

call_to_action
    must be non-empty string
    must not match generic disallowed phrases: ['Click here', 'Learn more', 'Read more']
    (exact phrase match, case-insensitive)

offer
    if present: must be non-empty string
    null is permitted

tone
    must be array with keys: voice, modifier, avoid
    voice: non-empty string
    modifier: non-empty string
    avoid: array (may be empty)

landing_page
    if present: must be valid URL
    null is permitted

success_metrics
    must be array with keys: primary_metric, secondary_metrics, baseline, timeframe
    primary_metric: non-empty string
    secondary_metrics: array (may be empty)
    baseline: non-empty string
    timeframe: non-empty string

channel_strategy
    must be non-empty array
    must contain one entry per channel ID in decision.channel_ids
    each entry must have keys: format, angle, constraints, priority
    format: non-empty string
    angle: non-empty string
    constraints: non-empty string
    priority: positive integer
```

Validation failure throws `BlueprintGenerationFailedException` with the specific key that failed. The exception message includes the key name and the constraint violated, to aid debugging.

---

## 8. Acceptance Criteria

These criteria define "done" for Campaign Blueprint generation in Milestone 5. All are verifiable by automated tests.

### Blueprint Generation

- [ ] `PrepareCampaign` job dispatched after `DecisionCommitted` fires
- [ ] `CampaignPreparationAnalyst` called with the Decision and BusinessBrain
- [ ] Blueprint generated and stored in `campaigns.blueprint` as valid JSON
- [ ] All 10 required Blueprint fields present and non-empty after generation
- [ ] `version` is `"1.0"`, `prompt_version` matches the prompt class's `version()` return value
- [ ] `channel_strategy` contains exactly one entry per channel ID in `decision.channel_ids`
- [ ] Campaign status set to `draft` when Blueprint generation begins
- [ ] `BlueprintGenerationFailedException` thrown when any required key is missing — Campaign does not advance to content generation
- [ ] `FakeAiProvider` used in all Blueprint generation tests — no live AI

### Goal Mapping

- [ ] `featured_item` Decision → `goal: "awareness"`
- [ ] `urgency_promotion` Decision → `goal: "conversion"`
- [ ] `re_engagement` Decision → `goal: "re_engagement"`
- [ ] `seasonal` Decision → `goal: "awareness"`

### Channel Strategy

- [ ] `channel_strategy` has one entry per active channel
- [ ] Each entry includes `format`, `angle`, `constraints`, and `priority`
- [ ] Blueprint with no `channel_strategy` entry for a required channel throws `BlueprintGenerationFailedException`

### Failure Paths

- [ ] AI unavailable: `PrepareCampaign` retries 3× with exponential backoff; after 3 failures, job fails; Campaign stays `draft`
- [ ] Malformed AI response: `StructuredResponseParser` throws; job fails immediately
- [ ] Missing required key: `BlueprintGenerationFailedException` thrown; Campaign stays `draft`; Decision stays `pending`

### Versioning

- [ ] `blueprint.version` is stored on every Campaign Blueprint
- [ ] `blueprint.prompt_version` matches the prompt class's `version()` return value
- [ ] Blueprint is not modified after initial write — immutability test verifies no update query on the blueprint column after creation

---

## 9. How Blueprints Become Marketing Assets

Once a Blueprint is stored and validated, `CampaignPreparationService` dispatches one `GenerateContent` job per channel in the Blueprint's `channel_strategy`. Each job runs on the `ai` queue.

### GenerateContent Job

```
GenerateContent
    input: Campaign (with Blueprint), Channel
    calls: ContentGenerationAnalyst
    output: ContentAsset (status: draft)
```

The `ContentGenerationAnalyst` receives:
- The full Blueprint — goal, audience, core message, supporting points, CTA, offer, tone, landing page, success metrics
- The channel-specific `channel_strategy` entry for this channel — format, angle, constraints, priority
- The channel's type — determines which `ContentGenerationPrompt` variant is used
- The subject CatalogItem's `media` array — for visual channels (Instagram, Facebook), image URLs are passed for inclusion in the ContentAsset metadata

### ContentGenerationPrompt Variants

Each channel type has its own prompt variant. The Blueprint fields are the shared input; the prompt variant adapts them to channel-specific requirements:

| Channel | Prompt Variant | Key Output Fields |
|---------|---------------|-------------------|
| `instagram` | `SocialContentPrompt` | `body` (caption), `metadata.hashtags`, `metadata.alt_text` |
| `facebook` | `SocialContentPrompt` | `body`, `metadata.hashtags`, `metadata.link_description` |
| `email` | `EmailContentPrompt` | `body` (HTML), `metadata.subject_line`, `metadata.preview_text` |
| `sms` | `SmsContentPrompt` | `body` (under 160 chars), `metadata.opt_out_text` |
| `blog` | `BlogContentPrompt` | `body` (Markdown), `title`, `metadata.meta_description` |
| `landing_page` | `LandingPageContentPrompt` | `body` (structured sections), `metadata.headline`, `metadata.sub_headline` |

### ContentAsset Creation

`ContentGenerationAnalyst` returns a `ContentAssetData` value object. `ContentGenerationService` validates it and creates the `ContentAsset` record:

```php
ContentAsset::create([
    'company_id'  => $campaign->company_id,
    'campaign_id' => $campaign->id,
    'channel_id'  => $channel->id,
    'type'        => $channelType,
    'body'        => $data->body,
    'title'       => $data->title,
    'media'       => $data->media,
    'metadata'    => $data->metadata,
    'status'      => 'draft',
]);
```

### Completion Handoff

When all expected ContentAssets are created in `draft` status, a `CampaignAssetsReady` event fires. The listener dispatches `CreateRecommendation`, which calls `RecommendationService::create()`:

1. Creates the `Recommendation` record with `status: pending`
2. Populates `rationale_display` from the Decision's rationale, formatted for UI rendering
3. Sets `expected_impact` from the Blueprint's `success_metrics`
4. Updates `Decision.status` to `recommended`
5. Fires `RecommendationCreated`
6. Sends an in-app notification to the company's `owner` and `admin` members

The user now sees a Recommendation card in the interface.

### Count Tracking

`Campaign` tracks expected vs. received ContentAssets:

| Column | Purpose |
|--------|---------|
| `expected_asset_count` | Set when Blueprint is stored; equals count of `channel_strategy` entries |
| `generated_asset_count` | Incremented when each ContentAsset is created |

When `generated_asset_count == expected_asset_count`, the `CampaignAssetsReady` event fires. This ensures `CreateRecommendation` only runs once all assets are present, even if `GenerateContent` jobs complete out of order.

---

## 10. How Marketing Assets Become Channel Renderers

This section defines the path from draft ContentAsset to published content. **Publishing is Milestone 6.** The architecture is defined here to ensure Milestone 5 ContentAssets are structured for Milestone 6 without rework.

### ContentAsset Structure

A ContentAsset's `body` contains the channel-rendered prose — what will be published. Its `metadata` contains channel-specific settings that the renderer needs.

The `body` and `metadata` schema varies by channel type:

**Instagram / Facebook (`social_post`):**
```json
{
    "body": "Caption text here. The 1967 Ferrari 275 GTB — one owner, numbers matching. Link in bio.",
    "metadata": {
        "hashtags": ["#Ferrari275GTB", "#ExoticCars", "#CollectorsEdition"],
        "alt_text": "Red 1967 Ferrari 275 GTB photographed in natural light",
        "platform_settings": {
            "post_type": "feed",
            "location_tag": null
        }
    }
}
```

**Email:**
```json
{
    "title": "We thought you'd want to know about this one.",
    "body": "<HTML email body>",
    "metadata": {
        "subject_line": "The Ferrari 275 GTB is still available",
        "preview_text": "One owner. Numbers matching. 45 days and no one has claimed it.",
        "send_list_id": null
    }
}
```

**SMS:**
```json
{
    "body": "CBB Motors: The Ferrari 275 GTB is still available. Inquire at [URL]. Reply STOP to opt out.",
    "metadata": {
        "opt_out_text": "Reply STOP to opt out",
        "segment_id": null
    }
}
```

### Channel Renderer Interface

In Milestone 6, each channel type has a `ChannelRenderer` implementation. The renderer's job is to take a `ContentAsset` and publish it to the platform via the company's configured channel credentials:

```php
interface ChannelRenderer
{
    public function render(ContentAsset $asset, Channel $channel): ExecutionResult;
}
```

Implementations:
- `InstagramRenderer` — uses Instagram Graph API; posts image + caption
- `FacebookRenderer` — uses Facebook Graph API; posts to page
- `EmailRenderer` — uses configured email provider (Mailchimp, Klaviyo, Postmark); sends to list
- `SmsRenderer` — uses configured SMS provider (Twilio); sends to list
- `BlogRenderer` — posts to CMS via API or file-based publishing
- `LandingPageRenderer` — creates/updates a landing page via CMS or hosted page service

The `ChannelRenderer` contract is stable by end of Milestone 5. Milestone 6 implements the bodies. ContentAssets created in Milestone 5 must be structured to be renderable without modification.

### Execution Record

When a renderer publishes, it creates an `Execution` record:

```
ContentAsset (approved)
    ↓ [ChannelRenderer.render()]
Execution (completed)
    → result: { platform_id, url, published_at }
```

Each ContentAsset gets exactly one Execution. The Execution's `result` field stores the platform response — the post ID, message ID, or equivalent — which is used for metric retrieval in Milestone 7.

---

## 11. Future Extensibility

### Human-Authored Blueprints

The Blueprint schema is intentionally separate from the AI generation pathway. In a future phase, users may be able to provide a Blueprint directly — specifying their own core message, audience, and CTA without going through `CampaignPreparationAnalyst`. The Campaign Engine would skip AI generation and go straight to `GenerateContent` jobs using the user-provided Blueprint.

The `campaigns.blueprint` column accommodates this — the schema is the same regardless of how the Blueprint was authored.

### Blueprint Templates Per Vertical

CBB Auctions campaigns follow different creative conventions than exotic car dealer campaigns. In a future phase, vertical knowledge packs will include Blueprint templates — pre-configured defaults for `tone`, `channel_strategy.constraints`, and `supporting_points` seeds that the AI applies as a starting point rather than generating from scratch.

The template system would be a `BlueprintTemplate` lookup keyed by `(industry, campaign_type)`, loaded into the `CampaignPreparationPrompt` before the AI is called.

### A/B Blueprint Variants (Phase 8)

Once Learning is in place, Atlas can generate two Blueprint variants for a given Decision and test them. The Learning Engine records which variant's downstream campaign achieved higher engagement and uses that signal to calibrate `CampaignPreparationPrompt` behaviour.

The current schema supports this by versioning both the Blueprint schema (`version`) and the generating prompt (`prompt_version`). A variant system would add a `variant_id` field to distinguish siblings.

### Multi-Wave Campaigns

The current model assumes a Campaign is a single wave — one Blueprint, one set of ContentAssets, one Execution per channel. A future Campaign may span multiple waves (e.g., a teaser post on day 1, a feature post on day 3, a close post on day 7). Each wave would have its own Blueprint, derived from the parent Campaign Blueprint, with wave-specific angle and timing.

The `campaigns.blueprint` column stores the parent Blueprint. Wave Blueprints would be stored on a `campaign_waves` table (not in scope for MVP).

### Per-Company Blueprint Calibration (Phase 8)

Currently, Blueprint generation uses the same prompt for all companies. In Phase 8, `Learning` records from user edits and rejections accumulate per company, adjusting what the AI emphasises in future Blueprints for that company. A company that repeatedly edits the CTA away from urgency language learns that Atlas should generate softer CTAs. This calibration happens at the prompt level, not the Blueprint schema level — the schema is stable.
