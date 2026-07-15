# Project Atlas — AI Contributor Guide

## Project Identity

Project Atlas is an autonomous marketing operating system for small businesses.

Atlas is not a chatbot, copywriter, or simple campaign generator. Atlas is an AI marketing employee that observes a business, builds a digital twin, identifies growth opportunities, makes recommendations, prepares campaigns, and learns over time.

## Core Philosophy

Atlas follows this loop:

**Observe → Understand → Decide → Recommend → Prepare → Approve → Execute → Measure → Learn**

The best interface is not a blank prompt.
The best interface is a smart recommendation waiting for approval.

## Working Codebase Shape

The repo root contains docs and planning artifacts, but the meaningful runtime application today lives under:

- `backend/` — Laravel 13 + Inertia + Vue 3 application

Treat `backend/` as the operational app root for most coding, testing, and debugging.

Important locations:

- `backend/app/` — backend/domain logic, jobs, services, controllers
- `backend/resources/js/` — frontend UI
- `backend/routes/` — web/api/console routes
- `backend/tests/` — PHPUnit feature/unit coverage
- `backend/tests/e2e/` — Playwright browser smoke coverage
- `docs/` — roadmap, status, audits, product specs

## Product Vision

Atlas should let a client provide business information, website, social handles, and connected channels; periodically or manually audit the company’s online presence; identify marketing opportunities; generate recommendations and campaign assets; support human approval; execute real connected channels where possible; clearly indicate manual steps where not; track results; and improve future recommendations over time.

## Current Strategic Direction

Near-term strategy should favor **depth over breadth**.

The most important production path is not “support every channel.” It is to make a few channels truly real and honest end-to-end.

Preferred first golden path:

1. **Website observation**
2. **Email execution + analytics**
3. **WordPress execution**

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
- Marketing Presence

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
11. Product truth matters: UI, docs, and execution behavior must agree about whether a channel is observable, draftable, publishable, measurable, or only partially supported.

## Preferred Stack

### Backend

- Laravel
- PHP 8.3+
- PostgreSQL
- Redis
- Laravel Queues
- Laravel Events

### Frontend

- Vue 3
- TypeScript
- Tailwind CSS
- Inertia.js

### AI

- Provider abstraction
- Prompt templates
- Structured JSON outputs
- Versioned prompts
- Analyst-style services

## Local Development Commands

Run these from `backend/`.

### Setup

```bash
composer setup
```

### Full local dev stack

```bash
composer dev
```

This starts:
- `php artisan serve`
- `php artisan queue:work --queue=high,ai,default,observations,publishing,analytics,maintenance --tries=3`
- `php artisan schedule:work`
- `php artisan pail`
- `npm run dev`

### Backend tests

```bash
php artisan test
composer test
```

### Frontend/unit tests

```bash
npm test
```

### Build

```bash
npm run build
```

### Browser E2E

```bash
npm run test:e2e -- tests/e2e/onboarding-to-recommendation.spec.ts
```

## Testing Guidance

When possible, verify the smallest relevant slice first, then broaden.

### Onboarding / discovery

High-signal tests:
- `tests/Feature/App/OnboardingControllerTest.php`
- `tests/Feature/Api/OnboardingStatusControllerTest.php`
- `tests/Feature/OnboardingPipelineTest.php`
- `tests/e2e/onboarding-to-recommendation.spec.ts`

### Discovery / Business Brain

High-signal tests:
- `tests/Feature/Discovery/*`
- `tests/Feature/Brain/*`
- `tests/Unit/Discovery/DiscoveryPlannerTest.php`

### Publishing

High-signal tests:
- `tests/Feature/Publishing/Email/*`
- `tests/Feature/Publishing/WordPress/*`
- `tests/Feature/Publishing/Meta/*`
- `tests/Feature/Publishing/CheckChannelHealthTest.php`

### Analytics / Learning

High-signal tests:
- `tests/Feature/Analytics/*`
- `tests/Feature/Analytics/LearningServiceMetricsTest.php`
- `tests/Feature/Analytics/CampaignKpiServiceTest.php`

## Current Product/Engineering Truths

### 1. The app is further along in reasoning than in channel completeness
Atlas is strong at:
- discovery framing
- business understanding
- opportunity detection
- recommendation generation
- approval workflow
- learning architecture

Atlas is weaker at:
- breadth of real observation sources
- consistent real publishing across channels
- universal analytics coverage
- media/image sophistication
- keeping docs/UI truth aligned with backend capability

### 2. Email and WordPress should be treated as first-class production targets
If choosing where to deepen the product first, prefer:
- real email setup + send + metrics
- validated WordPress connection + publish flow
- honest UI states for manual vs automatic execution

### 3. Channel truth must remain synchronized
Take extra care when editing:
- `backend/resources/js/lib/channelCapability.ts`
- publishing UI and recommendation UI copy
- docs that describe live publishing/analytics capability

### 4. E2E tests may fail because of UI drift, not product regressions
On onboarding flows especially:
- inspect the current Vue page
- inspect Playwright artifacts
- compare selectors/assertions with current copy
- prove stale test assumptions before changing product logic

## Safe Editing Habits

1. Check the current working tree before making edits; this repo may contain large in-progress local changes.
2. Avoid mixing unrelated roadmap work into one commit.
3. Prefer small, coherent slices with real verification.
4. Keep docs/UI truth updates in the same change when channel capability changes.
5. Preserve provider abstractions; do not hardwire product logic directly to a single external provider if an abstraction already exists.

## Files Requiring Extra Caution

- `backend/routes/web.php`
- `backend/routes/api.php`
- `backend/routes/console.php`
- `backend/resources/js/lib/channelCapability.ts`
- `backend/app/Providers/PublisherServiceProvider.php`
- `backend/app/Providers/ConnectorServiceProvider.php`
- `backend/app/Services/Publishing/*`
- `backend/app/Services/Analytics/*`
- `backend/app/Services/Discovery/*`
- `docs/STATUS.md`
- `docs/reviews/Channel-Publishing-Reality-Audit.md`

## Repository Direction

Eventually move toward something like:

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
```

That target should not override present-day correctness: today, `backend/` is the real app root and should be treated that way until a deliberate repo restructure occurs.

## Existing Planning Artifact

There is a concrete production-readiness plan at:

- `backend/.hermes/plans/2026-07-15_094741-atlas-production-readiness-gap-plan.md`

If working on production-readiness, align with that plan unless a newer agreed plan supersedes it.
