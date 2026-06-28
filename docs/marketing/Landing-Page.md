# Atlas — Landing Page Design & Content Specification

**Document type:** Marketing design specification  
**Audience:** Designer, frontend engineer, copywriter  
**Status:** Specification — no code written  
**Date:** 2026-06-27

This document specifies every section of the Atlas marketing landing page: content, structure, copy direction, visual guidance, conversion goals, accessibility requirements, and animation notes. It is the source of truth for the landing page build.

---

## Contents

1. [Strategic Foundation](#1-strategic-foundation)
2. [Conversion Goals](#2-conversion-goals)
3. [Component Hierarchy](#3-component-hierarchy)
4. [Section 01 — Navigation](#4-section-01--navigation)
5. [Section 02 — Hero](#5-section-02--hero)
6. [Section 03 — Trust Bar](#6-section-03--trust-bar)
7. [Section 04 — Problem Statement](#7-section-04--problem-statement)
8. [Section 05 — How Atlas Works](#8-section-05--how-atlas-works)
9. [Section 06 — The Digital Twin](#9-section-06--the-digital-twin)
10. [Section 07 — Recommendation Showcase](#10-section-07--recommendation-showcase)
11. [Section 08 — The Approval Moment](#11-section-08--the-approval-moment)
12. [Section 09 — Features](#12-section-09--features)
13. [Section 10 — Learning Over Time](#13-section-10--learning-over-time)
14. [Section 11 — Industries](#14-section-11--industries)
15. [Section 12 — Social Proof](#15-section-12--social-proof)
16. [Section 13 — Trust & Security](#16-section-13--trust--security)
17. [Section 14 — Final CTA](#17-section-14--final-cta)
18. [Section 15 — FAQ](#18-section-15--faq)
19. [Section 16 — Footer](#19-section-16--footer)
20. [Mobile Layout](#20-mobile-layout)
21. [Animation Recommendations](#21-animation-recommendations)
22. [Accessibility Considerations](#22-accessibility-considerations)
23. [CTA Strategy](#23-cta-strategy)
24. [Copy Principles](#24-copy-principles)

---

## 1. Strategic Foundation

### What Atlas Is Not

Before writing a word of copy, understand what Atlas must never sound like.

- **Not a content generator.** Tools that write copy from a prompt are commodities. Do not use language from that category.
- **Not an AI chatbot.** Atlas never asks the user to type a prompt, pick a template, or explain their business to a text field.
- **Not a social media scheduler.** Buffer, Hootsuite, and Later are tools that execute what you tell them. Atlas decides what to do.
- **Not a marketing suite.** HubSpot, Klaviyo, and Mailchimp require marketing expertise to operate. Atlas provides the expertise.

### What Atlas Is

Atlas is an AI marketing employee. It observes your business, builds an understanding of it, identifies marketing moments, prepares campaigns, and brings them to you for approval. The user's role is to review and approve — not to create, configure, or strategize.

The product is the recommendation, not the interface. The interface's job is to get out of the way of a smart recommendation waiting for approval.

### The Four Core Messages

Every section of this page reinforces one or more of these:

1. **Atlas thinks before it creates.** The system observes your business continuously and only recommends when the moment is genuinely right. It is not a blank canvas.
2. **Atlas explains every recommendation.** The rationale is built into the product at the data level. "Why now, why this, why this channel, why we expect this to work" — always visible, never hidden.
3. **Atlas learns over time.** Every approval, rejection, edit, and campaign outcome makes Atlas smarter about your specific business. It is not a static tool.
4. **You remain in control.** Nothing is published without your approval. Atlas has no autonomous publishing. The approval step is not a limitation — it is the product.

### Persona Targets

**Primary:** Marcus — small business owner, 30–60 minutes per week for marketing, low-to-moderate technical comfort. He wants someone who noticed the right moment and already wrote the campaign before he even thought to look.

**Secondary:** Sofia — marketing contractor managing 2–3 clients simultaneously. She wants a strong first draft, full rationale, edit capability, and a learning trail she can show clients as evidence of ROI.

The landing page must convert Marcus first. Sofia will investigate on her own if the product sounds credible.

---

## 2. Conversion Goals

### Primary Goal

Visitor enters their website URL and begins onboarding.

The north-star metric is: **website URL → first approved campaign in under 10 minutes.** The landing page must set up the expectation that this is what happens.

### Secondary Goal

Visitor books a demo or requests early access (for pre-launch or agency use cases where direct self-serve is not appropriate).

### Micro-goals

- Visitor reads the "How Atlas Works" section and understands the loop
- Visitor reads the recommendation showcase and believes the output is real, not generic
- Visitor reaches the FAQ and has the right objections addressed before they form
- Visitor sees an industry section that confirms Atlas serves their type of business

### Anti-goals

- Do not convert a visitor who expects a chatbot or a prompt-based content tool — they will churn immediately
- Do not promise real-time publishing (out of scope for MVP)
- Do not imply zero setup — the website connection step is real and takes a few minutes

---

## 3. Component Hierarchy

The landing page is composed of the following sections in order:

```
Landing Page
├── [01] Navigation
├── [02] Hero
├── [03] Trust Bar
├── [04] Problem Statement
├── [05] How Atlas Works
├── [06] The Digital Twin
├── [07] Recommendation Showcase
├── [08] The Approval Moment
├── [09] Features
├── [10] Learning Over Time
├── [11] Industries
├── [12] Social Proof
├── [13] Trust & Security
├── [14] Final CTA
├── [15] FAQ
└── [16] Footer
```

Estimated reading time: ~6–8 minutes for a thorough read. Marcus will read the hero, skim the sections, and either sign up or leave. Sofia will read most of it. Design for the skim path first.

---

## 4. Section 01 — Navigation

### Layout

Fixed top bar. Left: Atlas wordmark. Center: navigation links. Right: two CTAs.

```
[Atlas]    [How It Works]  [Industries]  [Pricing]    [Sign In]  [Start Free]
```

On scroll past the hero, the nav bar gains a thin background blur (`backdrop-filter: blur(8px)`) and a 1px bottom border — it does not appear until the hero edge passes. This keeps the hero clean.

### Nav Links

- **How It Works** — scrolls to Section 05
- **Industries** — scrolls to Section 11
- **Pricing** — page anchor to pricing section (or separate pricing page if one exists)

### CTA Pair

- **Sign In** — text link, muted style, navigates to `/login`
- **Start Free** — small contained button, primary accent color (indigo 600), white text

### Mobile

Hamburger menu at right. Menu opens full-screen overlay with all links stacked. CTAs appear at the bottom of the overlay. Close button at top right.

### Accessibility

- `<nav>` element with `aria-label="Main navigation"`
- Skip-to-content link as first focusable element (visually hidden until focused)
- All links have descriptive labels (no "click here")
- Focus ring visible on all interactive elements

---

## 5. Section 02 — Hero

### Goal

Communicate the product's core value proposition in under 10 seconds. Establish that Atlas is categorically different from tools the visitor already knows about.

### Layout

Full-width. Center-aligned on desktop. Left-aligned on mobile. Two-column variant: headline + subhead on the left, product screenshot or UI mockup on the right. Above-the-fold on a 1280px viewport.

```
[Eyebrow label]

[Headline — 3 lines max]

[Subhead — 2–3 sentences]

[CTA Primary]   [CTA Secondary]

[Social proof micro-signal: X businesses already using Atlas]
```

### Content

**Eyebrow label** (small, uppercase, letter-spaced, muted):
```
AI Marketing for Independent Businesses
```

**Headline option A** (recommended — specificity over abstraction):
```
Marketing that arrives
before you think to ask for it.
```

**Headline option B** (more explicit about the product):
```
Atlas watches your business,
identifies marketing moments,
and prepares campaigns for your approval.
```

**Headline option C** (competitor contrast framing):
```
Not a tool that waits for you.
A system that works while you run your business.
```

Design recommendation: Use Option A as the primary headline for emotional resonance. Test Option B as a variant. Option C works if direct competitor contrast is desired.

**Subhead:**
```
Atlas connects to your business, builds a living model of what you sell,
and recommends the right campaign at the right moment — with a full explanation
of why. You review it. You approve it. Atlas handles the rest.
```

Alternative subhead (more concrete, slightly longer):
```
Every recommendation comes with a reason: why now, why this campaign,
why this channel, and what we expect it to do. You approve what makes sense.
Nothing is published without you.
```

**Primary CTA:**
```
Connect Your Business — It's Free
```

This label is intentional. "Connect Your Business" mirrors the onboarding action (connecting a website). It sets expectations: the first thing Atlas needs is access to your business, not your credit card.

**Secondary CTA:**
```
See a Demo
```

Muted button or text link. No visual weight competition with the primary.

**Social proof micro-signal** (below CTAs, small text):
```
▪ Used by independent auction houses, dealerships, and boutique retailers
```

Or, once data is available:
```
▪ 47 businesses served · 312 campaigns approved · 0 published without approval
```

### Visual — Right Panel

The hero's right side (desktop) shows a static or lightly animated UI mockup of the recommendation approval screen. This is the most important visual on the page.

**Mockup content:**

A recommendation card showing:
```
┌─────────────────────────────────────────────────────┐
│  ● Awaiting review                                   │
│                                                      │
│  Featured auction promotion                          │
│                                                      │
│  ┌─────────────────┐  ┌─────────────────┐           │
│  │ Why now?        │  │ Why this?       │           │
│  │ 12 auctions     │  │ Your Silver Age │           │
│  │ close in 48h.   │  │ lot scores 94/  │           │
│  │ This window     │  │ 100 — highest   │           │
│  │ expires Sunday. │  │ in 60 days.     │           │
│  └─────────────────┘  └─────────────────┘           │
│  ┌─────────────────┐  ┌─────────────────┐           │
│  │ Why this        │  │ Why it will     │           │
│  │ channel?        │  │ work?           │           │
│  │ Your last 3     │  │ Urgency posts   │           │
│  │ Instagram posts │  │ for closing     │           │
│  │ drove 2× your   │  │ auctions have   │           │
│  │ average reach.  │  │ driven 2–3×     │           │
│  └─────────────────┘  └─────────────────┘           │
│                                                      │
│  Confidence: ████████░░ 84                          │
│                                                      │
│  [ Approve ]  [ Edit & Approve ]  [ Not this time ] │
└─────────────────────────────────────────────────────┘
```

**Visual treatment of the mockup:**
- Screenshot style with soft shadow and rounded corners
- Slight 3–5° tilt or subtle perspective for depth (product screenshots on marketing pages perform better with depth)
- No background clutter — white card on a light stone/warm grey field
- The four rationale quadrants should be legible at mockup scale — this is the key differentiator being demonstrated visually

### Animation

On page load, the hero text fades in at 0ms. The UI mockup fades and slides up from 8px below over 400ms at 100ms delay. The CTA buttons appear at 200ms delay. No bouncing, no rotation.

If a lightly animated mockup is implemented: the "Confidence: 84" score bar fills on a 600ms ease-in from left, once the mockup enters the viewport.

### Tone Note

The hero must not use the word "AI" prominently in the headline. The word is accurate but it is also the single most overused word in SaaS marketing in 2026. The product behavior should communicate what AI is doing — the word itself is secondary. If it appears in the subhead, that is fine.

---

## 6. Section 03 — Trust Bar

### Goal

Immediately after the hero, provide a signal of legitimacy. This reduces the risk perception for a visitor who is skeptical about trying another new tool.

### Layout

Full-width horizontal strip. Muted background (light stone). Centered content.

**Option A — Partner / customer logos:**
```
Trusted by                [Logo]  [Logo]  [Logo]  [Logo]
```

**Option B — Proof signals when logos are not available:**
```
────────────────────────────────────────────────────────────────
  Designed with CBB Auctions  ·  Built for independent businesses
  ·  No card required to start  ·  Website → recommendation in minutes
────────────────────────────────────────────────────────────────
```

Option B is recommended for pre-launch. It states real facts (the CBB partnership is real) without requiring logos the company doesn't yet have.

**Option C — Stat strip** (use once stats are real):
```
   312             47               < 10 min
campaigns       businesses        URL to first
 approved          served          recommendation
```

### Note on Authenticity

Do not use placeholder company logos or made-up customer names. If logos are not available, use Option B. Fake social proof destroys trust faster than no social proof.

---

## 7. Section 04 — Problem Statement

### Goal

Make Marcus feel seen before he reads another word. This section does not describe the product — it describes the problem so precisely that the visitor thinks "that's exactly my situation."

### Layout

Constrained width (720px max). Center-aligned. Generous top and bottom padding. No decorative elements — pure typographic content.

### Content

**Section eyebrow:**
```
The problem with marketing small businesses
```

**Headline:**
```
You know your business.
You just don't have time to market it.
```

**Body:**
```
Most marketing tools are built for marketing teams. They assume you have
a campaign manager, a copywriter, a scheduler, and two hours on a Tuesday
to set everything up properly.

You don't.

You have 30 minutes on a Sunday evening, a week of inventory that needs
promoting, three social channels you post to inconsistently, and a sense
that you're leaving money on the table — but not enough time to figure
out exactly where.

The tools that exist either require marketing expertise you don't have,
or generate generic content that doesn't know anything about your business.

Neither works.
```

**Transition line** (larger, slightly emphasized, leading into the solution):
```
Atlas is built for the 30-minute window.
```

### Design Notes

- No bullet lists in this section. Prose only. This is the emotional section — bullets interrupt the rhythm.
- No illustrations or icons. White space and typography carry this section.
- The "You don't." short paragraph should be visually isolated — either by extra line spacing above and below, or by slightly larger type — to land with appropriate weight.

---

## 8. Section 05 — How Atlas Works

### Goal

Explain the loop clearly enough that Marcus understands what Atlas does without needing to read documentation. Sofia should understand the technical completeness of the loop.

### Layout

Three visual options (choose one based on designer preference and technical feasibility):

**Option A — Horizontal step flow** (preferred for desktop)
Nine steps laid out left-to-right with connecting lines. On mobile, stacks vertically.

**Option B — Two-column: steps left, description right**
Each step number is selectable; clicking updates the description panel on the right.

**Option C — Animated diagram**
The loop shows as a cycle. Steps highlight in sequence on first viewport entry. After the full loop completes, it rests.

Recommendation: Option A for the static version; Option C if animation resources are available.

### Section Header

**Eyebrow:**
```
The Atlas loop
```

**Headline:**
```
Nine steps. Continuous.
Yours to approve at every stage.
```

### The Nine Steps

Each step has a number, a name (1–2 words), and a one-sentence description.

```
01  Observe
    Atlas connects to your website and scans your catalog,
    inventory, and marketing signals continuously.

02  Understand
    Facts are extracted from each observation and synthesized
    into structured knowledge about your business.

03  Decide
    The Opportunity Engine identifies the highest-signal
    marketing moment — the one item, auction, or event that
    deserves attention right now.

04  Recommend
    Atlas prepares a complete campaign strategy with a
    rationale that explains every choice.

05  Prepare
    Campaign content is generated: posts, subject lines,
    email body, and channel selection — ready for review.

06  Approve
    You read the rationale, review the content, and approve,
    edit, or pass. Nothing is published without this step.

07  Execute
    Atlas schedules and publishes the approved campaign
    across your connected channels.

08  Measure
    Actual reach and engagement are compared against what
    Atlas predicted. The record is permanent.

09  Learn
    Every approval, edit, and outcome feeds back into Atlas's
    understanding of your business. The next recommendation
    is more precise.
```

### Visual Treatment

- Steps 01–05 are in the "Atlas does this" zone (visually distinct — perhaps a subtle background)
- Step 06 (Approve) is visually highlighted — a different treatment (e.g., a slight border, different color) to emphasize that human approval is the center of the loop, not an afterthought
- Steps 07–09 return to the "Atlas does this" zone
- The loop closes back to Step 01 with a subtle connecting arrow or curve

### Desktop vs Mobile

Desktop: horizontal row or two-column interactive layout.  
Mobile: vertical numbered list with step name as heading and description below. The visual distinction for Step 06 (Approve) remains.

---

## 9. Section 06 — The Digital Twin

### Goal

Explain the Business Brain concept without using the word "AI" as a crutch. The Digital Twin is Atlas's competitive moat — it is what separates Atlas from a content generator. This section must make visitors understand why Atlas improves over time while generic tools don't.

### Layout

Two-column: left side is text; right side is a visual representation of what the Digital Twin contains.

### Content

**Section eyebrow:**
```
The Business Brain
```

**Headline:**
```
Atlas builds a model of your business,
not a generic template for it.
```

**Body:**
```
When you connect your website, Atlas doesn't just read it — it builds
a structured understanding of what you sell, who your audience is,
what's performed in the past, and where your current opportunities are.

This model — the Business Brain — is what Atlas refers to when it
recommends a campaign. It knows your catalog. It knows which items
drive the most engagement. It knows which channels work for your audience.
It knows what you said no to last time and why.

That understanding compounds. Every campaign Atlas runs teaches it
something about your business. The Business Brain 90 days in is
meaningfully smarter than it was on day one.

This is why Atlas's recommendations improve over time while a generic
tool's recommendations don't. The tool doesn't know you. Atlas does.
```

### Right Panel — Business Brain Diagram

A visual representation of the Business Brain's contents. Static illustration or subtle data-like display. Not a data table — a conceptual representation.

```
┌─────────────────────────────────┐
│   CBB Auctions · Business Brain │
│                                 │
│  Catalog        847 items       │
│  Active auctions  12            │
│  Top channel    Instagram       │
│  Best content   Urgency posts   │
│  Approval rate  87%             │
│  Campaigns run  24              │
│                                 │
│  ─── What Atlas knows ───────   │
│  "Silver Age items drive 2–3×   │
│   the engagement of modern era  │
│   items for this audience."     │
│                                 │
│  "Urgency framing outperforms   │
│   featured-item framing in the  │
│   48h before an auction close." │
│                                 │
│  "Email performs below          │
│   expectations for this         │
│   company. Instagram first."    │
│                                 │
│  Last updated: 2 hours ago      │
└─────────────────────────────────┘
```

**Design notes for the diagram:**
- Warm, calm visual — not a scary data terminal
- The "What Atlas knows" knowledge entries should read like confident, plain-language observations — because that is literally what they are in the product
- A subtle "live" indicator (a small pulsing dot, not a spinner) communicates that the Brain is actively updating

### Callout

Below or beside the main content, a small callout card:

```
┌────────────────────────────────────────────┐
│  Health: Healthy  ██████████  91           │
│  "Atlas has enough context to recommend    │
│   with high confidence."                  │
└────────────────────────────────────────────┘
```

This demonstrates the health score concept from the product without requiring explanation.

---

## 10. Section 07 — Recommendation Showcase

### Goal

Show a complete, real-looking recommendation. This is the most important product demonstration on the page. The recommendation mockup must look real — populated with specific, plausible content — not placeholder Lorem Ipsum text.

### Layout

Full width or slightly wider than the text columns. Centered. The recommendation card is the full product component: rationale quadrant, confidence score, content preview, and the three action buttons.

### Section Header

**Eyebrow:**
```
What a recommendation looks like
```

**Headline:**
```
Atlas doesn't ask you to make decisions.
It presents conclusions for your review.
```

**Body (above the card):**
```
Every recommendation Atlas surfaces has already been through a full analysis:
what the opportunity is, why the timing is right, which channel fits your
audience, and what outcome to expect. You're not being asked to figure any
of this out. You're being asked to confirm that Atlas got it right.
```

### The Recommendation Card

The card is a high-fidelity mockup of the actual dashboard component. Do not simplify it. The detail is the point.

```
┌──────────────────────────────────────────────────────────────────┐
│  ● Awaiting review                                               │
│                                                                  │
│  Featured auction promotion  —  Instagram · Facebook             │
│                                                                  │
│  ────────────────────────────────────────────────────────────    │
│                                                                  │
│  ┌───────────────────────────┐  ┌───────────────────────────┐   │
│  │  Why now?                 │  │  Why this?                │   │
│  │                           │  │                           │   │
│  │  12 auctions close in     │  │  Your Action Comics #1    │   │
│  │  the next 48 hours.       │  │  CGC 6.0 is your highest- │   │
│  │  This is your peak        │  │  scoring item this month  │   │
│  │  bid-driving window.      │  │  (94/100). It hasn't been │   │
│  │  Urgency posts outside    │  │  featured in 23 days and  │   │
│  │  this window don't        │  │  the auction closes       │   │
│  │  perform.                 │  │  Sunday at 9 PM EST.      │   │
│  └───────────────────────────┘  └───────────────────────────┘   │
│                                                                  │
│  ┌───────────────────────────┐  ┌───────────────────────────┐   │
│  │  Why this channel?        │  │  Why it will work?        │   │
│  │                           │  │                           │   │
│  │  Your last 3 Instagram    │  │  Urgency-framed posts for │   │
│  │  posts generated 2.1×     │  │  closing auctions have    │   │
│  │  your 90-day average      │  │  produced 2–3× click-     │   │
│  │  reach. Email has         │  │  through in your last     │   │
│  │  underperformed for this  │  │  four comparable runs.    │   │
│  │  audience consistently.   │  │  Expected reach: 2,400–   │   │
│  │  Instagram first.         │  │  3,200 accounts.          │   │
│  └───────────────────────────┘  └───────────────────────────┘   │
│                                                                  │
│  Confidence  ████████░░  84                                      │
│                                                                  │
│  ────────────────────────────────────────────────────────────    │
│                                                                  │
│  Draft content — Instagram                                       │
│                                                                  │
│  "12 auctions closing Sunday — including a CGC 6.0 Action       │
│  Comics #1 that hasn't hit the block in over two decades.        │
│  Link in bio to browse the full catalog before 9 PM EST."       │
│                                                                  │
│  ────────────────────────────────────────────────────────────    │
│                                                                  │
│  [    Approve    ]   [ Edit & Approve ]   [ Not this time ]     │
└──────────────────────────────────────────────────────────────────┘
```

### Callout Labels

Three small annotated callouts float near the card (with connecting lines or positioned relative to the card on desktop):

```
↑ "Not a template — specific to
   your catalog, right now."

↑ "Four questions, always answered,
   before you're asked to approve."

↑ "One click to approve.
   Nothing published without it."
```

On mobile, these callouts appear below the card as a three-item list with checkmarks.

### Below the Card

**Body:**
```
The content you see is not a starting point for you to finish.
It's a first draft by a system that already knows your catalog,
your channel history, and your audience. If it's right, approve it.
If you'd change the tone, the channel, or a single sentence — edit it
inline and approve what you actually want to publish.

Either way, Atlas learns from what you did.
```

---

## 11. Section 08 — The Approval Moment

### Goal

Make the human-approval gate a feature, not a caveat. Many AI products treat the approval step as a temporary limitation ("autonomy coming soon"). Atlas treats it as the design intent. This section reframes control as trust.

### Layout

Centered, constrained (720px). Dark background option — this is a values-forward section that benefits from visual distinction. Alternatively: light with a heavy typographic treatment.

### Content

**Headline:**
```
Atlas does not publish anything without you.
```

**Body:**
```
This is not a disclaimer. It is the design.

Atlas has access to your brand, your audience, and your publishing channels.
A single post made at the wrong moment — with the wrong framing, the wrong
channel, the wrong item — costs more than the campaign was worth.

Before Atlas earns the right to act more independently, it has to prove that
it understands your business well enough to be trusted. It proves that through
recommendations you approve. Through edits you make. Through passes you give
with a note explaining why.

Every one of those signals makes Atlas more accurate. The approval step isn't
the product getting out of the way of Atlas — it's Atlas getting smarter with
your help.
```

**Callout stat (if available):**
```
0 campaigns published without explicit approval
across every business Atlas has ever served.
```

**Subpoint list:**

Four items with icons (checkmarks or shield icons):

```
✓  Every recommendation requires one approval action before anything is published
✓  You can edit any piece of content before approving — your version is what publishes
✓  Every approval, edit, and pass is permanently recorded — Atlas never forgets what you wanted
✓  Nothing is ever scheduled automatically — Atlas queues, you release
```

---

## 12. Section 09 — Features

### Goal

Give the technically-minded visitor (Sofia) or skeptical Marcus a clear list of what Atlas can do. Organize by user value, not technical capability.

### Layout

Three or four columns on desktop. Two columns on tablet. Single column on mobile. Each feature has an icon, a name, and a 2-sentence description.

### Section Header

**Eyebrow:**
```
What Atlas does
```

**Headline:**
```
Every part of the marketing loop, handled.
```

### Feature Groups

#### Group 1 — Business Intelligence

**Website Intelligence**
Atlas crawls your website continuously, extracting your catalog, active inventory, pricing signals, and marketing content. It builds structured knowledge — not a scraped snapshot, but an organized understanding of what you sell and how it changes.

**Business Brain**
A persistent, living model of your business. Facts Atlas has observed, knowledge it has synthesized, patterns it has identified. The Business Brain is what makes Atlas's recommendations specific to you rather than generic.

**Continuous Observation**
Atlas doesn't wait to be run. It monitors your connected sources on a schedule and updates the Business Brain when things change — a new item listed, an auction opening, a page updated.

#### Group 2 — Recommendation & Campaign

**Opportunity Detection**
The Opportunity Engine scans the Business Brain for high-signal marketing moments: an auction closing in 48 hours, a new high-grade item with no promotion, an inventory gap creating a re-engagement window. It scores each opportunity and selects the best one.

**Explained Recommendations**
Every recommendation answers four questions before you see it: why now, why this campaign, why this channel, and why we expect it to work. This is not a tooltip or an "info" button. It is the first thing on the recommendation screen.

**Campaign Strategy**
For each opportunity, Atlas prepares a full campaign blueprint: target audience, positioning, channel selection, timing, CTA, and expected impact. The strategy is generated before the content is written, not after.

**Multi-Channel Content**
Campaign content is generated for each selected channel: Instagram captions, Facebook posts, email subject lines and body copy, and more. Each piece is tailored to the platform's format and your brand's voice — not duplicated copy posted everywhere.

#### Group 3 — Approval & Control

**Inline Editing**
Every piece of content Atlas prepares is editable before you approve it. Change a sentence, adjust the tone, replace a detail — the system records what you changed and uses it to write better content next time.

**Approval Workflow**
Three actions on every recommendation: Approve, Edit & Approve, Not This Time. Approve means the content publishes as written. Edit & Approve means you adjust it first. Not This Time means Atlas learns why and surfaces a new recommendation when conditions are right.

**Audit Trail**
Every action is permanently recorded: who approved, what was changed, when, and why. If a client asks what went out and why, the record is complete.

#### Group 4 — Learning & Analytics

**Campaign Analytics**
After each campaign, Atlas compares actual reach and engagement against what it predicted. The comparison is always visible: expected vs actual, side by side.

**Learning Engine**
Atlas accumulates learning from every approval, edit, rejection, and campaign outcome. Over time, it adjusts its channel preferences, content style, and opportunity selection to match what has actually worked for your business.

**Approval Rate Trend**
Sofia can show a client a chart of Atlas's approval rate over 90 days. If it's rising — and it should be — that is measurable evidence that the system is learning and the recommendations are improving.

---

## 13. Section 10 — Learning Over Time

### Goal

The learning-over-time story is Atlas's defensible competitive advantage. This section must communicate compounding value — the longer you use Atlas, the more specifically useful it becomes for your business.

### Layout

Two-column asymmetric: large text left, visual/graphic right. Or: full-width timeline/chart showing fictional but plausible improvement over 90 days.

### Content

**Eyebrow:**
```
Atlas learns your business
```

**Headline:**
```
The recommendation in month three
is better than the one in week one.
```

**Body:**
```
When Atlas makes its first recommendation, it knows what it found on your website.
That is enough to get started — but not enough to be great.

What changes it:

Every time you approve, Atlas records what worked.
Every time you pass, Atlas records what to avoid.
Every time you edit a sentence, Atlas records the pattern — this business
adds prices, removes hashtags, prefers concrete language over abstract claims.

Every time a campaign runs, Atlas compares what it predicted to what actually happened.
When it was right, it reinforces the signal. When it was wrong, it adjusts.

After 90 days, Atlas knows:
which channel actually drives results for your audience,
which content style your customers respond to,
which types of campaigns have overperformed,
which ones have consistently underperformed,
and which times of day reach the most people.

None of this requires you to configure anything. It accumulates automatically,
as a consequence of running your business with Atlas.
```

### Visual

A simple timeline or comparison graphic showing "Week 1" vs "Month 3" state of the Business Brain. Not complex — just a visual signal that things improve.

```
Day 1 —————————————————————————→ Day 90

Business Brain:
  Facts:     12          →    284
  Knowledge:  4          →     61
  Campaigns:  0          →     11

Approval Rate:           →
  [▪▪▪▪▪▪░░░░]  62%       [▪▪▪▪▪▪▪▪▪░]  91%
```

### Callout

```
"The Atlas that served CBB Auctions in month one is not the same system
that serves them now. It's specific to them. It knows what makes their
audience bid."
```

---

## 14. Section 11 — Industries

### Goal

Confirm to the visitor that Atlas is built for their type of business. This reduces the mental effort of evaluating fit.

### Layout

Two cards on desktop. Single column on mobile. Each card has an industry name, a one-sentence context, a list of specific use cases, and a link to a more detailed industry page (if it exists) or a CTA.

### Section Header

**Eyebrow:**
```
Built for businesses like yours
```

**Headline:**
```
Atlas understands your inventory,
your timing, and your audience.
```

### Industry Card 1 — Comic Book Auction Houses

**Heading:**
```
Comic Book Auctions & Collectibles
```

**Context line:**
```
Atlas understands auction cycles, CGC grades, key issues, and the 48-hour
urgency window that drives serious bidder behavior.
```

**What Atlas detects:**
```
· Auctions closing in the next 48–72 hours
· High-grade items that haven't been featured recently
· New inventory arriving after a collection acquisition
· Re-engagement windows after a slow auction cycle
```

**Quote placeholder:**
```
"Atlas noticed the Silver Age lot closing Sunday before I did —
and wrote the post for it. I approved it in 90 seconds."
— Marcus T., comic book auction house owner
```

**CTA:**
```
See how Atlas works for auction houses →
```

### Industry Card 2 — Exotic & Specialty Car Dealers

**Heading:**
```
Exotic & Used Car Dealerships
```

**Context line:**
```
Atlas monitors inventory age, unit price, and market timing to surface
the right vehicle at the right moment — before it sits unsold.
```

**What Atlas detects:**
```
· New high-value arrivals that haven't been promoted
· Inventory approaching the 60-day threshold (pricing risk window)
· Featured vehicle rotation opportunities
· Model-specific demand signals
```

**Quote placeholder:**
```
"I was spending two hours a week writing posts about inventory
that was already half-sold. Atlas tells me what to promote
before the window closes."
— Automotive dealer, southwestern US
```

**CTA:**
```
See how Atlas works for car dealers →
```

### Third Industry Card (optional, placeholder for expansion)

**Heading:**
```
Your type of business
```

**Context line:**
```
Atlas is built for any business with dynamic inventory, recurring marketing
moments, and limited time to manage them manually.
```

**What Atlas works for:**
```
· Independent retailers with seasonal inventory
· Specialty collectors' markets
· Boutique service businesses
· Small marketplaces and storefronts
```

**CTA:**
```
Tell us about your business →
```

This card links to a contact or waitlist form — it is an open-ended lead capture for businesses that don't fit the first two verticals.

---

## 15. Section 12 — Social Proof

### Goal

Provide credibility signals from real users or real design partners. Do not fabricate. If real quotes are not yet available, use placeholder structure that accurately describes what the quotes will say.

### Layout

Three testimonial cards on desktop. Carousel on mobile. Below the cards: a supplementary stats row.

### Testimonial Structure

Each testimonial has:
- Quote text (2–4 sentences)
- Photo placeholder or initials avatar
- Name, role, and business name
- Optional: a specific metric that changed

**Testimonial 1 (Marcus archetype):**
```
"I used to spend my Sunday evenings figuring out what to post for the week.
Now I check Atlas, there's already a recommendation ready, and I approve it
in two minutes. The content is specific to what's actually in my auction —
not some generic 'shop our collectibles' post."

— Marcus [Last name], Owner
CBB Auctions · Comic book auction house
```

**Testimonial 2 (Sofia archetype):**
```
"Atlas gives me a first draft for each client that's 80% of the way there.
The rationale quadrant tells me exactly why it recommended this campaign —
which means I can explain it to the client, not just pass it along and hope
they trust me. The edit trail has also been useful when clients ask why
certain decisions were made."

— [Name placeholder], Marketing contractor
Managing 3 clients with Atlas
```

**Testimonial 3 (outcome-focused):**
```
"I was skeptical that anything could understand our inventory well enough
to be useful. Three months in, Atlas knows which grades drive our bidders,
which channels perform for our audience, and which kinds of copy we end up
changing. It's earned more autonomy than I expected to give a piece of software."

— [Name placeholder], [Title]
[Business name] · [Industry]
```

### Stats Row

```
312               87%                < 2 min              0
campaigns       average approval    average time        campaigns published
approved        rate after 90 days  to decision         without approval
```

**Note:** Replace with real stats as they become available. Do not publish this row with fabricated numbers. If stats are not yet available, omit the row or replace with the four-value proof signal from the Trust Bar.

---

## 16. Section 13 — Trust & Security

### Goal

Address the data concerns a business owner will have before connecting their website to an external system. Be direct and specific — vague security language ("we take your security seriously") performs worse than concrete statements.

### Layout

Three or four items in a grid or horizontal row. Icon + heading + 1–2 sentence description.

### Section Header

**Eyebrow:**
```
Data and trust
```

**Headline:**
```
Atlas is a trusted system, not a surveillance tool.
```

**Subhead:**
```
You are connecting your business's catalog and marketing data to Atlas.
Here is exactly what that means.
```

### Trust Items

**Your data stays yours**
Atlas reads your website to build the Business Brain. It does not sell that data, share it with other businesses, or use it to train models for other customers. Your catalog and your audience signals belong to your company.

**Nothing publishes without approval**
This is enforced at the system level, not just the UI. An approval record must exist in the database before any content is scheduled or sent. Removing the approval step is not possible — it is structural.

**Every action is recorded**
Who approved, who edited, who passed, and when — every action is permanently logged. If something goes out that shouldn't have, the audit trail is complete and immediate.

**One-click disconnection**
If you disconnect an integration, Atlas stops crawling immediately. No pending jobs complete after disconnection. Your data is not retained beyond the period you specify.

**No access to financial data**
Atlas reads your marketing-facing website and catalog. It does not have access to your accounting, payment processing, inventory management system, or any internal data that isn't intentionally public-facing.

**Security practices**
All credentials are encrypted at rest. Channel publishing credentials are stored using field-level encryption. No credentials are logged or visible in Atlas's admin interface.

---

## 17. Section 14 — Final CTA

### Goal

Convert the visitor who has read this far and is ready to try. This section is short, high-confidence, and action-focused.

### Layout

Dark background (matching Section 08's visual tone). Centered. Large headline. Single primary CTA. No distractions.

### Content

**Headline:**
```
Connect your business.
See your first recommendation.
```

**Body:**
```
Enter your website URL. Atlas handles the rest.
Your first recommendation is ready in minutes — with a complete explanation
of why it chose it.
```

**CTA:**
```
[ Connect Your Business — It's Free ]
```

**Below the CTA (micro-copy):**
```
No credit card required.  ·  No configuration needed.
Results in under 10 minutes.
```

**Secondary option:**
```
Not ready? Talk to someone first.
[ Book a 20-minute demo ]
```

### Tone Note

This section must not feel like a hard close. It should feel like an open door. The visitor has already read what Atlas is — this section simply makes the path to starting easy and removes last-minute hesitation.

---

## 18. Section 15 — FAQ

### Goal

Answer the objections that will stop conversion before they are explicitly stated. These are the real questions — not the polite ones.

### Layout

Accordion-style: question is always visible, answer expands on click. 8–10 questions maximum. Questions ordered from most common hesitation to most specific technical concern.

### Questions

**"Is this just another AI writing tool?"**
No. AI writing tools wait for you to describe what you want and generate content on demand. Atlas observes your business, decides what campaign is worth running right now, and prepares the content before you ask. The product is the recommendation and the rationale — not a blank box you type into.

**"How does Atlas know what to promote?"**
Atlas crawls your connected website and builds a structured model of your catalog and marketing signals. It tracks which items are getting attention, what's selling, what's expiring, and what you've promoted before. The Opportunity Engine scores your current inventory and identifies the highest-priority marketing moment — an auction closing, a new arrival, a re-engagement window — and recommends a campaign for it.

**"What happens when I approve a recommendation?"**
Atlas marks the campaign as approved, queues the content for publishing, and schedules it across your connected channels. You will see the campaign appear in your Campaigns list with its status. The content that publishes is exactly what you approved — or what you edited before approving.

**"Can I edit the content before it publishes?"**
Yes. Every recommendation includes an "Edit & Approve" option. You can change any piece of the generated content inline before approving. What you approve is what publishes. Atlas records what you changed and uses it to improve future content for your business.

**"What if the recommendation isn't right for my business right now?"**
Click "Not this time." Atlas will ask you for an optional note explaining why — timing, channel, content — and learns from it. You won't see the same recommendation again. Atlas will surface a new one when conditions change. Passing on a recommendation is not a failure. It is how Atlas gets smarter.

**"Will Atlas ever publish something without my approval?"**
No. This is not a setting you can turn on or off — it is structural. An approval must exist in the system before any content is sent. We do not offer autonomous publishing. We believe approval is the product, not a temporary safety measure.

**"How long does setup take?"**
The initial setup — entering your business name, industry, and website URL — takes under five minutes. Once you submit your website, Atlas begins crawling immediately. Your first recommendation typically appears within 10 minutes of connecting your site, depending on catalog size.

**"Does Atlas work for my type of business?"**
Atlas is designed for businesses with dynamic inventory, recurring marketing moments, and limited time to manage them manually. Auction houses, car dealers, specialty retailers, and service businesses have all been part of the design process. If you are unsure, connect your website and see what Atlas makes of it — it's free to start.

**"What does Atlas need access to?"**
Atlas needs your public-facing website URL. It crawls the pages, extracts catalog and product information, and builds the Business Brain from what it finds. It does not need access to your backend systems, payment data, or internal databases. Your website's public content is sufficient.

**"What happens to my data if I cancel?"**
Your data is retained for 30 days after cancellation to allow for export. After 30 days, it is permanently deleted from Atlas's systems. We do not sell or share business data under any circumstances.

---

## 19. Section 16 — Footer

### Layout

Four-column on desktop. Two-column on tablet. Single column on mobile.

```
Atlas                Product              Company              Legal
                     How It Works         About                Privacy Policy
[Wordmark]           Pricing              Blog                 Terms of Service
                     Industries           Contact              Data Processing Agreement
[Tagline]            Changelog            Careers (link)       Cookie Policy
                     Docs (link)
                     Status (link)
```

**Tagline** (below wordmark, small, muted):
```
AI marketing for independent businesses.
Not a tool that waits for you.
```

**Below the columns — copyright and certifications row:**
```
© 2026 Atlas. All rights reserved.
```

**Social links** (icon-only, labeled for accessibility):
- Twitter / X
- LinkedIn
- Instagram

### Footer Tone

Footers carry the last impression on a page that someone has scrolled to the bottom of. The Atlas footer should not be generic. The tagline in the footer should reinforce the core positioning: Atlas acts. Tools wait.

---

## 20. Mobile Layout

### Principles

1. **Single column throughout.** No two-column layouts on mobile. Every section stacks vertically.
2. **Reduce, don't hide.** On mobile, reduce the content of each section rather than hiding it behind a "Read more." If content doesn't survive the cut, it wasn't essential.
3. **CTA always within reach.** The primary CTA button appears in the navigation (sticky top bar), in the hero, after How Atlas Works, and in the final CTA section. On mobile, it is always no more than one screen-height away from the bottom of any section.
4. **Mockups at full width.** The UI mockup in the hero and the recommendation card in the showcase should render at full mobile width — not shrunk to a thumbnail. These are the product; the visitor needs to read them.

### Section-by-Section Mobile Adjustments

**Navigation** — Hamburger. Links become full-screen overlay. CTAs at bottom of overlay.

**Hero** — Single column. Headline first. Mockup below. CTA below mockup. Social proof signal below CTA.

**Trust Bar** — Three or four proof signals in a scrollable horizontal strip, or stacked vertically.

**Problem Statement** — Full text, no changes. Prose reads well at mobile width.

**How Atlas Works** — Vertical numbered list. Steps 01–09 stacked. Step 06 (Approve) has distinct background color to maintain visual emphasis.

**Digital Twin** — Single column. Text first, Business Brain panel second. Panel renders as a card with slightly smaller type.

**Recommendation Showcase** — The recommendation card renders at full mobile width. The four rationale quadrants become two-column (two rows of two) if possible, or a single vertical stack if the width is too narrow. The content preview and action buttons appear below.

**Approval Moment** — Full text. The four-item trust list stacks vertically.

**Features** — Single column list. Icon + title + description for each feature.

**Learning Over Time** — Text first, visual second. The Day 1 vs Day 90 comparison renders as a vertical stack of two states.

**Industries** — Single column. Cards stack vertically.

**Social Proof** — Single testimonial visible. Horizontal swipe to reveal remaining testimonials (carousel pattern). Stats row stacks 2×2.

**Trust & Security** — Single column list.

**Final CTA** — Full width button. No decorative elements.

**FAQ** — Full accordion. All questions visible, answers collapsed.

**Footer** — Two-column above copyright. Copyright and social links centered below.

### Breakpoints

Following the dashboard design system:
- Mobile: < 640px (sm)
- Tablet: 640px – 1024px (sm–lg)
- Desktop: > 1024px (lg+)
- Wide: > 1280px (xl)

---

## 21. Animation Recommendations

### Principles

Atlas's design philosophy is calm, clear, and low cognitive load. Animations must reinforce calm — they must never demand attention, race through transitions, or compete with the content.

**Rules:**
- Maximum 400ms for any transition
- Easing: `ease-out` for entrances, `ease-in-out` for state changes
- No bounce (`spring`) easing on the marketing page — reserved for delightful micro-interactions only
- No autoplay videos or looping animations that distract from reading
- All animations must respect `prefers-reduced-motion` — if reduced motion is set, replace with instant transitions

### Section-by-Section Recommendations

**Hero**
- Text content fades in at 0ms delay
- UI mockup fades + translates Y(+8px → 0) over 400ms at 100ms delay
- Score bar in the mockup fills from 0% to 84% over 600ms once the mockup enters viewport
- CTAs appear at 200ms delay, no additional motion

**How Atlas Works**
- On first viewport entry: steps appear sequentially from left to right (desktop) or top to bottom (mobile)
- Each step fades and translates Y(+6px → 0) with a 40ms stagger between steps
- The "Approve" step (06) gets a 100ms additional pause before and after — giving it visual weight without size change

**Business Brain Panel**
- The knowledge entries in the right panel enter with a staggered fade (40ms between each), simulating the Brain "filling in"
- The "Last updated: X hours ago" line pulses softly (opacity 0.7 → 1.0 → 0.7) at a very slow interval — communicating that the Brain is live without being distracting

**Recommendation Card**
- The four rationale quadrants animate in sequence: top-left → top-right → bottom-left → bottom-right, with a 60ms stagger and fade-in
- The confidence score bar fills from 0% to 84% over 800ms when the card enters the viewport
- The action buttons appear last, at 300ms after the card enters

**Learning Over Time**
- The comparison stats (Day 1 vs Day 90) count up from 0 to their values when they enter the viewport
- Approval rate bars fill visually from left, sequentially

**General Scroll Animations**
- Section headings: fade-in + Y(+12px → 0) on viewport entry, 300ms duration
- Section body text: fade-in only (no Y translation for prose) on viewport entry, 250ms duration
- Cards: fade-in + Y(+8px → 0), staggered at 50ms per card in a group
- Threshold: trigger when 15% of element is visible

### What Not to Animate

- Do not animate the navigation bar itself (only add the background on scroll)
- Do not animate the FAQ accordion arrows — the expand/collapse is functional, not decorative
- Do not animate form inputs or CTA buttons on hover with movement — color change only
- Do not add parallax to any image or section background

---

## 22. Accessibility Considerations

### Standards

WCAG 2.1 Level AA minimum. All interactive elements keyboard-navigable. All images have descriptive alt text or are marked decorative.

### Specific Requirements

**Color contrast**
- All body text: minimum 4.5:1 contrast ratio against its background
- All large text (headings): minimum 3:1
- CTA buttons: minimum 4.5:1 for text against button background
- Score bar fills: do not rely on color alone to convey score value — the numeric label is required

**Keyboard navigation**
- Tab order follows visual reading order (left-to-right, top-to-bottom)
- Skip-to-content link is the first focusable element
- All CTA buttons and nav links are reachable and activatable by keyboard
- FAQ accordion items are operable via Enter and Space keys
- Industry card links have descriptive labels, not just "Learn more"

**Screen reader support**
- Navigation: `<nav aria-label="Main navigation">`
- FAQ accordion: `<button aria-expanded="false/true">` for each question; answer panel has `aria-hidden` when collapsed
- UI mockups in hero and recommendation showcase: `<figure>` with `<figcaption>` describing what the mockup shows
- Score bars: `role="progressbar"` with `aria-valuenow`, `aria-valuemin`, `aria-valuemax`
- Testimonial section: `<blockquote>` for each testimonial with `<cite>` for attribution
- Social proof stats: numbers with abbreviated labels should have `<abbr>` or screen-reader-only full labels

**Images and icons**
- Decorative icons: `aria-hidden="true"`
- Informational icons: `aria-label` describing the icon's meaning
- UI mockup screenshots: alt text that describes the content — not "screenshot of dashboard" but "Recommendation card showing four rationale quadrants for a comic book auction promotion"

**Motion**
- All scroll-triggered animations check `window.matchMedia('(prefers-reduced-motion: reduce)')`
- If true: all animations resolve instantly (opacity change only, no transforms)
- The animated score bar fill falls back to a static rendered bar showing the final value

**Forms**
- All inputs have explicit `<label>` elements (not placeholder-only)
- Error states have both color and text description
- Required fields are marked with both visual indicator and `aria-required="true"`

**Focus management**
- When FAQ accordion items expand, focus moves to the expanded content
- When mobile menu opens, focus moves to the first menu item
- When modal or overlay closes, focus returns to the triggering element

**Heading hierarchy**
The page maintains a strict heading hierarchy:
```
h1 — Page title (hidden or in hero)
h2 — Section headings (Hero, How Atlas Works, etc.)
h3 — Subsection headings (feature group names, industry names)
h4 — Individual feature names, individual FAQ questions
```

No heading levels are skipped.

---

## 23. CTA Strategy

### Primary CTA — "Connect Your Business — It's Free"

**Appears in:** Navigation (persistent), Hero, after How Atlas Works section, Final CTA section.

**Why this label:** "Connect Your Business" describes the action the user will take on the next screen (entering their website URL). It sets expectations accurately. "It's Free" removes the financial friction objection at the moment of decision. The combination is more specific and honest than "Get Started" or "Try It Free."

**Visual treatment:** Filled button, indigo 600 background, white text, 600 weight, medium rounded corners. Consistent across all occurrences.

**Hover state:** Indigo 700 background. No size change, no movement.

### Secondary CTA — "Book a Demo"

**Appears in:** Navigation (muted), Hero (text link or ghost button), Final CTA (below primary).

**Why this exists:** Sofia — the marketing contractor managing multiple clients — will want to see a demo before committing. She is evaluating whether Atlas is worth recommending to clients. She needs a conversation, not a self-serve trial.

**Visual treatment:** Muted text link in the hero and navigation. Ghost button (border only) in the Final CTA section. Always lower visual weight than the primary CTA — it must never compete.

### Tertiary CTAs — Industry and "Tell Us About Your Business"

**Appears in:** Industry cards (after each).

**Function:** Capture leads from verticals beyond the two primary design partners.

### CTA Copy Variants to Test

The primary CTA is worth A/B testing once traffic is sufficient:

| Variant | Copy | Hypothesis |
|---------|------|-----------|
| A (control) | Connect Your Business — It's Free | Specific action + removes financial friction |
| B | See Your First Recommendation | Focuses on the outcome, not the input |
| C | Start in 5 Minutes | Addresses the time-to-value concern directly |
| D | Enter Your Website URL | Ultra-literal; lowest barrier framing |

Run variants sequentially, not simultaneously, to avoid splitting a small sample.

### Placement Logic

CTAs are placed at the natural end of a persuasive argument — not randomly. The structure is:

```
[Hero]                →  Primary CTA  (first exposure)
[How Atlas Works]     →  Primary CTA  (after understanding the loop)
[Recommendation Card] →  No CTA      (let the product speak; don't interrupt)
[Approval Moment]     →  No CTA      (values section; no hard sell)
[Features]            →  No CTA      (information only)
[Industries]          →  Secondary industry CTAs
[Social Proof]        →  Primary CTA (after credibility is established)
[Trust & Security]    →  No CTA      (let trust land first)
[Final CTA]           →  Primary + Secondary  (conversion moment)
```

CTAs do not appear in the FAQ or footer section body — only in the footer nav column as a text link.

---

## 24. Copy Principles

These rules govern all copy on the landing page. Share them with any copywriter contributing to this page.

### What Atlas Avoids

**No generic AI language.** The following phrases are banned:
- "leverage AI"
- "harness the power of"
- "AI-powered"
- "unlock your potential"
- "supercharge your marketing"
- "cutting-edge"
- "next-generation"
- "seamless"
- "revolutionary"
- "game-changer"

**No marketing jargon.** Marcus doesn't know what "conversion funnel optimization" means and he shouldn't have to. Sofia knows what it means and will be skeptical of it. Write for both by writing in plain English.

**No passive hedging.** "Atlas may help" or "could potentially improve" are not how a confident product describes itself. If Atlas does something, say it does it.

**No feature lists masquerading as benefits.** "12 content formats" is a feature. "Your Instagram caption, email subject line, and Facebook post — all written before you open your dashboard" is a benefit.

### What Atlas Sounds Like

**Specific.** "12 auctions close in the next 48 hours" is better than "time-sensitive inventory." "Action Comics #1 CGC 6.0" is better than "high-value collectible."

**Confident but not boastful.** Atlas is good at what it does. The copy asserts that without selling. It states the behavior and lets the behavior impress.

**Honest about what Atlas isn't.** Atlas doesn't promise to replace all marketing. It handles the recurring loop — observation, recommendation, approval, publishing, learning. That is what it does, clearly and consistently.

**Written for skimmers.** Every headline must work on its own. Every section's first sentence must communicate the point of the section. Visitors who read only the headlines should still understand what Atlas is.

**Short sentences.** Prefer a period to a semicolon. Prefer two short sentences to one long one. The recommendation card mockup has short sentences in it. The landing page should feel the same way.

---

*This document is the source of truth for the Atlas landing page. Any copy, design, or structure change should be reflected here before being implemented. The component hierarchy, CTA placements, and accessibility requirements are not suggestions — they are the specification.*
