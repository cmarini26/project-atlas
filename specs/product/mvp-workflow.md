# MVP Workflow

This document defines the complete first-run workflow for Atlas, from user signup through the first approved campaign. It is the implementation checklist for the initial Laravel build.

**North star metric:** Time from website URL entry to first approved Recommendation < 10 minutes.

**Design partners:** CBB Auctions (comic book auction house) and an exotic used car dealership. Both are used as examples throughout. The workflow itself is generic — it operates on Companies, Catalogs, and Catalog Items regardless of vertical.

---

## Workflow Overview

```
Step 1   User signs up
Step 2   User creates company
Step 3   User enters website URL → Integration created
Step 4   Atlas syncs the integration → Observation created
Step 5   Observation processed → Facts extracted
Step 6   Facts analyzed → Knowledge synthesized
Step 7   Digital Twin activated
Step 8   Opportunities detected
Step 9   Decision committed
Step 10  Campaign prepared
Step 11  Content generated
Step 12  Recommendation surfaced to user
Step 13  User approves
```

---

## Step 1 — User Signs Up

**Actor:** User (new, unauthenticated)

**User action:** Visits registration page. Submits name, email, and password.

**Services / classes involved:**
- `App\Http\Controllers\Auth\RegisterController`
- `App\Http\Requests\Auth\RegisterRequest`

**Records created:**

| Table | Key fields set |
|-------|----------------|
| `users` | `id`, `name`, `email`, `password` (hashed), `created_at` |

**No jobs or events fired at this step.** The user is logged in immediately. No email verification is required in MVP.

**Failure cases:**

| Condition | Handling |
|-----------|----------|
| Email already registered | 422 with validation error |
| Password too short (< 8 chars) | 422 with validation error |
| Database unavailable | 500; log the exception |

**Acceptance criteria:**
- [ ] `users` row created with bcrypt-hashed password
- [ ] User is authenticated (session started) immediately after registration
- [ ] User is redirected to company creation (Step 2)
- [ ] No plaintext password stored anywhere

---

## Step 2 — User Creates Company

**Actor:** Authenticated user (no company yet)

**User action:** Enters company name. Industry and brand voice are optional at this step. Submits.

**Services / classes involved:**
- `App\Http\Controllers\CompanyController::store()`
- `App\Http\Requests\CreateCompanyRequest`
- `App\Services\Company\CompanyService::create()`
- Listener: `App\Listeners\ProvisionCompanyDefaults` on `CompanyCreated`

**Sequence:**
1. `CompanyService::create($user, $data)` opens a DB transaction
2. Creates `Company`
3. Creates `Catalog` (name: "Main Catalog", type: "mixed")
4. Creates `DigitalTwin` (status: `initializing`, health_score: 0)
5. Creates `CompanyMembership` (role: `owner`, joined_at: now())
6. Commits transaction
7. Fires `CompanyCreated` event

**Records created:**

| Table | Key fields set |
|-------|----------------|
| `companies` | `id`, `name`, `slug` (from name), `brand: {}`, `settings: {}` |
| `catalogs` | `id`, `company_id`, `name: "Main Catalog"`, `type: "mixed"` |
| `digital_twins` | `id`, `company_id`, `status: "initializing"`, `health_score: 0` |
| `company_memberships` | `id`, `company_id`, `user_id`, `role: "owner"`, `joined_at: now()` |

**Failure cases:**

| Condition | Handling |
|-----------|----------|
| Any provisioning step fails | Entire transaction rolls back; user sees error |
| Duplicate company name | Slug gets numeric suffix (`acme-2`) to ensure uniqueness |

**Acceptance criteria:**
- [ ] All four records created within a single DB transaction
- [ ] `digital_twins.status = "initializing"`
- [ ] `company_memberships.role = "owner"` for the creating user
- [ ] User is redirected to website URL entry (Step 3)

---

## Step 3 — User Enters Website URL → Integration Created

**Actor:** Authenticated user (owner of new company)

**User action:** Enters the business's website URL. Submits.

*CBB Auctions example:* `https://cbbauctions.com`
*Car dealer example:* `https://exoticmotors.com`

**Services / classes involved:**
- `App\Http\Controllers\IntegrationController::store()`
- `App\Http\Requests\CreateIntegrationRequest`
- `App\Services\Observatory\IntegrationService::create()`
- `App\Jobs\SyncIntegration` (dispatched immediately)

**Sequence:**
1. Validate URL format (must be a reachable-looking HTTPS URL)
2. `IntegrationService::create($company, 'website_crawl', ['url' => $url])`
3. Persists Integration with `status: active`
4. Sets `next_run_at = now() + 7 days` for recurring syncs
5. Immediately dispatches `SyncIntegration` job to `observations` queue (does not wait for the scheduler)
6. User sees a "Connecting…" state in the UI — the workflow continues asynchronously from here

**Records created:**

| Table | Key fields set |
|-------|----------------|
| `integrations` | `id`, `company_id`, `type: "website_crawl"`, `name: "Website"`, `config: {url}` (encrypted), `status: "active"`, `next_run_at` |

**Failure cases:**

| Condition | Handling |
|-----------|----------|
| Invalid URL format | 422 validation error; user corrects |
| URL not reachable | Integration still created; crawl failure is handled in Step 4 |
| Duplicate URL for same company | Warn user and allow; do not block |

**Acceptance criteria:**
- [ ] `integrations` row created with `status: "active"`
- [ ] `config` field is encrypted at rest
- [ ] `SyncIntegration` job dispatched to `observations` queue immediately
- [ ] UI shows async "Connecting…" state; user is not blocked

---

## Step 4 — Atlas Syncs the Integration → Observation Created

**Actor:** System (`SyncIntegration` job on `observations` queue)

**Services / classes involved:**
- `App\Jobs\SyncIntegration`
- `App\Services\Observatory\Connectors\ConnectorRegistry`
- `App\Services\Observatory\Connectors\WebsiteCrawlConnector`
- Object storage (S3 or compatible)

**Sequence:**
1. `SyncIntegration` job loads the Integration
2. `ConnectorRegistry::resolve($integration)` → returns `WebsiteCrawlConnector`
3. `WebsiteCrawlConnector::sync($integration)`:
   a. Decrypts `config.url`
   b. HTTP GET with 30-second timeout, follows redirects
   c. Stores raw HTML to object storage → `raw_payload_ref` (e.g., `observations/{company_id}/{ulid}.html`)
   d. Creates `Observation` with HTML in `raw_payload` and storage key in `raw_payload_ref`
   e. Updates `Integration.last_run_at = now()`
4. Fires `ObservationRecorded` event
5. Listener: `App\Listeners\DispatchObservationProcessing` → dispatches `ProcessObservation` job to `ai` queue

**Records created / updated:**

| Table | Key fields set |
|-------|----------------|
| `observations` | `id`, `company_id`, `integration_id`, `source_type: "crawl"`, `source_identifier: url`, `raw_payload: html`, `raw_payload_ref: s3_key`, `status: "pending"`, `observed_at: now()` |
| `integrations` | `last_run_at = now()` |
| Object storage | Raw HTML at `raw_payload_ref` path |

**Failure cases:**

| Condition | Handling |
|-----------|----------|
| HTTP timeout (> 30s) | Job retried up to 3× with exponential backoff (60s, 180s, 540s). After 3 failures: Integration `status = "error"`, `last_error` set. User notified. |
| HTTP 4xx / 5xx | Same as timeout |
| Website returns empty body | Observation created with empty `raw_payload`. FactExtraction produces zero facts. DigitalTwin stays `initializing`. Next sync attempts to recover. |
| Object storage failure | Do not create Observation. Mark Integration `status = "error"`. Retry job. |

**Acceptance criteria:**
- [ ] `observations` row created with `status: "pending"`
- [ ] `raw_payload` contains crawled HTML (or structured content)
- [ ] `raw_payload_ref` contains a valid object storage key
- [ ] `ObservationRecorded` event fired
- [ ] `ProcessObservation` job dispatched to `ai` queue
- [ ] `Integration.last_run_at` updated

---

## Step 5 — Observation Processed → Facts Extracted

**Actor:** System (`ProcessObservation` job on `ai` queue)

**Services / classes involved:**
- `App\Jobs\ProcessObservation`
- `App\Services\Brain\BusinessBrainService`
- `App\Services\Analyst\FactExtractionAnalyst`
- `App\AI\Prompts\FactExtractionPrompt`
- `App\AI\Schemas\FactExtractionSchema`
- `App\AI\Contracts\AiProvider` (via bound provider)

**Sequence:**
1. Load Observation; set `status = "processing"`
2. Assemble `BusinessBrain` via `BusinessBrainService::for($company)`
3. Call `FactExtractionAnalyst::analyze($observation, $brain)`
4. Analyst builds `FactExtractionPrompt` with observation content (truncated to 8,000 chars) + brain context
5. AI call → structured JSON: `{facts: [{key, value, data_type, confidence}]}`
6. `StructuredResponseParser::parse()` validates against `FactExtractionSchema`
7. For each extracted fact:
   - If a current Fact with the same `key` exists and value is identical: skip
   - If value differs: set existing `is_current = false`, `superseded_by_id = new_fact.id`
   - Persist new Fact with `is_current = true`, `prompt_name`, `prompt_version`
   - Fire `FactExtracted` event
8. Set Observation `status = "processed"`, `processed_at = now()`
9. Dispatch `SynthesizeKnowledge` job to `ai` queue

**Example facts — CBB Auctions:**
```
catalog.active_auction_count      = 47          (integer, confidence: 95)
catalog.ending_within_48h_count   = 12          (integer, confidence: 90)
catalog.highest_value_item_price  = 4800        (integer, confidence: 85)
brand.tone                        = "energetic" (string, confidence: 70)
catalog.featured_categories       = ["golden-age", "silver-age"] (json, confidence: 80)
```

**Example facts — exotic car dealer:**
```
catalog.active_inventory_count    = 23          (integer, confidence: 95)
catalog.avg_price                 = 187000      (integer, confidence: 85)
catalog.most_expensive_item_price = 485000      (integer, confidence: 90)
catalog.newest_arrival_title      = "Ferrari 275 GTB" (string, confidence: 88)
marketing.days_since_last_campaign = 14         (integer, confidence: 70)
```

**Records created / updated:**

| Table | Key fields set |
|-------|----------------|
| `facts` | `id`, `company_id`, `observation_id`, `key`, `value`, `data_type`, `confidence`, `is_current: true`, `valid_from: now()`, `prompt_name`, `prompt_version` |
| `facts` (existing, if superseded) | `is_current = false`, `superseded_by_id` |
| `observations` | `status = "processed"`, `processed_at = now()` |

**Failure cases:**

| Condition | Handling |
|-----------|----------|
| AI returns invalid JSON | `MalformedAiResponseException` → job retried up to 3× |
| AI response fails schema validation | `AiResponseValidationException` → job fails, logged with prompt version; do not retry (prompt issue) |
| AI provider unavailable | Job retried with backoff; after 3 failures, sent to failed queue |
| Zero facts extracted | Valid outcome for sparse pages; Observation marked `processed`; DigitalTwin stays `initializing` |

**Acceptance criteria:**
- [ ] Observation `status` transitions: `pending` → `processing` → `processed`
- [ ] At least one `facts` row created for a non-trivial website
- [ ] Each Fact has `key` (dot-namespaced), typed `value`, `data_type`, `confidence > 0`
- [ ] Superseded facts have `is_current = false`, `superseded_by_id` set
- [ ] `prompt_name` and `prompt_version` stored on each Fact
- [ ] `FactExtracted` event fired for each new Fact
- [ ] `SynthesizeKnowledge` job dispatched

---

## Step 6 — Facts Analyzed → Knowledge Synthesized

**Actor:** System (`SynthesizeKnowledge` job on `ai` queue)

**Services / classes involved:**
- `App\Jobs\SynthesizeKnowledge`
- `App\Services\Brain\BusinessBrainService`
- `App\Services\Analyst\KnowledgeSynthesisAnalyst`
- `App\AI\Prompts\KnowledgeSynthesisPrompt`
- `App\AI\Schemas\KnowledgeSynthesisSchema`

**Sequence:**
1. Assemble `BusinessBrain` (all current Facts for this company)
2. Call `KnowledgeSynthesisAnalyst::analyze($company, $brain)`
3. Analyst passes all current Facts as context; AI derives patterns and insights
4. `StructuredResponseParser::parse()` validates response
5. For each Knowledge entry:
   - Archive existing active entries with same `(type, subject)`: `is_active = false`
   - Persist new entry with `is_active = true`, `source_fact_ids`, `prompt_name`, `prompt_version`
   - Fire `KnowledgeSynthesized` event
6. Dispatch `ActivateDigitalTwin` job

**Example knowledge — CBB Auctions:**
```
type: urgency | subject: catalog.auctions
body: "12 auctions end within 48 hours — this is a high-urgency promotion window."

type: insight | subject: catalog.value
body: "Active listings include multiple high-value golden-age keys above $2,000."

type: context | subject: marketing.cadence
body: "No recent campaign activity detected — audience has not been engaged recently."
```

**Example knowledge — exotic car dealer:**
```
type: insight | subject: catalog.featured
body: "A 1967 Ferrari 275 GTB recently joined inventory at $485,000 — a marquee piece ideal for a featured campaign."

type: pattern | subject: marketing.gap
body: "No campaign detected in the last 14 days — engagement window is open."

type: performance | subject: catalog.inventory
body: "23 active vehicles with average price $187K indicates a premium inventory mix."
```

**Records created / updated:**

| Table | Key fields set |
|-------|----------------|
| `knowledge_entries` | `id`, `company_id`, `type`, `subject`, `body`, `structured`, `source_fact_ids`, `confidence`, `is_active: true`, `generated_at`, `prompt_name`, `prompt_version` |
| `knowledge_entries` (existing, archived) | `is_active = false` |

**Failure cases:**

| Condition | Handling |
|-----------|----------|
| AI response fails schema validation | Job fails; logged; do not retry (prompt issue) |
| Zero knowledge entries returned | Valid for very sparse data; `ActivateDigitalTwin` will check readiness |

**Acceptance criteria:**
- [ ] At least one `knowledge_entries` row created with `is_active = true`
- [ ] Each entry has `type`, `subject`, `body` populated
- [ ] `source_fact_ids` references valid Fact IDs
- [ ] Previous entries for same `(type, subject)` archived
- [ ] `KnowledgeSynthesized` event fired
- [ ] `ActivateDigitalTwin` job dispatched

---

## Step 7 — Digital Twin Activated

**Actor:** System (`ActivateDigitalTwin` job on `default` queue)

**Services / classes involved:**
- `App\Jobs\ActivateDigitalTwin`
- `App\Services\Brain\DigitalTwinService`

**Sequence:**
1. Load company's DigitalTwin
2. Check readiness:
   - At least 3 current Facts with `confidence >= 70`
   - At least 1 active Knowledge entry
   - DigitalTwin is still `initializing`
3. If ready:
   - Compute `health_score` (0–100) based on fact count, knowledge count, and data freshness
   - Update `status = "active"`, `health_score`, `last_observed_at = now()`, `last_enriched_at = now()`
   - Fire `DigitalTwinActivated` event
   - Listener: `App\Listeners\TriggerOpportunityDetection` → dispatches `DetectOpportunities` job
4. If not ready:
   - Log the readiness check failure with counts
   - Do nothing; DigitalTwin stays `initializing`
   - Next sync cycle will trigger this check again

**Records updated:**

| Table | Key fields set |
|-------|----------------|
| `digital_twins` | `status = "active"`, `health_score`, `last_observed_at`, `last_enriched_at` |

**Failure cases:**

| Condition | Handling |
|-----------|----------|
| Readiness thresholds not met | Twin stays `initializing`; log counts for debugging; next sync retries |
| Twin already `active` | No-op (idempotent check) |

**Acceptance criteria:**
- [ ] `digital_twins.status = "active"` after minimum readiness thresholds are met
- [ ] `health_score > 0`
- [ ] `DigitalTwinActivated` event fired exactly once per company (idempotent)
- [ ] `DetectOpportunities` job dispatched

---

## Step 8 — Opportunities Detected

**Actor:** System (`DetectOpportunities` job on `default` queue)

**Services / classes involved:**
- `App\Jobs\DetectOpportunities`
- `App\Services\Opportunity\OpportunityEngine`
- `App\Services\Opportunity\Detectors\*` (rule-based detectors)
- `App\Services\Analyst\OpportunityDetectionAnalyst` (AI-based detection)
- `App\Services\Opportunity\OpportunityScorer`

**Sequence:**
1. Assemble `BusinessBrain`
2. `OpportunityEngine::scan($company, $brain)`:
   a. Run each registered `OpportunityDetector` → collect rule-based candidates
   b. Call `OpportunityDetectionAnalyst::analyze($company, $brain)` → collect AI-detected candidates
   c. Merge and deduplicate (skip if same `type + subject_type + subject_id` already has an `open` Opportunity)
   d. For each surviving candidate: `OpportunityScorer::score($candidate, $brain)` → compute four scores + composite
   e. Persist candidates with `composite_score > 0` as `Opportunity` records with `status: "open"`
   f. Fire `OpportunityDetected` for each persisted Opportunity
3. Listener: `App\Listeners\TriggerDecisionEvaluation` → dispatches `CommitDecision` job

**Registered detectors (MVP):**

| Detector | Triggers when | Example |
|----------|---------------|---------|
| `FeaturedItemDetector` | Active CatalogItem with no campaign in N days | Ferrari in inventory 45 days, never promoted |
| `UrgencyDetector` | CatalogItem with `expires_at` within 48 hours | CBB auction closing tomorrow |
| `NewArrivalDetector` | CatalogItem created within last 48 hours | New Lamborghini just added to inventory |
| `ReEngagementDetector` | No campaigns in last 14 days | Dealer hasn't posted in 2 weeks |

**Records created:**

| Table | Key fields set |
|-------|----------------|
| `opportunities` | `id`, `company_id`, `subject_type`, `subject_id`, `type`, `title`, `description`, `relevance_score`, `timing_score`, `confidence_score`, `urgency_score`, `composite_score`, `status: "open"`, `detected_at: now()` |

**Scoring example — CBB Auctions "urgency" opportunity:**
```
relevance:  85  (auctions are the core business)
timing:     95  (auctions close in < 48h)
confidence: 80  (multiple high-value items)
urgency:    98  (hard deadline)
composite:  89  (weighted sum)
```

**Scoring example — car dealer "featured_item" opportunity:**
```
relevance:  90  (marquee vehicle matches brand)
timing:     75  (no campaigns recently)
confidence: 70  (no prior campaign history for comparison)
urgency:    40  (no hard deadline)
composite:  70
```

**Failure cases:**

| Condition | Handling |
|-----------|----------|
| Zero Opportunities detected | Log; schedule retry in 24 hours; user sees "Analyzing your business…" state |
| All scores below threshold (< 30) | No Opportunities persisted; retry next sync cycle |
| `OpportunityDetectionAnalyst` fails | Log; rule-based candidates still proceed |

**Acceptance criteria:**
- [ ] At least one `opportunities` row with `status: "open"` and `composite_score > 0`
- [ ] No duplicate Opportunities for same `(type, subject_type, subject_id)` in `open` status
- [ ] `OpportunityDetected` event fired for each
- [ ] `CommitDecision` job dispatched

---

## Step 9 — Decision Committed

**Actor:** System (`CommitDecision` job on `ai` queue)

This job is `ShouldBeUnique` per company to prevent concurrent decisions.

**Services / classes involved:**
- `App\Jobs\CommitDecision`
- `App\Services\Decision\DecisionEngine`
- `App\Services\Analyst\RationaleGenerationAnalyst`
- `App\AI\Prompts\RationaleGenerationPrompt`
- `App\AI\Schemas\RationaleSchema`

**Sequence:**
1. `DecisionEngine::evaluate($company)`:
   a. Query open Opportunities ordered by `composite_score DESC`
   b. For each candidate, apply guards:
      - No existing `open` or `pending` Recommendation of same `campaign_type`
      - No Campaign of same `campaign_type` completed within the cooldown window (default: 7 days)
      - At least one active CatalogItem exists if the opportunity requires one
   c. Select the first candidate that passes all guards
2. Determine `campaign_type` and `channel_ids` based on opportunity type and company's active channels
3. Build a partial Decision (not yet persisted)
4. Call `RationaleGenerationAnalyst::analyze($opportunity, $partialDecision, $brain)`
5. AI call → `{why_now, why_this, why_channel, why_works, expected_impact}` — all five keys required
6. Validate all keys present and non-empty; throw `RationaleGenerationFailedException` if not
7. Persist `Decision` with `status: "pending"`
8. Update `Opportunity.status = "selected"`
9. Fire `DecisionCommitted` event
10. Listener: `App\Listeners\DispatchCampaignPreparation` → dispatches `PrepareCampaign` job

**Example rationale — CBB Auctions urgency campaign:**
```
why_now:    "12 of your active auctions close within 48 hours, including 3 golden-age keys
             above $2,000. This is a high-urgency promotion window that expires tomorrow."

why_this:   "Your ending-soon listings are your strongest conversion trigger.
             Buyers who see auction countdowns act faster."

why_channel: "Instagram and email are your highest-reach channels based on your profile.
              A visual countdown post and a 'last chance' email work best for auction urgency."

why_works:  "Urgency-driven posts typically see 2–3× higher engagement than standard
             inventory posts. This window won't be available again until your next auction cycle."

expected_impact:
  summary:           "Strong engagement expected given active auction inventory."
  reach_estimate:    "~800–1,200 accounts reached per social post"
  engagement_signal: "Urgency framing typically doubles click-through on endings"
```

**Records created / updated:**

| Table | Key fields set |
|-------|----------------|
| `decisions` | `id`, `company_id`, `opportunity_id`, `campaign_type`, `channel_ids`, `rationale`, `confidence_score`, `expected_outcome`, `expected_impact`, `status: "pending"`, `decided_at`, `prompt_name`, `prompt_version` |
| `opportunities` | `status = "selected"` |

**Failure cases:**

| Condition | Handling |
|-----------|----------|
| No Opportunity passes all guards | No Decision committed; job exits cleanly; retry scheduled in 24 hours |
| Rationale missing required keys | `RationaleGenerationFailedException`; Decision not persisted; job fails; alert logged |
| AI unavailable | Job retried with backoff up to 3× |

**Acceptance criteria:**
- [ ] `decisions` row created with `status: "pending"`
- [ ] All five rationale fields (`why_now`, `why_this`, `why_channel`, `why_works`, `expected_impact`) populated and non-empty
- [ ] `expected_impact` is valid JSON with at least a `summary` key
- [ ] `Opportunity.status = "selected"`
- [ ] `DecisionCommitted` event fired
- [ ] `PrepareCampaign` job dispatched

---

## Step 10 — Campaign Prepared

**Actor:** System (`PrepareCampaign` job on `ai` queue)

**Services / classes involved:**
- `App\Jobs\PrepareCampaign`
- `App\Services\Campaign\CampaignPreparationService`
- `App\Services\Analyst\CampaignPreparationAnalyst`
- `App\AI\Prompts\CampaignPreparationPrompt`
- `App\AI\Schemas\CampaignPreparationSchema`

**Sequence:**
1. Load Decision and assemble `BusinessBrain`
2. `CampaignPreparationService::prepare($decision, $brain)`:
   a. Call `CampaignPreparationAnalyst::analyze($decision, $brain)`
   b. AI call → `{title, strategy, target_audience, positioning, call_to_action, schedule}`
   c. Resolve `schedule.suggested_start` into an actual `scheduled_start_at` timestamp
      (e.g., "immediately" → `now()`, "Monday" → next Monday at 09:00 company local time)
   d. Persist `Campaign` with `status: "draft"`
3. Dispatch one `GenerateContent` job per Channel in `decision.channel_ids`, to `ai` queue

**Example campaign — CBB Auctions urgency:**
```
title:           "⏳ Last Chance — Auctions Close in 48 Hours"
strategy:        "Lead with urgency. Highlight the 3 highest-value endings.
                  Drive to the auction page with a countdown framing."
target_audience: "Comic book collectors and investors watching high-grade keys"
positioning:     "Now-or-never. These prices won't be available after the auction closes."
call_to_action:  "Bid Before Time Runs Out"
schedule:
  suggested_start: "immediately"
  duration_days:   2
  cadence:         "2 posts today, 1 email today, 1 post tomorrow morning"
```

**Example campaign — exotic car dealer featured item:**
```
title:           "This Week's Featured Vehicle — 1967 Ferrari 275 GTB"
strategy:        "Lead with the car's story and rarity. Position as investment-grade,
                  not just transportation."
target_audience: "High-net-worth collectors and enthusiasts in the $400K+ bracket"
positioning:     "Rare. Original. Ready to drive home."
call_to_action:  "Schedule a Private Viewing"
schedule:
  suggested_start: "Monday"
  duration_days:   7
  cadence:         "Daily social posts, one email mid-week"
```

**Records created:**

| Table | Key fields set |
|-------|----------------|
| `campaigns` | `id`, `company_id`, `decision_id`, `title`, `strategy`, `target_audience`, `positioning`, `call_to_action`, `status: "draft"`, `scheduled_start_at` |

**Failure cases:**

| Condition | Handling |
|-----------|----------|
| AI response fails schema validation | Job fails; logged with prompt version; manual intervention needed |
| Channel list is empty | Job fails; logged; Decision marked `cancelled` |

**Acceptance criteria:**
- [ ] `campaigns` row created with `status: "draft"`
- [ ] All strategy fields (`title`, `strategy`, `target_audience`, `positioning`, `call_to_action`) populated
- [ ] `scheduled_start_at` is a resolved timestamp (not a relative string)
- [ ] One `GenerateContent` job dispatched per Channel in `decision.channel_ids`

---

## Step 11 — Content Generated

**Actor:** System (one `GenerateContent` job per Channel, on `ai` queue)

**Services / classes involved:**
- `App\Jobs\GenerateContent`
- `App\Services\Analyst\ContentGenerationAnalyst`
- `App\AI\Prompts\SocialContentPrompt`, `EmailContentPrompt`, `SmsContentPrompt`
- `App\AI\Schemas\SocialContentSchema`, `EmailContentSchema`, `SmsContentSchema`

**Sequence (per Channel):**
1. Load Campaign, Channel, and `BusinessBrain`
2. `ContentGenerationAnalyst::analyze($campaign, $channel, $brain)` dispatches to correct prompt + schema via `match($channel->type)`
3. AI call → channel-specific content
4. `StructuredResponseParser::parse()` validates
5. Persist `ContentAsset` with `status: "draft"`, `prompt_name`, `prompt_version`
6. Fire `ContentAssetGenerated` event

After all ContentAssets for a Campaign are created, `CreateRecommendation` is called. For MVP, this is handled by the last `GenerateContent` job checking if all expected assets exist and dispatching `CreateRecommendation` if so.

**Example content — CBB Auctions Instagram:**
```
body:     "⏰ Final countdown — these auctions close in less than 48 hours.
           🔑 Key issues. Investment-grade condition. Don't let them slip.
           Tap to bid before time runs out. ⬆️ Link in bio.
           #ComicBooks #GoldenAge #ComicAuction #KeyIssues #CBBAuctions"

hashtags: ["ComicBooks", "GoldenAge", "ComicAuction", "KeyIssues", "CBBAuctions"]
alt_text: "Collage of high-grade golden-age comic book covers from the current auction"
```

**Example content — car dealer email:**
```
subject_line: "She Deserves Your Full Attention — 1967 Ferrari 275 GTB"
preview_text: "One owner. Original matching numbers. Now available at Exotic Motors."
body_html:    "<p>Some cars stop you mid-scroll...</p>" (full HTML body)
body_plain:   "Some cars stop you mid-scroll..."
```

**Records created:**

| Table | Key fields set |
|-------|----------------|
| `content_assets` | `id`, `company_id`, `campaign_id`, `channel_id`, `type`, `body`, `metadata` (channel-specific), `status: "draft"`, `prompt_name`, `prompt_version` |

**Failure cases:**

| Condition | Handling |
|-----------|----------|
| AI response fails schema validation | Job fails; ContentAsset not created for this channel; logged |
| One channel fails, others succeed | Partial content is acceptable; Recommendation still created with available assets |
| All channels fail | `Campaign.status = "cancelled"`; Decision marked `cancelled`; logged |

**Acceptance criteria:**
- [ ] One `content_assets` row per Channel, with `status: "draft"`
- [ ] `body` is non-empty for all generated assets
- [ ] Email assets have `metadata.subject_line` and `metadata.preview_text`
- [ ] Social assets have `metadata.hashtags`
- [ ] `prompt_name` and `prompt_version` stored on each asset
- [ ] `ContentAssetGenerated` event fired for each asset

---

## Step 12 — Recommendation Surfaced to User

**Actor:** System (`CreateRecommendation` job on `default` queue, dispatched after all content is generated)

**Services / classes involved:**
- `App\Jobs\CreateRecommendation`
- `App\Services\Recommendation\RecommendationService`
- Notification: `App\Notifications\NewRecommendationAvailable`

**Sequence:**
1. `RecommendationService::create($decision, $campaign)`:
   a. Build `rationale_display` from `decision.rationale` (formatted for UI rendering)
   b. Persist `Recommendation` with `status: "pending"`
   c. Update `Decision.status = "recommended"`
   d. Fire `RecommendationCreated` event
2. Listener: send `NewRecommendationAvailable` notification to all company members with `owner` or `admin` role (in-app notification; email notification is a future feature)

**What the user sees in the UI:**

The Recommendation card shows:
- **Title** — campaign title
- **Why Atlas is recommending this** — the four rationale fields in plain language
- **Confidence score** — displayed as a percentage with a brief explanation
- **Expected impact** — reach estimate and engagement signal
- **Content preview** — each ContentAsset, grouped by Channel, editable inline
- **Approve / Edit + Approve / Reject** — three action buttons

**Records created / updated:**

| Table | Key fields set |
|-------|----------------|
| `recommendations` | `id`, `company_id`, `decision_id`, `title`, `summary`, `rationale_display`, `confidence_score`, `expected_impact`, `status: "pending"` |
| `decisions` | `status = "recommended"` |

**Failure cases:**

| Condition | Handling |
|-----------|----------|
| No ContentAssets were generated | Do not create Recommendation; Decision marked `cancelled` |

**Acceptance criteria:**
- [ ] `recommendations` row created with `status: "pending"`
- [ ] `rationale_display` contains all four why-* fields in UI-renderable format
- [ ] `Decision.status = "recommended"`
- [ ] In-app notification delivered to all `owner` and `admin` members
- [ ] `RecommendationCreated` event fired
- [ ] UI shows Recommendation card with all content previews visible

---

## Step 13 — User Approves

**Actor:** User (owner or admin)

**User action:** Reviews the Recommendation. Clicks **Approve**, **Edit + Approve**, or **Reject**.

### 13a — Approve (as-is)

**Services / classes involved:**
- `App\Http\Controllers\ApprovalController::approve()`
- `App\Services\Approval\ApprovalService::approve()`

**Sequence:**
1. `ApprovalService::approve($recommendation, $user, $notes = null)`:
   a. Create `Approval` record (`action: "approved"`)
   b. Update `Recommendation.status = "approved"`, `responded_at = now()`
   c. Update `Decision.status = "approved"`
   d. Update `Campaign.status = "approved"`
   e. Update all `ContentAsset.status = "approved"`
   f. Create `Execution` record per ContentAsset (`status: "queued"`, `scheduled_at`)
   g. Create `Learning` record (`source_type: "approval"`, `signal: "recommendation_approved"`)
   h. Fire `RecommendationApproved` event

**Records created / updated:**

| Table | Key fields set |
|-------|----------------|
| `approvals` | `id`, `company_id`, `approvable_type: "Recommendation"`, `approvable_id`, `user_id`, `action: "approved"`, `acted_at` |
| `recommendations` | `status = "approved"`, `responded_at` |
| `decisions` | `status = "approved"` |
| `campaigns` | `status = "approved"` |
| `content_assets` | `status = "approved"` (all in campaign) |
| `executions` | One per ContentAsset: `status: "queued"`, `scheduled_at` |
| `learnings` | `source_type: "approval"`, `signal: "recommendation_approved"`, `value: {campaign_type, channel_ids, confidence_score}` |

### 13b — Edit + Approve

Same as 13a, plus:
- User edits one or more ContentAsset bodies inline before approving
- `Approval.action = "edited_and_approved"`
- `Approval.edits` = JSON diff of what changed (original vs. edited body per asset)
- Updated ContentAsset body is persisted before Execution records are created

### 13c — Reject

**Sequence:**
1. `ApprovalService::reject($recommendation, $user, $notes = null)`:
   a. Create `Approval` record (`action: "rejected"`)
   b. Update `Recommendation.status = "rejected"`, `responded_at = now()`
   c. Update `Decision.status = "rejected"`
   d. Update `Campaign.status = "cancelled"`
   e. Create `Learning` record (`signal: "recommendation_rejected"`, `value: {notes, campaign_type, channel_ids}`)
   f. Fire `RecommendationRejected` event
   g. Opportunity Engine re-evaluates on next cycle (the rejected Opportunity may re-emerge with a new angle, or a different Opportunity is selected)

**Failure cases:**

| Condition | Handling |
|-----------|----------|
| Non-owner/admin tries to approve | 403; authorization enforced via `CompanyMembershipPolicy` |
| Recommendation already responded to | 409; idempotent check before creating Approval |

**Acceptance criteria (approve path):**
- [ ] `approvals` row created with `action: "approved"`
- [ ] All related records updated: Recommendation, Decision, Campaign, ContentAssets → `approved`
- [ ] One `executions` row per ContentAsset with `status: "queued"`
- [ ] `learnings` row created
- [ ] `RecommendationApproved` event fired
- [ ] UI shows "Campaign approved" confirmation state

**Acceptance criteria (reject path):**
- [ ] `approvals` row created with `action: "rejected"`
- [ ] Recommendation, Decision → `rejected`; Campaign → `cancelled`
- [ ] `learnings` row created with rejection signal
- [ ] `RecommendationRejected` event fired

---

## End-to-End Acceptance Criteria

These criteria define "the MVP loop works." Run these as a manual smoke test and eventually as a feature test.

### Time target

From website URL entry (Step 3) to Recommendation visible in UI (Step 12): **< 10 minutes** in a healthy environment with a fast website and responsive AI provider.

### Full checklist

**Data integrity:**
- [ ] No `company_id` mismatch across any created records
- [ ] `digital_twins.status` transitions exactly: `initializing` → `active`
- [ ] Every Fact has `is_current = true` or a `superseded_by_id` reference
- [ ] Every `decisions` row has all four rationale keys and non-null `expected_impact`
- [ ] Every `content_assets` row has `prompt_name` and `prompt_version`

**Event chain:**
- [ ] `ObservationRecorded` → `ProcessObservation` dispatched
- [ ] `FactExtracted` → `SynthesizeKnowledge` dispatched (via `ProcessObservation`)
- [ ] `KnowledgeSynthesized` → `ActivateDigitalTwin` dispatched
- [ ] `DigitalTwinActivated` → `DetectOpportunities` dispatched
- [ ] `OpportunityDetected` → `CommitDecision` dispatched
- [ ] `DecisionCommitted` → `PrepareCampaign` dispatched
- [ ] `CampaignPrepared` → `GenerateContent` dispatched (one per channel)
- [ ] All content generated → `CreateRecommendation` dispatched
- [ ] `RecommendationCreated` → notification delivered
- [ ] `RecommendationApproved` → `Execution` records created, `Learning` recorded

**Failure resilience:**
- [ ] A failed crawl (HTTP timeout) retries and eventually marks Integration as `error`
- [ ] A failed AI call retries with backoff and does not corrupt any records
- [ ] A malformed AI response is logged and does not crash downstream jobs

**Security:**
- [ ] `integration.config` is encrypted at rest
- [ ] No user can view or approve another company's Recommendation
- [ ] `CompanyMembershipPolicy` enforced on all approval actions

---

## Implementation Checklist

Build in this order. Each row depends on the rows above it.

### Foundation
- [ ] `users` table + Laravel auth scaffolding (Breeze or Fortify)
- [ ] `companies`, `catalogs`, `digital_twins`, `company_memberships` tables + migrations
- [ ] `CompanyService::create()` with DB transaction + auto-provisioning
- [ ] Company creation UI + redirect flow

### Observatory
- [ ] `integrations` table + migration
- [ ] `IntegrationService::create()` + `IntegrationController`
- [ ] URL entry UI step
- [ ] `observations` table + migration (with `raw_payload_ref`)
- [ ] `SyncIntegration` job + `ConnectorRegistry`
- [ ] `WebsiteCrawlConnector` (HTTP fetch + object storage upload)
- [ ] `ObservationRecorded` event + `DispatchObservationProcessing` listener

### AI Layer
- [ ] `AiProvider` interface
- [ ] `AnthropicProvider` implementation (tool-use for structured output)
- [ ] `Prompt` base class
- [ ] `StructuredResponseParser` with typed exceptions
- [ ] `FakeAiProvider` for tests
- [ ] `BusinessBrainService::for(Company)` → `BusinessBrain` value object

### Analyst Pipeline
- [ ] `facts` table + migration (with `prompt_name`, `prompt_version`)
- [ ] `FactExtractionPrompt` + `FactExtractionSchema`
- [ ] `FactExtractionAnalyst` + `ProcessObservation` job
- [ ] `FactExtracted` event

- [ ] `knowledge_entries` table + migration
- [ ] `KnowledgeSynthesisPrompt` + `KnowledgeSynthesisSchema`
- [ ] `KnowledgeSynthesisAnalyst` + `SynthesizeKnowledge` job
- [ ] `KnowledgeSynthesized` event

### Intelligence
- [ ] `ActivateDigitalTwin` job + readiness check logic
- [ ] `DigitalTwinActivated` event

- [ ] `opportunities` table + migration
- [ ] `OpportunityDetector` interface + `FeaturedItemDetector`, `UrgencyDetector`, `NewArrivalDetector`, `ReEngagementDetector`
- [ ] `OpportunityDetectionAnalyst` + `OpportunityDetectionSchema`
- [ ] `OpportunityScorer` (composite score formula)
- [ ] `OpportunityEngine::scan()` + `DetectOpportunities` job
- [ ] `OpportunityDetected` event

- [ ] `decisions` table + migration (with `expected_impact`, `prompt_name`, `prompt_version`)
- [ ] `RationaleGenerationPrompt` + `RationaleSchema`
- [ ] `RationaleGenerationAnalyst`
- [ ] `DecisionEngine::evaluate()` with guards + `CommitDecision` job
- [ ] `DecisionCommitted` event

### Campaign + Content
- [ ] `campaigns` table + migration
- [ ] `CampaignPreparationPrompt` + `CampaignPreparationSchema`
- [ ] `CampaignPreparationAnalyst` + `PrepareCampaign` job
- [ ] `CampaignPrepared` event

- [ ] `channels` table + migration (+ seed system channel templates)
- [ ] `content_assets` table + migration (with `prompt_name`, `prompt_version`)
- [ ] `SocialContentPrompt` + `SocialContentSchema`
- [ ] `EmailContentPrompt` + `EmailContentSchema`
- [ ] `ContentGenerationAnalyst` + `GenerateContent` job
- [ ] `ContentAssetGenerated` event + "last asset" check → dispatch `CreateRecommendation`

### Approval Workflow
- [ ] `recommendations` table + migration (with `expected_impact`)
- [ ] `RecommendationService::create()` + `CreateRecommendation` job
- [ ] `RecommendationCreated` event + in-app notification
- [ ] Recommendation UI (card with rationale, confidence, content previews)

- [ ] `approvals` table + migration
- [ ] `executions` table + migration
- [ ] `learnings` table + migration
- [ ] `ApprovalService::approve()`, `reject()`, `editAndApprove()`
- [ ] `ApprovalController` + `CompanyMembershipPolicy`
- [ ] Approve / Edit + Approve / Reject UI actions
- [ ] `RecommendationApproved` + `RecommendationRejected` events

### Queue Infrastructure
- [ ] Redis queue driver configured
- [ ] Five queues defined: `high`, `ai`, `default`, `observations`, `maintenance`
- [ ] Supervisor configuration for four worker types
- [ ] Failed job handling + alerting
