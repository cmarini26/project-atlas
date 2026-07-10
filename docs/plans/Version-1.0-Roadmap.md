# Version 1.0 Product Roadmap

**Date:** 2026-07-10
**Horizon:** ~12 months
**Audience:** Founders, design partners, and future hires who need to understand where Atlas is going and why — not how to build any single feature.
**Companion documents:** [Beta-Readiness-Audit.md](../reviews/Beta-Readiness-Audit.md), [Product-Polish-Audit.md](../reviews/Product-Polish-Audit.md), [Channel-Publishing-Reality-Audit.md](../reviews/Channel-Publishing-Reality-Audit.md), [Landing-Page.md](../marketing/Landing-Page.md), [ROADMAP.md](../../ROADMAP.md), [Private-Beta-Plan.md](Private-Beta-Plan.md)

This document is strategic, not tactical. It does not assign engineering tasks, name files, or estimate sprints — [ROADMAP.md](../../ROADMAP.md) and the milestone-by-milestone review docs already do that at the implementation level. This document answers a different question: **in what order do we earn the right to more customers, and what has to be true at each step to deserve them.**

---

## 1. Current Platform Assessment

### What is complete

The eight-phase product loop specified in `ROADMAP.md` is fully built: **Observe → Understand → Decide → Recommend → Prepare → Approve → Execute → Measure → Learn**, plus Milestone 10 (full customer dashboard) and Milestone 11 (Marketing Presence — a company's declared marketing footprint, distinct from technical publishing capability, now integrated into the Business Brain, Decision Engine channel selection, and the Recommendation UI). Concretely, complete and tested:

- Multi-tenant domain model (companies, catalogs, digital twins) with 838+ passing tests and PHPStan level 8 clean across the whole codebase.
- Website crawling → Fact extraction → Knowledge synthesis → Business Brain assembly, running end-to-end against the real Anthropic API.
- Rule-based + AI-assisted opportunity detection, a Decision Engine with five guard conditions and Marketing-Presence-aware channel selection, and campaign preparation with per-channel content generation.
- A mandatory, structurally-enforced four-part rationale (why now / why this / why this channel / why it will work) on every recommendation — the product's core differentiator, and the one thing competitors relying on generic content generation cannot easily copy.
- A three-action approval workflow (Approve / Edit & Approve / Not This Time) with a full audit trail, and a Learning Engine that adjusts future scoring from approvals, rejections, and edits.
- A complete 16-page customer dashboard (Vue 3 + Inertia) covering onboarding, recommendations, campaigns, publishing status, analytics, learning, and settings, including a Marketing Presence settings page and a "channel mix" view on the recommendation detail page.
- A Filament admin panel gated behind a superadmin check, useful for support and debugging today.

### What is production-ready

"Production-ready" here means: the code is correct, tested, and would behave safely if it were reachable. By that bar:

- The domain model, tenancy scoping (`CompanyScope` + `BelongsToCompany`, enforced with explicit `withoutGlobalScopes()->where('company_id', ...)` in every queue/job context), and the AI pipeline's provider abstraction (Anthropic in production, deterministic fakes in tests) are solid engineering.
- SSRF protection on the website crawler, a real superadmin gate on the admin panel, and rate limiting on login/register/onboarding-submit are all shipped (per the P0 Product Polish sprint).
- Onboarding is failure-mode-complete: crawl failure, AI failure, provider overload, and missing-worker all have distinct, honest states — a rare level of polish for a pre-launch product.
- The recurring intelligence loop actually recurs now: integrations re-sync on schedule and opportunities expire, closing what was previously the single biggest gap between Atlas's stated philosophy ("knows more tomorrow than today") and its behavior.

### What is beta-ready (and what is not)

Beta-ready means safe to hand to a small number of real, paying customers who have agreed to be early adopters. **The platform is not there yet**, and the gap is almost entirely operational, not architectural:

- **No production environment exists.** No server, no domain, no SSL, no deployed application. `APP_URL` is still `localhost`.
- **No real outbound email.** The mail driver is `log` only — password reset, any future notification, and the beta plan's own assumption of "email publishing via Postmark as one real channel" are all unimplemented. The Channel Publishing Reality Audit confirmed in July that **no channel type publishes to a real external platform today** — every "Published" badge in the UI describes a log line, not a delivery.
- **No monitoring, no backups, no legal framework.** No uptime alerting, no error tracker, no failed-job visibility, no database backup/restore procedure, no privacy policy, no terms of service.
- **Multi-tenancy enforcement is better than the June audit assumed, but not yet proven under load.** `EnsureCompanyMembership` binds the active company to a request-scoped attribute (not a container singleton), which is the structurally correct pattern for a stateless PHP-FPM request — the specific "singleton leaks between concurrent requests" failure mode the audit worried about does not appear to apply as originally feared. What remains true and unresolved: no PostgreSQL Row-Level Security as defense-in-depth, and no dedicated cross-company isolation test suite exercised under concurrent load.

The [Beta-Readiness-Audit.md](../reviews/Beta-Readiness-Audit.md) scored the platform 31/100 with a NO-GO recommendation on 2026-06-27, citing 7 critical blockers. Five of those seven (production server, domain/SSL, backups, monitoring, legal documents) remain entirely unaddressed as of this writing — they require deliberate infrastructure and legal work that has not yet been scheduled, not further product engineering. The remaining two (tenancy verification, email delivery) are partially addressed in code but not yet operationally proven.

### Remaining risks

| Risk | Why it matters |
|---|---|
| **The landing page's marketing promises outrun what the product can deliver today.** | Copy already drafted for launch claims real email delivery, real publishing capability, working analytics comparisons, and a published privacy/data-deletion policy. None of these exist yet. Publishing this copy before the underlying capability ships would be a customer-trust failure on day one. |
| **Every channel is simulated.** | `LogChannelPublisher`/`LogEmailProvider` write to a log file and report success unconditionally. A private beta customer approving a campaign today is approving something that will never reach a real audience. This must be framed honestly in beta communications until at least one real publisher exists (see Stage A). |
| **AI provider is a single point of failure.** | No fallback provider, no per-company rate limiting, no cost metering. An Anthropic outage halts every company simultaneously; a runaway prompt loop has no spend ceiling. |
| **Nobody is watching the system.** | No error tracker, no failed-job alerting. Today's incidents are diagnosed by grepping a log file by hand. This does not scale past a handful of customers watched personally by the founding team. |
| **The design-partner relationship is informal.** | CBB Auctions is engaged but there's no formal agreement. The whole roadmap assumes their continued cooperation as Customer 1 and the primary validation vertical. |
| **Scope creep is the most likely cause of a missed launch, not any single technical gap.** | The platform already does a lot. The temptation to add more before fixing the operational floor is real and explicitly called out as a founding-era risk in `ROADMAP.md`. |

---

## 2. Roadmap by Stage

Each stage below is a **gate**, not a calendar date. A stage ends when its success metrics are met, not when a fixed number of weeks has elapsed. Stages are cumulative — nothing built for Stage A is thrown away; each later stage adds a layer on top of the previous one's foundation.

### Stage A — Private Beta (5–10 customers)

**Objective:** Prove the core loop works for real businesses, in production, without the founding team manually holding the system together.

**What must be true to enter this stage:** a production environment exists and is reachable over HTTPS; the platform's multi-tenancy claim is proven under a realistic concurrent-request test, not just code review; real transactional email is delivering; database backups exist and have been restore-tested at least once; a privacy policy and terms of service are published; and there is *some* way to be alerted if the system goes down without a customer having to report it.

**What this stage explicitly does not require:** real social media publishing, real analytics ingestion, multi-seat teams, billing, or a public-facing marketing launch. Customers are hand-selected, informed they are early adopters, and told plainly what does and doesn't work yet.

**Customer profile:** Comic book auction houses, exotic car dealerships, and closely adjacent dynamic-inventory verticals Atlas already understands. English-language, public website required, no procurement or SLA expectations. CBB Auctions is Customer 1.

**What "done" looks like:** 5–10 real businesses have connected a website, received at least one recommendation, and approved or rejected it — with the founding team spending its time on product feedback, not firefighting infrastructure.

### Stage B — Paid Beta (25–50 customers)

**Objective:** Prove the loop holds up at 5–10x the customer count, that at least one channel really publishes, and that customers will pay for what Atlas does today.

**What must be true to enter this stage:** Stage A ran for long enough to surface its real failure modes, and the ones that mattered were fixed; there's a real, working publisher for at least one channel (almost certainly email, since the provider contract and webhook receiver already exist and only need a real provider swapped in); billing exists in at least a minimal, manually-reconcilable form; there is enough monitoring and alerting that an incident is caught before a customer reports it, not after; and AI usage is tracked per company so spend and abuse are both visible.

**Customer profile:** Widens beyond the original two verticals to other dynamic-inventory or catalog-driven small businesses that fit Atlas's existing domain model without new detector types. Customers now pay — even a small amount changes the seriousness of every commitment made to them.

**What "done" looks like:** 25–50 paying customers, a real publish path that customers can see actually reach an audience, a support process that isn't purely founder-time-bound, and enough usage data to know which parts of the product actually drive the "approve" decision.

### Stage C — Version 1.0 Public Launch

**Objective:** Open the doors. Anyone in a supported vertical can sign up, pay, and get value without a human on the Atlas side doing anything bespoke for them.

**What must be true to enter this stage:** the landing page's claims and the product's actual capability are the same thing — no promise on the public marketing site describes a feature that doesn't exist; self-serve billing works without manual invoicing; the operational floor (monitoring, alerting, backups, incident response) has been proven under real paying-customer load from Stage B, not just designed; and at least two real publishing channels exist (so "connect your channels" is a real, valuable onboarding step, not a promise of a promise).

**Customer profile:** Self-serve signups matching the personas the landing page already targets (Marcus — the owner-operator; Sofia — the marketing contractor managing several small-business clients). No longer limited to hand-picked design partners.

**What "done" looks like:** Public launch announced, self-serve signup and payment both work, and the support burden per customer is low enough that growth doesn't require proportional headcount growth.

### Stage D — Version 2.0

**Objective:** Move from "a tool that recommends and waits for approval" to "a platform that compounds its own intelligence and expands what kinds of businesses it can serve" — without abandoning the founding promise that publishing requires human approval.

**Direction, not commitment:** Stage D is deliberately the least specified stage in this document. Its job is to be informed by what Stages A–C actually teach us, not by what looks appealing today. Likely candidate themes, none yet committed:

- Expansion beyond dynamic-inventory verticals into other small-business categories, guided by which customer segments self-select into Stage C.
- Deeper channel coverage (the social platforms explicitly deferred through Stage C).
- Team/agency features, since Sofia-persona customers (marketing contractors serving multiple businesses) are already named in the landing page's target personas but structurally unsupported today (no multi-seat permissions).
- A meaningfully deeper Learning Engine — today's version adjusts scoring weights and detects edit patterns; a mature version could compare outcomes across the customer base (never across companies' underlying data, only in aggregate, anonymized model-improvement terms) to make every company's Business Brain smarter faster.
- Whatever channel or integration Stage B/C data says customers actually want, which is unknowable in advance and shouldn't be pre-decided now.

---

## 3. Work Prioritized by Category

This is not a task list — it's a statement of relative priority within each category, so that when two pieces of work compete for the same engineering time, there's an existing answer for which one matters more right now.

### Infrastructure

**Priority order:** production environment → tenancy/security proof → monitoring & backups → performance headroom.

Nothing else in this roadmap matters if the platform isn't safely reachable. This category is entirely a Stage A concern; by Stage B it should be operating, not being built. `BusinessBrainService`'s missing Redis cache and PostgreSQL Row-Level Security both belong here as important-but-not-blocking hardening — see the technical debt section below for why each is sequenced where it is.

### Customer Experience

**Priority order:** honesty of current claims → account lifecycle basics → the channel dead-end → collaboration & polish.

The single highest-leverage, lowest-risk category of work available: every copy location that overclaims what the product does (fixed once already by the Channel Publishing Reality Audit) must stay honest as new capability ships, not just at the moment each fix lands. Account lifecycle gaps (password reset — shipped; email verification, profile management — not yet) are the most predictable source of support tickets in any early customer base. The absence of a Channels management UI is a real product limitation, not merely a polish item — the empty-state copy already tells users to do something the product doesn't let them do.

### Integrations

**Priority order:** one real publisher (email) → real analytics for that channel → additional social channels → additional data-source connectors.

This category should move slower than it's tempting to move it. The product's differentiation is the recommendation and its rationale, not breadth of channel coverage — Atlas can be right about *what* to recommend and *why* long before it can act on every channel a business might use. Real analytics ingestion is explicitly sequenced after real publishing, because measuring a simulated send teaches nothing.

### AI Improvements

**Priority order:** resilience and cost control → usage tracking and provenance → learning depth → new detector types.

Right now there is exactly one AI provider wired up and no fallback, no rate limiting, and no per-company spend tracking. This is riskier the more customers depend on the pipeline working, which makes it a Stage A/B concern, not a Stage D one — but it is explicitly *not* about making the AI "smarter" yet. Provenance (which prompt version produced which fact, decision, or piece of content) is cheap to add now and effectively impossible to retrofit onto historical data, so it should be treated as time-sensitive even though it's not customer-visible.

### Growth

**Priority order:** design-partner formalization → beta waitlist / hand-picked outreach → landing page truth-matching → public self-serve launch.

Growth work is sequenced almost entirely *after* the operational and product work in the earlier categories, on purpose — the landing page already promises more than the product delivers, and shipping growth motion ahead of product reality would spend trust the company doesn't have a large reserve of yet. The one growth task that belongs early is formalizing the CBB Auctions relationship, since the entire Stage A plan depends on their continued, reliable participation as Customer 1.

### Operations

**Priority order:** incident visibility → support workflow → legal/compliance → internal metrics for decision-making.

"Operations" here means the unglamorous work of running a company with paying customers: knowing when something breaks, having a channel a customer can reach a human through, being legally allowed to hold their data, and being able to answer "is this working" with something better than a founder's gut feeling. This category is intentionally weighted toward Stage A/B — it is exactly the layer the Beta Readiness Audit found almost entirely absent, and exactly the layer that determines whether the founding team can scale past personally watching every customer.

---

## 4. Deferred Features, and Technical Debt

### Features intentionally deferred

These are not oversights — they are explicit scope decisions, several already recorded in `ROADMAP.md`'s exclusion list, reaffirmed here for the Version 1.0 horizon:

- **Social media publishing** (Instagram, Facebook, LinkedIn, X) — deferred until at least one channel (email) has proven the publish → measure → learn loop works end-to-end in production. Content generation for these channels already exists; only the "actually send it" step is missing.
- **CRM and individual contact management** — Atlas reasons about audiences in aggregate; it does not and should not become a system of record for individual contacts.
- **Team and agency permissions beyond owner/admin/member** — needed for the Sofia persona (marketing contractors managing multiple client businesses) but not before Stage C proves the core single-owner loop at scale.
- **Billing and subscription self-service beyond a minimal Stage B implementation** — full plan management, usage-based pricing, and dunning flows are a Stage C+ concern.
- **Paid media / ads integrations** — organic content and recommendations come first; paid media is a distinct product surface that shouldn't be added before the core loop is proven.
- **White-label or API-only product** — Atlas is built for direct small-business use first; platform plays are explicitly a later, separate decision.
- **Mobile application** — the web application remains the primary and only surface through at least Version 1.0.
- **Multi-factor authentication** — genuinely valuable, but not expected by early adopters and lower-priority than the account-lifecycle basics (password reset, email verification) that are more likely to generate support burden.
- **Cross-company learning aggregation** (comparing outcomes across customers to improve the model) — a plausible Stage D theme, explicitly not designed or committed to yet, and must never leak one company's underlying data to another.

### Technical debt worth carrying

Debt that is understood, documented, and reasonable to keep past Version 1.0 because fixing it now would cost more than the risk it carries:

- **No PostgreSQL Row-Level Security.** `CompanyScope` plus consistent explicit `company_id` filtering in every queue/job context is the enforcement mechanism today. RLS is defense-in-depth, not the only line of defense — appropriate to add as the customer base grows, not before Stage A.
- **`EvidenceEvaluator`'s PHP-side filtering** for learning signal discrimination — correct and fine at beta-to-early-public-launch scale; becomes a real inefficiency only at a customer count well beyond Version 1.0's target.
- **No frontend unit test suite** beyond the handful of Vitest specs added alongside recent UI work — backend feature tests cover the product's actual contracts; frontend regressions are currently caught by manual QA, which is acceptable at current UI change velocity but should be revisited if that velocity increases.
- **Spec/code naming drift** (e.g., `Learning.value` vs. the spec's `payload` naming) — documentation-only mismatches with no runtime impact. Worth a cleanup pass eventually; not worth interrupting feature work for.
- **Mixed tenancy idioms across the codebase** (global scope vs. explicit `withoutGlobalScopes()->where('company_id', ...)`, chosen per context rather than unified) — functionally correct today because the two idioms are applied consistently within their respective contexts (web requests vs. queue/CLI), but relies on every future contributor knowing the rule. Worth documenting explicitly and worth an architectural lint rule, but not worth a large refactor.

### Technical debt that must be resolved before public launch (Stage C)

Debt that is acceptable for a hand-picked private beta but not acceptable once strangers can sign themselves up and pay:

- **Every "publish" and "connected" claim in the UI must correspond to something real by the time self-serve signup opens.** It's honest and defensible to tell 10 relationship-managed beta customers "this is simulated for now." It is not defensible to let a stranger self-serve into a product whose core promise — reaching their audience — doesn't yet function for the channel they configure.
- **AI provider resilience** (fallback provider or graceful degradation, per-company rate limiting, cost ceilings) — acceptable to run on a single provider with no cap while the founding team can watch usage personally across 50 customers; not acceptable once growth is self-serve and unwatched.
- **Real incident monitoring and alerting** — grepping a log file by hand does not scale past the customer count where the founders personally know every account.
- **A tested backup-and-restore procedure** — "we have backups" and "we have restored from a backup successfully at least once" are different claims, and only the second one is acceptable before entrusting the platform with self-serve customers' data.
- **A published, accurate privacy policy and terms of service**, including a working data-deletion/export path if the landing page continues to promise one (it currently does) — this is a legal requirement in most jurisdictions once the product accepts sign-ups from the public, not a nice-to-have.
- **Authorization consistency** — every mutating endpoint must enforce the same role checks the approval workflow already enforces; gaps here are tolerable to catch via founder-led support in a small beta and are not tolerable once account creation is unsupervised.

---

## 5. Success Metrics per Stage

| Stage | Primary metric | Supporting signals |
|---|---|---|
| **A — Private Beta** | Number of design-partner-caliber businesses that complete onboarding → first recommendation → approve-or-reject, without founder intervention | Time from website URL entry to first recommendation (target: under 10 minutes, per the founding north-star); zero cross-company data exposure incidents; at least one successful backup restore drill; system uptime tracked and visible, even if not yet formally SLA'd |
| **B — Paid Beta** | Number of paying customers, and the percentage still active/paying after 60 days | Recommendation approval rate trending stable-or-up as the Learning Engine accumulates evidence; at least one channel with a real, verifiable publish (not simulated); support tickets resolved without needing a database query by an engineer; AI cost per active company known and bounded |
| **C — Version 1.0 Public Launch** | Self-serve signups converting to paying customers without manual founder involvement | Landing-page-claim-to-product-reality audit passes clean (every promise on the public site is true); customer support volume per customer holds flat or improves as customer count grows (i.e., support isn't purely linear with headcount); at least two real publishing channels live |
| **D — Version 2.0** | To be defined once Stage C data exists | Placeholder only — committing to specific Version 2.0 metrics now would mean guessing what customers value before Stages A–C have told us |

---

*This roadmap should be revisited at the end of each stage, not on a fixed calendar cadence — a stage that meets its success metrics early should not wait for a calendar date to advance, and a stage that hasn't met them should not advance because time has passed.*
