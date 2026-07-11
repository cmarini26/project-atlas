# Engineering Status

This is the live engineering dashboard for Project Atlas. Update it after every sprint, milestone, or significant decision. It is the first document an engineer should read to understand where the project stands today.

---

## Stack

| Component | Version |
|-----------|---------|
| PHP | 8.3+ |
| Laravel | 13.x |
| PHPStan / Larastan | level 8 (0 errors) |
| Laravel Pint | Laravel preset |
| PostgreSQL | 16 (CI); local install for dev |
| Redis | 7 (CI); local install for dev |

---

## Project Health

| Dimension         | Status | Notes |
|-------------------|--------|-------|
| Specifications    | ✅ Complete | Domain model, architecture, database, AI, MVP workflow, analytics engine, and learning engine all defined. `specs/core/marketing-presence.md` — Milestone 11 domain spec, approved; **Phases 1–7 (domain model, service layer, onboarding, Settings UI, Business Brain integration, channel selection, Recommendation UI) now implemented**. |
| Implementation    | ✅ Customer dashboard complete | All 10 milestones delivered. Full customer-facing Vue 3 + Inertia.js dashboard live. Milestone 11 (Marketing Presence) Phases 1–7 shipped — see [Milestone-11-Phase-1-Review.md](reviews/Milestone-11-Phase-1-Review.md) through [Milestone-11-Phase-7-Review.md](reviews/Milestone-11-Phase-7-Review.md). Phase 8 (consolidated test checklist) covered incrementally by each phase's own tests; no distinct session run. |
| Tests             | ✅ Strong | 964 tests (961 passing, 3 skipped where the local environment can't support it) + 53 Vitest tests; PHPStan level 8 — 0 errors; Pint clean. Latest: UI Polish Phase 2 (`PageHeader.vue` + page descriptions), 4 new Vitest tests. |
| CI/CD             | 🟡 Active | GitHub Actions running on push to main; `pdo_sqlite` extension fix applied — awaiting confirmation CI is green |
| Design partner    | 🟡 Informal | CBB Auctions engaged as design partner; formal agreement TBD |
| Infrastructure    | ⬜ Not provisioned | No staging or production environment |

**Overall:** Milestone 10 complete + onboarding pipeline fixed (Phase 1–9) + P0 product-polish tier shipped + P1 Customer Trust & Navigation slice shipped (approval confirmation dialog, safety-tested company switcher, persistent layout + `Link` sweep, toast primitive — see [P1-Customer-Trust-Navigation-Review.md](reviews/P1-Customer-Trust-Navigation-Review.md)) + Channel Publishing Reality audit complete (see [Channel-Publishing-Reality-Audit.md](reviews/Channel-Publishing-Reality-Audit.md)) + Milestone 11 specified and planned (see [marketing-presence.md](../specs/core/marketing-presence.md) and [Milestone-11-Marketing-Presence.md](plans/Milestone-11-Marketing-Presence.md)) + **Milestone 11 Phases 1–7 implemented** (see [Milestone-11-Phase-1-Review.md](reviews/Milestone-11-Phase-1-Review.md) through [Milestone-11-Phase-7-Review.md](reviews/Milestone-11-Phase-7-Review.md)). The `marketing_channels` table, `App\Models\MarketingChannel`, five new backed enums, and `MarketingChannelFactory` exist — declaring a marketing channel is representable in the database with zero API connection required. `App\Services\MarketingPresence\MarketingPresenceService` provides the full CRUD lifecycle; `MarketingChannelCapabilityResolver` derives a channel's domain-level lifecycle stage. The onboarding wizard has a fourth, final step declaring channels with zero metadata. A Settings sub-page (`/app/settings/marketing-presence`) lets a user view/add/edit/disable declared channels with capability badges. `App\Domain\BusinessBrain\BusinessBrain` carries a synthesized `MarketingPresenceSummary` (never raw rows). `App\Services\Decision\MarketingChannelSelector` makes `DecisionEngine::evaluate()` Marketing-Presence-aware when resolving `Decision.channel_ids` — preferring `primary`-linked channels, excluding `inactive`/`planned`-linked ones, and reporting declared-but-unlinked channels as draft-only content targets. **New in Phase 7:** the Recommendation detail page now shows a "channel mix" (primary/supporting/draft-only/unavailable, computed fresh at display time via `App\Services\Recommendation\ChannelMixPresenter`), and the existing four-state capability badge (`ChannelCapabilityBadge.vue`/`channelCapability.ts`) was extended — additively, not replaced — to resolve capability from a linked `MarketingChannel`'s `supports_publishing` flag when one exists, falling back to the prior global type-only lookup otherwise, per `specs/core/marketing-presence.md` §11. No new AI prompt work, no schema change, no regression to the approval workflow. No Opportunity detection changes, no publishing changes were made. Remaining P1 items (email notifications, Sentry, AI usage persistence, icon/Button/FormField primitives, a first real channel publisher) and all P2 items are tracked in [Product-Polish-Audit.md](reviews/Product-Polish-Audit.md). 840 tests (838 passing, 2 Redis skipped) + 24 Vitest tests. PHPStan level 8 — 0 errors. Pint clean.

---

## Current Milestone

**UI Polish Phase 2 — Page descriptions ✅ Complete**
*Completed: 2026-07-11*

Second of three approved UI improvements. Every top-level app page except `MarketingPresence/Index.vue` rendered a bare `<h1>` with no explanation of what the page was for or what to do on it — no shared header component existed, so each page hand-rolled its own title markup with no room for description copy.

**What changed:** New `resources/js/Components/UI/PageHeader.vue` (`title`, optional `description`, optional `icon`, an `actions` slot) reproduces `MarketingPresence/Index.vue`'s existing hand-rolled spacing so no page's vertical rhythm shifted. 9 pages migrated to it with a new one-sentence description and a matching Heroicon: Dashboard, Recommendations, Opportunities, Business Brain, Campaigns (index), Publishing, Analytics (index), Learning, Settings. `MarketingPresence/Index.vue` also migrated onto the shared component, deleting its now-redundant hand-rolled markup (copy preserved verbatim). `Campaigns/Show.vue` and `Analytics/Show.vue` were deliberately **not** migrated — direct inspection showed both already have a bespoke, richer header (back-link + status badge or subtitle) that `PageHeader`'s generic shape would have regressed; they weren't part of the "bare title" problem this phase targets.

Also added 4 `data-tour="..."` attributes to `Dashboard.vue`'s recommendation-prompt, summary-cards, health-card, and recent-executions sections — stable anchors for Phase 3's walkthrough, added now to avoid touching `Dashboard.vue` a second time.

4 new Vitest tests (`PageHeader.spec.ts`). 964 PHP tests unaffected (no backend changes this phase), 53 Vitest tests (up from 49). PHPStan level 8 — 0 errors. Pint clean. Build and `vue-tsc --noEmit` green.

**Previous milestone:**

**UI Polish Phase 1 — Visual refresh (color + icons) ✅ Complete**
*Completed: 2026-07-11*

First of three approved UI improvements (visual refresh → page descriptions → first-time walkthrough), requested after user feedback that the app "looks very basic." This phase targets the flattest part of the UI: `EmptyState.vue` was reused identically (same gray 3-dot ellipsis icon) across every empty list in the app — Dashboard, Recommendations, Opportunities, Business Brain (×3), Campaigns (index + show), Publishing, Analytics (index + show), and Learning, 13 call sites total.

**What changed:** `resources/css/app.css` gained `--color-warning-*`/`--color-info-*` token pairs (formalizing colors `Badge.vue` already used ad hoc) — the existing single-indigo-accent restraint was deliberately left untouched. `Badge.vue` gained an `info` variant. `EmptyState.vue` gained an additive, optional `variant` prop (`default | accent | success | warning | info`) that recolors its icon circle; its existing `title`/`description`/`icon`/`action` contract is unchanged. Each of the 13 empty-state call sites now passes a context-appropriate Heroicon (`@heroicons/vue`, already an installed dependency previously used only on the public marketing site) plus a matching variant — e.g. `LightBulbIcon`/`accent` for "no recommendations," `MagnifyingGlassIcon`/`info` for "no open opportunities," `AcademicCapIcon`/`success` for "no learnings yet." `Recommendations/Index.vue`'s one hand-inlined sparkle SVG was replaced with the real `LightBulbIcon` for consistency.

12 new Vitest tests (`EmptyState.spec.ts`, `Badge.spec.ts`) covering the new `variant` prop (including that the default variant preserves the pre-existing look) and the new `info` badge variant. 964 tests total on the PHP side (unaffected — no backend changes this phase), 49 Vitest tests (up from 37). PHPStan level 8 — 0 errors. Pint clean. Build green.

**Previous milestone:**

**Bugfix — DetectOpportunities crashing on AI-invented subject_id ✅ Complete**
*Completed: 2026-07-11*

Surfaced from a real queue log: after a first recommendation pipeline completed successfully, a later `DetectOpportunities` run failed repeatedly with `SQLSTATE[22001]: String data, right truncated: value too long for type character(26)`, retried 3 times, and left the affected company with facts extracted but 0 opportunities and 0 recommendations persisted.

**Root cause:** `opportunities.subject_id` is a fixed `char(26)` column sized exactly for a ULID. All four rule-based detectors always supply a real Eloquent model's ULID, but `OpportunityDetectionAnalyst` (the AI-assisted detector) cast whatever `subject_type`/`subject_id` the LLM returned straight into an `OpportunityCandidate` with no validation — a hallucinated value (a product title, a SKU, free text) longer than 26 characters crashed the insert and failed the whole job. Non-deterministic by nature: safe on calls where the AI omitted or correctly matched a subject reference, crashing on calls where it invented one.

**Fix:** `OpportunityDetectionAnalyst::normalizeSubjectReference()` now requires `subject_type` to be one of the known internal types (`company`, `catalog`, `catalog_item`) and `subject_id` to pass `Str::isUlid()` — either check failing sanitizes both to `null` rather than crashing the batch; the AI-detected opportunity still persists as a description-only candidate. `OpportunityDetectionPrompt` also gained explicit system-prompt guidance telling the model to prefer `null` over inventing a subject reference.

One new regression test (`OpportunityDetectionAnalystTest::test_invalid_ai_subject_references_are_sanitized_to_null`) reproduces an AI response with a non-ULID, over-length `subject_id` and confirms it's sanitized to `null`. All 53 Opportunity-suite tests passing.

**Previous milestone:**

**Bugfix — Marketing Presence "Add channel" got stuck on "Adding…" ✅ Complete**
*Completed: 2026-07-11*

Reported live during Instagram Observation testing: adding a marketing-presence channel appeared to hang forever on "Adding…". Confirmed via a real headless-browser reproduction (not just a hunch) that the server-side request always succeeded — the row was created every time — but the page crashed while re-rendering afterward: `Uncaught TypeError: Cannot read properties of undefined (reading 'status')` in `resources/js/Pages/App/Settings/MarketingPresence/Index.vue`.

**Root cause:** `rowState` (the per-row status/importance/objective edit state) was built once, at component mount, from the initial `channels` prop. When a channel was added, Inertia's redirect brought back an updated `channels` prop with the new row — but `rowState` was never updated, so the new row's `<select v-model="rowState[channel.id].status">` read `.status` off `undefined` and crashed the whole render. This bug was pre-existing (Milestone 11), unrelated to the new Instagram Integration work, and would have affected adding *any* channel type once at least one channel already existed.

**Fix:** `rowState` is now populated reactively via a `watch` on `props.channels` that adds an entry for any new row without touching entries that already exist — so in-progress edits to existing rows survive a reload triggered by adding another channel.

Added `resources/js/Pages/App/Settings/MarketingPresence/Index.spec.ts` (3 tests) — one reproduces the exact crash against the pre-fix code (verified by temporarily reverting the fix and confirming the test fails with the same error message the user hit), one confirms the new row renders cleanly, and one confirms unsaved edits to existing rows survive. 37 Vitest tests total, all passing. No PHP/backend changes.

**Previous milestone:**

**Milestone 12 Phase 1 — Instagram Observation (Beta) ✅ Complete**
*Completed: 2026-07-11*

Instagram is now Atlas's first observable Marketing Source alongside the website crawl. A company connects an Instagram account from Settings (beta scope: a manually-entered access token, one account per company — no OAuth flow, no publishing, no historical import) and Atlas fetches a single, current profile snapshot (account id, username, display name, profile picture, bio, website, follower/following counts, last synced timestamp) via the Instagram Graph API.

**Reused the existing architecture end-to-end, exactly as specced:** a new `InstagramConnector` implements the same `Connector` contract `WebsiteConnector` does and is resolved by the same `ConnectorRegistry`; the profile snapshot is recorded as an ordinary `Observation` (`source_type: social`) through the existing `ObservationService`; and it flows through the unchanged `Observe → Understand → Decide` loop. No separate AI pipeline was created — a new `InstagramAnalyst` maps the already-structured profile fields directly into `Fact` rows (`instagram.username`, `instagram.follower_count`, etc.) deterministically, with no AI call at all, for the same reason `MarketingPresenceSynthesizer` doesn't call an AI provider either: bucketing known-shape fields isn't a probabilistic task. `ProcessObservation` now resolves the right analyst via a new `AnalystRegistry` (mirroring `ConnectorRegistry`'s `supports()`/`resolve()` pattern) instead of hard-coding `WebsiteAnalyst` — adding a future observation source means adding its Analyst, never touching the job.

**Business Brain integration required no code changes** — `BusinessBrainService::assemble()` was already fully source-agnostic (`activeFacts` pulls by `company_id` alone, not by source), so Instagram-derived Facts automatically appear in the same `BusinessBrain.activeFacts` collection website facts already populate. Verified end-to-end with a dedicated integration test running a real Instagram Observation through `ProcessObservation` and asserting both website and Instagram facts land in the same brain.

**Multi-channel recommendation reference was already correct and untouched** — `DecisionEngine`'s channel-type affinity lists and `MarketingChannelSelector`'s primary/active preference logic already include Instagram (Milestone 11), and were already tested (`DecisionEngineTest`, `MarketingChannelSelectorTest`); this phase changed neither, confirming the existing Marketing-Presence-aware channel selection already extends naturally to a connected Instagram account.

New `instagram_accounts` table (one row per company, typed profile fields, kept in sync by a new `InstagramAccountService` called from `InstagramAnalyst`) gives fast, typed access to "what does Atlas currently know about this account" without querying Facts — Facts remain the Business Brain's source of truth. `integrations.type` and `observations.source_type` both gained new enum values (`instagram`, `social`), added to the base migrations for fresh databases plus a Postgres-only constraint-rewrite migration for already-migrated databases, mirroring the existing `retrying`-status precedent exactly — verified against a real local PostgreSQL instance, not just sqlite.

27 new tests across connector unit/feature tests, the Instagram Graph API fetcher (mocked HTTP), the analyst (fact mapping, account upsert, missing-field handling), the analyst registry, tenant isolation, the Business Brain integration, and the Settings connect/reconnect flow. 963 tests (960 passing, 3 skipped). PHPStan level 8 — 0 errors. Pint clean. Build green.

See:
- `specs/core/marketing-presence.md` — confirms `Integration` (observation source) and `MarketingChannel` (declared presence) are deliberately unrelated concepts; this phase only touches the former
- `specs/core/domain-model.md` — `Integration`/`Observation`/`Fact` entity definitions this phase extends

**Previous milestone:**

**Private Beta Customer Success Toolkit ✅ Complete**
*Completed: 2026-07-10*

Documentation-only deliverable — no application code changed. Three new `docs/beta/` documents operationalize [Version-1.0-Roadmap.md](plans/Version-1.0-Roadmap.md)'s Stage A private beta and [Private-Beta-Execution.md](plans/Private-Beta-Execution.md)'s operational checklist into the actual tools a founder uses once real customers start onboarding:

- [Customer-Interview-Guide.md](beta/Customer-Interview-Guide.md) — structured questions for the four real checkpoints in a customer's lifecycle (onboarding, first recommendation, week one, month one) plus an open-ended discovery section, cross-referenced to the actual product mechanics (the four-part rationale, the three-action approval workflow, the marketing-presence onboarding step) rather than generic interview boilerplate.
- [Founder-Learning-Log.md](beta/Founder-Learning-Log.md) — a reusable per-customer, per-checkpoint entry template (customer, industry, expectations, surprises, struggles, what they loved, bugs, feature requests, willingness to pay, follow-up actions), plus a customer roster seeded only with the one real, confirmed fact available today (CBB Auctions as Customer 1) — left otherwise empty on purpose, since Stage A hasn't started and no other customers are named yet.
- [Beta-Success-Metrics.md](beta/Beta-Success-Metrics.md) — operationalizes the roadmap's Stage A success metric into eight specific, measurable criteria (onboarding completion rate, time to first recommendation, approval rate, engagement, recommendation usefulness, weekly active companies, support burden, willingness to continue after beta), each with a definition, measurement method, data source, and target — explicitly scoped to Stage A's 5–10 hand-picked customer scale, not Stage B's.

All three documents were written with the same honesty discipline as the recent landing page work: no fabricated example customer entries, no invented metrics data, and an explicit acknowledgment throughout that Stage A hasn't started yet (no production environment exists) — these are ready-to-use tools, not a record of beta activity that hasn't happened.

See:
- [Version-1.0-Roadmap.md](plans/Version-1.0-Roadmap.md) — the Stage A objective and metrics these tools operationalize
- [Private-Beta-Execution.md](plans/Private-Beta-Execution.md) — the operational checklist these tools support

**Previous milestone:**

**Marketing Landing Page ✅ Complete**
*Completed: 2026-07-10*

Built the public marketing landing page at `/` per `docs/marketing/Landing-Page.md`'s full 16-section specification (nav, hero, trust bar, problem statement, how-it-works loop, Business Brain, recommendation showcase, approval moment, features, learning-over-time, industries, social proof, trust & security, final CTA, FAQ, footer) using the existing Vue 3 + Inertia + Tailwind v4 design system (`docs/design/System.md`) — no new design tokens invented beyond the typography scale System.md itself specifies but that hadn't been added to `resources/css/app.css` yet. `routes/web.php`'s root route now renders `Marketing/Landing` for guests and redirects authenticated users to their dashboard, replacing the previous unconditional `/` → `/login` redirect.

**Copy was corrected against current product reality, not copied verbatim from the spec, in several places the spec's own draft language overstated what's actually built:**
- Every reference to campaigns "publishing"/"scheduling across channels" was reworded to describe the real, verifiable behavior (an approval record gating queuing for publishing) without asserting live external delivery — per the existing [Channel Publishing Reality Audit](reviews/Channel-Publishing-Reality-Audit.md), every channel today (including email) only logs a simulated result; nothing has ever left the application.
- The spec's fabricated testimonials (`"Marcus T."`, `[Name placeholder]`) and fabricated stats (`312 campaigns approved`, `47 businesses served`) were **not** published. The Social Proof section instead honestly describes the real CBB Auctions design partnership, with no invented quotes or numbers.
- CTAs that would have pointed at non-existent infrastructure (a demo-booking system, a pricing page, legal/company footer pages, a third "tell us about your business" contact form) were either re-pointed at real routes (`/register`, `/login`, in-page anchors) or omitted — no dead or misleading links.
- The "Execute" step and every "Approval Workflow" description were reworded from "publishes" to "queues for delivery/publishing" for the same reason.

**Design system gap filled:** `docs/design/System.md`'s typography scale (`text-display`, `text-heading-1` through `text-label-sm`) was specified but never actually added to `resources/css/app.css` — added now (additive, matches the spec's own Appendix A exactly) so the landing page (and any future page) can use it.

**Accessibility:** skip-to-content link, `<nav aria-label="Main navigation">`, FAQ accordion with `aria-expanded`/`aria-hidden` and focus-moves-to-panel on expand, score/confidence bars as `role="progressbar"` with visible numeric labels (never color-only), `<figure>`/`<figcaption>` on both UI mockups describing their content, strict heading hierarchy with no skipped levels (one deliberate exception: FAQ questions are `<h3>` not the spec's literal `<h4>`, since jumping from the section's `<h2>` straight to `<h4>` would itself skip a level — the "never skip a heading level" rule in the same accessibility section takes precedence over the descriptive heading-level table). Mobile-first responsive throughout, matching System.md's breakpoints exactly.

**Animation:** all scroll-triggered reveals and count-up numbers built on a new `useScrollReveal`/`useCountUp` composable pair (IntersectionObserver via the already-installed `@vueuse/core`), both resolving instantly with no motion when `prefers-reduced-motion: reduce` is set — layered on top of the existing blanket CSS override in `app.css` that already zeroes all animation/transition durations under reduced motion.

`@heroicons/vue` (specified by System.md but never actually installed before this) was added — the first real icon usage in the codebase.

14 new tests: 3 PHP (`tests/Feature/Marketing/LandingPageTest.php` — guest sees the landing page, authenticated user redirects to dashboard, route naming) and 10 Vitest (FAQ accordion expand/collapse/single-open behavior, mobile nav menu open/close/focus, `ScoreBar`'s accessible progressbar attributes and reveal-triggered fill — this last test caught a real inverted-boolean bug in the initial `ScoreBar` implementation before it shipped). Two pre-existing tests (`ApplicationBootTest`, `ExampleTest`) asserting the old `/` → `/login` redirect were updated to match the new, intentional behavior. 936 tests (933 passing, 3 skipped). PHPStan level 8 — 0 errors. Pint clean. Build green. 34 Vitest tests, all passing.

See:
- [Landing-Page.md](marketing/Landing-Page.md) — the full specification this build implements
- [System.md](design/System.md) — the design system it's built on

**Previous milestone:**

**Critical Production Blocker 8 of 8 — Backup and Disaster Recovery Readiness 🟡 Partially Complete**
*Completed: 2026-07-10 (repository-representable subset only — see below)*

Eighth and final blocker from the Production Deployment Readiness Audit; like Blocker 7, this blocker's original acceptance criteria are entirely operator-executed (real backups running against a real production database, at least one restore actually performed against it) and remain genuinely undone — no production database exists yet. What was completed is the repository-representable subset: working, tested backup/verify/restore scripts (`infrastructure/backup/`) and a real, automated local restore drill against disposable scratch PostgreSQL databases — not a mock — plus full strategy documentation in `docs/operations/Backup-and-Recovery.md`.

`atlas-db-backup.sh` wraps `pg_dump` (provider-neutral, works against any PostgreSQL instance), fails loudly on any error, never treats an empty dump as success, and supports optional GPG encryption and an optional off-site upload hook. `atlas-db-verify.sh` does a lightweight integrity check, explicitly distinguished from a full restore drill. `atlas-db-restore.sh` is destructive and never proceeds without exact-match confirmation of the target database name — interactively, or via `--yes --confirm-database=<name>` for scripted drills.

Building the automated local drill surfaced a real operational gotcha, now documented: `pg_dump` refuses to dump from a server newer than itself, and a dump taken by a *newer* client than the restore target's server can include settings the older server doesn't recognize — encountered directly (Homebrew's pg_dump 14 vs. a PostgreSQL 16 server, then a PostgreSQL 17 client's dump failing to restore into that same PostgreSQL 16 server). Also confirmed via `grep -rn "Storage::" app/`: no application-managed uploaded files exist today, so no file-backup mechanism was invented for data that doesn't exist — documented explicitly rather than silently omitted.

`docs/operations/Backup-and-Recovery.md` leads with an explicit "code-complete vs. operator-complete" table so this work is never mistaken for "backups are operational" — retention, encryption, off-site storage, and production scheduling guidance are all documented, but none of it has been executed against real infrastructure.

12 new tests (8 safety/argument-parsing tests requiring only a shell, no Postgres; 1 real end-to-end drill requiring — and skipping gracefully without — a compatible local PostgreSQL server). 933 tests (930 passing, 2 Redis + up to 1 backup-drill skipped depending on local environment). PHPStan level 8 — 0 errors. Pint clean. Build green.

See:
- [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md) — full 8-blocker plan, now complete (with 2 of 8 blockers' operator-executed remainder still open)
- [Backup-and-Recovery.md](operations/Backup-and-Recovery.md) — backup strategy, scripts, safety, retention/encryption/off-site guidance, and the restore testing checklist

**All eight Critical Production Blockers have now been addressed to the extent this repository can address them.** Blockers 7 and 8 each have a genuinely open, operator-executed remainder (real infrastructure, real backups) gated on choosing a hosting provider — see both blockers' "Status" notes in `Critical-Production-Blockers.md`. Per that document's own closing section, the Production Deployment Audit should be re-run against the then-current state once Blockers 7–8's operator-executed work is actually complete, rather than assuming this plan's completion is self-verifying.

**Previous milestone:**

**Critical Production Blocker 7 of 8 — Production Infrastructure Configuration 🟡 Partially Complete**
*Completed: 2026-07-10 (code-representable subset only — see below)*

Seventh of eight critical blockers from the Production Deployment Readiness Audit; this blocker's original acceptance criteria are entirely operator-executed infrastructure provisioning (a real server, domain, SSL, a live deploy) and remain genuinely undone — no infrastructure was provisioned this session, per explicit instruction. What was completed is the code-representable subset: removing Blocker 3's hardcoded `TrustProxies` wildcard (`at: '*'`) in favor of an operator-configured `TRUSTED_PROXIES` env var, parsed by a new `App\Services\Http\TrustedProxyResolver`, and documenting the expected production topology in `docs/deployment/Production-Topology.md`.

The default changed from fail-open (trust the immediate caller unconditionally) to fail-closed (unset `TRUSTED_PROXIES` → trust no proxies) — correct for local/testing, where no reverse proxy exists, and safer for an unconfigured production deploy, which will now visibly misbehave (no HSTS, wrong client IPs) rather than silently trusting whatever connects. This mirrors the fail-clearly philosophy `ProductionMailerGuard` established in Blocker 6.

12 new tests prove HTTPS detection, HSTS, client IP resolution, and IP-keyed rate limiting (the `analytics-webhook` limiter from Blocker 2) all behave correctly given a trusted proxy — and are correctly *not* fooled by an untrusted proxy forging the same forwarded headers. 921 tests (919 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. Pint clean. Build green.

See:
- [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md) — full 8-blocker plan, updated with Blocker 7's completion notes
- [Production-Topology.md](deployment/Production-Topology.md) — the expected production shape (reverse proxy, app server, queue workers, scheduler, Redis, database)

**Previous milestone:**

**Critical Production Blocker 6 of 8 — Real Transactional Email ✅ Complete**
*Completed: 2026-07-10*

Sixth of eight critical blockers from the Production Deployment Readiness Audit resolved, per `docs/plans/Critical-Production-Blockers.md`. Postmark is now a fully wired, credential-driven production mailer option: `config/mail.php`'s `message_stream_id` is uncommented and filled in, and — a previously-undocumented gap found while auditing this — `symfony/postmark-mailer`/`symfony/http-client` were never actually installed, so `MAIL_MAILER=postmark` would have thrown a class-not-found error even with a valid API key. Both packages are now in `composer.json`. `POSTMARK_API_KEY`/`POSTMARK_MESSAGE_STREAM_ID` are documented in `.env.example`; the safe `MAIL_MAILER=log` local default is untouched.

**Delivery safety, beyond the original plan's config-only scope:** the live task explicitly asked for production-misconfiguration rejection, failure logging, and anti-enumeration re-verification — none expressible as pure config, since `MAIL_MAILER=log`/`array` never throws (it "succeeds" by writing to a log file instead of delivering). A new `App\Services\Mail\ProductionMailerGuard` checks for exactly that before every password-reset send attempt; if production is misconfigured, delivery is skipped and a `Log::critical(...)` fires instead. Real transport failures (e.g., an invalid Postmark token) are now caught and logged (`Log::error`, mailer + recipient email + exception message — never the reset token or password). In every branch — misconfigured, real failure, or success — the exact same generic "If an account exists..." response is returned, so the anti-enumeration guarantee is unchanged.

17 new tests cover: the guard's environment/mailer matrix, Postmark transport resolution (no live API call), production+log rejection and its critical log, production+Postmark normal delivery, local/test safety, delivery-failure handling without secret leakage, and no user-enumeration regression across all of the above. 909 tests (907 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. Pint clean. Build green.

See:
- [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md) — full 8-blocker plan, updated with Blocker 6's completion notes

**Previous milestone:**

**Critical Production Blocker 5 of 8 — Failed Job Visibility and Error Tracking ✅ Complete**
*Completed: 2026-07-10*

Fifth of eight critical blockers from the Production Deployment Readiness Audit resolved, per `docs/plans/Critical-Production-Blockers.md`. Scope was widened at execution time to fold in the `failed_jobs` visibility gap Blocker 4 identified and deliberately deferred, alongside this blocker's original error-tracking scope.

**Failed job visibility + recovery:** a new `App\Models\FailedJob` maps the framework's own `failed_jobs` table (queue, job class, failure timestamp, exception summary — all now parsed and surfaced, none of it visible before). `App\Services\Queue\FailedJobRecoveryService` provides Retry (mirrors `artisan queue:retry`'s exact mechanism — resets the payload's attempt counter, re-pushes to the original connection/queue) and Discard (mirrors `artisan queue:forget`), both structured-logging every action. A new `FailedJobResource` Filament panel (`/admin/failed-jobs`) exposes this to operators, gated by the same superadmin-only panel access every existing Filament resource already relies on — no new authorization mechanism was needed.

**Error tracking — abstraction prepared, not fully integrated:** no real vendor package (Sentry or equivalent) was installed, per the live task's explicit allowance to defer full integration. Instead, `App\ErrorTracking\Contracts\ErrorTracker` (a one-method interface) and `App\ErrorTracking\NullErrorTracker` (a no-op, bound by default and unconditionally in `testing`) are wired into `bootstrap/app.php`'s `withExceptions()->reportable()` callback — additive to Laravel's own exception logging, never a replacement. `ERROR_TRACKING_DRIVER`/`ERROR_TRACKING_DSN` are documented in `.env.example`. Exactly what remains for production activation (composer-require a vendor SDK, implement one new `ErrorTracker` class, add one `match` arm, set the real DSN) is documented in `Critical-Production-Blockers.md`.

18 new tests cover: retry/forget recovery behavior and logging, `job_class`/`exception_summary` diagnostics parsing, the `ErrorTracker` binding and `withExceptions()` wiring, Filament resource visibility, and authorization (superadmin can view; regular/unauthenticated users cannot). 892 tests (890 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. Pint clean. Build green.

See:
- [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md) — full 8-blocker plan, updated with Blocker 5's completion notes

**Previous milestone:**

**Critical Production Blocker 4 of 8 — Scheduler and Queue Production Readiness ✅ Complete**
*Completed: 2026-07-10*

Fourth of eight critical blockers from the Production Deployment Readiness Audit resolved, per `docs/plans/Critical-Production-Blockers.md`. All six `routes/console.php` scheduled entries (`atlas:sync-due-integrations`, `ExpireOpportunities`, `PublishScheduledContent`, `CheckChannelHealth`, `PruneRawMetrics`, `ApplyLearnings`) now have `->withoutOverlapping()`, and `->onOneServer()` on the five not already deduped via `ShouldBeUnique` (`ApplyLearnings` is unique per company per day, so `onOneServer()` would be redundant there). A new `infrastructure/cron/atlas-scheduler` artifact — mirroring `infrastructure/supervisor/atlas-worker.conf`'s documented style — gives an operator a ready-to-install crontab entry for `php artisan schedule:run`, since nothing in the repo previously triggered it in production at all.

Also addressed the audit's related "Queue recovery" finding: `CheckChannelHealth`, `ProcessAnalyticsWebhookEvent`, `PruneRawMetrics`, and `PublishScheduledContent` had no `$tries`/`$backoff`, silently falling back to the `maintenance`/`observations` queue workers' blunt CLI defaults. All four now have explicit `$tries = 3` plus a job-appropriate `$backoff` (60s for network/DB-adjacent work, 30s for the lighter webhook-metric update, 300s for the low-urgency monthly prune), and a `failed()` method logging a structured error once retries are exhausted — matching the `SyncIntegration`/`PublishContent` convention already used elsewhere.

**Deliberately not done:** a `failed_jobs` recovery command or Filament resource. The audit flags that failed jobs land in `failed_jobs` with no visibility, but that's scoped to Blocker 5 (real error tracking), not this one — this blocker's own acceptance criteria never asked for it, and the live task's instructions were explicit that it should only be added if this blocker's plan already called for it.

14 new tests (`tests/Feature/Scheduling/ScheduledJobsProductionReadinessTest.php`) cover: all six entries registered, every entry has overlap protection, `onOneServer()` on the five non-unique jobs, queue assignment for the three maintenance-queue jobs, and `$tries`/`$backoff` values for all four newly-configured jobs. 874 tests (872 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. Pint clean. Build green.

See:
- [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md) — full 8-blocker plan, updated with Blocker 4's completion notes

**Previous milestone:**

**Critical Production Blocker 3 of 8 — HTTPS Enforcement + Security Headers ✅ Complete**
*Completed: 2026-07-10*

Third of eight critical blockers from the Production Deployment Readiness Audit resolved, per `docs/plans/Critical-Production-Blockers.md`. `TrustProxies` is now configured (trusting `*`, the immediate calling proxy, since Blocker 7's production proxy layer doesn't exist yet), and a new global `SecurityHeaders` middleware (`app/Http/Middleware/SecurityHeaders.php`, appended in `bootstrap/app.php` outside any specific group so it also covers the Filament admin panel, which builds its own middleware list rather than reusing `'web'`) adds `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, and a baseline `Content-Security-Policy` to every response. `Strict-Transport-Security` is only sent when the request is actually secure (direct TLS or a trusted proxy's forwarded HTTPS scheme) — sending it over plain HTTP would be meaningless, not harmful, so it's simply omitted there.

**Deliberate scope decision:** the shipped CSP is narrow (`frame-ancestors 'none'; object-src 'none'; base-uri 'self'`) rather than a full `script-src`/`style-src`/`connect-src` lockdown. Filament (Livewire + Alpine.js) and Inertia both use inline scripts/styles, and local dev loads assets from the Vite dev server on a different origin — restricting those sources correctly needs a nonce-based rollout wired through Blade, Filament's asset pipeline, and Inertia, which is a separate, larger project. Documented as a deferred follow-up in `Critical-Production-Blockers.md` rather than attempted here, to avoid risking a broken admin panel or broken local dev for a headline-checkbox CSP.

5 new tests (`tests/Feature/Security/SecurityHeadersTest.php`) confirm the headers are present on an Inertia web response, a JSON API response, and the Filament admin login page, and that HSTS is present/absent correctly depending on request scheme. 860 tests (858 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. Pint clean. Build green.

See:
- [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md) — full 8-blocker plan, updated with Blocker 3's completion notes

**Previous milestone:**

**Critical Production Blocker 2 of 8 — Analytics Webhook Rate Limiting ✅ Complete**
*Completed: 2026-07-10*

Second of eight critical blockers from the Production Deployment Readiness Audit resolved, per `docs/plans/Critical-Production-Blockers.md`. `POST /api/analytics/webhooks/{provider}` — previously a fully public, unthrottled endpoint — now has a named rate limiter (`analytics-webhook`, 60/minute per IP, registered in `AppServiceProvider::boot()`) with structured logging on rejection. Signature verification remains the actual correctness gate; this only adds a volume limit.

**Significant discovery while implementing:** Laravel's bare `throttle:N,M` middleware (used by every pre-existing throttled route — login, register, password reset, onboarding integration) keys its rate limit by route *domain + IP only*, with no route distinction unless a prefix is explicitly given. Confirmed empirically that exhausting `/login`'s bucket also blocks `/register`. This is out of scope for this blocker (those are unrelated, already-throttled routes) but is now documented in `Critical-Production-Blockers.md` as a recommended future High Priority item, and is exactly why this blocker uses a named limiter instead of a bare `throttle:60,1` string — a shared bucket would have let webhook traffic and real user logins silently starve each other.

10 new tests (`tests/Feature/Analytics/AnalyticsWebhookRateLimitTest.php`) cover: limit reached, structured logging on rejection, limit reset after the decay window, legitimate retry sequences, cross-route bucket isolation (webhook vs. login, both directions), and regression (existing signature/unknown-provider behavior unchanged). 855 tests (853 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. Pint clean. Build green.

See:
- [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md) — full 8-blocker plan, updated with Blocker 2's completion notes

**Previous milestone:**

**Critical Production Blocker 1 of 8 — Tenant Isolation Container Binding ✅ Complete**
*Completed: 2026-07-10*

First of eight critical blockers from the Production Deployment Readiness Audit resolved, per the execution plan in `docs/plans/Critical-Production-Blockers.md`. `EnsureCompanyMembership` now binds `current_company_id` into the container for every real `/app/*` web request, so `CompanyScope`'s global scope is genuine defense-in-depth — not dead code — on top of the explicit `company_id` filtering every controller already performs.

**Regression caught and fixed by the test suite itself:** activating the scope broke the cross-company "company switcher" listing (`HandleInertiaRequests`'s `companies` prop, `EnsureCompanyMembership`'s own membership lookup, and `CompanySelectorController`) — all three query a user's memberships *across* companies by `user_id`, which the newly-active scope incorrectly narrowed to just the current tenant. Fixed by making those three specific lookups explicit `withoutGlobalScopes()` calls, since "which companies does this user belong to" is inherently a cross-tenant, user-keyed question the scope was never meant to answer.

5 new tests (`tests/Feature/Tenancy/CompanyScopeActivationTest.php`) prove the binding happens on a real request and that the scope actively filters an unfiltered query — not merely that manual filtering still works. 845 tests (843 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. Pint clean. Build green.

See:
- [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md) — the full 8-blocker execution plan, ordered by dependency and merge-conflict risk
- [Production-Deployment-Audit.md](reviews/Production-Deployment-Audit.md) — source audit

**Previous milestone:**

**Production Deployment Readiness Audit ✅ Complete**
*Completed: 2026-07-10*

Read-only, evidence-based audit of the actual repository (config files, middleware, routes, jobs, CI) for production deployment readiness — distinct from the Beta Readiness Audit's broader operational scoring. Every finding is backed by exact file/line evidence, not inference.

**Headline finding:** `CompanyScope`, the global scope every tenant model relies on, never activates during a real HTTP request — `current_company_id` is bound in the container only inside test files, never in `app/`. Tenant isolation today works only because every controller and job manually filters by `company_id`; there is no structural safety net if a future code path forgets to. This is rated above every infrastructure gap because it's a false sense of security, not merely an absence.

Also confirmed still open since June: no production environment, no backups, no real email delivery, no error tracking/monitoring beyond genuinely solid health-check endpoints, no HTTPS/security-header enforcement, no deploy pipeline (CI is test-only), and no cron trigger for the six scheduled jobs that carry Atlas's recurring-intelligence promise. New findings not previously documented: the analytics webhook endpoint is public and unthrottled; several mutating endpoints (company settings, integration sync, all Marketing Presence CRUD) have no role check beyond company membership; password reset doesn't invalidate other sessions.

See:
- [Production-Deployment-Audit.md](reviews/Production-Deployment-Audit.md) — the full audit, with critical/high-priority/nice-to-have findings

**Previous milestone:**

**Private Beta Execution Checklist ✅ Complete**
*Completed: 2026-07-10*

Operator's checklist for running Stage A (Private Beta, 5–10 customers) of the Version 1.0 Roadmap — distinct from the roadmap (strategy) and `Private-Beta-Plan.md` (the build-out sprint plan). Covers a production infrastructure checklist (hosting, domain, SSL, database, backups, monitoring, error tracking, email, queue workers, scheduler, log retention), a per-customer onboarding checklist (account creation through publishing expectations, including the new Marketing Presence step), a daily internal support checklist, a single objective Go/No-Go gate for inviting Customer 1, and a first-week operating cadence with daily tasks and metrics. No code changes — a pure operational document, meant to be run and re-run, not read once.

See:
- [Private-Beta-Execution.md](plans/Private-Beta-Execution.md) — the full checklist

**Previous milestone:**

**Version 1.0 Product Roadmap ✅ Complete**
*Completed: 2026-07-10*

Strategic (non-implementation) product roadmap for the next ~12 months, written after Milestone 11 (Marketing Presence, Phases 1–7) shipped. Assesses current platform state against the Beta Readiness Audit, Product Polish Audit, and Channel Publishing Reality Audit, then lays out four gated stages — Private Beta (5–10 customers) → Paid Beta (25–50) → Version 1.0 Public Launch → Version 2.0 — each defined by entry/exit criteria and success metrics rather than calendar dates. Also states explicitly what's deferred, what technical debt is fine to carry, and what technical debt must be resolved before public self-serve launch.

See:
- [Version-1.0-Roadmap.md](plans/Version-1.0-Roadmap.md) — the full strategic roadmap

**Headline assessment:** the 8-phase product loop plus Milestone 10 (customer dashboard) and Milestone 11 (Marketing Presence) are complete and well-tested (840+ tests, PHPStan level 8 clean), but the platform remains **not beta-ready** — the gap is almost entirely operational (no production server, no real email delivery, no monitoring, no backups, no legal documents), not architectural. The Channel Publishing Reality Audit's finding that no channel type publishes externally today (every "Published" badge describes a log line) is the single biggest risk to address before any paying customer is onboarded.

**Previous milestone:**

**Private Beta Readiness Audit ✅ Complete**
*Completed: 2026-06-27*

CTO-style operational audit across 40 areas. Beta Readiness Score: 31/100. Go/No-Go: NO-GO. 7 critical blockers identified. Full 4-week remediation sprint plan written.

See:
- [Beta-Readiness-Audit.md](reviews/Beta-Readiness-Audit.md) — 40-area audit with severity, effort, and blocks-beta assessment for every finding
- [Private-Beta-Plan.md](plans/Private-Beta-Plan.md) — week-by-week sprint plan to safely onboard first 10 paying customers

**Critical blockers (must resolve before any paying customer is onboarded):**
1. `ResolveCurrentCompany` middleware not verified / may not exist — multi-tenancy enforcement gap
2. No production server provisioned
3. Email delivery uses log driver only (no Postmark, no Mailgun)
4. No monitoring or alerting (only health endpoints exist)
5. No database backups configured
6. No domain configured (APP_URL is localhost)
7. No privacy policy or terms of service

**What is working well:**
- Full AI pipeline operational (Anthropic + SSRF protection)
- Domain model correct and well-tested (579/581 tests, PHPStan level 8)
- Customer dashboard complete (all 16 pages, Tier 1 & 2 polish done)
- Learning Engine implemented
- Filament admin panel with superadmin gate
- End-to-end smoke test passing

**Beta Readiness Score: 31 / 100** (strong foundation, infrastructure entirely absent)

**Previous milestone:**

**Landing Page Design & Content Specification ✅ Complete**
*Completed: 2026-06-27*

Full landing page spec written for the Atlas marketing site. 24 sections covering hero through footer, mobile layout, animation, accessibility, CTA strategy, and copy principles. No code written — this is a design and content specification document.

See:
- [Landing-Page.md](marketing/Landing-Page.md) — complete landing page specification

**Deliverables:**
- Strategic foundation and four core messages
- 16 content sections with full copy direction and layout guidance
- Recommendation showcase mockup with specific, plausible CBB Auctions content
- Industry cards for comic book auction houses and exotic car dealers
- Mobile layout specification for every section
- Animation recommendations with explicit timing and easing values
- WCAG 2.1 AA accessibility requirements throughout
- CTA strategy with placement logic and A/B test variants
- Copy principles: what Atlas avoids and what it sounds like

**Previous milestone:**

**Version 0.2 Polish — Tier 1 & 2 ✅ Complete**
*Completed: 2026-06-27*

All Tier 1 (trust blockers) and Tier 2 (clarity gaps) items from `docs/plans/Version-0.2-Polish.md` implemented. 17 frontend issues resolved across 16 files. All four quality gates pass.

See:
- [Version-0.2-Polish-Tier-1-2-Review.md](reviews/Version-0.2-Polish-Tier-1-2-Review.md) — implementation notes and decisions

**Tier 1 — Trust blockers (all resolved):**
- T1-1: HealthCard + Brain.vue status labels fixed — `active` → "Active" in emerald, not raw gray
- T1-2: Onboarding redirects to first recommendation; 5-min timeout message; polling at 5s intervals
- T1-3: All enum badge values translated — opportunity types, campaign statuses, execution statuses, learning signals, source types
- T1-4: Analytics metric keys translated with human-readable labels and titleCase fallback

**Tier 2 — Clarity gaps (all resolved):**
- T2-1: "Edit & Approve" secondary button added; emits event to open ContentEditor
- T2-2: Explanatory copy added below approval buttons
- T2-3 + T2-4: ScoreBar rewritten — value-based color scale + ARIA progressbar roles
- T2-5: Opportunity expiry shows time remaining with amber (<48h) / rose (<24h) urgency coloring
- T2-6: `<Head>` title tags added to all 16 app pages (title formatter wired in app.ts)
- T2-7: Mobile padding fixed — `px-8` → `px-4 lg:px-8` throughout AppLayout
- T2-8: Already done (Inertia progress bar was wired in app.ts)
- T2-9: Inline error messages added to approval buttons via `onError` callbacks
- T2-10: Form label typography — `text-xs uppercase tracking-widest text-muted` on all form pages
- T2-11: Health score (0–100) + "Healthy"/"Building"/"Learning" label added to HealthCard
- T2-12: Nav label "Brain" → "Business Brain"
- T2-13: Rationale body text → `text-base leading-relaxed`
- T2-14: Onboarding timeout message shown after 5 min with suggestions

**Quality gates:**

| Gate | Result |
|------|--------|
| PHPUnit (581 tests) | 579 passing, 2 Redis skipped |
| PHPStan level 8 | 0 errors |
| Laravel Pint | Clean |
| Frontend build | 129 modules, 0 errors |

**Previous milestone:**

**Product Validation Sprint ✅ Complete**
*Completed: 2026-06-27*

Full customer experience review. 24 issues across 20 review areas. See [Product-Validation-Review.md](reviews/Product-Validation-Review.md) and [Version-0.2-Polish.md](plans/Version-0.2-Polish.md).

**Previous milestone:**

**Version 0.2 Planning ✅ Complete**
*Completed: 2026-06-27*

9-milestone roadmap written covering all production-readiness and real-provider work. See [Version-0.2-Roadmap.md](plans/Version-0.2-Roadmap.md) for full details.

**Planned milestones:**

| Milestone | Goal | Status |
|-----------|------|--------|
| M11 — Production Infrastructure | Forge + DigitalOcean, PostgreSQL RLS, zero-downtime deploys | ⬜ |
| M12 — Error Reporting | Flare or Sentry; job failure alerts; exception triage runbook | ⬜ |
| M13 — Telemetry & Monitoring | Laravel Pulse; uptime monitoring; scheduled job heartbeats | ⬜ |
| M14 — Demo Environment | Seeded `mountain-city-comics`; nightly reset; read-only guard | ⬜ |
| M15 — Onboarding Improvements | Email verification; progress persistence; welcome email; error recovery | ⬜ |
| M16 — Real Email Publishing | `PostmarkEmailProvider`; channel credential UI; sandbox mode | ⬜ |
| M17 — Real Social Publishing | Meta OAuth; `MetaPublisher`; image upload; content policy handling | ⬜ |
| M18 — Real Analytics Integrations | `MetaAnalyticsProvider`; Postmark pull; real learning signals | ⬜ |
| M19 — Customer Feedback Tooling | In-app NPS; `Feedback` model; weekly digest; Filament review panel | ⬜ |

**Previous milestone:**

**Milestone 10 — Customer Dashboard & UX ✅ Complete**
*Completed: 2026-06-28*

Full customer-facing dashboard built across 10 phases. See [Milestone-10-Review.md](reviews/Milestone-10-Review.md) for full details.

**Quality gates (M10):**

| Gate | Result |
|------|--------|
| PHPUnit (581 tests) | 579 passing, 2 Redis skipped |
| PHPStan level 8 | 0 errors |
| Laravel Pint | Clean |
| Frontend build | 129 modules, 0 errors |

---

---

## Completed Milestones

### Milestone 10 — Customer Dashboard & UX ✅
*Completed: 2026-06-28*

Full customer-facing Inertia.js + Vue 3 + TypeScript dashboard. 10 implementation phases. 581 tests. See [Milestone-10-Review.md](reviews/Milestone-10-Review.md).

### Milestone 9.5 — Version 0.1 Stabilization Sprint ✅
*Completed: 2026-06-27*

All 5 production-blocking gaps resolved. Two systemic pipeline defects fixed. See [Milestone-9.5-Review.md](reviews/Milestone-9.5-Review.md).

### Milestone 8.5 — Learning Engine Specification ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| `specs/core/learning-engine.md` | Full Phase 8 implementation blueprint — 14 sections covering every design decision for the Learning Engine |
| Learning domain model | `Learning` (existing, Phase 7); `LearningApplication` (new — tracks applied effects + rollback); `CompanyScoringWeights` (new — versioned per-company scoring weights) |
| Learning lifecycle | Created → Unapplied → Applied → (optional Rollback); `applied_at` set once and never changed |
| `ApplyLearnings` job design | `ShouldBeUnique`; company-scoped; scheduled daily at 02:00 UTC; delegates to `LearningEngine` service |
| Learning prioritization | Tier 1 (safety: immediate), Tier 2 (performance: 2+ signals), Tier 3 (preference: 3+ signals); 90-day rolling evidence window |
| Conflict resolution | 4-rule ordered resolution: safety override → recency → majority → no-action tie |
| Confidence recalibration | Upward bias: 1 positive signal sufficient; 2+ negative signals required for downward adjustment; ±5% max per run; 14-day cooling |
| `CompanyScoringWeights` design | Versioned rows; `is_current` flag; floor 0.05, ceiling 0.60, sum always 1.00; `type_modifiers` (0.50–1.50) |
| BusinessBrain mutation rules | Fact supersession (new row, old `is_current = false`); Knowledge `type = 'learning'` with 90-day expiry; weight versioning; `OpportunityScorer` integration pattern |
| Prompt adaptation strategy | Indirect: learning enriches BusinessBrain context, never modifies prompt templates; edit-pattern detection (length, hashtags, price, CTAs) |
| Safety constraints | Hard limits table; company scoping enforcement pattern; no-auto-publish; notification requirements for Tier 1 signals |
| Explainability | `LearningApplication.effects` descriptor shape; Filament admin views (Learning Log, Applied Effects, BusinessBrain Mutations) |
| Rollback strategy | Compensating records only — no deletes; `rolled_back_at` + `rollback_reason`; Learning `applied_at` reset to null for re-evaluation |
| Versioning | Weight version history; Knowledge supersession; prompt version linkage; full audit trail via SQL queries documented |
| 47 acceptance criteria | All verifiable by automated tests; no live API or provider calls |
| Future extensibility | Cross-company aggregation; ML-trained scoring; preference cascade to brief; user-initiated overrides; real-time Tier 1 path |
| `ROADMAP.md` updated | Phase 8 now references `specs/core/learning-engine.md`; deliverables expanded with concrete models, jobs, and safety invariants |

### Milestone 8 — Analytics Engine ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| 4 migrations | `execution_metrics`, `campaign_kpi_snapshots`, `metric_retrieval_logs`, `learnings` |
| `ExecutionMetric` model | Per-execution platform metrics; normalised keys; `raw` payload; retrieval window tracking |
| `CampaignKpiSnapshot` model | Campaign-level KPI rollup; `actual_kpis`, `performance_rating`, `snapshot_type`; immutable (`UPDATED_AT = null`) |
| `MetricRetrievalLog` model | Append-only audit log per pull attempt; immutable |
| `Learning` model | Idempotent signal records; `applied_at = null` until Learning Engine runs |
| `AnalyticsProvider` interface | `pull`, `normalize`, `isWindowClosed`, `pollingDelayHours`, `repollingIntervalHours`, `supports` |
| `AnalyticsProviderRegistry` | First-match registry; `register()`, `for()`, `all()` |
| `FakeAnalyticsProvider` | Queue/assert test double; `queueMetrics()`, `queueFailure()`, `assertPulled()`, `assertNotPulled()`, `setWindowClosed()` |
| `LogAnalyticsProvider` | No-op provider for blog/landing page channels |
| `WebhookEvent` VO | Immutable: `providerType`, `platformMessageId`, `eventType`, `occurredAt` |
| `AnalyticsWebhookHandler` interface | `verify()`, `parse()`, `supports()` |
| `WebhookHandlerRegistry` | First-match registry for webhook handlers |
| `PostmarkWebhookHandler` | HMAC-SHA256 verification; maps RecordType → Open/Click/Bounce/Delivery/SpamComplaint |
| `AnalyticsServiceProvider` | Registers all analytics singletons; boots providers and handlers |
| `ScheduleMetricRetrieval` listener | `ExecutionCompleted` → delayed `RetrieveExecutionMetrics` dispatch |
| `RetrieveExecutionMetrics` job | Self-rescheduling pull polling; `updateOrCreate` ExecutionMetric; `snapshotIfReady` on window close |
| `PruneRawMetrics` job | Monthly maintenance; nulls `raw` on records older than 1 year |
| `AnalyticsWebhookController` | `POST /api/analytics/webhooks/{provider}`; HMAC verified; dispatches `ProcessAnalyticsWebhookEvent` |
| `ProcessAnalyticsWebhookEvent` job | Idempotent counter merging by `platform_id`; silent no-op if not found |
| `CampaignKpiService` | `aggregate()`, `snapshotIfReady()`, `ratePerformance()`, `bestChannel()`; interim/final snapshot lifecycle |
| `RecommendationKpiService` | Approval/rejection/edit rates; median time-to-decision (driver-aware SQL); 30-day trend |
| `DecisionEffectivenessService` | Accuracy rate by detector, by campaign type; score-band correlation |
| `LearningService` | `recordFromMetrics()` with 8 signal types; idempotency guard; `applied_at = null` |
| Filament panels | Campaign performance infolist; ExecutionMetric sub-panel; Company approval rate |
| `api.php` routes | `POST /api/analytics/webhooks/{provider}` registered via `bootstrap/app.php` |
| 97 new tests | 16 test files; all use `FakeAnalyticsProvider`; no live API calls; 365 total (363 passing) |
| PHPStan level 8 | 0 errors |

### Milestone 7.5 — Analytics Engine Specification ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| `specs/core/analytics-engine.md` | Full Phase 7 implementation blueprint: domain model, event ingestion, webhook interface, attribution, metrics by channel, campaign KPIs, recommendation KPIs, decision effectiveness metrics, BusinessBrain feedback loop, learning inputs, provider abstraction, data retention, privacy considerations, acceptance criteria, future extensibility |
| `ROADMAP.md` updated | Phase 7 now references `analytics-engine.md` as authoritative spec; Major Deliverables expanded with concrete models, services, and jobs |

### Milestone 7 — EmailPublisher ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| `EmailPayload` VO | Readonly: `subject`, `fromName`, `fromEmail`, `body`, `previewText`; `fromPlatformPayload()` throws `MalformedPayloadException` if subject is empty |
| `EmailProvider` interface | `send(EmailPayload, ChannelCredentials): string`, `ping(ChannelCredentials): PingResult`, `supports(string): bool` |
| `EmailProviderRegistry` | Resolves `EmailProvider` by `provider_type` string; throws `UnknownEmailProviderException` (non-retryable); `register()`, `for()`, `all()` |
| `UnknownEmailProviderException` | Extends `PublishingException`; non-retryable; `userMessage()` directs user to contact support |
| `LogEmailProvider` | Sends to `publishing` log channel; returns `'log-email-{ulid}'`; supports only `'log'` provider type |
| `FakeEmailProvider` | Queue/assertion test double; `queueMessageId()`, `queueFailure()`, `assertSent()`, `assertNotSent()`, `sentItems()` |
| `EmailRenderer` | Implements `ChannelRenderer`; reads `metadata.subject_line` → fallback `title` → throws; packs `subject/from_name/from_email/body/preview_text` into `PlatformPayload`; supports only `'email'` channel type |
| `EmailPublisher` | Implements `ChannelPublisher`; resolves credentials → renders → creates `EmailPayload` → picks provider from registry → sends; `ping()` delegates to provider; supports only `'email'` |
| `PublisherServiceProvider` updated | `EmailRenderer` registered first (priority over `GenericRenderer`); `EmailPublisher` registered first (priority over `LogChannelPublisher`) |
| 29 new tests | `EmailRendererTest` (6), `EmailProviderRegistryTest` (6), `LogEmailProviderTest` (6), `EmailPublisherTest` (12, including full `PublishContent` job integration) |
| PHPStan level 8 | 0 errors |

### Milestone 6.5 — Publishing Hardening ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| `ChannelRendererRegistry` | Mirrors `ChannelPublisherRegistry`; `register()`, `for()`, `all()`; throws `UnknownChannelException` |
| `GenericRenderer` | `supports()` returns true for all channel types; wraps asset body/title/media/metadata into `PlatformPayload` |
| `FakeChannelRenderer` | Test double; `assertRendered()`, `assertNotRendered()`, `renderedItems()` |
| `LogChannelPublisher` updated | Now injects `ChannelRendererRegistry`; calls `render()` before logging payload |
| `PublisherServiceProvider` updated | Registers both `ChannelRendererRegistry` and `ChannelPublisherRegistry` as singletons; boots `GenericRenderer` |
| `CredentialsExpiredException` | New non-retryable exception; `userMessage()` instructs reconnection |
| `ChannelCredentialsRepository` updated | Three-stage validation: not found/revoked → `CredentialsNotFoundException`; expired → `CredentialsExpiredException`; error → `AuthenticationException` |
| Blueprint validation hardened | 8 new checks: `tone.voice`, `tone.modifier`, `tone.avoid`, `landing_page`, `success_metrics.*` (4 fields), channel_strategy count and field completeness |
| `CampaignPublished` bug fixed | Event no longer fires when all executions fail; campaign marked `cancelled` without event |
| `docs/technical/Tenancy.md` | Documents CompanyScope, required middleware pattern, production-readiness requirement |
| 28 new tests | `RendererIntegrationTest` (5), `ChannelCredentialsRepositoryTest` (9), `CampaignPreparationServiceTest` (14 new) |
| PHPStan level 8 | 0 errors |

### Milestone 6 — Publishing Infrastructure ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| 3 migrations | `channel_credentials`, `executions`, `execution_attempts` |
| `ChannelCredentials` model | `BelongsToCompany`, `HasUlids`; encrypted JSON credentials; `isExpired()` |
| `Execution` model | `BelongsToCompany`, `HasUlids`; status lifecycle; `isSettled()`; `attemptLogs()` HasMany |
| `ExecutionAttempt` model | `HasUlids`; append-only audit log; no `updated_at` |
| `ExecutionResult` VO | Readonly: `platformId`, `url`, `publishedAt`, `metadata` |
| `PlatformPayload` VO | Readonly: `channelType`, `data` |
| `PingResult` VO | Readonly: `reachable`, `error` |
| `PublishingException` hierarchy | Base + 8 subclasses; `isRetryable()` + `userMessage()` |
| `ChannelPublisher` interface | `publish()`, `supports()`, `ping()` |
| `ChannelRenderer` interface | `render()`, `supports()` |
| `SupportsRollback` interface | Opt-in; `rollback(): bool` |
| `ChannelPublisherRegistry` | Resolves publisher by `supports(channelType)` |
| `ChannelCredentialsRepository` | `for(companyId, channelType)` → throws `CredentialsNotFoundException` |
| `FakeChannelPublisher` | Queue-based test double; `assertPublished()`, `assertNotPublished()` |
| `LogChannelPublisher` | Writes to `publishing` log channel; supports all 8 channel types; no API calls |
| `ExecutionService` | `queueForCampaign`, `markCompleted`, `markFailed`, `logAttempt`, `checkCampaignCompletion` |
| `RollbackService` | Iterates completed Executions; dispatches rollback if `SupportsRollback`; reports unrollable |
| `PublishCampaign` job | `high` queue; creates Executions; dispatches immediate `PublishContent` jobs |
| `PublishContent` job | `high` queue; 4 tries; 60/300/900s backoff; non-retryable → `fail()`; retryable → re-throw |
| `PublishScheduledContent` job | `maintenance` queue; every 5 min; dispatches due Executions |
| `CheckChannelHealth` job | `maintenance` queue; every 30 min; pings all active credentials |
| 3 events | `ExecutionCompleted`, `ExecutionFailed`, `CampaignPublished` |
| `TriggerCampaignPublishing` listener | `RecommendationApproved → PublishCampaign` |
| `PublisherServiceProvider` | Singleton registry; registers `LogChannelPublisher` for all 8 channel types |
| Filament `ExecutionResource` | Read-only; status badge; attempts; last_error; company/campaign/channel columns |
| `publishing` log channel | `storage/logs/publishing.log`; separate from `laravel.log` |
| Campaign status `published` | Added to campaign status enum |
| 47 new tests | All passing; no live API calls; `FakeChannelPublisher` throughout |
| PHPStan level 8 | 0 errors |

### Milestone 4 — Opportunity & Decision Engine ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| 6 new migrations | `catalog_items`, `channels`, `opportunities`, `decisions`, `campaigns`, `recommendations` |
| `CatalogItem` model | `BelongsToCompany`, `HasUlids`, `SoftDeletes`, datetime casts; `isActive()` |
| `Channel` model | `HasUlids` only; nullable `company_id` (system channels) |
| `Campaign` model | Full implementation; `campaign_type`, `completed_at` for Guard 3 |
| `Recommendation` model | Minimal; `campaign_type` for Guard 2 |
| `Opportunity` model | Full: polymorphic subject, score fields, lifecycle methods |
| `Decision` model | Full: JSON casts for `channel_ids`, `rationale`, `expected_impact` |
| `OpportunityCandidate` VO | Readonly; all 4 score fields + `aiDetected` |
| `OpportunityScorer` | Composite formula; min-30 threshold; AI confidence cap at 75 |
| 4 rule-based detectors | `FeaturedItemDetector`, `UrgencyDetector`, `NewArrivalDetector`, `ReEngagementDetector` |
| `OpportunityDetectionAnalyst` | AI-assisted supplemental detection; never bypasses scoring/deduplication |
| `OpportunityEngine::scan()` | Orchestrates detectors → AI → dedup → score → persist → fire events |
| `DecisionContext` VO | Immutable: `Opportunity`, `BusinessBrain`, `campaignType`, `channelIds` |
| `DecisionEngine::evaluate()` | 5 guard conditions; score-ordered selection; channel affinity resolution |
| `DecisionService::commit()` | Validates 5 rationale keys + 4 `expected_impact` sub-keys; persists; fires event |
| `RationaleGenerationAnalyst` | AI rationale generation; temperature 0.4; versioned prompt |
| `RationaleGenerationFailedException` | Hard failure when rationale is incomplete |
| 4 jobs | `DetectOpportunities` (default), `CommitDecision` (ai, `ShouldBeUnique`), `ExpireOpportunities` (maintenance), `PrepareCampaign` stub (ai) |
| 2 events | `OpportunityDetected`, `DecisionCommitted` |
| 3 listeners | `TriggerOpportunityDetection`, `TriggerDecisionEvaluation`, `DispatchCampaignPreparation` |
| Morph map | `catalog_item`, `catalog`, `company` registered in `AppServiceProvider` |
| `BusinessBrainService` | `featuredItems` and `recentCampaigns` now populated from DB |
| 2 AI fixtures | `opportunity-detection.json`, `rationale-generation.json` |
| 44 new tests | All M4 components tested with `FakeAiProvider`; no live AI; 127 total passing |
| PHPStan level 8 | 0 errors |

### Milestone 3 — Fact Extraction & Knowledge Synthesis ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| `Fact` model + migration | `facts` table; ULID PK; `is_current` versioning; `(company_id, key, is_current)` index |
| `Knowledge` model + migration | `knowledge_entries` table; `active()` scope with `expires_at` handling |
| `FactData` value object | Readonly VO: key, value, dataType, confidence — decouples analyst from Eloquent |
| `FactRepository` + `KnowledgeRepository` | Encapsulated Eloquent queries with `withoutGlobalScopes()` |
| `FactExtractionPrompt` | Versioned prompt (v1.0); structured JSON schema; temperature 0.1 |
| `StructuredResponseParser` | Parses AI JSON; strips markdown fences; throws on invalid response |
| `WebsiteAnalyst` | Implements `Analyst`; calls `AiProvider`; returns `Collection<FactData>`; short-circuits on empty payload |
| `FactService` | `storeExtracted()`: persists Facts; supersedes existing current facts; fires `FactExtracted` |
| `KnowledgeService` | `synthesizeForCompany()`: groups facts by domain; upserts Knowledge; activates DigitalTwin; fires events |
| `BusinessBrainService` | `for(Company): BusinessBrain`; assembles from current Facts, active Knowledge, recent Observations |
| Real `ProcessObservation` | Full pipeline: analyze → store facts → synthesize knowledge → mark processed; marks failed on error |
| 4 domain events | `FactExtracted`, `KnowledgeSynthesized`, `ObservationProcessed`, `DigitalTwinActivated` |
| Company model | Added `facts()` and `knowledge()` `hasMany` relationships |
| `AiProvider` binding | Bound to `FakeAiProvider` in `testing` environment |
| AI fixture | `tests/Fixtures/AI/website-facts.json` |
| 35 new tests | 7 test classes covering all new services, AI layer, and end-to-end pipeline — 83 total (81 passing) |
| PHPStan level 8 | 0 errors |

### Milestone 2 — Discovery & Knowledge Platform ✅
*Completed: 2026-06-26*

**Delivered:**

| Item | Description |
|------|-------------|
| ULID PKs throughout | All domain tables use `char(26)` ULID PKs; users, personal_access_tokens patched for compatibility |
| Multi-tenancy foundation | `CompanyScope` global scope; `BelongsToCompany` trait; scoping is no-op when no company bound (safe in CLI/tests) |
| Domain migrations | `companies`, `company_memberships`, `catalogs`, `digital_twins`, `integrations`, `observations` — all with ULID PKs and FKs |
| Eloquent models | Full implementations: `Company`, `CompanyMembership`, `Catalog`, `DigitalTwin`, `Integration`, `Observation` — with fillable, casts, relationships, and `HasUlids` |
| `CompanyService` | Single DB transaction creates Company + Catalog (type: `mixed`) + DigitalTwin (initializing) + owner CompanyMembership |
| Connector framework | `Connector` interface, `ConnectorRegistry`, `ConnectorResult` value object, `UnsupportedIntegrationException` |
| `WebPageCrawler` | BFS crawler using Guzzle + DOMDocument; max 20 pages / depth 3; strips nav/footer/scripts; 5,000-char body cap |
| `WebsiteConnector` | Maps crawled `WebPageData` → `ConnectorResult`; supports `website_crawl` integration type |
| `ConnectorServiceProvider` | Registers `WebsiteConnector` in `ConnectorRegistry` as a singleton |
| Observation pipeline | `ObservationService`, `SyncIntegration` job, `ProcessObservation` stub job, `ObservationRecorded` event, `DispatchObservationProcessing` listener |
| Event wiring | `ObservationRecorded → DispatchObservationProcessing` registered in `AppServiceProvider` |
| `IntegrationService` | `create(Company, type, config)` — provisions Integration, sets `name`, `status: active`, `next_run_at: +7 days`; callers own dispatch |
| `SyncIntegration` uniqueness | Implements `ShouldBeUnique`; `uniqueId()` keyed on `integration->id` — prevents duplicate syncs in queue |
| Feature tests | 20 new tests: company creation, tenant isolation, connector registry, observation service, queue dispatch, integration service — 48 total, 46 passing (2 Redis skipped) |
| PHPStan level 8 | 0 errors; full generic annotations on all Eloquent relationships |

### Milestone 1 — Platform Foundation ✅
*Completed: 2026-06-25 | Hardened: 2026-06-25*

**Delivered:**

| Item | Description |
|------|-------------|
| Laravel 13.x / PHP 8.3 application | Installed in `backend/`; PostgreSQL + Redis configured; app boots cleanly |
| `.env` configuration | PostgreSQL, Redis, mail (log driver), storage (local + S3 stubs) |
| Queue topology | Five named queues in `config/queue.php`: `high`, `ai`, `default`, `observations`, `maintenance` |
| Supervisor stubs | `infrastructure/supervisor/atlas-worker.conf` — one worker group per queue |
| Laravel Pint | `pint.json` with Laravel preset; all files passing |
| PHPStan / Larastan | `phpstan.neon` at **level 8**; 0 errors |
| GitHub Actions CI | `.github/workflows/ci.yml` — Pint + PHPStan + PHPUnit on push/PR to `main`/`develop` |
| Domain folder structure | `app/Domain/{Company,Catalog,BusinessBrain,Opportunity,Decision,Recommendation,Campaign,Shared}/`, `app/Application/`, `app/Infrastructure/`, `app/Presentation/` |
| Core contracts | `AiProvider`, `Analyst`, `Connector`, `OpportunityDetector`, `ContentGenerator` interfaces |
| Abstract base classes | `Prompt` with `system()`, `user()`, `schema()`, `temperature()`, `maxTokens()`, `version()`, `name()` |
| Value objects | `AiResponse` readonly class; `BusinessBrain` readonly value object |
| FakeAiProvider | `queueResponse()`, `queueFixture()`, `assertPromptSent()`, `assertNothingSent()` |
| Eloquent model stubs | 7 structural placeholders for entities referenced by contracts — **not yet implemented domain persistence** |
| Bootstrap tests | 25 tests: Laravel boots, DB connection, queue dispatch, AI contracts, Prompt — all passing |
| Sanctum installed | Authentication package ready for Milestone 2 scaffolding |

### Milestone 0 — Specification Phase ✅
*Completed: 2026-06-25*

All foundational documents written, reviewed, and committed.

**Delivered:**

| Document | Description |
|----------|-------------|
| `specs/core/domain-model.md` | 18 entities — fields, relationships, lifecycle states, Laravel notes |
| `docs/technical/Architecture.md` | Module structure, layered architecture, event chain, queue topology, Connector and Analyst patterns |
| `docs/technical/Database.md` | Data classification, multi-tenancy strategy, indexing, retention, backup |
| `docs/technical/AI.md` | Provider abstraction, 6 MVP analysts, prompt design, testing strategy |
| `docs/technical/DigitalTwin.md` | Definition, purpose, core objects, competitive moat |
| `docs/technical/DecisionEngine.md` | Opportunity scoring, explainability, decision lifecycle |
| `specs/product/mvp-workflow.md` | 13-step MVP workflow with acceptance criteria and implementation checklist |
| `FOUNDING_PRINCIPLES.md` | 10 engineering principles with self-tests |
| `ROADMAP.md` | 8-phase product roadmap |
| `docs/product/PRD.md` | Updated with Digital Twin lifecycle and decision lifecycle |
| `docs/vision/FoundersBible.md` | Updated with CBB Auctions as primary design partner |

---

## Current Objectives

See `docs/plans/Version-0.1-Architecture-Audit.md` for the full pre-customer-dashboard readiness checklist.

**Immediate priorities (blocking for production):**
1. Implement `AnthropicProvider` — the AI pipeline does not function without it
2. Add Filament superadmin gate — all company data is currently exposed to any registered user
3. SSRF protection on `WebPageCrawler` — user-supplied URLs must be validated to public IPs before outbound requests
4. Add health check endpoint (`GET /api/health`)
5. Confirm PostgreSQL RLS rollout plan

---

## Technical Debt

| Item | Introduced | Notes |
|------|------------|-------|
| No real AI provider implemented | 2026-06-26 | `AnthropicProvider.php` does not exist. `FakeAiProvider` is used in all environments. Atlas cannot run the observation → fact → campaign pipeline in production. |
| `BusinessBrainService` has no caching | 2026-06-26 | Spec requires 5-minute Redis TTL per company. Currently assembles fresh on every call. Will degrade at moderate scale. |
| `EvidenceEvaluator` PHP-side filtering | 2026-06-26 | Loads all Learning records for a company+signal then filters discriminator in PHP. Correct for cross-DB compat in tests; inefficient at production scale. Replace with SQL JSON extraction on PostgreSQL. |
| No PostgreSQL RLS | 2026-06-25 | `docs/technical/Database.md` specifies RLS as defense-in-depth. Not yet applied to any table. Required before production. |
| Queue tests use `Queue::fake()` — no live Redis execution | 2026-06-25 | Dispatch mechanism is tested; real Redis worker execution is not. Add integration test or smoke test before production. |
| Spec/code column drift | 2026-06-26 | `learning-engine.md` spec uses `payload`; implementation uses `value`. Spec defines `LearningApplication.applied_at`; implementation uses `created_at`. Update spec or add migration. |
| `ApplyLearnings` on `ai` queue instead of `maintenance` | 2026-06-26 | Architecture.md assigns this job to `maintenance`. Implementation uses `ai`. Align with spec. |

---

## Open Questions

| Question | Context | Priority |
|----------|---------|----------|
| Frontend: Inertia.js + Vue 3 or API-first SPA? | CLAUDE.md lists both. Decision needed before customer dashboard work begins. | High |
| AI provider: Anthropic or OpenAI? | Anthropic preferred per Architecture.md. Implement before any production run. | Critical |
| Hosting and deployment target? | No infrastructure provisioned. Options: Laravel Forge + DigitalOcean, Vapor, bare VPS. Decision affects queue worker config and environment secrets strategy. | High |
| CBB Auctions inventory format? | RSS feed, structured API, or HTML-only? Determines which Connector is primary. | High |
| JavaScript-rendered inventory pages? | WebsiteCrawlConnector uses simple HTTP. Headless browser connector may be required. | Medium |
| Image handling for catalog items? | `catalog_items.media` stores URLs. Re-host vs. link-to-source decision needed before content generation goes live. | Medium |

---

## Recent Decisions

| Decision | Rationale | Date |
|----------|-----------|------|
| PHPStan raised to level 8 | Level 8 passed with 0 errors on current codebase; no reason to defer — stricter analysis catches more issues earlier | 2026-06-25 |
| Laravel 13.x chosen | Current stable release; PHP 8.3+; compatible with Larastan 3.x and PHPStan level 8 | 2026-06-25 |
| Sanctum over Passport for auth | Sanctum is lighter and sufficient for token-based API auth; Passport adds OAuth complexity not needed in MVP | 2026-06-25 |
| Stub models for interface type safety | Interfaces reference Eloquent models that don't yet have migrations; stubs allow PHPStan to pass without deferring type checking | 2026-06-25 |
| PostgreSQL over MySQL | Required for `pgvector` (future embeddings) and Row-Level Security as defense-in-depth | 2026-06-25 |
| ULIDs over UUIDs | Sortable, URL-safe, reduces B-tree index fragmentation vs. random UUIDs | 2026-06-25 |
| Business Brain is a value object, not a DB row | It's a query projection assembled on demand — persisting it would create a stale cache problem | 2026-06-25 |
| Opportunity detection is hybrid | Rule-based detectors (fast, deterministic) run first; AI analyst supplements for non-obvious opportunities | 2026-06-25 |
| Anthropic uses tool-use for structured output | Anthropic has no JSON mode; tool-use with `tool_choice: forced` achieves equivalent structured output | 2026-06-25 |
| Shared schema multi-tenancy | Schema-per-tenant is operationally expensive at this scale; shared schema + `CompanyScope` + RLS is sufficient | 2026-06-25 |
| `char(26)` for ULID columns | ULIDs are always exactly 26 chars; `char` avoids variable-length overhead and preserves lexicographic sort | 2026-06-25 |
| CBB Auctions as primary design partner | Comic book auctions and exotic cars share the dynamic-inventory pattern. CBB is more willing to engage early. | 2026-06-25 |

---

## Next Tasks (Post-M9.5)

All production-blocking items resolved. Remaining pre-production items:

1. `BusinessBrainService` Redis caching — 5-min TTL per `company_id`; required before the brain is queried at any scale
2. Rate limiting on `/api/analytics/webhooks/{provider}` — required before analytics webhooks are exposed publicly
3. Spec/code drift — `Learning.value` vs spec `payload`; update spec to match implementation
4. `ApplyLearnings` queue alignment — change from `ai` to `maintenance` per Architecture.md
5. First production environment provisioning (Forge + DigitalOcean or Vapor)

---

## Recently Completed

- **Milestone 9.5 — Version 0.1 Stabilization Sprint** — All 5 production blockers resolved: `AnthropicProvider`, Filament superadmin gate, SSRF protection, health endpoints, E2E smoke test. Two systemic pipeline defects fixed (job dispatch silencing, duplicate event listeners). 519 tests (517 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. Pint clean. See [Milestone-9.5-Review.md](reviews/Milestone-9.5-Review.md).

- **Version 0.1 Architecture Audit** — `docs/plans/Version-0.1-Architecture-Audit.md` written. 15 audit areas reviewed. 5 critical/production-blocking items identified. 5 customer-dashboard-blocking items identified. 12 recommended refactors prioritized.

- **Milestone 9 — Learning Engine** — Full Learning Engine implemented and verified. 449 tests (447 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. Pint clean. See [Milestone-9-Review.md](reviews/Milestone-9-Review.md).

- **Milestone 8.5 — Learning Engine Specification** — `specs/core/learning-engine.md` written. 14 sections: domain model, learning lifecycle, `ApplyLearnings` job design, 3-tier prioritization, 4-rule conflict resolution, confidence recalibration, BusinessBrain mutation rules, prompt adaptation, safety constraints, explainability, rollback, versioning, 47 acceptance criteria, and future extensibility.

- **Milestone 8 — Analytics Engine** — Full analytics pipeline implemented. Pull polling + webhook ingestion; `CampaignKpiSnapshot` (interim/final); `RecommendationKpiService`; `DecisionEffectivenessService`; `LearningService` with 8 signal types; Filament panels. 97 new tests (365 total, 363 passing). PHPStan level 8 — 0 errors. See [Milestone-8-Review.md](reviews/Milestone-8-Review.md).

- **Milestone 7.5 — Analytics Engine Specification** — `specs/core/analytics-engine.md` written. Covers domain model (`ExecutionMetric`, `CampaignKpiSnapshot`, `MetricRetrievalLog`), pull polling + webhook push ingestion, `AnalyticsProvider` interface and registry, normalised metric keys, campaign KPIs, recommendation KPIs, decision effectiveness metrics, BusinessBrain feedback loop, learning inputs, privacy constraints, acceptance criteria, and future extensibility. `ROADMAP.md` Phase 7 updated with concrete deliverables.

- **Milestone 7 — EmailPublisher** — First real channel publisher shipped. `EmailProvider` interface + `EmailProviderRegistry` + `LogEmailProvider` + `FakeEmailProvider` + `EmailRenderer` + `EmailPublisher` all wired into M6 infrastructure. 29 new tests (268 total, 266 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. See [Milestone-7-Review.md](reviews/Milestone-7-Review.md).

- **Milestone 6.5 — Publishing Hardening** — Renderer layer integrated, credential validation hardened, blueprint validation expanded, `CampaignPublished` event bug fixed. 28 new tests (239 total, 237 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. See [Milestone-6.5-Review.md](reviews/Milestone-6.5-Review.md).

- **Milestone 6 — Publishing Infrastructure** — Full pipeline implemented: `RecommendationApproved → PublishCampaign → PublishContent × n → LogChannelPublisher → Execution completed → CampaignPublished`. 47 new tests (211 total, 209 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. See [Milestone-6-Review.md](reviews/Milestone-6-Review.md).

- **Milestone 5 — Campaign Engine** — Full Campaign Preparation + Content Generation + Approval Workflow implemented. `CampaignBlueprint` VO, `CampaignPreparationAnalyst`, `CampaignPreparationService`, 5 `ContentGenerationPrompt` variants, `ContentGenerationAnalyst`, `ContentGenerationService`, `RecommendationService`, `ApprovalService` (approve + reject with full status transitions). Jobs: `PrepareCampaign` (full), `GenerateContent`, `CreateRecommendation`. Events: `CampaignAssetsReady`, `RecommendationCreated`, `RecommendationApproved`, `RecommendationRejected`. Filament admin panel with 6 resources (Company, Opportunity, Decision, Campaign, ContentAsset, Recommendation) + approve/reject actions. 35 new tests (164 total, 162 passing, 2 Redis skipped). PHPStan level 8 — 0 errors.
- **Milestone 5 — Campaign Blueprint spec** — `specs/core/campaign-blueprint.md` written; covers Blueprint definition, relationship to Decision, all 10 required fields with validation rules, versioning and immutability, `CampaignPreparationAnalyst` AI contract, `BlueprintGenerationFailedException`, full Blueprint→Asset→Renderer pipeline, `ChannelRenderer` interface contract, acceptance criteria, and future extensibility
- **Milestone 4 — Decision Engine spec** — `specs/core/decision-engine.md` written; covers Decision definition, lifecycle, statuses, types, inputs, all five guard conditions, selection algorithm, required rationale fields, `RationaleGenerationAnalyst` contract, Campaign pipeline handoff (M5), M4 implementation list, explicit out-of-scope list, acceptance criteria, and extensibility
- **Milestone 4 — Opportunity Engine spec** — `specs/core/opportunity-engine.md` written and CTO approved; covers Opportunity lifecycle, types, scoring formula, evidence chains, expiration, deduplication, `OpportunityDetector` interface, rule-based vs. AI-assisted detectors, implementation scope
- **Milestone 3 + cleanup** — Fact extraction, knowledge synthesis, BusinessBrain assembly; `Observation.facts()` + `last_enriched_at` fix; 83 tests (81 passing); PHPStan level 8 clean
- **Milestone 2 + cleanup** — `IntegrationService::create()`, `SyncIntegration` uniqueness guard, catalog type fix; 48 tests (46 passing); PHPStan level 8 clean
- **Milestone 1 hardening** — PHPStan raised to level 8 (0 errors); stack versions documented; technical debt items recorded; CHANGELOG updated
- **Milestone 1** — Laravel 13 / PHP 8.3 application scaffolded with full tooling chain (Pint, PHPStan, PHPUnit, GitHub Actions)
- Core domain contracts: `AiProvider`, `Analyst`, `Connector`, `OpportunityDetector`, `ContentGenerator`
- Abstract `Prompt`, `AiResponse`, `FakeAiProvider`, `BusinessBrain` value object
- 25 bootstrap tests → 40 feature tests; Supervisor config for all five queues

---

## Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Auction/dealer sites render inventory via JavaScript, blocking simple HTTP crawl | High | High | Spike a headless browser connector (Puppeteer or Playwright via Node sidecar) before Phase 2 goes live |
| AI provider rate limits during parallel processing (crawl → extract → synthesize) | Medium | Medium | All AI jobs run on dedicated `ai` queue; implement per-provider rate limiting in `AnthropicProvider` |
| No real AI provider — all AI paths use `FakeAiProvider` | High | Critical | Implement `AnthropicProvider` before any customer data is processed |
| SSRF in `WebPageCrawler` — user URLs not validated to public IPs | High | Critical | Add IP range validation before outbound Guzzle requests |
| Filament panel has no superadmin gate | High | Critical | Add `canAccess()` policy or `authMiddleware` before Filament is accessible in production |
| CBB Auctions engagement becomes informal, reducing design partner feedback | Low | Medium | Formalize the design partner relationship; schedule regular demos |
| Scope creep into CRM, billing, or ads integrations before core loop is proven | Low | High | ROADMAP.md exclusions list is authoritative; defer any out-of-scope request explicitly |

---

## Last Updated

**2026-07-11** — Fixed a live bug reported during Instagram Observation testing: adding a Marketing Presence channel got stuck on "Adding…" forever. Confirmed via headless-browser reproduction that the server-side request always succeeded (the row was created every time), but `resources/js/Pages/App/Settings/MarketingPresence/Index.vue` crashed while re-rendering afterward — `rowState` (per-row edit state) was only ever populated once at mount time, so a newly-added row's `<select v-model="rowState[channel.id].status">` read off `undefined` and threw, halting the render. Pre-existing bug from Milestone 11, unrelated to Instagram specifically — would affect adding any channel type once one already existed. Fixed with a `watch` on `props.channels` that adds missing rowState entries without clobbering in-progress edits to existing rows. 3 new Vitest tests, one of which reproduces the exact crash against the pre-fix code. 37 Vitest tests total, all passing. No backend changes. See [Index.spec.ts](../backend/resources/js/Pages/App/Settings/MarketingPresence/Index.spec.ts).

**2026-07-11** — Milestone 12 Phase 1 (Instagram Observation, Beta) complete: Instagram is now Atlas's first observable Marketing Source alongside the website crawl. A new `InstagramConnector` reuses the existing `Connector`/`ConnectorRegistry` architecture to fetch a single current profile snapshot (account id, username, display name, profile picture, bio, website, follower/following counts) via the Instagram Graph API, given a company-entered access token (beta scope — no OAuth, no publishing, no historical import). The snapshot is recorded as an ordinary `Observation` and flows through the unchanged Observe → Understand → Decide loop — no separate AI pipeline. A new `InstagramAnalyst` maps the already-structured fields directly into Facts deterministically (no AI call), and `ProcessObservation` now resolves the right analyst via a new `AnalystRegistry` mirroring `ConnectorRegistry`'s pattern. Business Brain integration required zero code changes (`BusinessBrainService::assemble()` was already source-agnostic) and multi-channel recommendation reference was already correct and already tested (`DecisionEngineTest`, `MarketingChannelSelectorTest`) — both verified, neither modified. 27 new tests, including a real Postgres migration verification. 963 tests (960 passing, 3 skipped), PHPStan level 8 clean, Pint clean, build green.

**2026-07-10** — Private Beta Customer Success Toolkit created (documentation only, no application code changed): `docs/beta/Customer-Interview-Guide.md` (structured questions for onboarding, first recommendation, week one, month one, plus open-ended discovery), `docs/beta/Founder-Learning-Log.md` (a reusable per-customer entry template plus a customer roster with only the one confirmed fact — CBB Auctions as Customer 1), and `docs/beta/Beta-Success-Metrics.md` (eight measurable Stage A success criteria — onboarding completion, time to first recommendation, approval rate, engagement, recommendation usefulness, weekly active companies, support burden, willingness to pay — each with a definition, measurement method, and target). All three operationalize `Version-1.0-Roadmap.md`'s Stage A objective and `Private-Beta-Execution.md`'s checklist, written with no fabricated example data since Stage A hasn't started yet. See [Customer-Interview-Guide.md](beta/Customer-Interview-Guide.md), [Founder-Learning-Log.md](beta/Founder-Learning-Log.md), [Beta-Success-Metrics.md](beta/Beta-Success-Metrics.md).

**2026-07-10** — Marketing landing page built at `/` per `docs/marketing/Landing-Page.md`'s full 16-section spec, using the existing Vue/Inertia/Tailwind v4 design system. Copy was corrected against current product reality in several places the spec overstated: publishing claims reworded to describe the real approval gate rather than asserting live external delivery (no channel actually publishes externally yet, per the Channel Publishing Reality Audit); fabricated testimonials/stats were not published, replaced with an honest description of the real CBB Auctions design partnership; CTAs pointing at non-existent infrastructure (demo booking, pricing page, legal pages) were re-pointed at real routes or omitted. Filled a real gap in `docs/design/System.md`'s implementation — its typography scale was specified but never added to `app.css` — added now. `@heroicons/vue` installed (specified by the design system, never previously used). Accessibility: skip link, FAQ accordion with proper ARIA and focus management, progressbars with visible numeric labels, figure/figcaption on UI mockups, no skipped heading levels. Animations respect `prefers-reduced-motion` via new `useScrollReveal`/`useCountUp` composables. 14 new tests (3 PHP, 10 Vitest — one of which caught a real inverted-boolean bug in `ScoreBar` before it shipped). 936 PHP tests (933 passing, 3 skipped), 34 Vitest tests, PHPStan level 8 clean, Pint clean, build green. See [Landing-Page.md](marketing/Landing-Page.md).

**2026-07-10** — Critical Production Blocker 8 of 8 (final blocker) partially resolved — repository-representable subset only; real backups against a real production database remain operator-executed and undone (gated on Blocker 7). Added `infrastructure/backup/atlas-db-backup.sh`/`atlas-db-verify.sh`/`atlas-db-restore.sh` (provider-neutral `pg_dump` wrapper, fails loudly, destructive restore requires exact-match confirmation) and `docs/operations/Backup-and-Recovery.md` (strategy, safety, retention/encryption/off-site guidance, explicit code-complete-vs-operator-complete distinction). A real automated local restore drill (`tests/Feature/Backup/BackupRestoreDrillTest.php`) round-trips data between two disposable scratch PostgreSQL databases, surfacing a documented pg_dump/server version-compatibility gotcha along the way. Confirmed no application-managed uploaded files exist today, so no speculative file-backup mechanism was added. 12 new tests. All eight Critical Production Blockers are now addressed to the extent this repository can address them — Blockers 7 and 8 each retain a genuine operator-executed remainder. See [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md).

**2026-07-10** — Critical Production Blocker 7 of 8 partially resolved (code-representable subset only — real infrastructure provisioning is still operator-executed and undone). Replaced Blocker 3's hardcoded `TrustProxies` wildcard with an operator-configured `TRUSTED_PROXIES` env var (fail-closed default: unset trusts no proxies) parsed by a new `App\Services\Http\TrustedProxyResolver`. Added `docs/deployment/Production-Topology.md` documenting the expected reverse-proxy/app-server/database/Redis/queue-worker/scheduler shape. 12 new tests prove HTTPS detection, HSTS, client IP resolution, and IP-keyed rate limiting all work correctly behind a trusted proxy and can't be spoofed by an untrusted one. See [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md).

**2026-07-10** — Critical Production Blocker 6 of 8 resolved: Postmark is now a fully wired production mailer (config completed, and a previously-undocumented gap fixed — `symfony/postmark-mailer`/`symfony/http-client` were never installed, so `MAIL_MAILER=postmark` would have thrown even with a valid key). A new `ProductionMailerGuard` refuses delivery and logs critically if production is left on `log`/`array`; real transport failures are caught and logged without leaking secrets; the same generic anti-enumeration response is preserved in every case. 17 new tests. See [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md).

**2026-07-10** — Critical Production Blocker 5 of 8 resolved: a new `FailedJobResource` Filament panel (`/admin/failed-jobs`) gives operators visibility into and a Retry/Discard recovery workflow for `failed_jobs` (queue, job class, failure timestamp, exception summary), gated by existing superadmin-only panel access. An `ErrorTracker` abstraction (`App\ErrorTracking\Contracts\ErrorTracker` + `NullErrorTracker`) is wired into `withExceptions()->reportable()`, additive to Laravel's own logging — no real vendor (Sentry) installed yet, deliberately deferred and documented with exact production-activation steps. 18 new tests. See [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md).

**2026-07-10** — Critical Production Blocker 4 of 8 resolved: every scheduled entry in `routes/console.php` now has `->withoutOverlapping()`/`->onOneServer()` (except `ApplyLearnings`, already `ShouldBeUnique`), plus a committed `infrastructure/cron/atlas-scheduler` artifact so `php artisan schedule:run` actually gets triggered in production. `CheckChannelHealth`, `ProcessAnalyticsWebhookEvent`, `PruneRawMetrics`, and `PublishScheduledContent` — the four jobs the audit flagged as missing retry/backoff — now have `$tries`/`$backoff`/`failed()` structured logging. `failed_jobs` recovery visibility deliberately deferred to Blocker 5. 14 new tests. See [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md).

**2026-07-10** — Critical Production Blocker 3 of 8 resolved: `TrustProxies` configured (trusting `*`, pending Blocker 7's real proxy layer) and a new global `SecurityHeaders` middleware adds `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, a baseline `Content-Security-Policy`, and conditional `Strict-Transport-Security` (only sent over an actually-secure request) to every response, including the Filament admin panel. Full script/style/connect-src CSP lockdown deliberately deferred as a larger, nonce-based follow-up to avoid risking Filament/Inertia/Vite breakage. 5 new tests. See [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md).

**2026-07-10** — Critical Production Blocker 2 of 8 resolved: `POST /api/analytics/webhooks/{provider}` now has a named rate limiter (`analytics-webhook`, 60/min per IP, with structured logging on rejection) instead of being fully public and unthrottled. Discovered and documented (but did not fix, as out of scope) that every pre-existing bare `throttle:N,M` route shares one rate-limit bucket per IP regardless of route — confirmed exhausting `/login`'s bucket also blocks `/register`. 10 new tests. See [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md).

**2026-07-10** — Critical Production Blocker 1 of 8 resolved: `EnsureCompanyMembership` now binds `current_company_id` into the container on every real `/app/*` request, making `CompanyScope`'s global scope genuine defense-in-depth instead of dead code. Fixing this surfaced a real regression — three places that look up a user's memberships *across* companies (the sidebar switcher's `companies` prop, the middleware's own membership resolution, and `CompanySelectorController`) were incorrectly narrowed by the newly-active scope, since they're inherently cross-tenant, user-keyed queries — all three fixed with explicit `withoutGlobalScopes()`. 5 new tests prove the binding and the scope's live filtering, not just that manual filtering still works. See [Critical-Production-Blockers.md](plans/Critical-Production-Blockers.md) for the full 8-blocker plan.

**2026-07-10** — Production Deployment Readiness Audit complete. Read-only, evidence-based audit of the repository (not infrastructure that doesn't exist yet) covering infrastructure config, Laravel production config, security, and operational risk, each with exact file/line evidence and a READY/PARTIALLY READY/NOT READY verdict. Headline finding: `CompanyScope`'s global scope never activates in production (`current_company_id` is only ever bound in test files) — tenant isolation today relies entirely on manual per-query `company_id` filtering, applied consistently but with no structural safety net. 8 critical blockers, 8 high-priority items, 6 nice-to-have improvements identified. See [Production-Deployment-Audit.md](reviews/Production-Deployment-Audit.md).

**2026-07-10** — Private Beta Execution Checklist written. Operator's checklist (not a roadmap, not a sprint plan) for running Stage A private beta: production infrastructure checklist, per-customer onboarding checklist (including Marketing Presence), daily internal support checklist, a single Go/No-Go gate for inviting Customer 1, and a first-week operating cadence with daily tasks and metrics. See [Private-Beta-Execution.md](plans/Private-Beta-Execution.md).

**2026-07-10** — Version 1.0 Product Roadmap written. Strategic, non-implementation roadmap covering current platform assessment (complete/production-ready/beta-ready/risks), four gated stages (Private Beta → Paid Beta → Version 1.0 Public Launch → Version 2.0), work prioritized across Infrastructure/Customer Experience/Integrations/AI Improvements/Growth/Operations, explicit deferred-features and technical-debt-to-carry-vs-must-fix lists, and success metrics per stage. See [Version-1.0-Roadmap.md](plans/Version-1.0-Roadmap.md).

**2026-06-29** — P0 onboarding pipeline fix complete (Phase 4). Critical `body_text`/`bodyText` key mismatch in `WebsiteAnalyst` fixed — all real crawls now produce facts. AI provider binding updated: `AnthropicProvider` used when `ANTHROPIC_API_KEY` is set in local env; `LocalAiProvider` only when no key. `OnboardingStatusController` adds `crawl_succeeded` and `ai_failed` fields. Status page shows dedicated "AI analysis encountered an error" card distinct from crawl failure. All test payloads updated from `bodyText` to `body_text`. `SettingsControllerTest::test_sync_integration_dispatches_job` fixed with `Bus::fake()`. 603 tests (601 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. See [P0-New-Customer-Onboarding-Fix.md](reviews/P0-New-Customer-Onboarding-Fix.md).

**2026-06-28** — P0 onboarding pipeline fix complete (Phase 3). AI pipeline now runs end-to-end in local development: `LocalAiProvider` returns deterministic stubs in `local` env; default blog channel seeded on onboarding; `.env.example` defaults to `QUEUE_CONNECTION=sync`; pipeline logging added at every stage; status page shows "queue worker needed" card when facts stall > 90s. Full crawl → facts → recommendation pipeline test added (`OnboardingPipelineTest`). 603 tests (601 passing, 2 Redis skipped). PHPStan level 8 — 0 errors. See [P0-New-Customer-Onboarding-Fix.md](reviews/P0-New-Customer-Onboarding-Fix.md).

**2026-06-28** — P0 onboarding pipeline fix Phase 1 + Phase 2. Website crawl now runs synchronously on form submit (`dispatchSync`) — no queue worker needed for first sync. `connect_timeout` bug fixed in `WebPageCrawler`; `max_pages` default changed to 1 for fast local onboarding. Integration error state exposed on the status API (`integration_status`, `sync_started`). Status page shows clear failure UI when crawl fails. See [P0-New-Customer-Onboarding-Fix.md](reviews/P0-New-Customer-Onboarding-Fix.md).

**2026-06-27** — Landing Page Design & Content Specification complete. Full marketing spec for Atlas: hero through footer, 16 content sections, recommendation showcase mockup, industry cards, mobile layout, animation spec, accessibility requirements, CTA strategy, and copy principles. See `docs/marketing/Landing-Page.md`.

*Update this document at the end of every sprint and whenever a significant decision is made or risk changes.*
