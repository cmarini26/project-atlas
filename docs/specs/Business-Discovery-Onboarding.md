# Business Discovery Onboarding — Design Specification

**Milestone:** 15
**Status:** Design only — no code exists yet
**Read first:** `docs/specs/Marketing-Intelligence.md`, `docs/specs/Marketing-Health.md`, `docs/specs/Google-Business-Intelligence.md`, `specs/core/domain-model.md`, `docs/plans/Version-1.0-Roadmap.md`, `docs/STATUS.md`
**Companion:** `docs/plans/Milestone-15-Business-Discovery-Onboarding-Plan.md` — sequences this spec into implementation phases; read this document first.

---

## 1. What This Is

Onboarding today (`app/Http/Controllers/OnboardingController.php`) is a website-first funnel: company name → website URL (which immediately queues a crawl) → a generic "where do you market today?" checklist → wait. It works, but it embodies a narrower idea of what Atlas is than the product actually is: a website crawl is *one* way Atlas learns about a business, not *the* way. Milestone 12 (Instagram) and Milestone 14 (Google Business, designed) already prove Atlas can learn from more than a website — onboarding hasn't caught up.

This milestone redesigns onboarding around a different idea: **teach Atlas about the business first, in full, as a human would explain it to a new employee — then let Atlas go discover what it can, from everything it's been told, all at once.** Concretely, that means separating *declaring* a business's marketing assets (fast, no credentials, no waiting) from *discovering* what's true about them (potentially slow, per-source, resilient to partial failure) — two phases that are currently conflated into one linear website-crawl-blocks-everything flow.

**This is a UX and orchestration redesign, not a new observation capability.** Every connector this milestone's Discovery stage runs already exists (`WebsiteConnector`) or is already designed (`InstagramConnector`/`GoogleBusinessConnector` per Milestones 12/14) — this spec does not invent new ways to observe a business, it changes *when* and *how* observation begins relative to the user's onboarding experience, and it makes that experience honestly reflect that Atlas learns from many sources, not one.

---

## 2. UX Flow

### 2.1 The six steps

| Step | Name | What happens | Blocking? |
|---|---|---|---|
| 1 | **Company Information** | Name, industry (unchanged from today's step 1) | User input only |
| 2 | **Business Goals** | Primary goal, target audience, growth priority (new) | User input only |
| 3 | **Marketing Assets** | Which channels does this business use? One per channel type for beta (§2.4) | User input only |
| 4 | **Asset Details** | For each declared asset, the minimal identifying info needed to attempt discovery (§2.5) | User input only |
| 5 | **Atlas Discovery** | Orchestrated, resilient, multi-connector observation — the redesign's core (§4) | Automated; user watches stage-based progress, does not wait on a single blocking spinner |
| 6 | **Recommendations** | Redirect to the first ready Recommendation once one exists | Automated |

**The load-bearing change from today:** steps 1–4 are pure data collection — no crawl, no sync, no network call to any third party happens until step 4 is submitted in full. Today, submitting the website URL (step 2 of 4) immediately queues `SyncIntegration`; a slow or unreachable site is already being retried in the background while the user is still filling in the marketing-presence checklist. This spec's step 5, "Atlas Discovery," is the *only* point at which any connector runs — matching objective 5 exactly ("the user completes all onboarding before any discovery begins").

### 2.2 Step 1 — Company Information

Unchanged from today: `name` (required), `industry` (optional). No `website_url` here — website is no longer special-cased as part of company identity; it's declared in step 3 like every other asset.

### 2.3 Step 2 — Business Goals (new)

Fields:
- **Primary goal** — a fixed select: `increase_sales`, `build_awareness`, `generate_leads`, `drive_foot_traffic`, `build_loyalty`, `other`
- **Target audience** — free text, optional (e.g. "collectors of Silver Age Marvel comics in the Northeast")
- **Growth priority / timeframe** — optional select: `this_month`, `this_quarter`, `this_year`, `ongoing`

**Where this data goes** (see §5.1 for the full mechanism): rather than inventing a new `Company`-level column or a parallel storage path, these answers are recorded as an ordinary `Observation` with `source_type: 'manual'` (an enum value that already exists — no migration needed) and processed through the exact same `Observation → Fact → Knowledge` pipeline every other data source uses. A human telling Atlas its goals directly is exactly what `'manual'` was already designed to represent (`specs/core/domain-model.md`'s `Observation.source_type` table: `'manual'` — no exceptional handling). This means Business Goals participates in Business Brain assembly, Knowledge synthesis, and (via existing `marketing.*`/`business.*`-style Facts) future Opportunity/Decision reasoning with zero special-case code anywhere downstream — the same "one pipeline, not a parallel one" discipline this project has applied to every prior milestone.

### 2.4 Step 3 — Marketing Assets

A checklist of `MarketingChannelType` values (the enum already exists, unchanged: `Website`, `Email`, `Instagram`, `Facebook`, `LinkedIn`, `X`, `YouTube`, `TikTok`, `GoogleBusinessProfile`, `Events`, `Print`, `Other`). The user checks every channel their business actually uses. **At least one is required** — a deliberate loosening from today's flow, which hard-requires a website URL. A business that only has an Instagram account and no website is a real business Atlas should still be able to onboard.

**"One asset per channel for beta"** (objective 3): the step renders one checkbox per `MarketingChannelType`, not a repeatable list — a business can declare one Website, one Instagram, one Google Business Profile, etc. `specs/core/marketing-presence.md` already documents that `marketing_channels` has no database-level uniqueness constraint on `(company_id, type)`, by design, so a business *could* later add a second Instagram account from the Settings → Marketing Presence page post-onboarding (that flexibility is preserved, unchanged). This spec only constrains the *onboarding wizard's UI* to one-per-type — a scope decision for the redesigned wizard, not a new domain-level restriction.

### 2.5 Step 4 — Asset Details

For each channel type checked in step 3, the wizard renders exactly one matching sub-form, collecting only enough information to *identify* the asset — never a password, API key, or access token. Per objective 4:

| Asset type | Fields collected |
|---|---|
| **Website** | URL + platform (select: `WordPress`, `Squarespace`, `Shopify`, `Webflow`, `Wix`, `Custom`, `Other`, `Unknown`) |
| **Instagram** | Profile URL |
| **Facebook** | Page URL |
| **LinkedIn** | Company URL |
| **Google Business Profile** | URL *or* business name (either is sufficient — see §4.2 for why) |
| **Email, YouTube, TikTok, X, Events, Print, Other** | A label/description only (e.g. "Monthly postcard mailer") — no identifying URL exists for these in a form Atlas can act on yet; declared for Marketing Presence Coverage purposes only, exactly as today |

**Extensibility (objective 9):** this table is not hardcoded per-type Vue logic — it's a small, declarative schema (`AssetFieldSchema`, one entry per `MarketingChannelType`, §5.4) that the Asset Details step renders generically. Adding a ninth asset type in a future milestone means adding one schema entry, not redesigning this step.

**Persistence:** each submitted asset becomes one `MarketingChannel` row via the existing, unchanged `MarketingPresenceService::declare()` — the exact same call today's Marketing Presence settings page and onboarding step 3 already make. The asset-specific structured fields (e.g. website's `platform` choice) are stored in `MarketingChannel.metadata` (an existing, currently-unused `json` column — no migration needed for this part) and the identifying URL/name goes in the existing `handle_or_url` column.

### 2.6 Step 5 — Atlas Discovery

The redesign's core, detailed fully in §4. The user sees a **stage-based** progress experience — Discover → Analyze → Understand → Recommend (objective 8) — not a list of individual connector jobs or a single ambiguous spinner. Per-asset detail is available (which assets succeeded, which are still pending, which will need a later connection step) but the primary visual language is the four stages, because that's what a business owner actually cares about ("is Atlas figuring out my business yet"), not "did the Instagram Graph API call return a 200."

### 2.7 Step 6 — Recommendations

Once at least one `Recommendation` exists in `pending` status for the company, the wizard's final step redirects to it — the same outcome as today's `Status.vue` redirect, just reframed as the wizard's own terminal step rather than a separate "status page" bolted onto the end.

---

## 3. Domain Changes

### 3.1 `marketing_channels.integration_id` (new, nullable)

**A real gap, found by tracing the existing code, not invented for this spec:** `MarketingPresenceService::link()` (`app/Services/MarketingPresence/MarketingPresenceService.php:153`) only accepts a `Channel` (a *publishing* destination) to mark a `MarketingChannel` as connected. But Instagram Observation (Milestone 12) and Google Business Intelligence (Milestone 14, designed) both connect via an `Integration` (an *observation* source) — neither creates a `Channel` at all. Under today's code, **a declared Instagram `MarketingChannel` from onboarding can never become `is_connected = true` via observation alone**, even after a real, working Instagram `Integration` exists for that company — `link()` has no path that accepts an `Integration`.

This milestone needs that gap closed, because Discovery (§4) is precisely the moment a declared asset's `Integration` starts existing, and the UI needs to be able to say "this one's connected now." Fix: add `marketing_channels.integration_id` (nullable, FK to `integrations`, `nullOnDelete()`) alongside the existing `channel_id`, and generalize the "connected" scope (`MarketingChannel::scopeConnected()`, currently `whereNotNull('channel_id')->where('is_connected', true)`) to `(whereNotNull('channel_id') OR whereNotNull('integration_id')) AND is_connected = true`. `MarketingPresenceService` gains a parallel `linkIntegration(MarketingChannel $channel, Integration $integration): MarketingChannel` method, mirroring `link()`'s exact shape (tenant-match guard, event dispatch) but setting `integration_id` instead of `channel_id`.

### 3.2 New tables: `discovery_runs` and `discovery_connector_attempts`

Detailed fully in §4.3. No existing table's schema changes beyond §3.1.

### 3.3 No changes needed to `observations.source_type`, `integrations.type`, or `opportunities.type`

Business Goals reuses the existing `'manual'` source type (§2.3). Asset discovery reuses `'website_crawl'` (existing), `'instagram'` (existing, Milestone 12), and `'google_business'` (Milestone 14, designed but not yet implemented — this milestone's plan sequences around that dependency, see the companion plan's Phase ordering). No new Opportunity types are introduced by this milestone; it changes *how observation begins*, not what Atlas does with the resulting Facts.

---

## 4. Discovery Orchestration

### 4.1 What "Discovery" coordinates

Once step 4 is submitted, onboarding is, by definition, complete (objective 5) — every `MarketingChannel` row for the company already exists. Discovery's job is to look at that full set of declared assets and, **for each one that can be observed without additional credentials the wizard never collected**, start observing it immediately.

### 4.2 Which assets can auto-discover during onboarding, and which can't

This is the single most important, concrete design decision in this spec, and it follows directly from what each connector actually requires (per Milestones 12 and 14):

| Asset type | Can Discovery auto-run it from onboarding's Asset Details data alone? | Why |
|---|---|---|
| **Website** | **Yes** | `WebsiteConnector` needs only a URL — no credentials, exactly as today. |
| **Google Business Profile** | **Yes — via a new, lighter connector this spec adds to Milestone 14's design** | Google's **Places API** (a public, API-key-only surface — distinct from the OAuth-gated Business Profile Management API Milestone 14 originally designed around) can look up a business's public name, address, category, hours, photos, rating average, and review count from just a business name or Maps URL, **with no owner authentication at all**. This is exactly the shape of information objective 4 asks onboarding to collect ("Google Business URL or business name" — not a token). See §4.2.1. |
| **Instagram** | **No — declared, pending connection** | Instagram's Graph/Basic Display API has no public, no-auth surface for the profile data Milestone 12 captures; a real access token is required, and onboarding's Asset Details step deliberately never collects one (objective 4 only asks for a profile URL). The `MarketingChannel` row exists (`is_connected: false`); the user connects it for real later from Settings, exactly as Milestone 12 Phase 1 already designed that flow. |
| **Facebook** | **No — declared, pending connection** | Same reasoning as Instagram; Facebook Graph API requires a token. |
| **LinkedIn** | **No — declared, pending connection** | LinkedIn's Company API requires OAuth; no public no-auth equivalent exists. |
| **Email, YouTube, TikTok, X, Events, Print, Other** | **No — declared only** | No connector exists for these at all yet (per Milestone 13's own "future connectors" list); declared for Marketing Presence Coverage purposes only. |

This table is exactly why objective 7's "pending connectors should not block onboarding completion" is stated as a requirement, not an aspiration — it is the *expected, common case* for a typical business declaring 3–4 assets in step 3 that only 1–2 of them (Website, and Google Business Profile once §4.2.1 ships) can actually run automatically during Discovery, and that is fine. The pending ones simply wait for the user to visit Settings later, unchanged from how Instagram connection already works today.

#### 4.2.1 A necessary addendum to Milestone 14's design

`docs/specs/Google-Business-Intelligence.md` designed `GoogleBusinessConnector` around the OAuth-gated Business Profile Management API, because that's what's required for *owner-only* data (Q&A, precise `owner_reply_rate`, and any future write capability). This spec adds a second, complementary connector, `GoogleBusinessPublicConnector`, using the Places API's public, API-key-only surface — no user OAuth, no business-owner verification, works the moment a business name or Maps URL is known. Both connectors write into the same `google_business.*` Fact namespace (`docs/specs/Google-Business-Intelligence.md` §4.2); Facts from the public connector are stored with lower confidence values (fewer guaranteed-accurate fields, no owner-verified freshness guarantee) than the OAuth connector's, and are superseded by the OAuth connector's Facts the moment a company connects for real — the existing `FactService` supersession mechanism already handles "a newer, better-sourced Fact replaces an older one" with zero new code. This addendum is scoped fully in the companion plan's Phase 2; it does not change anything else in Milestone 14's design.

### 4.3 Orchestration data model

**`DiscoveryRun`** — one row per onboarding completion (and, in the future, any re-discovery trigger — e.g. "re-scan my business" from Settings, not built by this milestone but not precluded by this shape either):

| Column | Type | Notes |
|---|---|---|
| `id` | ulid | PK |
| `company_id` | ulid | FK |
| `stage` | enum | `discovering`, `analyzing`, `understanding`, `recommending`, `completed`, `completed_with_errors` — see §4.4 |
| `started_at` | timestamp | |
| `completed_at` | timestamp | nullable |

**`DiscoveryConnectorAttempt`** — one row per asset Discovery actually attempts to run (i.e., excludes the "declared only, no connector exists" rows from §4.2's table entirely — those never get an attempt row, they're just `MarketingChannel`s with `is_connected: false` and no further tracking):

| Column | Type | Notes |
|---|---|---|
| `id` | ulid | PK |
| `discovery_run_id` | ulid | FK |
| `company_id` | ulid | FK (denormalized, matching `CatalogItem`'s existing denormalization precedent for query performance) |
| `marketing_channel_id` | ulid | FK — which declared asset this attempt is for |
| `connector_type` | enum | `website_crawl`, `google_business_public` (initially — grows as more no-auth-capable connectors ship) |
| `status` | enum | `pending`, `running`, `succeeded`, `failed`, `skipped_no_credentials` |
| `attempt_count` | tinyint | for retry backoff, mirroring `ProcessObservation`'s existing `$tries`/`$backoff` convention |
| `observation_id` | ulid | nullable FK — set once a real `Observation` exists |
| `error_message` | text | nullable |
| `started_at` / `completed_at` | timestamp | nullable |

**Crucially, `DiscoveryRun`/`DiscoveryConnectorAttempt` are a pure observability and orchestration-trigger layer — they never gate or block the real pipeline.** The existing `Observation → ProcessObservation → ObservationProcessed → TriggerOpportunityDetection → DetectOpportunities` chain (`specs/core/domain-model.md`'s event table, confirmed still exactly as designed) is completely unchanged and fires per-Observation, independent of how many other connector attempts in the same `DiscoveryRun` are still pending or retrying. This is the same non-invasive relationship `MarketingHealthService` already has to that pipeline (`recompute()` called alongside `KnowledgeService::synthesizeForCompany()`, never gating it) — Discovery orchestration follows the identical pattern, not a new one.

### 4.4 The four stages, precisely

The stage-based progress UI (objective 8) needs each stage to mean something concrete and observable, not a vague label:

- **Discover** — `DiscoveryConnectorAttempt` rows exist and are `pending`/`running`. Maps to: connectors are fetching data.
- **Analyze** — at least one attempt has `succeeded` (an `Observation` exists) and its `ProcessObservation` job is running or has produced Facts. Maps to: Atlas is extracting structured facts from what it found.
- **Understand** — `KnowledgeService::synthesizeForCompany()` has run at least once for this company since Discovery started (i.e., at least one Knowledge entry's `generated_at` is after `DiscoveryRun.started_at`) and `DigitalTwin.status` has reached `active`. Maps to: Atlas has synthesized a coherent picture, not just scattered facts.
- **Recommend** — at least one `Recommendation` exists in `pending` status. Maps to: Atlas has something to show the user.

`DiscoveryRun.stage` is computed and updated by a small set of new event listeners (§6) reacting to *existing* events (`ObservationRecorded`, `ObservationProcessed`, `KnowledgeSynthesized`, `RecommendationCreated`) — no new events are needed to drive the stage machine itself, only to update `DiscoveryConnectorAttempt` rows' own per-connector status (§6).

`DiscoveryRun.stage` reaches `completed` once at least one connector attempt succeeded AND the Recommend stage's condition is met, OR `completed_with_errors` if every attempted connector ultimately failed but the run is no longer waiting on anything (all attempts reached a terminal state) — this second outcome is honest, not a failure state to hide: it means "Atlas tried what it could, and none of it worked (e.g. every declared website was unreachable), here's what to do next," matching the existing `OnboardingStatusController`'s precedent of surfacing failure states plainly (`ai_failed`, `pipeline_stalled`) rather than leaving the user in an infinite spinner.

---

## 5. Event Flow

### 5.1 Business Goals → Fact (step 2, at submission time — not deferred to Discovery)

```
Step 2 form submit ──▶ OnboardingController::saveBusinessGoals()
        ──▶ ObservationService::record() with source_type: 'manual', source_identifier: 'onboarding_goals'
                ──▶ fires ObservationRecorded (existing event, unchanged)
                        ──▶ DispatchObservationProcessing listener (existing, unchanged) ──▶ ProcessObservation job
                                ──▶ AnalystRegistry::resolve() ──▶ new OnboardingGoalsAnalyst (deterministic, ObservationAnalyst)
                                        ──▶ Facts: business.primary_goal, business.target_audience, business.growth_priority
```

This runs immediately on step 2's submission, not gated behind step 4 — it's a human-provided fact, not a discovery result, so there's no reason to wait. It is technically the *first* thing that flows through the Observation pipeline in the new onboarding, which is a deliberate signal: Atlas starts learning the moment the user starts telling it things, not only once a connector runs.

### 5.2 Onboarding completion → Discovery start

```
Step 4 form submit ──▶ OnboardingController::completeAssetDetails()
        ──▶ persists all declared MarketingChannel rows (existing MarketingPresenceService::declare(), unchanged)
        ──▶ DiscoveryOrchestrator::start($company)   (new)
                ├──▶ creates DiscoveryRun (stage: discovering)
                ├──▶ for each MarketingChannel with an auto-runnable connector (§4.2):
                │        creates DiscoveryConnectorAttempt (status: pending)
                │        dispatches the matching sync job (SyncIntegration for website_crawl;
                │        a new SyncGoogleBusinessPublic job for google_business_public)
                └──▶ for each MarketingChannel with no auto-runnable connector:
                         no attempt row created — remains is_connected: false, waiting on Settings
        ──▶ redirects to the wizard's step 5 (Atlas Discovery) progress view
```

### 5.3 Per-connector-attempt completion → stage advancement

```
SyncIntegration / SyncGoogleBusinessPublic job completes
        ──▶ fires IntegrationSyncCompleted (existing event, unchanged)
        ──▶ NEW listener: UpdateDiscoveryConnectorAttempt
                ──▶ marks the matching DiscoveryConnectorAttempt succeeded/failed
                ──▶ if the Integration's connector requires credentials the wizard didn't
                    collect and genuinely cannot run (shouldn't happen for the two
                    auto-runnable types, defensive only) — marks skipped_no_credentials

(the underlying Observation → Fact → Knowledge → Opportunity chain fires exactly as
 in §4.3's existing, unchanged event chain, completely independent of the above)

ObservationRecorded / ObservationProcessed / KnowledgeSynthesized / RecommendationCreated
        ──▶ NEW listener: AdvanceDiscoveryRunStage
                ──▶ recomputes DiscoveryRun.stage per §4.4's precise conditions
                    (idempotent — recomputing to the same stage is a no-op)
```

### 5.4 Asset field schema (drives Step 4's generic rendering, objective 9)

```php
readonly class AssetFieldSchema
{
    /** @param list<AssetField> $fields */
    public function __construct(
        public MarketingChannelType $type,
        public array $fields,
        public bool $canAutoDiscover,      // does an onboarding-runnable connector exist for this type
        public ?string $connectorType,     // 'website_crawl' | 'google_business_public' | null
    ) {}
}
```

One instance per `MarketingChannelType`, registered in a small `AssetFieldSchemaRegistry` — the Asset Details step's Vue component and `DiscoveryOrchestrator` both read this registry generically rather than branching on type by name in multiple places. Adding a future asset type (e.g. once a no-auth-capable connector exists for a platform not listed here) means adding one registry entry, not touching the wizard or the orchestrator.

---

## 6. Error Handling and Retry Behavior

Mirrors the retry/backoff conventions already established by `ProcessObservation` (`$tries = 3`, `$backoff = [30, 120]`) and `OnboardingStatusController`'s existing stale-observation retry pattern — this milestone does not invent a new retry philosophy, it extends the existing one to the connector-attempt layer:

- **Per-attempt retry:** `DiscoveryConnectorAttempt.attempt_count` increments on each try; the same backoff schedule as `ProcessObservation` applies before a re-dispatch. After exhausting retries, the attempt is marked `failed` with `error_message` populated — never left ambiguously `running` forever.
- **Whole-run resilience (objective 7, the load-bearing requirement):** `DiscoveryRun` never requires every attempt to succeed to progress. The Recommend stage's condition (§4.4) is satisfiable the moment *any single* connector attempt's Observation has flowed all the way through to a Recommendation — a company whose only working asset was their website still gets recommendations exactly as fast as one whose Instagram, Facebook, and Google Business Profile all also happened to auto-discover successfully (which, per §4.2, won't happen in beta anyway — Instagram/Facebook never auto-run).
- **Stall detection:** mirrors `OnboardingStatusController`'s existing 90-second-since-last-activity heuristic, generalized from "the one Integration" to "any `DiscoveryConnectorAttempt` still `pending`/`running` past a configurable threshold with no queue worker apparently processing it" — surfaced in the progress UI as an honest "this is taking longer than expected" state, not a silent infinite spinner, matching the existing `pipeline_stalled` precedent exactly.
- **All-failed outcome:** if every attempted connector ultimately fails (e.g. the declared website is unreachable and no Google Business Profile was declared), `DiscoveryRun.stage` becomes `completed_with_errors` and the UI offers a clear next action (try a different URL, or proceed to the dashboard with a note that Atlas is still learning) — never a dead end.

---

## 7. Migration Strategy

- `marketing_channels` gains `integration_id` (nullable, FK to `integrations`, `nullOnDelete()`) — a single-column `ALTER TABLE` addition to an existing table with real data by the time this ships (Milestone 11 already shipped); a straightforward, low-risk addition, not a backfill (existing rows simply have `integration_id: null`, correctly reflecting "not connected via an Integration," which is true for all of them today).
- Two new tables, `discovery_runs` and `discovery_connector_attempts`, `char(26)` ULID primary keys and `company_id` FK with `cascadeOnDelete()`, per the established convention.
- No changes to `observations.source_type`, `integrations.type`, or `opportunities.type` (§3.3).
- No backfill of historical `DiscoveryRun` data for companies that onboarded before this ships — their onboarding history simply predates this tracking layer, the same "history begins the day this ships" precedent already accepted for `MarketingHealthSnapshot` (Milestone 13) and this milestone's own `DiscoveryRun`.

---

## 8. Explicitly Out of Scope

- **Building the `GoogleBusinessConnector` (OAuth) itself** — that's Milestone 14's own scope, this spec only adds the complementary public-API connector and depends on Milestone 14's Fact-key design.
- **An in-app OAuth connect flow for Instagram/Facebook/LinkedIn/Google Business during onboarding.** All three remain "declared, connect later from Settings," unchanged from today's post-onboarding connection UX.
- **Re-discovery / "re-scan my business" as a user-facing feature.** The `DiscoveryRun` shape doesn't preclude it, but triggering a new run outside of initial onboarding completion is not designed or built here.
- **Any change to the Opportunity Engine, Decision Engine, or Marketing Health scoring formulas.** This milestone changes how and when observation begins; it does not change what Atlas does with the resulting Facts.
- **A visual progress bar with granular percentage completion.** The four-stage model (§4.4) is deliberately coarse and honest, not a fake precision indicator.
- **Removing or restricting post-onboarding Marketing Presence editing.** A company can still add a second Instagram account, edit an asset's details, or declare a new channel type at any time from Settings, exactly as today.

---

## 9. Future Phases (not designed here)

- A real in-app OAuth connect flow for Instagram/Facebook/LinkedIn during onboarding itself (would remove the "declared, pending" state for those three entirely).
- Re-discovery as a user-triggered feature, reusing the same `DiscoveryRun` shape.
- Extending the Asset Field Schema registry (§5.4) to a data-source marketplace UI, once more no-auth-capable connectors exist.
- Surfacing `DiscoveryConnectorAttempt`-level detail (not just the four-stage aggregate) as an optional "advanced" view for technically-curious users — the plain four-stage view is the only UI designed here.
