# Product Requirements Document (PRD)

# Project Atlas

---

# Product Summary

MarketingOS is an Autonomous Marketing Operating System that builds a Digital Twin of every business it serves and uses it to proactively recommend, create, and optimize marketing campaigns.

Instead of asking users to create campaigns manually, MarketingOS observes the business, maintains a living Business Brain, identifies opportunities, and prepares campaigns for approval — without waiting for a prompt.

---

# Problem Statement

Small businesses struggle to market consistently.

Existing platforms require users to:

- Know marketing
- Build campaigns
- Write copy
- Create graphics
- Schedule posts
- Measure results

Most businesses don't have the time or expertise.

---

# Solution

MarketingOS becomes an AI marketing employee.

The system understands the business and performs the work normally done by:

- Marketing manager
- Social media manager
- Copywriter
- Email marketer
- SEO specialist
- Campaign strategist

---

# Decision Lifecycle

Observe → Understand → Decide → Recommend → Prepare → Approve → Execute → Measure → Learn

This loop is continuous. Every execution produces Learning that feeds back into the Business Brain and improves future Decisions.

---

# Digital Twin Lifecycle

1. **Company created** — basic profile, brand identity, channels
2. **Website connected** — URL or data feed provided
3. **Initial crawl** — Atlas extracts catalog items, pages, and signals
4. **Observations recorded** — raw data captured and timestamped
5. **Facts extracted** — structured facts derived from observations
6. **Knowledge synthesized** — patterns and insights derived from facts
7. **Business Brain populated** — Digital Twin is live
8. **Opportunities identified** — Opportunity Engine scans the Business Brain
9. **Decision made** — Decision Engine selects the best opportunity
10. **Recommendation surfaced** — user sees a prepared campaign awaiting approval
11. **Campaign executed** — user approves; content is scheduled
12. **Learning recorded** — outcomes feed back into the Digital Twin

---

# Primary Workflow

User signs up

↓

Connects website or data feed

↓

Atlas crawls and extracts catalog

↓

Business Brain created (Digital Twin goes live)

↓

Opportunity Engine identifies marketing moments

↓

Decision Engine selects best opportunity

↓

Campaign recommendation surfaced with rationale

↓

User approves (or edits and approves)

↓

Campaign assets generated

↓

Scheduled for publishing

---

# Initial Validation Markets

## Comic Book Auction Houses

Design partner: CBB Auctions

Periodic auctions, seller stores, collectible inventory. Key marketing moments include ending-soon auctions, new high-value listings, and featured inventory.

## Exotic Used Car Dealerships

Dynamic vehicle inventory with high-value, visually compelling products. Key marketing moments include new arrivals, featured vehicle selection, and long-tail inventory promotion.

---

# MVP Success Criteria

A business connects its website.

Within five minutes the system can:

✓ Extract the catalog and populate the Business Brain

✓ Identify a marketing opportunity

✓ Recommend one featured catalog item

✓ Create one week's worth of marketing content

✓ Present everything — with rationale — for approval

---

# User Types

## Owner

Runs the business.

Needs recommendations.

Minimal marketing experience.

## Marketing Manager

Reviews AI output.

Edits content.

Approves campaigns.

## Agency (Future)

Manages multiple companies.

---

# Functional Requirements

## Company

- Create company
- Upload logo
- Brand colors
- Brand voice

## Website Intelligence

- Crawl website
- Discover pages
- Extract catalog items
- Extract services
- Detect catalog structure
- Detect CTAs

## Business Brain

Store:

- Catalog and catalog items
- Customers and audience
- Brand identity
- Competitors
- Marketing history
- Observations, Facts, Knowledge

## Recommendation Engine

Recommend:

- Featured catalog item
- Seasonal campaigns
- Holiday campaigns
- Catalog campaigns
- Engagement campaigns

## Campaign Engine

Generate:

- Campaign title
- Strategy
- Target audience
- Positioning
- CTA
- Schedule

## Content Engine

Generate:

- Facebook
- Instagram
- LinkedIn
- X
- Email
- SMS
- Blog
- Landing page

## Approval Workflow

Everything requires approval before publishing.

---

# Non-Functional Requirements

- AI-first
- Multi-tenant
- Fast
- Scalable
- Explainable AI
- Human approval
- Event-driven
- Queue-based

---

# Product Principles

1. AI does the work.
2. Human approves.
3. Business context is required.
4. Every recommendation explains why.
5. Everything is editable.
6. AI learns over time.

---

# Version 1 Excludes

- CRM
- Billing
- Team permissions
- Publishing
- Ads integrations
- SMS sending
- Analytics dashboards

These come later.

---

# North Star Metric

Time from website connection to first approved campaign.

Target:

Less than 10 minutes.