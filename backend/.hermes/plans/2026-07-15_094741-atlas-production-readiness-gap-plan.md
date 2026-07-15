# Atlas Production Readiness Gap Plan

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** Close the highest-value product and operational gaps so Atlas becomes an honest, production-ready autonomous marketing system for early real customers.

**Architecture:** Keep Atlas's core loop intact — **Observe → Understand → Decide → Recommend → Prepare → Approve → Execute → Measure → Learn** — and focus on making one complete end-to-end path real before broadening channel support. Prioritize product truth, real delivery, measurement, and operational reliability over breadth.

**Tech Stack:** Laravel 13, PHP 8.3+, PostgreSQL, Redis, queues, scheduler, Vue 3, TypeScript, Inertia, Playwright, Anthropic provider abstraction, Postmark, Meta Graph API, WordPress REST API.

---

## 1. Current audited state

### 1.1 What Atlas already does well

From code inspection and tests, Atlas already has strong foundations in:

- multi-step onboarding and business discovery framing
- observation pipeline (website + Instagram at minimum)
- Business Brain / Digital Twin / Facts / Knowledge
- opportunity detection and decision engine
- recommendation generation and approval workflow
- campaign preparation and text content generation
- learning framework from approvals/rejections/metrics
- scheduler, queue-backed orchestration, and recovery paths

### 1.2 What is incomplete or inconsistent

The main gaps are:

1. **Product truth mismatch**
   - Docs/UI/backend disagree in places about whether publishing is real vs simulated.
   - Files to reconcile include:
     - `backend/resources/js/lib/channelCapability.ts`
     - `docs/reviews/Channel-Publishing-Reality-Audit.md`
     - `docs/STATUS.md`
     - approval and publishing UI copy

2. **Execution completeness is uneven**
   - Real backend implementations exist for WordPress, Meta, and Postmark paths.
   - But setup UX, capability labeling, fallback behavior, and operational validation are incomplete.

3. **Observation breadth is still narrow**
   - Website is real.
   - Instagram observation exists with manual token flow.
   - Google Business, broader social observation, and email-platform observation are not complete enough for the product promise.

4. **Creative/media is weaker than text**
   - Text generation is mature.
   - Image/media support is still best-effort crawl fallback, not a robust campaign asset system.

5. **Measurement exists as a framework, but coverage is limited**
   - Meta and Postmark analytics providers exist.
   - Broader analytics consistency and cross-channel attribution are incomplete.

6. **Operational production readiness is still behind product code maturity**
   - Infrastructure, monitoring, backup/restore, alerting, legal/compliance, and production verification still need deliberate work.

### 1.3 Current repo caveat

The repo currently has significant in-progress local changes. Do **not** batch this roadmap into those unrelated edits. Execute in isolated, verified slices and commit them separately.

---

## 2. Guiding decisions for this roadmap

### Decision A — Make one golden path truly real before broadening

The first production-ready path should be:

- **Website observation**
- **Email execution + analytics**
- **WordPress execution**

Why:
- highest value with lowest platform friction
- easiest to test end-to-end
- cleanest path to a real customer promise
- aligns with Atlas's recommendation + approval workflow

### Decision B — Product honesty is a feature, not polish

Before adding more channels, Atlas must always accurately tell the user:

- what it can observe
- what it can draft
- what it can publish automatically
- what must be manual
- what it can measure afterward

### Decision C — Separate channel breadth from channel depth

Do not claim support for many channels if most are shallow. Prefer a few channels with:

- real setup
- real publish/send
- real success/failure reporting
- real metrics
- real learning feedback

### Decision D — Production-readiness work is mandatory, not optional

This plan includes both product and operations. A feature-complete app with no production environment, alerts, or restore path is not production ready.

---

## 3. Proposed phases

## Phase 0 — Truth audit + capability matrix

**Objective:** Make the product's stated behavior match the real codebase.

**Outcome:** Atlas has one authoritative source of truth for channel capabilities and product messaging.

### Deliverables

- A channel capability matrix covering, per channel type:
  - observation support
  - content generation support
  - approval support
  - execution support
  - analytics support
  - learning support
- Docs/UI/backend alignment around real vs simulated behavior
- Removal of stale product claims

### Files likely to change

- `backend/resources/js/lib/channelCapability.ts`
- `backend/resources/js/Pages/App/Settings.vue`
- `backend/resources/js/Pages/App/Publishing.vue`
- `backend/resources/js/Components/Recommendations/ApproveActions.vue`
- `backend/app/Http/Controllers/App/RecommendationController.php`
- `docs/reviews/Channel-Publishing-Reality-Audit.md`
- `docs/STATUS.md`
- `docs/plans/Version-1.0-Roadmap.md`

### Tasks

#### Task 0.1: Create a canonical channel capability table
**Objective:** Define the truth once and reuse it everywhere.

**Steps:**
1. Audit every current channel type and document whether it is:
   - observable
   - draftable
   - executable
   - measurable
   - learnable
2. Add/update one canonical doc under `docs/` describing this matrix.
3. Ensure UI capability labels are derived from this truth, not stale assumptions.

**Verification:**
- [ ] Every customer-visible capability label matches real backend behavior.
- [ ] No doc claims a live channel where only fallback/simulation exists.

#### Task 0.2: Reconcile stale docs with actual provider registrations
**Objective:** Fix the current code-vs-doc mismatch.

**Steps:**
1. Compare `PublisherServiceProvider` and real providers against the publishing reality docs.
2. Update docs to distinguish:
   - implemented in code
   - enabled in product UX
   - production validated
3. Call out any channel that is implemented but not yet operationally proven.

**Verification:**
- [ ] `docs/STATUS.md` reflects current code truth.
- [ ] Publishing-related docs no longer contradict provider registration.

#### Task 0.3: Standardize user-facing “manual vs automatic” language
**Objective:** Make Atlas honest at the approval moment.

**Steps:**
1. Audit recommendation approval screens and publishing history screens.
2. Replace vague copy like “Atlas will handle this” with precise behavior.
3. Add clear fallback language when a channel is not fully connected.

**Verification:**
- [ ] A user can tell whether a campaign will publish automatically or require manual action.

---

## Phase 1 — Finish the real Email path

**Objective:** Make email the first unquestionably real end-to-end Atlas channel.

**Outcome:** A company can connect email, approve a recommendation, send a real campaign, retrieve real metrics, and feed those results back into learning.

### Gaps to close

- No complete customer-facing email channel setup flow
- Recipient/list configuration needs product UX, not just provider code
- Need production validation for Postmark path
- Need explicit execution/analytics UX for email outcomes

### Files likely to change

- `backend/app/Http/Controllers/App/SettingsController.php`
- `backend/resources/js/Pages/App/Settings.vue`
- `backend/resources/js/Pages/App/Recommendations/Show.vue`
- `backend/resources/js/Pages/App/Publishing.vue`
- `backend/resources/js/Pages/App/Analytics/Show.vue`
- `backend/app/Services/Publishing/EmailPublisher.php`
- `backend/app/Services/Publishing/Email/PostmarkEmailProvider.php`
- `backend/app/Services/Analytics/PostmarkAnalyticsProvider.php`
- `backend/tests/Feature/Publishing/Email/*`
- `backend/tests/Feature/Analytics/*`

### Tasks

#### Task 1.1: Add product-complete email channel setup UX
**Objective:** Let a real company configure email in-app.

**Requirements:**
- create or activate an `email` channel for the company
- capture provider type (initially Postmark)
- capture sender identity
- capture recipient target or audience configuration
- store credentials safely
- ping/validate credentials on connect

**Verification:**
- [ ] A real company can configure an email channel from Settings.
- [ ] Invalid credentials fail fast and visibly.
- [ ] Valid credentials are stored and reflected in UI state.

#### Task 1.2: Validate email execution end-to-end
**Objective:** Ensure approved email recommendations actually send.

**Requirements:**
- publish path uses real `PostmarkEmailProvider`
- `Execution` state reflects success/failure correctly
- failures are visible and retry behavior is correct

**Verification:**
- [ ] Approving an email recommendation produces a real Postmark send in a non-test environment.
- [ ] Failure modes surface clearly in Publishing UI.

#### Task 1.3: Complete email analytics and learning loop
**Objective:** Email should be the first fully closed-loop Atlas channel.

**Requirements:**
- retrieve message-level metrics from Postmark
- show them in analytics UI
- ensure KPI snapshots and learning signals are created

**Verification:**
- [ ] Email send produces metrics rows.
- [ ] Campaign analytics page renders those results.
- [ ] Learning records are created from metric snapshots.

---

## Phase 2 — Make WordPress publishing production-ready

**Objective:** Turn WordPress from “implemented publisher” into “customer-safe live channel.”

**Outcome:** A company can connect a WordPress blog, validate the connection, publish real blog campaigns, and review results/status confidently.

### Gaps to close

- connect flow stores credentials but does not validate them immediately
- publish success is not yet production-proven against a real site
- limited post-publication measurement

### Files likely to change

- `backend/app/Http/Controllers/App/SettingsController.php`
- `backend/app/Services/Publishing/WordPressPublisher.php`
- `backend/app/Services/Publishing/WordPressMediaUploader.php`
- `backend/resources/js/Pages/App/Settings.vue`
- `backend/resources/js/Pages/App/Publishing.vue`
- `backend/tests/Feature/Publishing/WordPress/*`

### Tasks

#### Task 2.1: Validate WordPress credentials at connect time
**Objective:** Don’t report “connected” without verifying the site/account.

**Requirements:**
- ping site on connect
- reject bad credentials or unreachable site
- persist only validated connections unless explicit draft/save behavior is designed

**Verification:**
- [ ] Bad WordPress credentials do not result in a false “connected” state.
- [ ] Good credentials are verified live.

#### Task 2.2: Harden publish error handling and observability
**Objective:** Make real WordPress failures understandable.

**Requirements:**
- better surfaced publish error details
- distinguish media upload failure from post creation failure
- ensure execution logs are useful to support/customer success

**Verification:**
- [ ] Publishing page shows meaningful failure reasons.
- [ ] Retryability behavior is correct.

#### Task 2.3: Define WordPress measurement strategy
**Objective:** Close at least a minimal result loop for blog campaigns.

**Options to choose from:**
- URL-level tracking via UTM and external analytics later
- initial “published successfully” only, explicitly marked as not yet performance-measured

**Verification:**
- [ ] Product truth clearly states whether WordPress is execution-only or execution+measurement.

---

## Phase 3 — Expand observation depth where it matters most

**Objective:** Make Atlas meaningfully closer to “audit a business’s online presence.”

**Outcome:** Atlas can observe beyond website + partial Instagram and generate better recommendations from more real evidence.

### Priority order
1. Google Business Profile
2. Instagram observation polish and resilience
3. Facebook observation strategy
4. Other social/data connectors later

### Files likely to change

- `backend/app/Services/Observatory/Connectors/*`
- `backend/app/Providers/ConnectorServiceProvider.php`
- `backend/app/Services/Discovery/DiscoveryPlanner.php`
- `backend/app/Http/Controllers/App/SettingsController.php`
- `backend/resources/js/Pages/App/Settings.vue`
- related tests under `backend/tests/Feature/Discovery/*`, `Brain/*`, `Observatory/*`

### Tasks

#### Task 3.1: Ship Google Business Profile observation
**Objective:** Add a high-value local/business-presence source.

**Requirements:**
- implement/finish Google Business public observation path
- surface results in Business Brain / discovery / status
- integrate into opportunity and marketing-health reasoning

**Verification:**
- [ ] A declared GBP source can be observed for a real company.
- [ ] Resulting Facts/Knowledge feed recommendation logic.

#### Task 3.2: Improve source coverage reporting in UI
**Objective:** Show exactly what Atlas is learning from and what still needs connection.

**Requirements:**
- business-level coverage summary
- connector state visibility
- clearer “declared vs connected vs observed” distinctions

**Verification:**
- [ ] A user can see which sources Atlas has real intelligence from.

---

## Phase 4 — Upgrade media and creative asset quality

**Objective:** Move Atlas from text-first recommendation system to stronger campaign creator for visually-driven businesses.

**Outcome:** Recommendations and campaigns have materially better image/media support.

### Gaps to close

- current image support is crawl-first fallback only
- no asset library or strong recommendation-to-media matching
- no generation path for missing creative

### Files likely to change

- `backend/app/Services/Analyst/Content/ContentGenerationAnalyst.php`
- `backend/app/Services/Observatory/Connectors/Website/*`
- `backend/app/Models/ContentAsset.php`
- possibly new media-selection or asset-resolution services
- recommendation/campaign UI files
- tests for content generation and media fallback

### Tasks

#### Task 4.1: Improve media resolution strategy
**Objective:** Use more relevant images for campaigns.

**Requirements:**
- score/select crawl images better
- prefer topic-specific or page-specific media where possible
- avoid generic/logo fallback where possible

**Verification:**
- [ ] Visual channels receive more relevant media than “first image from recent crawl.”

#### Task 4.2: Add a generated-image fallback strategy
**Objective:** Ensure visual campaigns are not blocked by missing assets.

**Requirements:**
- define when Atlas can generate images vs require user asset upload
- preserve approval and auditability

**Verification:**
- [ ] Visual campaign path has a documented and testable fallback when no suitable media exists.

---

## Phase 5 — Unify execution and analytics by channel

**Objective:** Make campaign results easier to reason about across channels.

**Outcome:** Atlas has a coherent execution + measurement model rather than a collection of per-provider behaviors.

### Tasks

#### Task 5.1: Create a channel execution truth table in code
**Objective:** Standardize what “done” means for each channel.

**Requirements:**
- define per-channel support levels
- define required credentials/config
- define measurable outputs
- define learning eligibility

**Verification:**
- [ ] Every channel type has an explicit lifecycle contract.

#### Task 5.2: Normalize analytics presentation
**Objective:** A user should see comparable result summaries across channels.

**Requirements:**
- unify KPI labels where possible
- separate provider-specific metrics from normalized campaign metrics
- show “measurable” vs “not measurable yet” honestly

**Verification:**
- [ ] Analytics UI does not imply unsupported attribution or comparability.

---

## Phase 6 — Production infrastructure and operational readiness

**Objective:** Make Atlas actually safe to run for real customers.

**Outcome:** Atlas is not just feature-ready; it is operationally ready.

### Required workstreams

#### Task 6.1: Production environment
**Objective:** Deploy Atlas to a real environment.

**Requirements:**
- production server/environment
- domain + SSL
- app config and secrets management
- queue worker and scheduler setup

**Verification:**
- [ ] Production URL is live over HTTPS.
- [ ] queue workers and scheduled jobs are active and observable.

#### Task 6.2: Monitoring and alerting
**Objective:** Know when Atlas breaks before customers tell you.

**Requirements:**
- app error tracking
- failed job alerting
- uptime monitoring
- basic operational dashboard

**Verification:**
- [ ] failed queue jobs surface automatically
- [ ] outages generate alerts

#### Task 6.3: Backup and restore
**Objective:** Protect customer data.

**Requirements:**
- scheduled backups
- restore drill
- retention policy

**Verification:**
- [ ] one successful restore drill documented

#### Task 6.4: Legal / policy / customer trust
**Objective:** Be safe to onboard real external customers.

**Requirements:**
- privacy policy
- terms of service
- support contact flow
- data deletion/export policy if promised publicly

**Verification:**
- [ ] public legal pages exist and match product behavior.

---

## 4. Suggested execution order

### Wave 1 — Foundation for honest beta
1. Phase 0 — truth audit + capability matrix
2. Phase 1 — real email path
3. Phase 2 — WordPress validation/hardening
4. Phase 6.1 / 6.2 / 6.3 / 6.4 — production ops baseline

### Wave 2 — Product depth
5. Phase 3 — source coverage expansion (GBP first)
6. Phase 5 — execution/analytics unification
7. Phase 4 — richer media and creative quality

### Wave 3 — Broader channel expansion
8. deepen Meta publish + measure path
9. add other supported channels only after truthfully complete slices exist

---

## 5. Recommended milestones and success criteria

## Milestone A — Private beta readiness

**Must be true:**
- product truth is aligned
- one real channel loop (email) works end-to-end
- WordPress is safe/validated if exposed
- production infra exists
- monitoring/backups/legal baseline exists

**Success criteria:**
- 1–3 real design-partner customers can onboard and receive value without hand-holding through system failures

## Milestone B — First truly complete Atlas loop

**Must be true:**
- website observation is reliable
- email send is real
- email analytics are real
- learning from metrics is visible

**Success criteria:**
- recommendation → approval → send → measure → learn works live for email

## Milestone C — Broader presence intelligence

**Must be true:**
- Atlas can observe more than website + partial Instagram
- Google Business Profile is live
- source coverage is visible to users

**Success criteria:**
- a business with website + GBP + Instagram yields better recommendations than website-only

---

## 6. Risks and tradeoffs

### Risk 1: Trying to “support every channel” too early
**Mitigation:** finish one golden path first.

### Risk 2: Product truth continues to drift from implementation
**Mitigation:** capability matrix becomes required update for every channel-related change.

### Risk 3: Real integrations exist in code but not in UX or ops
**Mitigation:** define “implemented” vs “enabled” vs “production-validated” explicitly.

### Risk 4: Creative quality lags behind recommendation quality
**Mitigation:** schedule media improvements after one complete execution loop is live, not before.

### Risk 5: Production-readiness work gets deprioritized behind feature work
**Mitigation:** treat infrastructure/legal/monitoring as gating milestones, not optional chores.

---

## 7. Concrete next actions

## Recommended immediate next 5 tasks

### Task N1: Write the channel capability matrix
**Files:**
- Create: `docs/product/Channel-Capability-Matrix.md`
- Modify: `docs/STATUS.md`
- Modify: `docs/reviews/Channel-Publishing-Reality-Audit.md`

**Verification:**
- [ ] Matrix exists and matches current code.

### Task N2: Design and build complete Email Settings UX
**Files:**
- Modify: `backend/app/Http/Controllers/App/SettingsController.php`
- Modify: `backend/resources/js/Pages/App/Settings.vue`
- Add/update tests under `backend/tests/Feature/App/`

**Verification:**
- [ ] Real email channel can be configured and validated.

### Task N3: Validate WordPress on connect
**Files:**
- Modify: `backend/app/Http/Controllers/App/SettingsController.php`
- Possibly modify: `backend/app/Services/Publishing/WordPressPublisher.php`
- Add tests under `backend/tests/Feature/Publishing/WordPress/`

**Verification:**
- [ ] Invalid WordPress setup no longer appears connected.

### Task N4: Create a production-readiness checklist doc
**Files:**
- Create: `docs/ops/Production-Readiness-Checklist.md`

**Include:**
- deployment
- scheduler
- workers
- secrets
- monitoring
- backups
- restore drill
- legal pages

**Verification:**
- [ ] Checklist is executable and owner-assigned.

### Task N5: Add “manual vs automatic” product states to recommendation/publishing UI
**Files:**
- Modify: `backend/resources/js/Pages/App/Recommendations/Show.vue`
- Modify: `backend/resources/js/Pages/App/Publishing.vue`
- Modify: `backend/resources/js/lib/channelCapability.ts`

**Verification:**
- [ ] A customer can tell exactly what Atlas will do after approval.

---

## 8. Validation strategy

For every phase, require:

- targeted PHPUnit coverage
- frontend tests where UI truth changes
- Playwright smoke test updates if onboarding/approval/execution UX changes
- build passes: `npm run build`
- backend tests pass: `php artisan test`
- where possible, one real provider verification in a non-mocked environment

---

## 9. Final recommendation

Do **not** position Atlas as a broad, fully automated omnichannel marketing platform yet.

Position it as:

> Atlas understands a business, finds marketing opportunities, drafts high-quality campaigns, and can execute the channels that are truly connected — starting with the most reliable ones.

That positioning is honest, aligned with the strongest parts of the codebase, and creates the shortest path to a production-ready product.
