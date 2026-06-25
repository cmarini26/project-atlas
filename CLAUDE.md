# Project Atlas — Claude Code Instructions

## Project Identity

Project Atlas is an autonomous marketing operating system for small businesses.

Atlas is not a chatbot, copywriter, or simple campaign generator. Atlas is an AI marketing employee that observes a business, builds a digital twin, identifies growth opportunities, makes recommendations, prepares campaigns, and learns over time.

## Core Philosophy

Atlas follows this loop:

Observe → Understand → Decide → Recommend → Prepare → Approve → Execute → Measure → Learn

The best interface is not a blank prompt.  
The best interface is a smart recommendation waiting for approval.

## First Use Cases

### CBB Auctions

A comic book auction house and marketplace with:

- Periodic auctions
- Seller stores
- Seller inventory
- Auction items
- Ending soon items
- Featured inventory
- Collectible categories
- High-value books

### Exotic Used Car Dealers

Dealerships with:

- Dynamic inventory
- High-value vehicles
- Website inventory
- Visual products
- Weekly featured vehicle campaigns

## Core Domain Concepts

Use these terms consistently:

- Company
- Digital Twin
- Business Brain
- Catalog
- Catalog Item
- Observation
- Fact
- Knowledge
- Opportunity
- Decision
- Recommendation
- Campaign
- Content Asset
- Channel
- Approval
- Execution
- Learning

Avoid over-specific domain names like Car or Comic in core architecture. Use generic concepts like Catalog Item, with metadata for vertical-specific fields.

## Architectural Principles

1. Business logic belongs in domain services, not controllers.
2. Prefer domain-driven organization over framework-driven organization.
3. Controllers should be thin.
4. AI should be abstracted behind services/interfaces.
5. The core platform should not depend directly on a single LLM provider.
6. Every recommendation must explain itself.
7. Every decision should answer:
   - Why now?
   - Why this?
   - Why this channel?
   - Why do we expect this to work?
8. Atlas should know more about the business tomorrow than it knew today.
9. Human approval is required before external publishing in the MVP.
10. Build generic platform primitives first, then vertical-specific behavior through metadata and knowledge packs.

## Preferred Stack

Backend:

- Laravel
- PHP
- PostgreSQL
- Redis
- Laravel Queues
- Laravel Events

Frontend:

- Vue 3
- TypeScript
- Tailwind CSS
- Inertia.js or API-first SPA

AI:

- Provider abstraction
- Prompt templates
- Structured JSON outputs
- Versioned prompts
- Agent/analyst-style services

## Suggested Repository Direction

Eventually move toward:

```text
project-atlas/
├── apps/
│   └── web/
├── packages/
│   ├── atlas-core/
│   ├── atlas-ai/
│   └── atlas-connectors/
├── specs/
│   ├── core/
│   ├── product/
│   └── architecture/
├── docs/
├── scripts/
└── .github/