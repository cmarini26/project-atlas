# Atlas Domain Model

This document is the canonical reference for Atlas domain concepts. Read it before writing migrations, Eloquent models, service classes, or events. When this document conflicts with other docs, this one wins — update the others.

---

## Primary Lifecycle

```
Observe → Understand → Decide → Recommend → Prepare → Approve → Execute → Measure → Learn
```

Each stage maps to one or more domain entities:

| Stage       | Primary Entity     | Triggered By                          |
|-------------|-------------------|---------------------------------------|
| Observe     | Observation        | Integration sync (scheduled or manual)|
| Understand  | Fact, Knowledge    | Observation processed event           |
| Decide      | Opportunity, Decision | Opportunity Engine (scheduled job) |
| Recommend   | Recommendation     | Decision committed event              |
| Prepare     | Campaign, Content Asset | Recommendation created event    |
| Approve     | Approval           | User action                           |
| Execute     | Execution          | Approval granted event                |
| Measure     | Execution (results)| Platform callbacks / polling          |
| Learn       | Learning           | Execution completed or Approval acted on |

The loop runs continuously per company. A new Observation can trigger a new cycle at any time.

---

## Multi-Tenancy

All entities below are scoped to a `company_id`. Apply a global scope on every model that carries `company_id` to prevent cross-company data leaks. The `Company` model itself is the tenancy root — never query across companies without an explicit, audited reason.

**ID strategy:** Use ULIDs (`Str::ulid()`) for all primary keys. They are sortable, URL-safe, and avoid sequential ID enumeration. Add `use HasUlids` to every model.

**Soft deletes:** Apply `SoftDeletes` to Company, CatalogItem, Campaign, ContentAsset, and Recommendation. Hard-delete Observations and raw payloads on a schedule to control storage growth.

---

## Entities

---

### Company

**Definition:** The root entity. Represents one business using Atlas. Every other entity belongs to a Company.

**Purpose:** Tenancy anchor. Holds brand identity and top-level configuration. Atlas builds everything else around this.

**Table:** `companies`

| Column         | Type         | Notes                                         |
|----------------|--------------|-----------------------------------------------|
| id             | ulid         | PK                                            |
| name           | string       |                                               |
| slug           | string       | unique, URL-safe identifier                   |
| industry       | string       | nullable; used for vertical routing           |
| website_url    | string       | nullable; primary crawl target                |
| brand          | json         | `{voice, tone, colors[], logo_path}`          |
| settings       | json         | company-level feature flags and preferences   |
| created_at     | timestamp    |                                               |
| updated_at     | timestamp    |                                               |
| deleted_at     | timestamp    | nullable; soft delete                         |

**Relationships:**
- `hasOne` DigitalTwin
- `hasOne` Catalog
- `hasMany` Integration
- `hasMany` Observation
- `hasMany` Fact
- `hasMany` Knowledge
- `hasMany` Opportunity
- `hasMany` Decision
- `hasMany` Recommendation
- `hasMany` Campaign
- `hasMany` Learning

**Lifecycle states:** No formal state machine. A Company is considered "active" once its DigitalTwin is initialized.

**Laravel notes:**
- Model: `App\Models\Company`
- Use `HasUlids`, `SoftDeletes`
- Cast `brand` and `settings` to `array`
- `slug` generated on creation from `name` using `Str::slug()`

---

### Digital Twin

**Definition:** A living, structured model of a Company that Atlas builds and continuously maintains. One per Company.

**Purpose:** Holds the aggregate health and state of everything Atlas knows about the business. Acts as the top-level record for the Business Brain. It is the entity that Atlas updates when new knowledge arrives and the entity that the Opportunity Engine queries to determine readiness.

**Table:** `digital_twins`

| Column           | Type      | Notes                                                         |
|------------------|-----------|---------------------------------------------------------------|
| id               | ulid      | PK                                                            |
| company_id       | ulid      | FK, unique (one twin per company)                             |
| status           | enum      | `initializing`, `active`, `stale`, `archived`                 |
| health_score     | tinyint   | 0–100; composite of data freshness, fact coverage, knowledge depth |
| last_observed_at | timestamp | nullable; when the last Observation was recorded              |
| last_enriched_at | timestamp | nullable; when Facts or Knowledge were last updated           |
| metadata         | json      | arbitrary twin-level context (vertical config, feature flags) |
| created_at       | timestamp |                                                               |
| updated_at       | timestamp |                                                               |

**Relationships:**
- `belongsTo` Company
- Logically owns all Facts, Knowledge, Observations, and Opportunities for the Company (queried via `company_id`)

**Lifecycle states:**

| State          | Meaning                                                               |
|----------------|-----------------------------------------------------------------------|
| `initializing` | Created but not yet populated; initial crawl pending                  |
| `active`       | Has been populated; Opportunity Engine is scanning it                 |
| `stale`        | No observations in the last 7 days; health_score degrading            |
| `archived`     | Company is inactive; twin preserved but no further updates            |

**Laravel notes:**
- Model: `App\Models\DigitalTwin`
- Use `HasUlids`
- `health_score` is recomputed by a scheduled job (`RecalculateDigitalTwinHealth`) — not a DB-computed column
- Fire `DigitalTwinActivated` event when status transitions from `initializing` → `active`
- Cast `metadata` to `array`

---

### Business Brain

**Definition:** The knowledge layer of the Digital Twin. Not a separate database table — it is a service-layer concept that aggregates Facts, Knowledge, and Company profile into a structured context object used by AI services.

**Purpose:** Provides the AI layer with a complete, structured snapshot of what Atlas knows about the business at a given moment. Passed as context when generating Opportunities, Decisions, rationale, and campaign content.

**Representation:** A `BusinessBrain` value object (readonly PHP class), assembled on demand by `BusinessBrainService`.

**Structure:**
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
    ) {}
}
```

**Laravel notes:**
- No migration — assembled in memory
- Service: `App\Services\Brain\BusinessBrainService`
- Method: `BusinessBrainService::for(Company $company): BusinessBrain`
- Serialized to structured JSON when passed to AI prompt builders
- Cache with a short TTL (e.g., 5 minutes) per company; invalidated on new Fact or Knowledge

---

### Catalog

**Definition:** The structured container for everything a Company sells, offers, or promotes. One per Company.

**Purpose:** Acts as the root of the Company's product/inventory/service data. Catalog Items are attached here. The Catalog also holds sync configuration and metadata about the item schema for this business.

**Table:** `catalogs`

| Column         | Type      | Notes                                                   |
|----------------|-----------|---------------------------------------------------------|
| id             | ulid      | PK                                                      |
| company_id     | ulid      | FK, unique                                              |
| name           | string    | default: "Main Catalog"                                 |
| type           | enum      | `inventory`, `services`, `menu`, `listings`, `mixed`    |
| item_schema    | json      | defines expected metadata fields for this vertical      |
| last_synced_at | timestamp | nullable                                                |
| created_at     | timestamp |                                                         |
| updated_at     | timestamp |                                                         |

**Relationships:**
- `belongsTo` Company
- `hasMany` CatalogItem

**Laravel notes:**
- Model: `App\Models\Catalog`
- Use `HasUlids`
- `item_schema` describes the shape of `CatalogItem.metadata` for this business; used by the UI to render vertical-specific fields
- Auto-created when Company is created (`CompanyCreated` listener)

---

### Catalog Item

**Definition:** A single item in a Company's Catalog. Intentionally generic — the vertical-specific fields live in `metadata`.

**Purpose:** Represents anything a business might promote: a vehicle, a comic book listing, a menu item, a service. The Opportunity Engine queries Catalog Items to identify candidates for campaigns.

**Table:** `catalog_items`

| Column       | Type      | Notes                                                                |
|--------------|-----------|----------------------------------------------------------------------|
| id           | ulid      | PK                                                                   |
| catalog_id   | ulid      | FK                                                                   |
| company_id   | ulid      | FK, denormalized for query performance                               |
| external_id  | string    | nullable; source system identifier (feed ID, URL slug, SKU)          |
| title        | string    |                                                                      |
| description  | text      | nullable                                                             |
| status       | enum      | `active`, `featured`, `sold`, `expired`, `archived`                  |
| price        | decimal   | nullable; 10,2                                                       |
| media        | json      | `[{url, type, alt, is_primary}]`                                     |
| metadata     | json      | vertical-specific fields (grade, make/model, auction_end_at, etc.)   |
| promoted_at  | timestamp | nullable; last time this item was the subject of a campaign          |
| featured_at  | timestamp | nullable; when status was set to `featured`                          |
| expires_at   | timestamp | nullable; hard deadline (auction close, listing expiry)              |
| sold_at      | timestamp | nullable                                                             |
| created_at   | timestamp |                                                                      |
| updated_at   | timestamp |                                                                      |
| deleted_at   | timestamp | nullable; soft delete                                                |

**Relationships:**
- `belongsTo` Catalog
- `belongsTo` Company
- `hasMany` Opportunity (as the subject)

**Lifecycle states:**

| Status     | Meaning                                           |
|------------|---------------------------------------------------|
| `active`   | Available in catalog; eligible for promotion      |
| `featured` | Currently selected as a featured item             |
| `sold`     | No longer available; archived for Learning        |
| `expired`  | Listing closed or past its deadline               |
| `archived` | Manually removed from active consideration        |

**Laravel notes:**
- Model: `App\Models\CatalogItem`
- Use `HasUlids`, `SoftDeletes`
- Cast `media` and `metadata` to `array`
- Index: `(company_id, status)`, `(catalog_id, status)`, `(external_id, company_id)`
- `external_id` + `company_id` should be unique to prevent duplicates on re-sync
- The `metadata` JSON shape is validated against `Catalog.item_schema` in a `CatalogItemObserver` or form request

---

### Integration

**Definition:** A configured connection between a Company and an external data source that Atlas observes.

**Purpose:** Defines where and how Atlas pulls data — website URLs, RSS feeds, inventory APIs, manual uploads. Each Integration is the source of one or more Observations.

**Table:** `integrations`

| Column         | Type      | Notes                                                              |
|----------------|-----------|--------------------------------------------------------------------|
| id             | ulid      | PK                                                                 |
| company_id     | ulid      | FK                                                                 |
| type           | enum      | `website_crawl`, `rss_feed`, `api`, `csv_upload`, `manual`        |
| name           | string    | human-readable label                                               |
| config         | json      | `{url, headers, auth, schedule, selectors}` — encrypted at rest   |
| status         | enum      | `active`, `paused`, `error`, `disconnected`                        |
| last_run_at    | timestamp | nullable                                                           |
| next_run_at    | timestamp | nullable; used by the scheduler                                    |
| last_error     | text      | nullable; last error message                                       |
| created_at     | timestamp |                                                                    |
| updated_at     | timestamp |                                                                    |

**Relationships:**
- `belongsTo` Company
- `hasMany` Observation

**Laravel notes:**
- Model: `App\Models\Integration`
- Use `HasUlids`
- Encrypt `config` using Laravel's `encrypted` cast
- Scheduler queries `integrations` where `status = active` and `next_run_at <= now()`
- Each Integration type has a corresponding handler: `App\Services\Integrations\WebsiteCrawlHandler`, etc.
- Fire `IntegrationSyncStarted` and `IntegrationSyncCompleted` events

---

### Observation

**Definition:** A raw, timestamped snapshot captured when Atlas syncs with an Integration. The unprocessed record of what was seen.

**Purpose:** Provides an audit trail of everything Atlas has ingested. Observations are the input to the Fact extraction pipeline. They are processed asynchronously; once processed, the raw payload may be pruned to control storage.

**Table:** `observations`

| Column            | Type      | Notes                                                        |
|-------------------|-----------|--------------------------------------------------------------|
| id                | ulid      | PK                                                           |
| company_id        | ulid      | FK                                                           |
| integration_id    | ulid      | FK, nullable (null for manual or internal observations)      |
| source_type       | enum      | `crawl`, `feed`, `api`, `manual`, `internal`                 |
| source_identifier | string    | URL, endpoint, or label that was observed                    |
| raw_payload       | longtext  | nullable; JSON or HTML — pruned after processing             |
| status            | enum      | `pending`, `processing`, `processed`, `failed`               |
| observed_at       | timestamp | when the observation was captured (may differ from created_at)|
| processed_at      | timestamp | nullable                                                     |
| created_at        | timestamp |                                                              |
| updated_at        | timestamp |                                                              |

**Relationships:**
- `belongsTo` Company
- `belongsTo` Integration (nullable)
- `hasMany` Fact (facts extracted from this observation)

**Lifecycle states:** `pending` → `processing` → `processed` / `failed`

**Laravel notes:**
- Model: `App\Models\Observation`
- Use `HasUlids`
- No soft deletes; hard-delete `raw_payload` after `processed_at` + 30 days via scheduled prune job
- Queued job: `App\Jobs\ProcessObservation` — dispatched on `ObservationRecorded` event
- Index: `(company_id, status)`, `(integration_id, observed_at)`

---

### Fact

**Definition:** A discrete, structured, verifiable piece of information about a Company, derived from an Observation.

**Purpose:** The atomic unit of knowledge in the Business Brain. Facts are machine-readable, typed, and versioned. When a newer Fact from the same source supersedes an older one, the old Fact is archived (`is_current = false`) rather than deleted — the history is valuable.

**Table:** `facts`

| Column           | Type      | Notes                                                            |
|------------------|-----------|------------------------------------------------------------------|
| id               | ulid      | PK                                                              |
| company_id       | ulid      | FK                                                              |
| observation_id   | ulid      | FK, nullable                                                    |
| key              | string    | namespaced dot notation: `catalog.active_item_count`, `brand.tone` |
| value            | json      | typed value; always JSON even for scalars                        |
| data_type        | enum      | `integer`, `float`, `string`, `boolean`, `json`                  |
| confidence       | tinyint   | 0–100; how reliable this fact is                                 |
| is_current       | boolean   | false when superseded by a newer fact with the same key         |
| superseded_by_id | ulid      | FK to facts, nullable                                            |
| valid_from       | timestamp | when this fact became true                                       |
| valid_until      | timestamp | nullable; known expiry                                           |
| created_at       | timestamp |                                                                  |
| updated_at       | timestamp |                                                                  |

**Relationships:**
- `belongsTo` Company
- `belongsTo` Observation (nullable)
- `belongsTo` Fact (superseded_by)

**Laravel notes:**
- Model: `App\Models\Fact`
- Use `HasUlids`
- No soft deletes; use `is_current` flag
- Scope: `Fact::current()` → `where('is_current', true)`
- Index: `(company_id, key, is_current)` — the primary query path
- Fact keys should be registered in a `FactKey` enum or constants class to prevent typos

---

### Knowledge

**Definition:** A higher-order insight derived from one or more Facts through AI analysis or rule-based synthesis. Represents patterns, inferences, and strategic context — not raw data.

**Purpose:** Knowledge feeds the Opportunity Engine. Facts tell Atlas what is true. Knowledge tells Atlas what it means. Example: the Fact "no campaign published in 14 days" becomes the Knowledge "this business is at risk of audience disengagement."

**Table:** `knowledge_entries`

| Column           | Type      | Notes                                                          |
|------------------|-----------|----------------------------------------------------------------|
| id               | ulid      | PK                                                            |
| company_id       | ulid      | FK                                                            |
| type             | enum      | `pattern`, `insight`, `preference`, `performance`, `context`  |
| subject          | string    | what this knowledge is about (e.g., `channel.email`, `catalog.featured`) |
| body             | text      | human-readable statement of the knowledge                     |
| structured       | json      | machine-readable form; shape varies by type                   |
| source_fact_ids  | json      | array of Fact IDs that produced this knowledge                |
| confidence       | tinyint   | 0–100                                                         |
| is_active        | boolean   | false when superseded or invalidated                          |
| generated_at     | timestamp |                                                               |
| expires_at       | timestamp | nullable; some knowledge has a natural shelf life             |
| created_at       | timestamp |                                                               |
| updated_at       | timestamp |                                                               |

**Relationships:**
- `belongsTo` Company

**Laravel notes:**
- Model: `App\Models\Knowledge` (table: `knowledge_entries` to avoid SQL reserved word)
- Use `HasUlids`
- Scope: `Knowledge::active()` → `where('is_active', true)->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))`
- AI synthesis job: `App\Jobs\SynthesizeKnowledge` — dispatched after Facts are updated
- Index: `(company_id, type, is_active)`

---

### Opportunity

**Definition:** A marketing moment identified by the Opportunity Engine — a condition under which a campaign would be timely, relevant, and likely to perform.

**Purpose:** Translates Business Brain state into actionable marketing candidates. Opportunities are scored and ranked. The Decision Engine selects among them. Not all Opportunities become Decisions.

**Table:** `opportunities`

| Column           | Type      | Notes                                                           |
|------------------|-----------|-----------------------------------------------------------------|
| id               | ulid      | PK                                                             |
| company_id       | ulid      | FK                                                             |
| catalog_item_id  | ulid      | FK, nullable; the item this opportunity is about               |
| type             | enum      | `featured_item`, `urgency`, `new_arrival`, `re_engagement`, `seasonal`, `milestone` |
| title            | string    | short label, e.g., "Ferrari 275 GTB — no campaign in 45 days" |
| description      | text      | why this is an opportunity                                     |
| relevance_score  | tinyint   | 0–100                                                          |
| timing_score     | tinyint   | 0–100                                                          |
| confidence_score | tinyint   | 0–100                                                          |
| urgency_score    | tinyint   | 0–100                                                          |
| composite_score  | tinyint   | 0–100; weighted sum                                            |
| status           | enum      | `open`, `selected`, `dismissed`, `expired`                     |
| expires_at       | timestamp | nullable; after this, status auto-transitions to `expired`     |
| detected_at      | timestamp |                                                               |
| created_at       | timestamp |                                                               |
| updated_at       | timestamp |                                                               |

**Relationships:**
- `belongsTo` Company
- `belongsTo` CatalogItem (nullable)
- `hasOne` Decision

**Lifecycle states:**

| Status      | Meaning                                              |
|-------------|------------------------------------------------------|
| `open`      | Scored and available for the Decision Engine         |
| `selected`  | Decision Engine committed to this opportunity        |
| `dismissed` | Explicitly skipped (manually or by scoring logic)    |
| `expired`   | Opportunity window passed without a Decision         |

**Laravel notes:**
- Model: `App\Models\Opportunity`
- Use `HasUlids`
- Composite score formula: `(relevance × 0.30) + (timing × 0.25) + (confidence × 0.25) + (urgency × 0.20)`
- Score computed in `OpportunityScorer` service, not in the model
- Index: `(company_id, status, composite_score)` — primary query path for Decision Engine
- Scheduled job: `App\Jobs\DetectOpportunities` — runs per company on a configurable schedule

---

### Decision

**Definition:** A committed choice by the Decision Engine to act on a specific Opportunity.

**Purpose:** The moment Atlas commits to a marketing action. A Decision is irrevocable in the sense that it creates a Recommendation and Campaign. If the user rejects the Recommendation, that rejection is recorded as a Learning — but the Decision itself is not undone.

**Table:** `decisions`

| Column           | Type      | Notes                                                            |
|------------------|-----------|------------------------------------------------------------------|
| id               | ulid      | PK                                                              |
| company_id       | ulid      | FK                                                              |
| opportunity_id   | ulid      | FK, unique (one Decision per Opportunity)                        |
| campaign_type    | enum      | `featured_item`, `urgency_promotion`, `seasonal`, `re_engagement`|
| channel_ids      | json      | array of Channel IDs selected for this campaign                  |
| rationale        | json      | `{why_now, why_this, why_channel, why_works}` — required         |
| confidence_score | tinyint   | 0–100                                                            |
| expected_outcome | text      | nullable; what Atlas expects to happen                           |
| status           | enum      | `pending`, `recommended`, `approved`, `rejected`, `executed`, `cancelled` |
| decided_at       | timestamp |                                                                  |
| created_at       | timestamp |                                                                  |
| updated_at       | timestamp |                                                                  |

**Relationships:**
- `belongsTo` Company
- `belongsTo` Opportunity
- `hasOne` Recommendation
- `hasOne` Campaign

**Lifecycle states:**

| Status        | Meaning                                              |
|---------------|------------------------------------------------------|
| `pending`     | Decision committed; Recommendation being prepared    |
| `recommended` | Recommendation surfaced to user                      |
| `approved`    | User approved; Campaign is executing                 |
| `rejected`    | User rejected; Learning recorded                     |
| `executed`    | Campaign completed                                   |
| `cancelled`   | Cancelled before user saw it (e.g., opportunity expired) |

**Laravel notes:**
- Model: `App\Models\Decision`
- Use `HasUlids`
- `rationale` is required at creation — validation enforced in `DecisionService`, not just model
- Cast `channel_ids` and `rationale` to `array`
- Fire `DecisionCommitted` event on creation → triggers `CreateRecommendation` listener
- The `rationale` JSON must always contain all four keys; missing keys cause a validation exception

---

### Recommendation

**Definition:** The user-facing output of a Decision. What the user sees in the approval interface.

**Purpose:** Bridges the internal Decision to the user experience. The Recommendation presents everything the user needs to evaluate and respond: what Atlas wants to do, why, the confidence level, and the prepared content.

**Table:** `recommendations`

| Column              | Type      | Notes                                                     |
|---------------------|-----------|-----------------------------------------------------------|
| id                  | ulid      | PK                                                       |
| company_id          | ulid      | FK                                                       |
| decision_id         | ulid      | FK, unique                                               |
| title               | string    | e.g., "Feature the Ferrari 275 GTB this week"            |
| summary             | text      | 2–3 sentence explanation of what Atlas wants to do       |
| rationale_display   | json      | structured rationale formatted for UI rendering          |
| confidence_score    | tinyint   | 0–100                                                    |
| status              | enum      | `pending`, `viewed`, `approved`, `edited_and_approved`, `rejected` |
| viewed_at           | timestamp | nullable; when user first opened it                      |
| responded_at        | timestamp | nullable                                                 |
| created_at          | timestamp |                                                          |
| updated_at          | timestamp |                                                          |

**Relationships:**
- `belongsTo` Company
- `belongsTo` Decision
- `hasOne` Campaign (via Decision)
- `hasMany` ContentAsset (via Campaign)
- `hasOne` Approval

**Lifecycle states:**

| Status                | Meaning                                          |
|-----------------------|--------------------------------------------------|
| `pending`             | Created; not yet viewed by user                  |
| `viewed`              | User opened the recommendation                   |
| `approved`            | Approved as-is                                   |
| `edited_and_approved` | User edited content then approved                |
| `rejected`            | User rejected; reason optionally recorded        |

**Laravel notes:**
- Model: `App\Models\Recommendation`
- Use `HasUlids`, `SoftDeletes`
- Cast `rationale_display` to `array`
- `pending` recommendations should appear as a notification/badge in the UI
- Index: `(company_id, status)` — primary dashboard query

---

### Campaign

**Definition:** The marketing plan prepared in response to a Decision. Contains strategy, positioning, and the schedule for execution.

**Purpose:** The container for a coherent marketing effort. A Campaign holds the strategic layer (angle, audience, CTA) while Content Assets hold the individual pieces of content. One Campaign may have many Content Assets across multiple Channels.

**Table:** `campaigns`

| Column              | Type      | Notes                                                     |
|---------------------|-----------|-----------------------------------------------------------|
| id                  | ulid      | PK                                                       |
| company_id          | ulid      | FK                                                       |
| decision_id         | ulid      | FK, nullable (future: manual campaigns)                  |
| recommendation_id   | ulid      | FK, nullable                                             |
| title               | string    |                                                          |
| strategy            | text      | the campaign angle and approach                          |
| target_audience     | text      |                                                          |
| positioning         | text      |                                                          |
| call_to_action      | string    |                                                          |
| status              | enum      | `draft`, `approved`, `scheduled`, `executing`, `completed`, `cancelled`, `archived` |
| scheduled_start_at  | timestamp | nullable                                                 |
| scheduled_end_at    | timestamp | nullable                                                 |
| created_at          | timestamp |                                                          |
| updated_at          | timestamp |                                                          |
| deleted_at          | timestamp | nullable; soft delete                                    |

**Relationships:**
- `belongsTo` Company
- `belongsTo` Decision (nullable)
- `belongsTo` Recommendation (nullable)
- `hasMany` ContentAsset
- `hasMany` Execution (via ContentAsset)

**Lifecycle states:**

| Status       | Meaning                                               |
|--------------|-------------------------------------------------------|
| `draft`      | Being prepared by the Campaign Engine                 |
| `approved`   | Approved; ready for scheduling                        |
| `scheduled`  | Queued for execution at a specific time               |
| `executing`  | In progress; some content assets being published      |
| `completed`  | All executions finished                               |
| `cancelled`  | Cancelled before execution                            |
| `archived`   | Historical record; no further actions                 |

**Laravel notes:**
- Model: `App\Models\Campaign`
- Use `HasUlids`, `SoftDeletes`
- Fire `CampaignApproved` event on status → `approved` transition
- Fire `CampaignCompleted` event on status → `completed` transition → triggers Learning

---

### Content Asset

**Definition:** A single piece of content generated for a specific Channel within a Campaign.

**Purpose:** The atomic unit of publishable content. One Campaign produces multiple Content Assets — one per channel per content piece. Each Content Asset is individually approvable and executable.

**Table:** `content_assets`

| Column       | Type      | Notes                                                              |
|--------------|-----------|--------------------------------------------------------------------|
| id           | ulid      | PK                                                               |
| company_id   | ulid      | FK                                                               |
| campaign_id  | ulid      | FK                                                               |
| channel_id   | ulid      | FK                                                               |
| type         | enum      | `social_post`, `email`, `sms`, `blog_post`, `ad_copy`, `landing_page` |
| title        | string    | nullable                                                         |
| body         | text      | the generated content                                            |
| media        | json      | `[{url, type, alt, is_primary}]`                                 |
| metadata     | json      | platform-specific settings (hashtags, subject line, send list, etc.) |
| status       | enum      | `draft`, `approved`, `scheduled`, `published`, `archived`        |
| scheduled_at | timestamp | nullable                                                         |
| published_at | timestamp | nullable                                                         |
| created_at   | timestamp |                                                                  |
| updated_at   | timestamp |                                                                  |
| deleted_at   | timestamp | nullable; soft delete                                            |

**Relationships:**
- `belongsTo` Company
- `belongsTo` Campaign
- `belongsTo` Channel
- `hasOne` Execution
- `hasOne` Approval (polymorphic)

**Laravel notes:**
- Model: `App\Models\ContentAsset`
- Use `HasUlids`, `SoftDeletes`
- Cast `media` and `metadata` to `array`
- Generated by `App\Services\Content\ContentGenerationService` via AI
- An email Content Asset's `metadata` carries `{subject_line, preview_text, send_list_id}`
- A social Content Asset's `metadata` carries `{hashtags[], platform_settings}`

---

### Channel

**Definition:** A publishing destination. Represents a platform or medium through which content is distributed.

**Purpose:** Defines what channels a Company is active on and how to reach each one. Content Assets are generated and scoped per Channel. Channels carry platform-specific config.

**Table:** `channels`

| Column     | Type      | Notes                                                           |
|------------|-----------|-----------------------------------------------------------------|
| id         | ulid      | PK                                                            |
| company_id | ulid      | FK, nullable — null rows are global/system channel definitions  |
| type       | enum      | `facebook`, `instagram`, `linkedin`, `x`, `email`, `sms`, `blog`, `landing_page` |
| name       | string    | e.g., "CBB Auctions Instagram"                                  |
| config     | json      | encrypted; platform credentials, page IDs, list IDs            |
| is_active  | boolean   | default true                                                    |
| created_at | timestamp |                                                                 |
| updated_at | timestamp |                                                                 |

**Relationships:**
- `belongsTo` Company (nullable for system channels)
- `hasMany` ContentAsset

**Laravel notes:**
- Model: `App\Models\Channel`
- Use `HasUlids`
- Encrypt `config` using Laravel's `encrypted` cast
- Global channel templates (company_id = null) define supported channel types; company channels extend them with credentials
- In MVP, channels are used for content generation scoping only — publishing is out of scope

---

### Approval

**Definition:** A user's recorded response to a Recommendation or individual Content Asset. Captures the action taken, any notes, and any edits made.

**Purpose:** Provides the audit trail for human oversight. Every Approval record is a signal that feeds back into Learning. Approval is required before any Campaign proceeds to Execution.

**Table:** `approvals`

| Column           | Type      | Notes                                                           |
|------------------|-----------|-----------------------------------------------------------------|
| id               | ulid      | PK                                                            |
| company_id       | ulid      | FK                                                            |
| approvable_type  | string    | polymorphic; `Recommendation` or `ContentAsset`               |
| approvable_id    | ulid      | polymorphic                                                   |
| user_id          | ulid      | FK; the user who took the action                              |
| action           | enum      | `approved`, `rejected`, `edited_and_approved`                 |
| notes            | text      | nullable; user's reason or feedback                           |
| edits            | json      | nullable; structured diff of what the user changed            |
| acted_at         | timestamp | when the user responded                                       |
| created_at       | timestamp |                                                               |
| updated_at       | timestamp |                                                               |

**Relationships:**
- `belongsTo` Company
- `morphTo` approvable (Recommendation or ContentAsset)
- `belongsTo` User

**Laravel notes:**
- Model: `App\Models\Approval`
- Use `HasUlids`
- Cast `edits` to `array`
- Fire `RecommendationApproved`, `RecommendationRejected`, or `ContentAssetApproved` events based on `approvable_type` + `action`
- Index: `(approvable_type, approvable_id)` for polymorphic lookups

---

### Execution

**Definition:** The record of a Content Asset being published or scheduled for publishing on a Channel.

**Purpose:** Tracks the outcome of each publishing action. Carries the platform response, error state, and timing. Used for measurement and Learning.

**Table:** `executions`

| Column           | Type      | Notes                                                             |
|------------------|-----------|-------------------------------------------------------------------|
| id               | ulid      | PK                                                              |
| company_id       | ulid      | FK                                                              |
| campaign_id      | ulid      | FK                                                              |
| content_asset_id | ulid      | FK, unique (one execution per content asset)                    |
| channel_id       | ulid      | FK                                                              |
| status           | enum      | `queued`, `executing`, `completed`, `failed`, `cancelled`        |
| scheduled_at     | timestamp | when it was supposed to run                                     |
| executed_at      | timestamp | nullable; when it actually ran                                  |
| result           | json      | nullable; platform response (post ID, message ID, etc.)         |
| error            | text      | nullable; error message if failed                               |
| created_at       | timestamp |                                                                 |
| updated_at       | timestamp |                                                                 |

**Relationships:**
- `belongsTo` Company
- `belongsTo` Campaign
- `belongsTo` ContentAsset
- `belongsTo` Channel

**Lifecycle states:** `queued` → `executing` → `completed` / `failed` / `cancelled`

**Laravel notes:**
- Model: `App\Models\Execution`
- Use `HasUlids`
- Cast `result` to `array`
- Fire `ExecutionCompleted` or `ExecutionFailed` events → triggers Learning
- In MVP, publishing is out of scope; Executions are created but `status` stays `queued` until publishing is built
- Index: `(campaign_id, status)`, `(content_asset_id)`

---

### Learning

**Definition:** A recorded signal derived from a user action (Approval, Rejection, edit) or a campaign outcome (Execution result). Feeds back into the Digital Twin to improve future Decisions.

**Purpose:** Makes the Atlas loop self-improving. Every action the user takes and every result a campaign produces is captured here and eventually incorporated into the Business Brain. This is the accumulation mechanism that gives the Digital Twin its compounding value.

**Table:** `learnings`

| Column        | Type      | Notes                                                              |
|---------------|-----------|--------------------------------------------------------------------|
| id            | ulid      | PK                                                               |
| company_id    | ulid      | FK                                                               |
| source_type   | enum      | `approval`, `rejection`, `execution_result`, `edit`, `manual`    |
| source_id     | ulid      | ID of the source record (Approval, Execution, etc.)              |
| subject_type  | enum      | `campaign`, `content_asset`, `opportunity_type`, `channel`, `catalog_item` |
| subject_id    | ulid      | nullable; ID of what was learned about                           |
| signal        | string    | short label: e.g., `user_rejected_channel`, `email_drove_engagement` |
| value         | json      | structured learning payload; shape varies by signal type         |
| applied_at    | timestamp | nullable; when this Learning was incorporated into the Business Brain |
| created_at    | timestamp |                                                                  |
| updated_at    | timestamp |                                                                  |

**Relationships:**
- `belongsTo` Company

**Laravel notes:**
- Model: `App\Models\Learning`
- Use `HasUlids`
- Cast `value` to `array`
- Created by listeners on: `RecommendationRejected`, `ContentAssetApproved`, `ExecutionCompleted`, `ExecutionFailed`
- `applied_at` is set by a scheduled job (`ApplyLearnings`) that reads unapplied Learnings and updates Facts or Knowledge accordingly
- Index: `(company_id, applied_at)` — query path for the apply job

---

## Entity Relationship Summary

```
Company
  ├── DigitalTwin (1:1)
  ├── Catalog (1:1)
  │   └── CatalogItem (1:N)
  ├── Integration (1:N)
  │   └── Observation (1:N)
  │       └── Fact (1:N)
  ├── Knowledge (1:N)
  ├── Opportunity (1:N)
  │   └── Decision (1:1)
  │       ├── Recommendation (1:1)
  │       │   └── Approval (1:1, polymorphic)
  │       └── Campaign (1:1)
  │           ├── ContentAsset (1:N)
  │           │   ├── Approval (1:1, polymorphic)
  │           │   └── Execution (1:1)
  │           └── Channel (N:N via ContentAsset)
  └── Learning (1:N)
```

---

## Key Design Constraints

**Every entity carries `company_id`.** No exceptions. Apply a global scope to enforce it.

**Decisions require a rationale.** Enforced in the service layer. A `Decision` record without all four rationale keys (`why_now`, `why_this`, `why_channel`, `why_works`) must not be persisted.

**Approvals gate Executions.** No Execution proceeds to `executing` without an Approval record in state `approved` or `edited_and_approved`.

**Facts use `is_current`, not deletion.** When a Fact is superseded, set `is_current = false` and `superseded_by_id`. Never delete Facts.

**Business Brain is assembled, not stored.** The `BusinessBrain` is a readonly PHP value object assembled by `BusinessBrainService`. It is never persisted as a row. Prompts receive a serialized snapshot; they do not query the database directly.

**AI output always flows through a service.** No controller should call an AI provider directly. All AI interactions go through `App\Services\AI\*`. The platform never has a hard dependency on a specific LLM provider.

---

## Event Naming Conventions

Use past-tense domain events. Listeners do the work; events describe what happened.

| Event                        | Fired When                                          |
|------------------------------|-----------------------------------------------------|
| `ObservationRecorded`        | Observation created                                 |
| `ObservationProcessed`       | Observation status → `processed`                    |
| `FactExtracted`              | New Fact created from Observation                   |
| `KnowledgeSynthesized`       | New Knowledge entry created                         |
| `DigitalTwinActivated`       | DigitalTwin status → `active`                       |
| `OpportunityDetected`        | New Opportunity created                             |
| `DecisionCommitted`          | Decision created                                    |
| `RecommendationCreated`      | Recommendation created                              |
| `CampaignPrepared`           | Campaign + ContentAssets created in `draft` status  |
| `RecommendationApproved`     | Approval action → `approved`                        |
| `RecommendationRejected`     | Approval action → `rejected`                        |
| `CampaignApproved`           | Campaign status → `approved`                        |
| `ExecutionCompleted`         | Execution status → `completed`                      |
| `ExecutionFailed`            | Execution status → `failed`                         |
| `LearningRecorded`           | Learning created                                    |
| `LearningApplied`            | Learning `applied_at` set                           |
