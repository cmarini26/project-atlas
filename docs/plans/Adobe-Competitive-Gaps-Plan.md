# Adobe Competitive Gaps — Execution Plan

**Source:** [Competitors.md — Adobe section](../product/Competitors.md), 2026-08-05.
**Purpose:** Turn the gaps identified in the Adobe comparison into scoped, independently shippable tickets. The IDs below are **proposed tracker imports** so this plan can be handed to a real backlog with minimal translation.
**Rule for this document:** every ticket below traces to a gap identified by directly reading Atlas's code (`ContentGenerationAnalyst.php`, `Channel-Capability-Matrix.md`, `EditPatternDetector.php`, `ApprovalService.php`) against Adobe's publicly described capabilities — nothing here is invented from the competitive analysis alone.

## Proposed backlog import

These are ordered by what Atlas should do **next**, not by which Adobe product sounds biggest. The sequence follows Atlas's own strategy in `AGENTS.md` / `CLAUDE.md`: deepen the honest golden path before chasing breadth.

| Proposed ID | Priority | Ticket | Outcome |
|---|---|---|---|
| SCRUM-70 | ✅ Complete locally | Close the edit-pattern learning loop | Atlas's existing approval-edit learning now influences future content drafts when consistent channel-specific patterns exist. |
| SCRUM-71 | Now | Add visual asset generation to social/blog drafts | Atlas stops being text-only on its highest-visibility draft types. |
| SCRUM-72 | Next | Website Measure/Learn source decision | Choose the smallest honest website measurement source before coding. |
| SCRUM-73 | Later (after SCRUM-72) | Website Measure/Learn MVP implementation | Website stops being observation-only and gains a real feedback path. |
| SCRUM-74 | Strategic exploration | On-site conversational agent spec | Decide whether a customer-facing site agent belongs in Atlas at all. |

### Recommendation

With **SCRUM-70 now implemented locally**, the best next implementation is **SCRUM-71**. That is now Atlas's clearest remaining Adobe-comparison gap on the content-generation path.

---

## How these are ordered, and why

SCRUM-70 (edit-pattern feedback) is the smallest, most isolated, and highest-confidence win — the detection code already exists and is already tested; it just isn't consulted anywhere. SCRUM-71 and SCRUM-73 are larger, additive slices consistent with the "depth over breadth" strategy: they extend existing channels (content drafting, website observation) rather than adding new ones. SCRUM-72 is intentionally a decision ticket, because website measurement should not be guessed into existence. SCRUM-74 is explicitly **not** an implementation ticket — it's a strategic bet requiring a product decision before any code is written, included here only so it isn't lost.

| Order | Ticket | Type | Touches |
|---|---|---|---|
| 1 | SCRUM-70 — Feed edit-pattern learning back into content generation | Code | `ContentGenerationAnalyst.php`, prompt builders, `LearningService` |
| 2 | SCRUM-71 — Visual asset generation for content drafts | Code + new dependency | `ContentGenerationAnalyst.php`, new image-gen provider abstraction, `ContentAsset` model/UI |
| 3 | SCRUM-72 — Website Measure/Learn source decision | Design/product decision | `docs/product/Channel-Capability-Matrix.md`, analytics docs/specs |
| 4 | SCRUM-73 — Website Measure/Learn MVP | Code + new migration | `WebsiteConnector`, `AnalyticsProviderRegistry`, new `WebsiteAnalyticsProvider`, `LearningService` |
| 5 | SCRUM-74 — On-site conversational agent — exploration only | Design/product decision | none yet — deliberately unscoped |

---

## SCRUM-70 — Feed Detected Edit Patterns Back Into Content Generation

### Title
Close the edit-pattern learning loop: content generation should read previously detected length/hashtag/price patterns, not just record them.

### Why it matters
`EditPatternDetector::detect()` (`app/Services/Learning/EditPatternDetector.php`) already detects `length_preference`, `hashtag_preference`, and `price_inclusion` patterns whenever a user edits-and-approves a recommendation, and `ApprovalService::recordApprovalLearning()` persists them as `Learning` rows. Confirmed by direct grep: **no code anywhere reads these values back** — `ContentGenerationAnalyst` and its per-channel prompt builders have zero references to `length_preference`, `hashtag_preference`, or `price_inclusion`. The detection pipeline is fully built and tested, but the loop it exists to close — "Atlas should know more about the business tomorrow than it knew today" (root `CLAUDE.md`) — never actually closes for content style. This is close to what Adobe markets as GenStudio's Brand Intelligence (learns from edits/annotations over time), except Atlas already has the detection half built and unused.

### Acceptance criteria
- [x] Before generating content for a channel type, `ContentGenerationAnalyst` (or a new collaborator it calls) queries the most recent/aggregated `Learning` rows of this pattern kind for the company, scoped per channel type where the pattern was observed.
- [x] At minimum, `length_preference` and `hashtag_preference` measurably influence the generated prompt (e.g., a documented "keep it shorter" or "avoid hashtags" instruction appended when the pattern is consistently observed across a threshold of edits — avoid overfitting to a single edit).
- [x] `price_inclusion` influences whether price/offer details are included in generated copy when the asset type supports it.
- [x] No behavior change for companies with no edit history yet — prompts remain exactly as they are today until real signal exists.
- [x] New tests prove: (a) a company with a consistent edit-pattern history produces measurably different generated content than one without, and (b) a single one-off edit does not overfit the next generation.
- [x] Full test suite passes, PHPStan level 8 clean, Pint clean, `npm run build` green.

### Estimated effort
Small–Medium (a few days). No schema change — `Learning` rows already exist with the right shape; this is a new read path plus prompt-builder wiring.

### Dependencies
None.

### Status
Implemented locally on 2026-08-10. `ContentPreferenceGuide` now aggregates `recommendation_edited_and_approved` learnings per company/channel and injects learned guidance into social, email, SMS, blog, and landing-page prompts. Verified by targeted prompt tests plus the full PHPUnit suite and production build.

Follow-up fix 2026-08-27: `ApprovalService::primaryChannel()` stored the Decision channel **id** on the learning row, but the guide matches on channel **type**, so the loop never fired against real data (the initial tests fabricated rows and masked it). `primaryChannel()` now resolves id → `Channel::type`; added one unit-level assertion on the persisted value and one end-to-end test that runs two real edited approvals through `ApprovalService` and confirms `ContentPreferenceGuide` returns guidance afterward.

### Verification steps
1. Read `EditPatternDetector.php` and `ApprovalService::recordApprovalLearning()` to confirm the exact `Learning.value` shape already stored.
2. Add a query method (e.g. `LearningService::patternsFor(Company $company, string $channelType)`) that aggregates pattern signals with a minimum-occurrence threshold before treating them as real preference, not noise.
3. Wire that method into the relevant per-channel prompt builders in `ContentGenerationAnalyst`.
4. Write tests proving the before/after prompt difference with and without sufficient edit history.
5. Run `php artisan test`, PHPStan, Pint, `npm run build`; update `docs/STATUS.md` with the result.

---

## SCRUM-72 / SCRUM-73 — Website Measure/Learn Loop

### Title
Give Website observation a real Measure/Learn path instead of `N/A` all the way down.

### Why it matters
Per `docs/product/Channel-Capability-Matrix.md`, Website is the only channel type where Draft/Approve/Execute/Measure/Learn are all `N/A` — it is purely an observation source feeding Facts/Knowledge, with no way to know whether Atlas's recommendations about the business (informed by the website crawl) ever correlate with real visitor behavior or outcomes. Adobe's stack (Real-Time CDP + Adobe Analytics) is built specifically to track this kind of behavioral signal and route it back into personalization. Atlas doesn't need a CDP to close a meaningfully smaller version of this gap: even a lightweight, privacy-conscious traffic/conversion signal tied back to the Business Brain would let recommendations eventually account for "did this content actually get visited/convert," which today only email and Meta can answer.

### Acceptance criteria

#### SCRUM-72 — Source decision
- [ ] Define the smallest honest version of "website measurement" Atlas can support without becoming a full analytics platform — e.g., a lightweight first-party tracking snippet the customer adds to their site, or an integration with a metric the customer already has (e.g., existing Google Analytics/Search Console read, if the company has one).
- [ ] Capture the decision in a short design note before implementation starts.
- [ ] Confirm the chosen source keeps Atlas within its current product identity (autonomous marketing operator for SMBs, not a full CDP/analytics suite).

#### SCRUM-73 — MVP implementation
- [ ] The chosen source produces real `ExecutionMetric`-equivalent or Fact-equivalent rows attributable to the website observation source, not simulated/empty data.
- [ ] `LearningService` gains a website-specific signal (mirroring its three existing email-specific checks) once real data exists to check against.
- [ ] `docs/product/Channel-Capability-Matrix.md`'s Website row is updated in the same change if Measure/Learn capability changes from `N/A` to a real state — per that doc's own canonical-source rule.
- [ ] Full test suite passes, PHPStan level 8 clean, Pint clean, `npm run build` green.

### Estimated effort
- **SCRUM-72:** Small design ticket.
- **SCRUM-73:** Medium–Large implementation ticket once the source is chosen.

### Dependencies
SCRUM-73 depends on SCRUM-72. **Do not start implementation until the source decision is resolved.**

### Verification steps
1. Get explicit sign-off on which measurement source to build against.
2. Confirm current state via `AnalyticsProviderRegistry`/`AnalyticsServiceProvider.php` — no website entry exists today.
3. Build the chosen provider following the existing `PostmarkAnalyticsProvider`/`MetaAnalyticsProvider` pattern (real pull + `normalize()` emitting canonical `normalised_reach`/`normalised_engagement`/`normalised_clicks` keys, per the bug already fixed once for Postmark).
4. Add the website-specific `LearningService` check.
5. Update the Channel-Capability-Matrix in the same change.
6. Run the full verification suite and update `docs/STATUS.md`.

---

## SCRUM-71 — Visual Asset Generation for Content Drafts

### Title
Add an image-generation step to content drafting — Atlas is currently text-only across every channel.

### Why it matters
`ContentGenerationAnalyst` maps every channel type to a text-only prompt (`BlogContentPrompt`, `SocialContentPrompt`, `EmailContentPrompt`, `SmsContentPrompt`, `LandingPageContentPrompt` — confirmed by direct read of `app/Services/Analyst/Content/ContentGenerationAnalyst.php`). No image or video generation exists anywhere in the pipeline. Adobe's Firefly is the single most prominent AI capability in their entire marketing stack, and social/blog/landing-page content without imagery is a materially weaker draft for a small business owner to approve. This is the largest content-completeness gap identified against Adobe, and it's additive to the existing golden path rather than a new channel.

### Acceptance criteria
- [ ] A new `ImageGenerationProvider`-style abstraction, consistent with existing provider-abstraction patterns (`EmailProvider`, `SmsProvider`) and the root `CLAUDE.md`'s "AI should be abstracted behind services/interfaces" principle — no direct coupling to one image-gen vendor.
- [ ] At minimum, social (`facebook`/`instagram`) and blog content drafts can include a generated image proposal alongside the generated copy.
- [ ] Generated images are stored as part of the `ContentAsset` (or a clearly related model) and surface in the existing approval UI (`ApproveActions.vue` / recommendation review) as a component of what's being approved — never auto-published without going through the same approval gate as copy.
- [ ] Cost/rate-limiting consideration documented — image generation is meaningfully more expensive per call than text generation; a company-level or global cap should exist before this ships broadly.
- [ ] Full test suite passes, PHPStan level 8 clean, Pint clean, `npm run build` green.

### Estimated effort
Medium–Large. New external dependency, new provider abstraction, UI changes to the approval flow, and a real product decision on which image-gen vendor/model to use (unlike text generation, this isn't already provider-abstracted anywhere in the codebase today).

### Dependencies
None technically, but benefits from being sequenced after Ticket 1 (prompt-quality feedback loop) so image generation inherits the same edit-pattern-awareness rather than needing it bolted on separately later.

### Current design artifact
- [SCRUM-71-Visual-Asset-Generation-Plan.md](SCRUM-71-Visual-Asset-Generation-Plan.md) — grounded implementation plan covering current code touchpoints, first-slice scope, provider-abstraction shape, storage strategy, and rollout guardrails.

### Verification steps
1. Confirm no existing image-generation code path exists (already confirmed via grep for this analysis).
2. Design the provider abstraction and get sign-off on vendor choice before implementation.
3. Wire into `ContentGenerationAnalyst` for social/blog channel types first (narrowest honest slice, matching this repo's stated preference for depth over breadth).
4. Surface generated images in the approval UI.
5. Add cost/rate-limit guardrails and tests.
6. Run the full verification suite and update `docs/STATUS.md`.

---

## SCRUM-74 — On-Site Conversational Agent (Exploration Only, Not Scoped)

### Title
Evaluate whether an Adobe-"Brand-Concierge"-style customer-facing chat agent fits Atlas's product direction.

### Why this is different from the other three
This is not a gap in an existing Atlas capability — it's new territory Atlas has never attempted (a conversational agent embedded on the *client's own* website, talking to *their* customers, not the business owner). Adobe's Brand Concierge pattern is a genuinely different product surface, not a missing feature of something Atlas already does. Scoping this as an implementation ticket before a deliberate product decision would violate the same discipline this repo applies elsewhere (see Ticket 2's explicit "confirm before building" gate, and the historical precedent of `docs/specs/*.md` design-only milestones preceding implementation, e.g. Milestone 13/14/15).

### What this ticket actually asks for
- [ ] A product/strategy discussion: does an on-site customer-facing agent fit Atlas's "AI marketing employee for the business owner" positioning, or does it push Atlas toward a different product (a customer-service tool) outside current scope?
- [ ] If greenlit, a design-only spec (mirroring the `docs/specs/*.md` pattern) before any implementation ticket is written — no acceptance criteria or effort estimate here, deliberately, since scoping this without that decision would be premature.

### Estimated effort
Not estimated — pending the product decision above.
