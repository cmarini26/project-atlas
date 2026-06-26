# Changelog

All notable changes to Project Atlas are documented here. Entries are organized by milestone, then by commit.

Format: each entry identifies what changed, which files/paths are affected, and why the change was made.

---

## [Milestone 5 — Campaign Engine] — 2026-06-26

### Added

**Domain**

- `app/Domain/Campaign/Exceptions/BlueprintGenerationFailedException.php` — thrown when blueprint generation fails validation
- `app/Domain/Campaign/ValueObjects/CampaignBlueprint.php` — readonly VO: 10 required Blueprint fields; `fromArray()` / `toArray()`
- `app/Domain/Content/ValueObjects/ContentAssetData.php` — readonly VO: type, body, title, media, metadata, promptName, promptVersion

**AI Prompts**

- `app/AI/Prompts/CampaignPreparationPrompt.php` — version `1.0`; temperature `0.5`; full Blueprint JSON schema
- `app/AI/Prompts/Content/SocialContentPrompt.php` — for `instagram`, `facebook`, `linkedin`, `x` channels
- `app/AI/Prompts/Content/EmailContentPrompt.php` — for `email` channel
- `app/AI/Prompts/Content/SmsContentPrompt.php` — for `sms` channel (160-char constraint)
- `app/AI/Prompts/Content/BlogContentPrompt.php` — for `blog` channel
- `app/AI/Prompts/Content/LandingPageContentPrompt.php` — for `landing_page` channel

**Analysts**

- `app/Services/Analyst/CampaignPreparationAnalyst.php` — calls AI → returns `CampaignBlueprint` VO
- `app/Services/Analyst/Content/ContentGenerationAnalyst.php` — dispatches channel-specific prompt; returns `ContentAssetData`

**Services**

- `app/Services/Campaign/CampaignPreparationService.php` — validates Blueprint (7 rules); persists Campaign in `draft`; sets `expected_asset_count`
- `app/Services/Content/ContentGenerationService.php` — creates `ContentAsset`; increments `generated_asset_count`; fires `CampaignAssetsReady` when complete
- `app/Services/Recommendation/RecommendationService.php` — builds `rationale_display` from Decision; creates Recommendation; updates Decision to `recommended`; fires `RecommendationCreated`
- `app/Services/Recommendation/ApprovalService.php` — `approve()`: transitions Recommendation/Campaign/ContentAssets; `reject()`: cancels Campaign, archives assets; fires `RecommendationApproved/Rejected`

**Jobs**

- `app/Jobs/PrepareCampaign.php` — full implementation (was stub): loads Decision + Company + BusinessBrain → `CampaignPreparationService` → dispatches `GenerateContent` per channel
- `app/Jobs/GenerateContent.php` — `ai` queue; loads Campaign + Channel; calls `ContentGenerationAnalyst` → `ContentGenerationService`
- `app/Jobs/CreateRecommendation.php` — `default` queue; calls `RecommendationService::create()`

**Events**

- `app/Events/CampaignAssetsReady.php`
- `app/Events/RecommendationCreated.php`
- `app/Events/RecommendationApproved.php`
- `app/Events/RecommendationRejected.php`

**Listeners**

- `app/Listeners/TriggerRecommendationCreation.php` — handles `CampaignAssetsReady` → dispatches `CreateRecommendation`

**Models**

- `app/Models/ContentAsset.php` — full: `HasUlids`, `BelongsToCompany`, `SoftDeletes`; all fillable fields; JSON casts; `campaign()` + `channel()` relationships
- `app/Models/Approval.php` — full: `HasUlids`, `BelongsToCompany`; `morphTo approvable`; `user()` relationship
- `app/Models/Campaign.php` — updated: blueprint fields + `contentAssets()` relationship + `allAssetsGenerated()` helper; `$casts` property form
- `app/Models/Recommendation.php` — updated: `campaign_id` added; `$casts` property form; `decision()` + `campaign()` relationships
- `app/Models/Decision.php` — updated: `$casts` property form (fixes Larastan type inference for `channel_ids`, `rationale`, `expected_impact`)
- `app/Models/User.php` — implements `FilamentUser` interface + `canAccessPanel()` for Filament admin access

**Migrations**

- `2026_06_26_001800_add_blueprint_columns_to_campaigns_table.php` — `blueprint`, `blueprint_version`, `prompt_version`, `expected_asset_count`, `generated_asset_count`
- `2026_06_26_001900_create_content_assets_table.php` — full `content_assets` table with type enum, status enum, media/metadata JSON, soft deletes
- `2026_06_26_002000_create_approvals_table.php` — `approvals` table with polymorphic `approvable`, `user_id`, `action` enum, `edits` JSON
- `2026_06_26_002100_add_campaign_id_to_recommendations_table.php` — adds `campaign_id` to `recommendations`

**Filament Admin Panel**

- `app/Filament/Resources/RecommendationResource.php` — list with status badge; Approve + Reject actions (with notes form); View page
- `app/Filament/Resources/CampaignResource.php` — list with status/asset count columns; View page
- `app/Filament/Resources/ContentAssetResource.php` — list with type/status; View page
- `app/Filament/Resources/CompanyResource.php`, `DecisionResource.php`, `OpportunityResource.php` — inspect-only views
- `app/Providers/Filament/AdminPanelProvider.php` — auto-discovers resources at `/admin`
- `backend/phpstan.neon` — `app/Filament` excluded from PHPStan scanning

**Tests**

- `tests/Feature/Campaign/CampaignPreparationServiceTest.php` — 8 tests: creates Campaign, sets expected_asset_count, sends prompt, throws on invalid goal/audience/CTA/channel_strategy, persists blueprint
- `tests/Feature/Campaign/ContentGenerationServiceTest.php` — 6 tests: creates email/social assets, increments count, fires `CampaignAssetsReady` when complete, does not fire prematurely, stores prompt metadata
- `tests/Feature/Campaign/RecommendationServiceTest.php` — 5 tests: creates pending recommendation, builds rationale_display, updates decision status, fires event, copies expected_impact
- `tests/Feature/Campaign/ApprovalServiceTest.php` — 12 tests: approve/reject transitions, status cascade, approval record, events, invalid state guards, no publishing
- `tests/Feature/Campaign/CampaignPipelineTest.php` — 4 tests: job dispatches GenerateContent, full E2E pipeline, no publishing

**AI Fixtures**

- `tests/Fixtures/AI/campaign-blueprint.json` — conversion blueprint for CBB Auctions Silver Age auction
- `tests/Fixtures/AI/social-content.json` — Instagram/social post content
- `tests/Fixtures/AI/email-content.json` — email with subject line, body, preview text

**AppServiceProvider**

- `CampaignAssetsReady → TriggerRecommendationCreation` event wiring added

---

## [Milestone 5 Specification — Campaign Blueprint] — 2026-06-26

### Added

- `specs/core/campaign-blueprint.md` — authoritative specification for the Campaign Blueprint; source of truth for Milestone 5 implementation

**Defines:**
- Campaign Blueprint as the strategic creative brief generated between a Decision and channel-specific content generation
- 10 required fields: `goal`, `audience`, `core_message`, `supporting_points`, `call_to_action`, `offer`, `tone`, `landing_page`, `success_metrics`, `channel_strategy`
- Blueprint schema with `version` and `prompt_version` fields for auditability
- Blueprint immutability rule: stored on `campaigns.blueprint`; never modified after write
- `CampaignPreparationAnalyst` contract: inputs (Decision, BusinessBrain), output (`CampaignBlueprint` VO), temperature `0.5`, failure handling
- `BlueprintGenerationFailedException` — thrown when any required key is missing; Campaign stays `draft`
- Validation rules for all 10 fields with specific character minimums and enum values
- Acceptance criteria for Milestone 5 (Blueprint generation, goal mapping, channel strategy, failure paths, versioning)
- Pipeline: Blueprint → `GenerateContent` jobs per channel → `ContentGenerationAnalyst` → `ContentAsset` records → `CampaignAssetsReady` → `RecommendationService::create()`
- `ContentGenerationPrompt` variants per channel type: `SocialContentPrompt`, `EmailContentPrompt`, `SmsContentPrompt`, `BlogContentPrompt`, `LandingPageContentPrompt`
- `ContentAsset.body` + `metadata` schema per channel type (ready for Milestone 6 rendering)
- `ChannelRenderer` interface contract (Milestone 6 implementation target)
- `expected_asset_count` / `generated_asset_count` tracking on Campaign for deterministic `CampaignAssetsReady` event
- Future extensibility: human-authored blueprints, vertical templates, A/B variants, multi-wave campaigns, per-company calibration

---

## [Milestone 4 — Opportunity & Decision Engine] — 2026-06-26

### Added

**Opportunity Domain**

- `database/migrations/2026_06_26_001200_create_catalog_items_table.php` — `catalog_items` table: ULID PK, `status` enum, `price`, `media`, `metadata`, `promoted_at`, `expires_at`, soft deletes, compound indexes
- `database/migrations/2026_06_26_001300_create_channels_table.php` — `channels` table: nullable `company_id` (null = system template), `type` enum, `is_active`
- `database/migrations/2026_06_26_001400_create_opportunities_table.php` — `opportunities` table: all four score columns, `composite_score`, `ai_detected`, polymorphic `subject`, `status` enum, `expires_at`, `detected_at`
- `database/migrations/2026_06_26_001500_create_decisions_table.php` — `decisions` table: `campaign_type` enum, `channel_ids` JSON, `rationale` JSON, `expected_impact` JSON, `prompt_version`, `decided_at`
- `database/migrations/2026_06_26_001600_create_campaigns_table.php` — `campaigns` table: `campaign_type`, `completed_at`, full status enum (used for Guard 3 cooldown)
- `database/migrations/2026_06_26_001700_create_recommendations_table.php` — `recommendations` table: `campaign_type` (used for Guard 2 duplicate check), status enum

**Models**

- `app/Models/CatalogItem.php` — full implementation: `BelongsToCompany`, `HasUlids`, `SoftDeletes`, datetime casts, `scopeActive()`, `isActive()`
- `app/Models/Channel.php` — `HasUlids` only (no `BelongsToCompany`; `company_id` is nullable for system channels)
- `app/Models/Campaign.php` — updated from stub: full fillable, `campaign_type`, `completed_at`, datetime casts
- `app/Models/Recommendation.php` — new: `BelongsToCompany`, `HasUlids`, `SoftDeletes`, `campaign_type`
- `app/Models/Opportunity.php` — new: `BelongsToCompany`, `HasUlids`, polymorphic `subject()`, `decision()`, `scopeOpen()`, `select()`, `dismiss()`
- `app/Models/Decision.php` — new: `BelongsToCompany`, `HasUlids`, `opportunity()`, `recommendation()`, `campaign()`, JSON casts for `channel_ids`, `rationale`, `expected_impact`
- `app/Models/Company.php` — added `opportunities()` and `decisions()` `HasMany` relationships

**Opportunity Engine**

- `app/Services/Opportunity/OpportunityCandidate.php` — readonly VO with all four score fields + `aiDetected` flag
- `app/Services/Opportunity/Detectors/Contracts/OpportunityDetector.php` — updated interface: `detect(Company, BusinessBrain)` → `Collection<int, OpportunityCandidate>`
- `app/Services/Opportunity/OpportunityRepository.php` — `hasDuplicate()`, `openForCompany()`, `expiredCandidates()`
- `app/Services/Opportunity/OpportunityScorer.php` — composite formula `(r×0.30 + t×0.25 + c×0.25 + u×0.20)`; minimum 30 threshold; AI confidence cap at 75
- `app/Services/Opportunity/Detectors/FeaturedItemDetector.php` — rule-based: detects un-promoted items; 14-day / 45-day cooldown by value; scores by price tier
- `app/Services/Opportunity/Detectors/UrgencyDetector.php` — rule-based: item-level expiry within 48h; falls back to `catalog.ending_within_48h_count` Fact
- `app/Services/Opportunity/Detectors/NewArrivalDetector.php` — rule-based: items created within 48h; timing score degrades with age
- `app/Services/Opportunity/Detectors/ReEngagementDetector.php` — rule-based: uses `marketing.days_since_last_campaign` Fact or `recentCampaigns`; 14-day threshold
- `app/Services/Opportunity/OpportunityEngine.php` — orchestrates all detectors → AI analyst → deduplication → scoring → persistence → `OpportunityDetected` event per candidate

**AI: Opportunity Detection**

- `app/AI/Prompts/OpportunityDetectionPrompt.php` — version `1.0`, temperature `0.3`; structured JSON schema; passes already-detected types to avoid overlap
- `app/Services/Analyst/OpportunityDetectionAnalyst.php` — implements `Analyst`; calls `OpportunityDetectionPrompt`; marks all results `aiDetected: true`; validates required fields
- `tests/Fixtures/AI/opportunity-detection.json` — fixture: one seasonal candidate

**Decision Engine**

- `app/Services/Decision/DecisionContext.php` — immutable readonly VO: `Opportunity`, `BusinessBrain`, `campaignType`, `channelIds`
- `app/Services/Decision/Exceptions/RationaleGenerationFailedException.php` — thrown when any of 5 required rationale keys is missing or empty
- `app/Services/Decision/DecisionRepository.php` — `openForCompany()`, `findByOpportunity()`
- `app/Services/Decision/DecisionEngine.php` — five guard conditions in order; deterministic score-ordered selection; channel affinity resolution; commits via `DecisionService`
- `app/Services/Decision/DecisionService.php` — calls `RationaleGenerationAnalyst`, validates all 5 rationale keys + 4 `expected_impact` sub-keys, persists `Decision`, transitions Opportunity to `selected`, fires `DecisionCommitted`
- `app/AI/Prompts/RationaleGenerationPrompt.php` — version `1.0`, temperature `0.4`; includes Opportunity, company identity, selected channels, Facts, Knowledge, subject item (if CatalogItem); structured JSON schema
- `app/Services/Analyst/RationaleGenerationAnalyst.php` — implements `Analyst`; returns raw rationale array for caller to validate
- `tests/Fixtures/AI/rationale-generation.json` — fixture: complete 5-key rationale with all `expected_impact` sub-keys

**Jobs**

- `app/Jobs/DetectOpportunities.php` — `default` queue; calls `BusinessBrainService::for()` then `OpportunityEngine::scan()`
- `app/Jobs/CommitDecision.php` — `ai` queue; `ShouldBeUnique` per company (`uniqueId()` = company ID); calls `DecisionEngine::evaluate()`
- `app/Jobs/ExpireOpportunities.php` — `maintenance` queue; bulk-expires open Opportunities past `expires_at`
- `app/Jobs/PrepareCampaign.php` — `ai` queue; Milestone 4 no-op stub; wired and dispatched; implemented in Milestone 5

**Events & Listeners**

- `app/Events/OpportunityDetected.php` — fired per persisted Opportunity from `OpportunityEngine::scan()`
- `app/Events/DecisionCommitted.php` — fired after `DecisionService` persists a Decision
- `app/Listeners/TriggerOpportunityDetection.php` — `DigitalTwinActivated` → dispatches `DetectOpportunities`
- `app/Listeners/TriggerDecisionEvaluation.php` — `OpportunityDetected` → dispatches `CommitDecision`
- `app/Listeners/DispatchCampaignPreparation.php` — `DecisionCommitted` → dispatches `PrepareCampaign`

**Infrastructure Updates**

- `app/Providers/AppServiceProvider.php` — added morph map (`catalog_item`, `catalog`, `company`); wired 3 new event/listener pairs
- `app/Services/Brain/BusinessBrainService.php` — populated `featuredItems` with active/featured `CatalogItem` records; populated `recentCampaigns` with 10 most recent `Campaign` records

**Tests** (127 passing, 2 Redis skipped)

- `tests/Unit/Opportunity/OpportunityScorerTest.php` — 5 unit tests: threshold, clamp, AI cap, weighted formula, score output shape
- `tests/Feature/Opportunity/FeaturedItemDetectorTest.php` — 6 tests: empty brain, never-promoted, in-cooldown, out-of-cooldown, high-value cooldown
- `tests/Feature/Opportunity/UrgencyDetectorTest.php` — 5 tests: no expiry, item-level 24h, item-level 36h, catalog-fact fallback, item priority over fact
- `tests/Feature/Opportunity/NewArrivalDetectorTest.php` — not enumerated here; covered by engine integration test
- `tests/Feature/Opportunity/ReEngagementDetectorTest.php` — 5 tests: no items, below threshold, above threshold from fact, campaign fallback, 999-day never-campaigned
- `tests/Feature/Opportunity/OpportunityEngineTest.php` — 4 tests: persists candidates, deduplicates by type+subject, fires `OpportunityDetected`, marks AI candidates
- `tests/Feature/Opportunity/OpportunityExpiryTest.php` — 3 tests: expires past-expiry, leaves future open, ignores null-expiry
- `tests/Feature/Opportunity/OpportunityDetectionAnalystTest.php` — 6 tests: parses fixture, marks AI detected, sends correct prompt, empty response, invalid fields filtered, scores clamped
- `tests/Feature/Decision/DecisionEngineTest.php` — 7 tests: Guard 1–5, commits on all-pass, selects highest score
- `tests/Feature/Decision/RationaleGenerationAnalystTest.php` — 2 tests: parses complete fixture, sends correct prompt
- `tests/Feature/Decision/DecisionPipelineTest.php` — 2 tests: full committed decision, rationale failure leaves opportunity open

### Updated

- `app/Models/Company.php` — added `opportunities()` and `decisions()` `HasMany` relationships
- `app/Services/Brain/BusinessBrainService.php` — `featuredItems` and `recentCampaigns` now populated from DB
- `app/Providers/AppServiceProvider.php` — morph map + new events

---

## [Milestone 4 Specification — Decision Engine] — 2026-06-25

### Added

- `specs/core/decision-engine.md` — pre-implementation design specification for the Decision Engine

**Document covers:**
- What a Decision is and what distinguishes it from an Opportunity (the full comparison table)
- Decision lifecycle from `pending` through `executed`; M4 boundary explicitly at `pending`
- Six Decision statuses with transition rules and who sets each
- Decision types (`campaign_type`) and how they map from Opportunity types
- Decision inputs: selected Opportunity, BusinessBrain, score components, guard conditions, company context
- Five guard conditions with implementation logic, query shapes, and on-failure behaviour:
  - Guard 1: minimum score (composite_score >= 30)
  - Guard 2: duplicate recommendation (no `pending`/`viewed` Recommendation of same campaign_type)
  - Guard 3: campaign cooldown (per-type windows: 3 days for urgency_promotion, 14 days for others)
  - Guard 4: catalog availability (CatalogItem must still be `active`; on failure: Opportunity dismissed)
  - Guard 5: channel availability (at least one active Channel exists)
- Selection algorithm: score-ordered, deterministic, with tie-breaking rules
- Channel selection logic and type-affinity defaults
- Five required rationale fields with good/bad examples and validation rules enforced in `DecisionService`
- `RationaleGenerationAnalyst` interface: inputs, output shape, prompt design (temperature 0.4, versioned), failure handling
- Campaign pipeline handoff (Milestone 5): full flow from `DecisionCommitted` through Recommendation
- Decision fields that drive the Campaign Engine (`campaign_type`, `channel_ids`, rationale keys, `confidence_score`)
- Complete M4 implementation list: models, services, jobs, events, listeners, exceptions
- Explicit out-of-scope list per milestone
- Acceptance criteria (all verifiable by automated tests): detection, guards, commitment, rationale, failure paths, expiry, test requirements
- Future extensibility: additional guards, per-company scoring weights (Phase 8), channel affinity learning, multiple Decisions per cycle, vertical calibration, human-initiated Decisions

### Updated

- `specs/core/opportunity-engine.md` — authority claim narrowed: DecisionEngine removed from scope (decision-engine.md is now authoritative for guard conditions and rationale); cross-reference to decision-engine.md added to header

---

## [Milestone 4 Specification — CTO Review & Scope Finalisation] — 2026-06-25

### Updated

- `specs/core/opportunity-engine.md` — CTO reviewed; implementation scope section rewritten and moved to the top of the document (immediately after the header block), replacing the earlier Section 15 draft

**Scope section now records authoritatively:**
- Required opportunity types in M4: `featured_item`, `urgency`, `new_arrival`, `re_engagement`
- Optional / spec-defined but not required in M4: `seasonal`, `milestone`
- Supporting models permitted: `CatalogItem`, `Campaign`, `Recommendation` — intentionally minimal; exist only to support detection, subject validation, evidence tracking, deduplication, cooldown checks, and duplicate recommendation guard conditions
- Hard DO NOT list: Campaign Engine behavior, campaign preparation, Marketing Assets, ContentAssets, channel renderers, any publishing integration (Facebook, Instagram, Email, SMS, LinkedIn, Google Ads, Meta Ads, Blog, Landing Pages), analytics, learning
- Goal of Milestone 4: produce a validated Decision with a complete rationale; Campaign creation begins in Milestone 5

---

## [Milestone 4 Specification] — Opportunity Engine — 2026-06-25

### Added

- `specs/core/opportunity-engine.md` — authoritative design specification for Milestone 4; supersedes any conflicting guidance in other documents for the Opportunity Engine, OpportunityDetectors, OpportunityScorer, and DecisionEngine

**Document covers:**
- What an Opportunity is and what it is not (not content, not a suggestion — a scored claim with evidence and expiry)
- Opportunity lifecycle: `open → selected → [Campaign created]`; also `dismissed` and `expired` transitions and who sets each
- Six opportunity types with trigger conditions, required evidence, scoring profiles, and vertical examples: `featured_item`, `urgency`, `new_arrival`, `re_engagement`, `seasonal`, `milestone`
- Composite scoring formula: `(relevance × 0.30) + (timing × 0.25) + (confidence × 0.25) + (urgency × 0.20)`; minimum threshold 30; component definitions with 0–100 ranges; tie-breaking rules
- Evidence chain: Facts → Knowledge → Opportunity description → Decision rationale; requirement that detectors read from `BusinessBrain` only (no direct DB queries except CatalogItem lookups)
- Expiration rules per type with rationale; `ExpireOpportunities` nightly maintenance job
- Deduplication rule: no new Opportunity persisted if an open or selected Opportunity with same `(type, subject_type, subject_id)` exists for the company; cooldown windows per campaign type enforced separately in `DecisionEngine`
- `OpportunityDetector` interface contract with `appliesTo(): string[]` and `detect(Company, BusinessBrain): Collection<int, OpportunityCandidate>`; full list of detector rules (no DB writes, no AI calls, return empty on sparse data)
- `OpportunityCandidate` readonly value object definition
- Four MVP rule-based detectors: `FeaturedItemDetector`, `UrgencyDetector`, `NewArrivalDetector`, `ReEngagementDetector`
- `OpportunityDetectionAnalyst` for AI-assisted detection: runs after rule-based pass; AI failure is non-fatal; confidence cap at 75 for AI-detected candidates
- Decision Engine selection algorithm: ordered by composite score; three guard conditions (no duplicate open recommendation, cooldown window, catalog availability); `RationaleGenerationAnalyst` generates all five rationale keys or throws `RationaleGenerationFailedException`
- How Decisions become Campaigns: field mapping from Decision to Campaign Engine (Milestone 5 scope); Milestone 4 stops at `Decision.status = "pending"` + `DecisionCommitted` event
- Full Milestone 4 acceptance criteria checklist (detection, detectors, Decision Engine, scoring, expiry, tests)
- Future extensibility: new detector pattern, new opportunity types, per-company weighted scoring (Phase 8), cross-company patterns (Phase 8), vertical-specific detectors, manual opportunity creation
- Scoring appendix: three worked examples (CBB urgency, exotic dealer featured item, dealer re-engagement) with per-component breakdown

### Updated

- `docs/STATUS.md` — current milestone section updated to reflect spec complete and implementation pending; Next Tasks rewritten with specific Milestone 4 implementation steps; Recently Completed updated

---

## [Milestone 3 Cleanup] — 2026-06-26

### Fixed

- `app/Models/Observation.php` — added `facts(): HasMany<Fact>` relationship; resolves the deferred spec compliance item from the M3 review
- `app/Services/Brain/KnowledgeService.php` — `updateTwin()` (renamed from `activateTwinIfReady()`) now updates `last_enriched_at` on every synthesis run, not only when the twin first transitions from `initializing → active`
- `tests/Feature/Brain/KnowledgeServiceTest.php` — added `test_updates_last_enriched_at_on_every_synthesis` to assert the fix

### Result

- 83 tests total; 81 passing, 2 skipped (Redis); PHPStan level 8 — 0 errors; Pint — clean

---

## [Milestone 3] — Fact Extraction & Knowledge Synthesis — 2026-06-26

### Added

**Database Migrations (`backend/database/migrations/`)**
- `2026_06_26_001000_create_facts_table.php` — `facts` table; `char(26)` ULID PK; `is_current` boolean; `superseded_by_id` self-referential; compound index `(company_id, key, is_current)`
- `2026_06_26_001100_create_knowledge_entries_table.php` — `knowledge_entries` table; `char(26)` ULID PK; type enum; `is_active` boolean; `expires_at` nullable; compound index `(company_id, type, is_active)`

**Eloquent Models (`backend/app/Models/`)**
- `Fact.php` — `BelongsToCompany`, `HasUlids`; `value` cast as `json`; `is_current` boolean cast; `current()` local scope; `observation()` and `supersededBy()` relationships
- `Knowledge.php` — `BelongsToCompany`, `HasUlids`; table `knowledge_entries`; `active()` local scope with `expires_at` handling
- `Company.php` — added `facts()` and `knowledge()` `hasMany` relationships

**AI Layer (`backend/app/AI/`)**
- `Prompts/FactExtractionPrompt.php` — extends `Prompt`; structured JSON schema; version `1.0`; temperature `0.1`; system prompt defines fact key conventions and confidence rules
- `StructuredResponseParser.php` — parses AI response to `array`; strips markdown code fences; throws `InvalidArgumentException` on non-JSON or non-array

**Analysts (`backend/app/Services/Analyst/`)**
- `WebsiteAnalyst.php` — implements `Analyst`; reads `Observation.raw_payload` as WebPageData JSON; calls `AiProvider::complete(FactExtractionPrompt)`; returns `Collection<int, FactData>`; short-circuits on empty `bodyText`

**Brain Services (`backend/app/Services/Brain/`)**
- `Data/FactData.php` — readonly VO: key, value, dataType, confidence
- `FactRepository.php` — `findCurrent(companyId, key)`, `currentForCompany(companyId)` — always `withoutGlobalScopes()`
- `KnowledgeRepository.php` — `activeForCompany(companyId)`, `findActiveForSubject(companyId, subject)`
- `FactService.php` — `storeExtracted(Observation, Collection<FactData>): Collection<Fact>`; creates new Facts; supersedes existing current fact for same key; fires `FactExtracted`
- `KnowledgeService.php` — `synthesizeForCompany(Company)`: groups current Facts by domain key; upserts Knowledge (type: `context`); fires `KnowledgeSynthesized`; activates DigitalTwin if `initializing`
- `BusinessBrainService.php` — `for(Company): BusinessBrain`; assembles from current Facts, active Knowledge, recent Observations, DigitalTwin, Catalog

**Events (`backend/app/Events/`)**
- `FactExtracted.php` — fired per Fact created by `FactService`
- `KnowledgeSynthesized.php` — fired per Knowledge entry upserted
- `ObservationProcessed.php` — fired when `ProcessObservation` marks an observation processed
- `DigitalTwinActivated.php` — fired when `KnowledgeService` transitions twin `initializing → active`

**Jobs (`backend/app/Jobs/`)**
- `ProcessObservation.php` — fully implemented (was stub); pipeline: `markProcessing → WebsiteAnalyst → FactService → KnowledgeService → markProcessed → ObservationProcessed`; `markFailed()` + re-throw on error

**Providers**
- `AppServiceProvider.php` — `register()` binds `AiProvider` to `FakeAiProvider` in `testing` environment

**Test Fixture**
- `tests/Fixtures/AI/website-facts.json` — 4-fact sample response used by analyst and pipeline tests

**Feature Tests (`backend/tests/Feature/Brain/`)**
- `WebsiteAnalystTest.php` — 3 tests: fact extraction, field mapping, empty payload short-circuit
- `FactServiceTest.php` — 4 tests: persist, supersede, observation linkage, empty input
- `KnowledgeServiceTest.php` — 6 tests: synthesis, events, twin activation, no duplicate, idempotent, empty input
- `BusinessBrainServiceTest.php` — 6 tests: company/twin, current facts, superseded excluded, active knowledge, catalog, empty M3 collections
- `ProcessObservationTest.php` — 6 tests: observation processed, facts created, knowledge created, twin activated, event fired, failure path

**Unit Tests (`backend/tests/Unit/AI/`)**
- `StructuredResponseParserTest.php` — 4 tests: plain JSON, markdown fences, code fences, invalid JSON exception
- `FactExtractionPromptTest.php` — 5 tests: system/user strings, schema structure, version, low temperature

### Result

- 82 tests total; 80 passing, 2 skipped (Redis); PHPStan level 8 — 0 errors; Pint — clean

### Spec Deviations

None. All implemented entities match `specs/core/domain-model.md` exactly.

### Technical Debt Introduced

| Item | Notes |
|------|-------|
| No production `AiProvider` implementation | Production deployment requires `AnthropicProvider` before AI jobs run |
| Knowledge synthesis is rule-based in M3 | AI-powered pattern synthesis deferred to M4+ |
| `DigitalTwin.last_enriched_at` only updated on activation | Should also update on re-synthesis |
| `Observation hasMany Fact` not added to Observation model | Deferred — not yet needed by any query path |

---

## [Milestone 2 Cleanup] — 2026-06-26

### Fixed

- `app/Services/Company/CompanyService.php` — default Catalog type corrected from `'inventory'` to `'mixed'`; `'mixed'` is the correct generic default for a newly onboarded company
- `tests/Feature/Discovery/CompanyServiceTest.php` — `test_creates_catalog_for_company` now explicitly asserts `type = 'mixed'`

### Added

- `app/Services/Observatory/IntegrationService.php` — `create(Company, string $type, array $config): Integration`; sets `name` via `defaultName()` match, `status: active`, `next_run_at: +7 days`; dispatches `SyncIntegration` immediately on creation
- `app/Jobs/SyncIntegration.php` — now implements `ShouldBeUnique`; `uniqueId()` returns `$this->integration->id` — prevents duplicate sync jobs from stacking in the queue
- `tests/Feature/Discovery/IntegrationServiceTest.php` — 5 new tests: correct attributes, encrypted config, `next_run_at` 7-day window, immediate `SyncIntegration` dispatch, default name for `website_crawl`
- `tests/Feature/Discovery/SyncPipelineTest.php` — `test_sync_integration_is_unique_per_integration` asserts job implements `ShouldBeUnique` and `uniqueId()` returns integration id

### Result

- 48 tests total; 46 passing, 2 skipped (Redis); PHPStan level 8 — 0 errors; Pint — clean

---

## [Milestone 2] — Discovery & Knowledge Platform — 2026-06-26

### Added

**Database Migrations (`backend/database/migrations/`)**
- `2026_06_26_000000_create_users_table.php` — rewrites Laravel default; `char(26)` ULID PK; sessions table `user_id` updated to `char(26)`
- `2026_06_26_000300_create_personal_access_tokens_table.php` — Sanctum migration; `char(26)` tokenable_id replacing default `bigInteger` morphs
- `2026_06_26_000400_create_companies_table.php` — `char(26)` PK, `slug` unique, `brand`/`settings` JSON, `softDeletes`
- `2026_06_26_000500_create_company_memberships_table.php` — `char(26)` PK/FKs, role enum (owner/admin/member/viewer)
- `2026_06_26_000600_create_catalogs_table.php` — `char(26)` PK, one per company, type enum (inventory/services/menu/listings/mixed)
- `2026_06_26_000700_create_digital_twins_table.php` — `char(26)` PK, status enum (initializing/active/stale/archived), health_score
- `2026_06_26_000800_create_integrations_table.php` — `char(26)` PK, type enum, encrypted config column, `last_successful_run_at`
- `2026_06_26_000900_create_observations_table.php` — `char(26)` PK, status enum (pending/processing/processed/failed), compound indexes

**Eloquent Models (`backend/app/Models/`)**
- `User.php` — `HasUlids`, `HasApiTokens`, `HasFactory<UserFactory>`; `memberships()` relationship
- `Company.php` — `HasUlids`, `SoftDeletes`, `HasFactory<CompanyFactory>`; auto-slugs from name; all relationships with generic type annotations
- `CompanyMembership.php` — `BelongsToCompany`, `HasUlids`; `user()`, `inviter()` relationships
- `Catalog.php` — `BelongsToCompany`, `HasUlids`; `item_schema` array cast
- `DigitalTwin.php` — `BelongsToCompany`, `HasUlids`; `isActive()`, `isInitializing()` helpers
- `Integration.php` — `BelongsToCompany`, `HasUlids`; `config` cast as `encrypted:array`; `markAsError()`; `last_successful_run_at`
- `Observation.php` — `BelongsToCompany`, `HasUlids`, `Prunable`; 180-day prune with payload nulling; `markProcessing/Processed/Failed()`

**Multi-Tenancy Foundation (`backend/app/Domain/Shared/`)**
- `Scopes/CompanyScope.php` — applies `WHERE company_id = ?` when `current_company_id` is bound in the container; no-op otherwise
- `Concerns/BelongsToCompany.php` — registers `CompanyScope`; provides `company()` `BelongsTo` relationship

**Connector Framework (`backend/app/Services/Observatory/Connectors/`)**
- `Contracts/Connector.php` — `supports(Integration)`, `sync(Integration): Collection<int, ConnectorResult>`
- `ConnectorResult.php` — readonly value object: `sourceType`, `sourceIdentifier`, `payload`, `observedAt`
- `ConnectorRegistry.php` — `resolve(Integration): Connector` (throws `UnsupportedIntegrationException`); `all(): array`
- `Exceptions/UnsupportedIntegrationException.php` — thrown when no connector supports an integration type
- `Website/WebPageData.php` — readonly value object for a single crawled page; `toArray()` serialises for payload
- `Website/WebPageCrawler.php` — BFS crawler; Guzzle HTTP + DOMDocument + DOMXPath; max 20 pages / depth 3; strips nav/footer/scripts; 5,000-char body text cap; single fetch per page (links extracted from same parse)
- `Website/WebsiteConnector.php` — implements `Connector`; crawls URL from `integration->config['url']`; maps `WebPageData → ConnectorResult`

**Observation Pipeline**
- `app/Services/Company/CompanyService.php` — `create(User, array): Company`; one DB transaction wraps Company + Catalog + DigitalTwin + owner CompanyMembership
- `app/Services/Observatory/ObservationService.php` — `record()` / `recordAll()`; persists `ConnectorResult` as `Observation`; dispatches `ObservationRecorded`
- `app/Events/ObservationRecorded.php` — fired after each Observation is persisted
- `app/Events/IntegrationSyncStarted.php` — fired when `SyncIntegration` begins
- `app/Events/IntegrationSyncCompleted.php` — fired when sync finishes; carries observation count
- `app/Jobs/SyncIntegration.php` — resolves connector via registry; syncs; records observations; updates timestamps; on `observations` queue; marks integration as error on failure
- `app/Jobs/ProcessObservation.php` — stub job on `ai` queue; no-op until Milestone 3 adds AI fact extraction
- `app/Listeners/DispatchObservationProcessing.php` — listens to `ObservationRecorded`; dispatches `ProcessObservation`

**Service Providers**
- `app/Providers/ConnectorServiceProvider.php` — registers `ConnectorRegistry` singleton with `WebsiteConnector`
- `app/Providers/AppServiceProvider.php` — wires `ObservationRecorded → DispatchObservationProcessing`
- `bootstrap/providers.php` — registers `ConnectorServiceProvider`

**Factories**
- `database/factories/CompanyFactory.php` — generates realistic company data for tests

**Feature Tests (`backend/tests/Feature/Discovery/`)**
- `CompanyServiceTest.php` — 5 tests: company creation, catalog, digital twin status, owner membership, atomicity
- `TenantIsolationTest.php` — 2 tests: CompanyScope filters by bound company; no-op when no company bound
- `ConnectorRegistryTest.php` — 3 tests: resolves WebsiteConnector; throws for unsupported type; registry is non-empty
- `WebsiteConnectorTest.php` — 2 tests: maps crawled pages to ConnectorResults; `supports()` correctly typed
- `SyncPipelineTest.php` — 2 tests: `SyncIntegration` dispatches to `observations` queue; `ProcessObservation` dispatches to `ai` queue

### Changed

- `backend/app/Models/Observation.php` — import order fixed by Pint
- `backend/app/Domain/Shared/Scopes/CompanyScope.php` — `@implements Scope<Model>` annotation added; FQCN fix by Pint

### Spec Deviation

- `Connector::sync()` declared as `sync(): Collection<int, ConnectorResult>` instead of spec's `sync(): Observation` — one result per crawled page/feed item, not one aggregate per sync. `ObservationService` is responsible for persisting each `ConnectorResult` as its own `Observation`.

---

## [Milestone 1 Hardening] — 2026-06-25

### Changed

- `backend/phpstan.neon` — raised from level 6 to **level 8**; passes with 0 errors; no code changes required
- `docs/STATUS.md` — stack table added (PHP 8.3+, Laravel 13.x, PHPStan level 8); technical debt section expanded with three named items; next tasks reordered to put ULID `User` PK conversion first; PHPStan level 8 decision recorded; project health notes clarified to distinguish placeholder models from implemented persistence
- `CHANGELOG.md` — this entry

### Technical Debt Recorded

| Item | Notes |
|------|-------|
| Eloquent model stubs are placeholders only | No migrations, fillable, casts, or relationships — exist for PHPStan type resolution only |
| Queue tests use `Queue::fake()` | Dispatch mechanism is proven; live Redis worker execution is not tested yet |
| `User` model uses integer PK | Must be converted to `char(26)` ULID before `company_memberships` migration |

---

## [Milestone 1] — Platform Foundation — 2026-06-25

### Added

**Laravel Application (`backend/`)**
- Laravel 13.17 project created in `backend/`
- PHP 8.3, Composer 2.x
- `backend/.env` — configured for PostgreSQL + Redis (queue, cache, session drivers)
- `backend/.env.example` — documented template for new environments
- `backend/pint.json` — Laravel preset with `simplified_null_return`, `blank_line_before_statement`, `new_with_parentheses`
- `backend/phpstan.neon` — Larastan at level 8; paths: `app/`

**Queue Topology (`backend/config/queue.php`)**
- Five named queue connections: `high`, `ai`, `default` (Redis), `observations`, `maintenance`
- `ai` queue has elevated `retry_after` (300s) to accommodate long AI calls
- Batching and failed job tables point to PostgreSQL (not SQLite)

**Domain Folder Structure**
- `app/Domain/Company/`
- `app/Domain/Catalog/`
- `app/Domain/BusinessBrain/`
- `app/Domain/Opportunity/`
- `app/Domain/Decision/`
- `app/Domain/Recommendation/`
- `app/Domain/Campaign/`
- `app/Domain/Shared/`
- `app/Application/`
- `app/Infrastructure/`
- `app/Presentation/`

**Core AI Contracts and Abstractions**
- `app/AI/Contracts/AiProvider.php` — single `complete(Prompt): AiResponse` method; the only interface external code touches
- `app/AI/AiResponse.php` — readonly value object: `content`, `model`, `inputTokens`, `outputTokens`
- `app/AI/Prompts/Prompt.php` — abstract base: `system()`, `user()`, `schema()`, `temperature()`, `maxTokens()`, `version()`, `name()`
- `app/AI/Testing/FakeAiProvider.php` — test double: `queueResponse()`, `queueFixture()`, `complete()`, `assertPromptSent()`, `assertNothingSent()`, `sentCount()`
- `tests/Fixtures/AI/` — directory for JSON fixtures consumed by `FakeAiProvider::queueFixture()`

**Domain Service Contracts**
- `app/Services/Analyst/Contracts/Analyst.php` — marker interface; only Analysts may call `AiProvider`
- `app/Services/Observatory/Connectors/Contracts/Connector.php` — `supports(Integration): bool`, `sync(Integration): Observation`
- `app/Services/Opportunity/Detectors/Contracts/OpportunityDetector.php` — `appliesTo(): string[]`, `detect(BusinessBrain): Collection`
- `app/Services/Content/Contracts/ContentGenerator.php` — `channel(): string`, `generate(Campaign): ContentAsset`

**Domain Value Objects**
- `app/Domain/BusinessBrain/BusinessBrain.php` — readonly value object assembled by `BusinessBrainService::for(Company)`; never persisted

**Eloquent Model Stubs** (structure only; no migrations, fillable, or relationships yet)
- `app/Models/Company.php` — with `SoftDeletes`
- `app/Models/DigitalTwin.php`
- `app/Models/Catalog.php`
- `app/Models/Integration.php`
- `app/Models/Observation.php`
- `app/Models/Campaign.php` — with `SoftDeletes`
- `app/Models/ContentAsset.php` — with `SoftDeletes`

**Bootstrap Tests (25 tests, all passing)**
- `tests/Feature/ApplicationBootTest.php` — Laravel boots, container resolves core bindings, environment is `testing`
- `tests/Feature/DatabaseConnectionTest.php` — DB connection established, migrations table exists, users table exists
- `tests/Feature/QueueDispatchTest.php` — jobs dispatched to queues, all five Atlas queues configured
- `tests/Feature/RedisConnectionTest.php` — Redis ping + set/get (skipped when Redis not in test env)
- `tests/Unit/AI/FakeAiProviderTest.php` — queueResponse, ordering, empty-queue exception, assertPromptSent, assertNothingSent, chaining
- `tests/Unit/AI/PromptTest.php` — defaults, version override, name, system/user return strings

**Infrastructure**
- `infrastructure/supervisor/atlas-worker.conf` — Supervisor config for all five queue workers

**CI/CD**
- `.github/workflows/ci.yml` — GitHub Actions: PostgreSQL 16 + Redis 7 services, Pint → PHPStan → PHPUnit on push/PR to `main`/`develop`

**Packages Installed**
- `laravel/sanctum` ^4.3 — API token authentication (used in Phase 2)
- `larastan/larastan` ^3.10 — PHPStan extension for Laravel

### Changed

- `app/Models/User.php` — untouched; uses default Laravel integer PK (will be migrated to ULID in Phase 2)

---

## [Milestone 0] — Specification Phase — 2026-06-25

All foundational specification documents written and committed. No application code.

**Documents created:**
- `specs/core/domain-model.md` — 18 entities with fields, relationships, lifecycle, Laravel notes
- `specs/product/mvp-workflow.md` — 13-step MVP workflow with acceptance criteria
- `docs/technical/Architecture.md` — module structure, event chain, queue topology
- `docs/technical/Database.md` — data classification, multi-tenancy, indexing, retention
- `docs/technical/AI.md` — provider abstraction, 6 MVP analysts, prompt versioning, FakeAiProvider pattern
- `docs/technical/DigitalTwin.md` — definition, purpose, competitive moat
- `docs/technical/DecisionEngine.md` — opportunity scoring formula, explainability, decision lifecycle
- `FOUNDING_PRINCIPLES.md` — 10 engineering principles with self-tests
- `ROADMAP.md` — 8-phase product roadmap with goals, deliverables, success criteria
- `docs/product/PRD.md` — product requirements document
- `docs/vision/FoundersBible.md` — founder vision, design partners, first use cases
- `README.md` — updated to reflect Atlas as autonomous marketing operating system
