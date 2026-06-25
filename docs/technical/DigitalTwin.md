# Digital Twin

## Definition

A Digital Twin is a living, structured model of a business that Atlas builds and continuously maintains.

Every Company in Atlas has exactly one Digital Twin. It is not a static profile — it is a dynamic representation of the business that grows more accurate and complete over time as Atlas observes, learns, and updates it.

## Purpose

The Digital Twin exists to answer one question at any moment:

> What does Atlas know about this business right now?

Without the Digital Twin, Atlas is a content generator responding to prompts.

With the Digital Twin, Atlas is an intelligence system that understands context, identifies opportunity, and makes informed marketing decisions — without waiting to be asked.

The Digital Twin is Atlas's primary competitive moat. It is the accumulated knowledge advantage that makes each business's Atlas instance more valuable over time.

## Core Objects

### Company

The root entity. Represents the business as a whole.

- Name, industry, location
- Brand identity (voice, colors, logo, tone)
- Target audience
- Business goals
- Channels and platforms

### Catalog

The structured representation of what the business sells, offers, or promotes.

A Catalog contains Catalog Items. Catalog Items are generic — the type of business determines what metadata they carry.

Examples:
- A comic book auction house: each item is a listed comic with grade, key issue status, auction end date
- A car dealership: each item is a vehicle with make, model, year, mileage, price
- A restaurant: each item is a menu item, special, or seasonal offering

### Catalog Item

A single entry in the Catalog. May include:

- Title and description
- Status (active, sold, expired, featured)
- Metadata (vertical-specific fields stored as structured JSON)
- Media (images, video)
- Price and value signals
- Created and updated timestamps

## Facts

Facts are discrete, verifiable pieces of information about the business.

Facts are sourced from:

- Website crawls
- Connected data feeds
- Manual input
- AI extraction from unstructured content

Examples:
- "This business has 47 active vehicles in inventory"
- "The highest-priced item in inventory is a 1967 Ferrari 275 GTB at $485,000"
- "No campaigns were published in the last 14 days"

Facts are timestamped and sourced. When a Fact conflicts with a newer Fact from the same source, the newer Fact wins and the old Fact is archived.

## Knowledge

Knowledge is derived from Facts through AI analysis and pattern recognition.

Knowledge is higher-order. It is not a raw observation — it is an inference, a pattern, or a strategic insight derived from one or more Facts.

Examples:
- "This dealership's best-performing posts feature vehicles under $80K"
- "Auction engagement spikes in the 48 hours before closing"
- "This business has not promoted its highest-value inventory in over 30 days"

Knowledge feeds the Opportunity Engine. The richer the Knowledge, the better the Opportunities.

## Observations

Observations are real-time snapshots recorded when Atlas checks on the business.

Every crawl, sync, or data pull creates an Observation. Observations are timestamped and tied to a source.

Observations are processed into Facts. Facts are synthesized into Knowledge.

The Observation pipeline:

```
External Source → Observation → Fact extraction → Knowledge synthesis → Opportunity detection
```

## Opportunities

Opportunities are marketing moments identified by the Opportunity Engine.

An Opportunity exists when the Business Brain contains enough context to justify a marketing action that is:

- Timely
- Relevant
- Likely to perform

Opportunities are scored and ranked. Not all Opportunities become Decisions. The Decision Engine selects which Opportunities to act on based on score, confidence, and business context.

Examples:
- "This vehicle has been in inventory for 45 days with no campaign — promote it"
- "There are 12 auctions ending in the next 48 hours — drive urgency"
- "A new high-value item was just listed — feature it immediately"

## Decisions

A Decision is a committed choice to act on an Opportunity.

When the Decision Engine selects an Opportunity, it produces a Decision. A Decision includes:

- The Opportunity it responds to
- The recommended campaign type and channel
- The rationale (why now, why this, why this channel)
- A confidence score
- The expected outcome

Decisions are the output of the system's autonomous intelligence. They drive the Recommendation presented to the user.

## Learning

Every Campaign execution and every Approval or Rejection feeds back into the Digital Twin.

Learning updates:

- Performance data (what worked, what didn't)
- User preference signals (what the user approved, edited, or rejected)
- Channel effectiveness
- Audience response patterns

Learning is what makes the Digital Twin more valuable over time. A Digital Twin with 6 months of Learning produces dramatically better Decisions than one built yesterday.

## Why This Is Our Competitive Moat

Most marketing tools are stateless. Every session starts from zero.

The Digital Twin is stateful, persistent, and cumulative.

Each Observation adds context. Each Campaign adds history. Each Approval or Rejection teaches the system what this business and its owner value.

Over time, the Digital Twin becomes a detailed institutional memory of the business's marketing intelligence — one that a competitor cannot replicate without the same time and data accumulation.

This is the moat:

- **Switching cost**: A business that has trained its Digital Twin for 12 months cannot easily move to a stateless competitor.
- **Performance gap**: A mature Digital Twin produces visibly better recommendations than a new one. Users feel this advantage.
- **Data network**: Aggregate patterns across Digital Twins (anonymized) improve the Opportunity and Decision Engines for all users.

The Digital Twin is not a feature. It is the product.
