# Roadmap

Atlas is built in eight phases. Each phase produces a working, testable system that stands on its own. No phase is a placeholder — each one delivers something a real business can benefit from.

**Phases 1–5 constitute the MVP.** They prove the core loop: a business connects its website, Atlas builds a Digital Twin, identifies an opportunity, prepares a campaign, and presents it for approval. A human approves. The loop is complete.

**Phases 6–8 make Atlas autonomous and self-improving.** They add publishing, performance measurement, and feedback-driven learning. These phases are what separates Atlas from a content tool.

Design partner for all phases: **CBB Auctions**. Second validation vertical: exotic used car dealerships.

---

## Phase 1 — Platform Foundation

*The infrastructure every other phase depends on. Nothing meaningful can be built without it.*

### Goals
- Stand up a production-grade Laravel application with multi-tenant architecture
- Establish the domain model, queue topology, and event infrastructure
- Create the testing foundation so every phase that follows can be verified

### Major Deliverables
- Laravel application with PostgreSQL and Redis configured
- `users`, `companies`, `company_memberships`, `catalogs`, `digital_twins` — tables, migrations, models, and relationships
- Company creation flow with auto-provisioning of Catalog and DigitalTwin
- `CompanyScope` global scope enforced on all tenant models
- Queue infrastructure: five queues (`high`, `ai`, `default`, `observations`, `maintenance`) with Supervisor configuration
- Event and listener scaffolding — all domain events registered, even if listeners are stubs
- `AiProvider` interface and `FakeAiProvider` for test-time use
- Base `Prompt`, `Analyst`, and `Connector` abstract classes and interfaces
- CI pipeline: migrations run, tests pass, no type errors

### Success Criteria
- A company can be created by a registered user and assigned the `owner` role
- The Digital Twin initializes in `initializing` state
- Events fire and listeners are invoked across the queue
- All five queues process jobs correctly in isolation
- The test suite uses `FakeAiProvider` — no test calls a real AI provider

### Dependencies
- None. This phase is the foundation.

---

## Phase 2 — Observatory

*Atlas gains eyes. It can connect to a business's data sources and record what it sees.*

### Goals
- Companies can connect their website as a data source
- Atlas crawls the website and records a structured Observation
- Raw data is captured durably and processed asynchronously

### Major Deliverables
- `integrations` and `observations` tables, migrations, models
- Integration creation UI (website URL entry step)
- `ConnectorRegistry` with `WebsiteCrawlConnector` as the first implementation
- `SyncIntegration` job: resolves the right connector, runs the sync, creates the Observation
- Object storage integration: raw crawl payload stored at `raw_payload_ref`; `raw_payload` pruned on schedule
- `ObservationRecorded` event and listener chain scaffolded
- Retry logic for failed crawls: exponential backoff, Integration marked `error` after 3 failures
- Recurring sync scheduling: Integrations re-synced on configurable cadence (default: weekly)
- Second connector: `RssFeedConnector` for businesses with an inventory feed

### Success Criteria
- A user enters a website URL and a crawl runs within 30 seconds
- An `Observation` record is created with the crawled content
- The raw payload is present in object storage at `raw_payload_ref`
- A failed crawl retries correctly and eventually marks the Integration as `error`
- The sync runs again automatically on the configured schedule without user action

### Dependencies
- Phase 1 (queue infrastructure, Integration and Observation models)

---

## Phase 3 — Business Brain

*Atlas starts to understand what it sees. Raw observations become structured knowledge.*

### Goals
- Atlas extracts typed, versioned Facts from every Observation
- Facts are synthesized into higher-order Knowledge
- The Digital Twin activates when enough is known about the business
- The Business Brain can be assembled on demand as a structured context object

### Major Deliverables
- `facts` and `knowledge_entries` tables, migrations, models
- `FactExtractionAnalyst` with `FactExtractionPrompt` and `FactExtractionSchema`
- Fact supersession logic: updated facts archive their predecessors with `is_current = false`
- `KnowledgeSynthesisAnalyst` with `KnowledgeSynthesisPrompt` and `KnowledgeSynthesisSchema`
- `DigitalTwinService`: readiness check and `initializing` → `active` transition
- `BusinessBrainService::for(Company)` — assembles the `BusinessBrain` value object (no DB row)
- `AnthropicProvider` wired up: real AI calls in production, `FakeAiProvider` in tests
- Prompt versioning: `prompt_name` and `prompt_version` stored on every Fact and Knowledge entry
- Observation pruning: `raw_payload` nulled at 30 days, row pruned at 180 days

### Success Criteria
- After a website crawl, at least 5 Facts are extracted with correct keys, types, and confidence scores
- After fact extraction, at least 1 Knowledge entry is synthesized with a clear, actionable body
- The Digital Twin transitions to `active` after minimum readiness thresholds are met
- `BusinessBrainService::for($company)` returns a complete snapshot within 500ms (cached)
- Swapping from `AnthropicProvider` to `OpenAiProvider` requires only a config change

### Dependencies
- Phase 1 (AI provider abstraction, base Analyst class)
- Phase 2 (Observations to process)

---

## Phase 4 — Intelligence

*Atlas develops judgment. It identifies marketing opportunities and commits to decisions it can explain.*

### Goals
- Atlas autonomously detects when a marketing opportunity exists for a business
- Opportunities are scored and ranked by relevance, timing, confidence, and urgency
- Atlas selects the best opportunity and commits a Decision with a complete, explainable rationale
- No Decision can exist without answering: why now, why this, why this channel, why it will work

### Major Deliverables
- `opportunities` and `decisions` tables, migrations, models
- `OpportunityDetector` interface with four MVP detectors: `FeaturedItemDetector`, `UrgencyDetector`, `NewArrivalDetector`, `ReEngagementDetector`
- `OpportunityDetectionAnalyst` for AI-detected opportunities (supplements rule-based detectors)
- `OpportunityScorer`: composite score formula `(relevance × 0.30) + (timing × 0.25) + (confidence × 0.25) + (urgency × 0.20)`
- `OpportunityEngine::scan()` — merges rule-based and AI-detected candidates, deduplicates, scores, persists
- `DecisionEngine::evaluate()` — selects highest-scoring Opportunity, applies guard conditions
- `RationaleGenerationAnalyst` — generates `{why_now, why_this, why_channel, why_works, expected_impact}`
- Guard conditions: cooldown window enforcement, no-duplicate-open-recommendation check, catalog content availability check
- `DecisionCommitted` event and downstream listener scaffolded

### Success Criteria
- After Digital Twin activation, at least one Opportunity is detected and persisted with a non-zero composite score
- The Decision Engine selects the highest-scoring Opportunity that passes all guard conditions
- Every committed Decision has all four rationale keys populated and non-null `expected_impact`
- A Decision without complete rationale cannot be persisted — `RationaleGenerationFailedException` is thrown
- For CBB Auctions: urgency opportunities fire correctly when auctions are ending within 48 hours
- For exotic dealers: featured item opportunities fire correctly for high-value unsold inventory

### Dependencies
- Phase 3 (Business Brain, active Digital Twin, Knowledge entries to score against)

---

## Phase 5 — Campaign Engine

*Atlas prepares the work. From a committed Decision, it produces a full campaign ready for human approval.*

**Specification:** `specs/core/campaign-blueprint.md` — authoritative implementation spec for this phase.

### Goals
- A committed Decision becomes a prepared campaign with strategy, positioning, and channel-specific content
- The user sees a Recommendation that explains what Atlas wants to do and why
- The user can approve, edit, or reject the Recommendation
- The MVP loop is complete: URL → Digital Twin → Opportunity → Decision → Campaign → Approval

### Major Deliverables
- `campaigns`, `content_assets`, `channels`, `recommendations`, `approvals`, `executions` tables, migrations, models
- `CampaignPreparationAnalyst` with `CampaignPreparationPrompt` and `CampaignPreparationSchema`
- `ContentGenerationAnalyst` with channel-aware prompt dispatch: `SocialContentPrompt`, `EmailContentPrompt`, `SmsContentPrompt`
- Per-channel JSON schemas with field validation (subject line, preview text, hashtags, character limits)
- `RecommendationService::create()` — assembles and surfaces the Recommendation after all content is ready
- Approval workflow: `ApprovalService::approve()`, `reject()`, `editAndApprove()` with full audit trail
- `ApprovalController` with `CompanyMembershipPolicy` enforcement (owner and admin only)
- Recommendation UI: rationale display, confidence score, content previews per channel, approve / edit / reject actions
- In-app notification when a new Recommendation is available
- `Execution` records created in `queued` status after approval (publishing deferred to Phase 6)
- `Learning` records created on approval and rejection

### Success Criteria
- **North star:** From website URL entry to first Recommendation visible in UI in under 10 minutes
- The Recommendation UI shows all four rationale fields, a confidence score, and prepared content for each channel
- A user can approve, edit, or reject a Recommendation; all relevant records update correctly
- An `Approval` record exists in the database before any Execution is created
- The MVP loop runs end-to-end for both CBB Auctions and an exotic car dealer without manual intervention

### Dependencies
- Phase 4 (committed Decisions to act on)
- At least one configured Channel per company (seeded or user-configured)

---

## Phase 6 — Publishing

*Atlas acts. Approved content reaches real audiences for the first time.*

### Goals
- Approved content is published to connected channels without manual copy-paste
- Atlas manages the publishing schedule, handles platform rate limits, and records the outcome
- Users connect their social media accounts and email provider through a guided setup flow

### Major Deliverables
- Social channel connectors: Instagram, Facebook, LinkedIn, X (via platform APIs)
- Email channel connector: integration with a transactional email provider (e.g., Mailchimp, Klaviyo, Postmark)
- OAuth-based channel authentication flow (platform tokens stored encrypted)
- `ExecutionService`: transitions `Execution` records from `queued` → `executing` → `completed` / `failed`
- Publishing scheduler: respects `scheduled_at` timestamps, handles platform rate limits, retries on failure
- Execution result recording: stores platform response (post ID, message ID) in `Execution.result`
- `ExecutionCompleted` and `ExecutionFailed` events — feed Phase 8 Learning
- User-facing publishing status: per-ContentAsset visibility into what has and hasn't gone out

### Success Criteria
- An approved Instagram post is published to the company's live Instagram account at the scheduled time
- An approved email is sent to the configured list via the connected email provider
- A failed execution is retried automatically and escalated to the user if it continues to fail
- Platform credentials are encrypted at rest and never exposed in logs or responses

### Dependencies
- Phase 5 (Approval workflow producing `queued` Execution records)
- External platform API credentials and OAuth flows per channel

---

## Phase 7 — Analytics

*Atlas measures what happened. Performance data comes back in and campaigns can be evaluated.*

**Specification:** `specs/core/analytics-engine.md` — authoritative implementation spec for this phase.

### Goals
- Atlas retrieves engagement metrics from publishing platforms after campaigns run
- Actual performance is compared to the `expected_impact` from the original Decision
- Users can see how campaigns performed, what content drove results, and which channels outperformed

### Major Deliverables
- `ExecutionMetric` and `CampaignKpiSnapshot` domain models and migrations
- `AnalyticsProvider` interface and `AnalyticsProviderRegistry` — provider-per-channel-type resolution
- `FakeAnalyticsProvider` — test double; mirrors `FakeEmailProvider` pattern
- `RetrieveExecutionMetrics` job — scheduled polling; re-polls until `isWindowClosed()`
- `ProcessAnalyticsWebhookEvent` job — processes normalised webhook events into `ExecutionMetric`
- `AnalyticsWebhookController` — validates HMAC; dispatches webhook events
- `WebhookHandlerRegistry` + `AnalyticsWebhookHandler` interface — per-provider webhook parsing
- `CampaignKpiService` — aggregates metrics; creates `CampaignKpiSnapshot`; rates performance against `expected_impact`
- `RecommendationKpiService` — approval rate, rejection rate, edit rate, trend per opportunity type
- `DecisionEffectivenessService` — decision accuracy rate by detector, campaign type, and composite score band
- `LearningService::recordFromMetrics()` — creates `Learning` records from finalized KPI snapshots
- Normalised metric keys across all channel types: `normalised_reach`, `normalised_engagement`, `normalised_engagement_rate`
- Campaign performance view in Filament admin: expected vs. actual KPIs, best channel, per-execution breakdown
- `MetricRetrievalLog` — append-only audit of every pull attempt

### Success Criteria
- After a campaign runs, Atlas retrieves and displays real engagement data from each channel
- The performance view shows `expected_impact` alongside actual metrics for direct comparison
- Channel effectiveness is surfaced at the company level: "email outperforms social for this business"
- Data is available within 48 hours of publishing for all connected channels

### Dependencies
- Phase 6 (published Executions with platform post/message IDs to retrieve metrics for)

---

## Phase 8 — Learning

*Atlas gets smarter. Every outcome feeds back into the Business Brain and improves future decisions.*

### Goals
- Every approval, rejection, edit, and campaign outcome is systematically incorporated into the Business Brain
- The Opportunity Engine and Decision Engine use accumulated Learning to make better choices over time
- Atlas is measurably better after 90 days than it was at setup — and users feel the difference

### Major Deliverables
- `ApplyLearnings` job: reads unapplied `Learning` records and updates Facts, Knowledge, and scoring weights
- Preference accumulation: user edits to ContentAssets produce preference signals that influence future content generation (channel voice, formatting, length, hashtag use)
- Rejection analysis: repeated rejections of a campaign type or channel produce Knowledge entries that the Opportunity Engine acts on
- Scoring weight calibration: per-company scoring weights adjust based on historical approval rates per opportunity type
- Prompt performance tracking: approval rates per `prompt_version` surfaced as an internal metric; underperforming prompt versions flagged for review
- Cross-company pattern aggregation (anonymized): aggregate Learning signals improve default scoring weights for new companies
- Learning dashboard (internal): visibility into what Atlas has learned per company and when

### Success Criteria
- After 30 days, the approval rate for a company's Recommendations is measurably higher than in week one
- Repeated rejection of a channel type results in that channel being deprioritized in future Decisions for that company
- User edits to content are detectably reflected in content generated for subsequent campaigns (same company)
- A new company benefits from aggregate patterns learned across all companies — its first Recommendation is better than a company onboarded before Phase 8

### Dependencies
- Phase 5 (Learning records from approvals and rejections)
- Phase 7 (performance data from published campaigns)

---

## Phase Sequencing

```
Phase 1 ── Platform Foundation     ← start here
  │
Phase 2 ── Observatory             ← data in
  │
Phase 3 ── Business Brain          ← understanding
  │
Phase 4 ── Intelligence            ← decisions
  │
Phase 5 ── Campaign Engine         ← MVP complete ✓
  │
Phase 6 ── Publishing              ← real-world action
  │
Phase 7 ── Analytics               ← measurement
  │
Phase 8 ── Learning                ← compounding value
```

Phases 1–5 are sequential. Each depends on the previous.

Phases 6–8 also build on each other in order, but Phase 6 can begin development while Phase 5 is being tested — the interfaces are already defined.

---

## What Is Not on This Roadmap

The following are intentionally excluded from the current roadmap. They may become phases later, but they are not scheduled and no design has been done for them.

- **CRM and contact management** — Atlas knows about audiences in aggregate; it does not manage individual contacts
- **Team and agency permissions** — multi-seat collaboration beyond owner/admin/member is a future concern
- **Billing and subscription management** — not in scope until the core loop is proven with real customers
- **Ads integrations** — paid media is a separate publishing surface; organic content comes first
- **White-label or API-only product** — Atlas is built for direct SMB use first; platform plays come later
- **Mobile application** — the web application is the primary surface in all phases above
