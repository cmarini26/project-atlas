# AGENTS.md

## Project identity

Project Atlas is an autonomous marketing operating system for small businesses.

Atlas is **not** a generic chatbot or one-shot copy generator. It is a system that:

1. observes a business and its online presence
2. builds a digital twin / business brain
3. detects opportunities
4. decides what to recommend
5. prepares campaigns and content assets
6. requires human approval before external publishing in the MVP
7. executes connected channels
8. measures outcomes
9. learns from approvals, edits, rejections, and results

Core loop:

**Observe → Understand → Decide → Recommend → Prepare → Approve → Execute → Measure → Learn**

## Working directory and repo shape

This repo currently has a meaningful application root under:

- `backend/` — Laravel + Inertia + Vue application

Top-level repo files/docs are still important for architecture and planning, but most product code changes happen in `backend/`.

Important locations:

- `backend/app/` — Laravel domain/application code
- `backend/resources/js/` — Vue/Inertia frontend
- `backend/routes/` — web/api/console routes
- `backend/tests/` — PHPUnit feature/unit tests
- `backend/tests/e2e/` — Playwright browser smoke tests
- `docs/` — product, roadmap, audit, and design documentation
- `backend/.hermes/plans/` — execution plans created during repo work

## Product direction

Near-term product goal:
- make Atlas production-ready for a real early-customer loop where a business provides website + business info + marketing channels, Atlas audits presence, generates recommendations, drafts campaigns, supports approval, executes real connected channels, measures outcomes, and improves over time

Current strategic priority:
- prefer **depth over breadth**
- finish a few channels honestly and completely before claiming broad omnichannel support
- first golden path should be:
  - website observation
  - email execution + analytics
  - WordPress execution

## Domain vocabulary

Use these terms consistently:

- Company
- Digital Twin
- Business Brain
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

Avoid hard-coding vertical-specific nouns like `Car` or `Comic` into core platform architecture. Keep vertical differences in metadata, heuristics, prompts, and knowledge packs.

## Architecture principles

1. Keep business logic in domain/services, not controllers.
2. Prefer thin controllers and explicit orchestration services/jobs.
3. Preserve provider abstractions for AI, publishing, analytics, and connectors.
4. Every recommendation must explain:
   - why now
   - why this
   - why this channel
   - why this should work
5. Human approval remains required before external publishing in MVP/beta flows unless product direction explicitly changes.
6. Product truth matters: the UI/docs must not claim a channel is live if it is only simulated, partially wired, or not operationally validated.

## Local run and test commands

Run from `backend/` unless noted otherwise.

### Setup

```bash
composer setup
```

### Full local dev stack

```bash
composer dev
```

This starts:
- Laravel dev server
- queue worker
- scheduler
- log tail
- Vite dev server

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

## High-signal test areas

When changing these areas, prefer targeted tests first, then broader verification:

- onboarding/discovery
  - `tests/Feature/App/OnboardingControllerTest.php`
  - `tests/Feature/Api/OnboardingStatusControllerTest.php`
  - `tests/Feature/OnboardingPipelineTest.php`
  - `tests/e2e/onboarding-to-recommendation.spec.ts`
- discovery/connectors/business brain
  - `tests/Feature/Discovery/*`
  - `tests/Feature/Brain/*`
- publishing
  - `tests/Feature/Publishing/WordPress/*`
  - `tests/Feature/Publishing/Meta/*`
  - `tests/Feature/Publishing/Email/*`
- analytics/learning
  - `tests/Feature/Analytics/*`

## Known repo realities and guardrails

### 1. `backend/` is the real app root
Do not assume top-level scripts/configs are the primary runtime surface. Most implementation work belongs in `backend/`.

### 2. The repo may contain substantial in-progress local changes
Before editing, check the working tree and avoid mixing unrelated changes into one commit.

### 3. E2E tests can drift when onboarding copy or step structure changes
If Playwright fails on a heading/label assertion:
- inspect the current Vue page
- inspect Playwright failure artifacts
- determine whether it is product breakage or stale test expectations
- prefer proving test drift with a corrected disposable probe before changing product code

### 4. Product truth is part of correctness
When changing channel capabilities, publishing, or analytics, update the corresponding docs/UI truth surfaces too. Do not leave capability labels stale.

### 5. Visual/media support is weaker than text generation
Be careful not to overstate image-generation or asset-selection capabilities in code or copy.

## Files that deserve extra caution

- `backend/resources/js/lib/channelCapability.ts`
- `docs/STATUS.md`
- `docs/reviews/Channel-Publishing-Reality-Audit.md`
- `backend/routes/web.php`
- `backend/routes/api.php`
- `backend/routes/console.php`
- publishing providers and registries
- analytics providers and normalization logic
- onboarding flow pages/tests

## Preferred workflow for agents

1. Inspect the current code and tests before patching.
2. Make the smallest coherent change that restores truth or functionality.
3. Run the most relevant targeted tests.
4. Run broader verification if the change touches shared flows.
5. If you change user-visible capability claims, update docs/UI copy in the same slice.
6. Keep commits logically grouped.

## Production-readiness direction

There is an existing production-readiness plan at:

- `backend/.hermes/plans/2026-07-15_094741-atlas-production-readiness-gap-plan.md`

If work relates to shipping Atlas for real customers, align changes with that plan unless the repo direction has explicitly changed.
