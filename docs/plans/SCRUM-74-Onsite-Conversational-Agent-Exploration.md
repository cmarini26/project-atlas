# SCRUM-74 — On-Site Conversational Agent: Exploration Note

**Status:** strategy-discussion input, **not** an implementation ticket and not a spec
**Date:** 2026-08-27
**Related:** [Adobe-Competitive-Gaps-Plan.md](Adobe-Competitive-Gaps-Plan.md) §SCRUM-74

---

## 1. What this note is

The Adobe gap plan lists SCRUM-74 deliberately as **not scoped**:

> This is not a gap in an existing Atlas capability — it's new territory Atlas
> has never attempted (a conversational agent embedded on the *client's own*
> website, talking to *their* customers, not the business owner). Scoping this
> as an implementation ticket before a deliberate product decision would violate
> the same discipline this repo applies elsewhere.

This note exists to give that product/strategy discussion something concrete to
react to. It does **not** propose acceptance criteria, effort, or a design. It
ends with a recommendation and a single decision request.

## 2. What Adobe's "Brand Concierge" is

A customer-facing chat surface embedded on a brand's own website / app. It
answers product questions, makes recommendations, and guides visitors toward
conversion, grounded in the brand's catalog and content, and hands off to
journeys / commerce. It is aimed at enterprises with a large catalog, a CDP,
and a merchandising team, and it is operated as part of that stack.

## 3. The core tension with Atlas's identity

From the root `CLAUDE.md`:

> Atlas is an AI marketing employee that observes a business, builds a digital
> twin, identifies growth opportunities, makes recommendations, prepares
> campaigns, and learns over time.

Every current Atlas surface talks to **the business owner** and is gated by
**one human approval** before anything goes external. An on-site agent inverts
both:

| Dimension | Atlas today | On-site agent |
|---|---|---|
| Who it talks to | the business owner | the owner's *customers* |
| Human in the loop | approval gate before every external action | none — it's live, autonomous, real-time |
| Failure blast radius | a bad draft the owner rejects | a wrong answer said directly to a paying customer |
| Product category | autonomous marketing operator | customer-service / conversational-commerce tool |
| Data needs | observed business facts | real-time catalog + inventory + policy grounding |

This is a different product surface, not a missing feature of an existing one.
It would be the first Atlas capability with **no approval gate**, which is a
first-principles departure from the stated philosophy, not a detail.

## 4. Arguments each way

### For pursuing it
- It is the most visible "AI does the work" surface a small business can see —
  strong demo / differentiation value.
- Atlas already builds a structured `BusinessBrain` (facts, catalog, knowledge)
  — arguably the hardest input to a grounded site agent is already being
  assembled.
- Competitors (Adobe, and many SMB-focused chat vendors) are moving here; a
  total absence may read as a gap to prospects even if it isn't one strategically.

### Against, or "not now"
- It contradicts the **depth-over-breadth** direction in `CLAUDE.md`: the
  current priority is making website → email → WordPress *truly real end to
  end*, and none of those is finished (email/WordPress still unverified against
  live third-party accounts; website has no Measure/Learn — see SCRUM-72).
- No approval gate means Atlas would be making unreviewed statements to the
  public in the client's name — a support, brand-safety, and liability surface
  Atlas has no infrastructure for (escalation, transcript review, guardrail
  tuning, abuse handling).
- Grounding quality bar is much higher than for drafts: a draft can be "close
  enough" and edited; a live answer about price / availability / returns cannot.
  Atlas's catalog ingestion is still shallow (no per-product pipeline — noted in
  `ContentGenerationAnalyst::resolveMediaFallback()` and the SCRUM-71 plan).
- It likely pulls Atlas toward a **conversational-commerce / helpdesk** product
  identity that is adjacent to, but not the same as, "AI marketing employee."

## 5. If it were greenlit later

A design-only spec (mirroring `docs/specs/*.md`) would need to resolve, at
minimum:

- Is this an Atlas product surface, or a separate product that shares the
  `BusinessBrain`?
- What replaces the approval gate — pre-launch owner review of the agent's
  scope + a hard capability allow-list? A "the agent may only answer from these
  facts" boundary?
- Grounding source of truth for price/inventory/policy, and what the agent does
  when it doesn't know (must fail to "let me connect you with the team", never
  guess).
- Transcript retention, owner review tooling, and an off switch.
- Which vertical to pilot (CBB Auctions vs. car dealers) and success metric.

None of that should be worked until the §6 decision is made.

## 6. Recommendation

**Defer. Do not schedule SCRUM-74 work this cycle.**

Rationale: it is the only backlog item that would take Atlas *outside* its
current product identity and its one-approval-gate safety model, and it
competes for attention with an unfinished golden path (email + WordPress live
verification, website Measure/Learn). The `BusinessBrain` investment that would
feed a future site agent keeps accruing value regardless, so nothing is lost by
waiting. Revisit once (a) the website→email→WordPress path is real end-to-end
and (b) there is a concrete design-partner pull for it.

## 7. Decision requested

- Accept "defer", or
- Greenlight a **design-only spec** (not implementation) now, in which case
  §5's questions become that spec's outline.
