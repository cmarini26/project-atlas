# Competitive Analysis

Tracks how Atlas compares to adjacent products, so positioning and roadmap decisions are made against real capability gaps rather than assumption. One section per competitor. Add new entries below rather than replacing prior ones — this is a running log, not a point-in-time snapshot.

---

## Adobe (Adobe Experience Platform / Firefly / GenStudio / Journey Optimizer)

**Date:** 2026-08-05
**Source:** `business.adobe.com/ai.html` could not be directly fetched (JS-heavy page, repeated timeouts). This analysis is built from Adobe's own recent public announcements instead — [Adobe Summit 2026 coverage](https://www.digit.in/features/general/adobe-summit-2026-cx-enterprise-genstudio-and-firefly-updates-show-adobe-wants-ai-to-run-the-workflow.html), [Constellation Research's Summit 2026 writeup](https://www.constellationr.com/insights/news/adobe-summit-2026-agentic-orchestration-cx-creative-workflows), [Adobe's Firefly creative-agent announcement](https://news.adobe.com/news/2026/04/adobe-new-creative-agent), [Adobe's Generative AI for Enterprise Marketing page](https://business.adobe.com/ai/adobe-genai.html), and [Adobe Summit 2025 coverage](https://news.adobe.com/news/2025/03/adobe-summit-2025-adobe-ai-platform-unites-creativity-marketing) — plus a direct read of Atlas's own codebase (`ConnectorServiceProvider.php`, `PublisherServiceProvider.php`, `ContentGenerationAnalyst.php`, `docs/product/Channel-Capability-Matrix.md`). Should be re-verified against the primary Adobe page directly if/when it becomes fetchable.

### Adobe's current AI stack (as publicly described)

| Product | What it does |
|---|---|
| Adobe Firefly | Generative image/video/text creative studio — custom brand models, "agentic creativity," pro-grade editing |
| GenStudio (Performance Marketing + Content Marketing) | Brief → asset production pipeline; Brand Intelligence learns from approvals/rejections/annotations over time |
| Adobe Experience Platform (AEP) + AI Assistant | CDP with natural-language querying of customer data, segment building |
| Adobe Journey Optimizer + Journey Agent | Journey orchestration; agentic skills for experiment design (metric selection, sample size, test setup); generates email/web/landing-page/push content in one flow |
| Agent Orchestrator | Manages multiple AI agents (Adobe + third-party) across an enterprise's data/content/journeys |
| Workfront AI agents | Cross-team creative/marketing project collaboration |
| Adobe Analytics (AI-driven) | Anomaly detection, attribution, intelligent alerts on behavioral data |

### Overlap with Atlas

- **Generative content drafting** — Firefly/GenStudio ↔ Atlas's `ContentGenerationAnalyst` (blog/email/social/SMS/landing-page prompts).
- **Explainability/feedback learning** — Adobe's Brand Intelligence (learns from approve/reject) ↔ Atlas's `LearningService` and the CLAUDE.md-mandated principle that every recommendation must answer "why now / why this / why this channel / why expect to work."
- **Journey/channel content generation** — Journey Optimizer's one-flow email/web/push generation ↔ Atlas's per-channel content prompts.
- **Moving toward agentic execution** — Adobe's Journey Agent/Agent Orchestrator ↔ Atlas's Observe→Understand→Decide→Recommend→Prepare→Approve→Execute→Measure→Learn loop.

### Where Atlas is fundamentally different (not just smaller)

- **Adobe assumes an existing customer base and first-party data.** It is CDP-first — a business feeds it behavioral/transactional data at scale, and it personalizes/orchestrates against that. Atlas assumes the opposite starting point: an SMB with no CDP and often no marketing team, where Atlas's job is to *observe the business itself* (website, socials, store) to build the Business Brain from nothing. This is core differentiation, not a gap to close.
- **Adobe is a toolkit operated by marketers; Atlas is meant to replace the need for one.** Adobe's agents assist a human operator inside a multi-team enterprise workflow (Workfront, brief approvals). Atlas's approval gate is deliberately the *only* human touchpoint, per the product philosophy in the root `CLAUDE.md`.
- **Adobe has real creative-asset generation (image/video); Atlas is text-only today.** Every Atlas content prompt (`BlogContentPrompt`, `SocialContentPrompt`, etc. in `ContentGenerationAnalyst`) generates copy only — no image or video generation exists anywhere in the pipeline.
- **Adobe has a real CDP/analytics layer; Atlas's Measure/Learn stage is thin outside email/Meta.** Per `Channel-Capability-Matrix.md`, Website observation has no Measure/Learn stage at all (`N/A`) — no visitor-behavior tracking, no on-site analytics feeding back into the Business Brain.

### Gaps worth closing (tracked as tickets)

See [Adobe-Competitive-Gaps-Plan.md](../plans/Adobe-Competitive-Gaps-Plan.md) for scoped, ticket-ready write-ups of:

1. ~~Closing the edit-pattern learning loop so Atlas's existing edited-approval signals actually influence future content generation~~ — **implemented locally as SCRUM-70 on 2026-08-10**
2. Visual asset generation (image-gen step in content drafting)
3. Website Measure/Learn source decision + MVP implementation (closing the observation-only dead end without pretending Atlas is a full CDP)
4. On-site conversational agent exploration (Adobe's "Brand Concierge" pattern) — flagged as a strategic bet, not a scoped implementation ticket, pending a product decision.

### Adobe comparison status after SCRUM-70

Atlas has now closed the smallest, highest-confidence GenStudio-style gap identified in this comparison: edited approvals can influence future prompt instructions when the same preference repeats consistently for the same channel. The biggest remaining Adobe-comparison gaps on Atlas's current golden path are still visual asset generation and website measurement/learning.
