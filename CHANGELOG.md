# Changelog

All notable changes to Project Atlas are documented here. Entries are organized by milestone, then by commit.

Format: each entry identifies what changed, which files/paths are affected, and why the change was made.

---

## [UI Polish Phase 1 — Visual refresh] — 2026-07-11

First of three approved UI improvements (visual refresh → page descriptions → first-time walkthrough) after user feedback that the app "looks very basic." This phase fixes the flattest part of the UI: every empty list in the app rendered the same gray 3-dot ellipsis icon regardless of context.

### Added

- `resources/css/app.css` — `--color-warning-{surface,border,text}` and `--color-info-{surface,border,text}` token pairs, formalizing colors `Badge.vue` already used ad hoc (amber). The indigo accent scale is untouched — the single-accent restraint is a deliberate design choice, not an oversight.
- `resources/js/Components/UI/Badge.vue` — new `info` variant, wired to the new tokens.
- `resources/js/Components/UI/EmptyState.vue` — new optional `variant?: 'default' | 'accent' | 'success' | 'warning' | 'info'` prop that recolors the icon circle. Purely additive — existing `title`/`description`/`icon`/`action` contract unchanged, so every existing call site keeps its current look until it opts in.
- `resources/js/Components/UI/EmptyState.spec.ts`, `Badge.spec.ts` (new, 12 tests) — cover the new `variant` prop (including a regression guard that the default variant preserves the pre-existing look) and the new `info` badge variant.

### Changed

- 13 `EmptyState` call sites across `Dashboard.vue`, `Recommendations/Index.vue`, `Opportunities.vue`, `Brain.vue` (×3: facts/knowledge/observations), `Campaigns/Index.vue`, `Campaigns/Show.vue`, `Publishing.vue`, `Analytics/Index.vue`, `Analytics/Show.vue`, `Learning.vue` — each now passes a context-appropriate Heroicon (`@heroicons/vue/24/outline`, already an installed dependency previously used only on the public marketing site, never inside the authenticated app) via the existing `icon` slot, plus a matching `variant` where it's not `default`.
- `Recommendations/Index.vue` — replaced one hand-inlined sparkle `<svg>` with the real `LightBulbIcon` for consistency with the rest of the app.

---

## [Bugfix — DetectOpportunities crashing on AI-invented subject_id] — 2026-07-11

Fixed a repeating queue failure surfaced after Instagram Observation testing: `DetectOpportunities` failed on retry after retry with `SQLSTATE[22001]: String data, right truncated: value too long for type character(26)`, silently leaving affected companies with facts extracted but zero opportunities/recommendations persisted.

### Fixed

- `app/Services/Analyst/OpportunityDetectionAnalyst.php` — the AI-assisted detector cast whatever `subject_type`/`subject_id` the LLM returned directly into an `OpportunityCandidate` with no validation. `opportunities.subject_id` is a fixed `char(26)` column sized for a ULID; every rule-based detector always supplies a real Eloquent model's ULID, but the AI analyst had no such guarantee — a hallucinated label (a product title, a SKU, a free-text description) longer than 26 characters crashed the insert and failed the whole job. Added `normalizeSubjectReference()`: `subject_type` must be one of the known internal types (`company`, `catalog`, `catalog_item`) and `subject_id` must pass `Str::isUlid()`, otherwise both are sanitized to `null` — the AI-detected opportunity still persists (with a description-only opportunity, no subject reference) instead of crashing the batch.
- `app/AI/Prompts/OpportunityDetectionPrompt.php` — added explicit system-prompt instructions telling the model to only set `subject_type`/`subject_id` when it has an exact internal Atlas entity reference, and to prefer `null` over inventing one.

### Added

- `tests/Feature/Opportunity/OpportunityDetectionAnalystTest.php::test_invalid_ai_subject_references_are_sanitized_to_null` — reproduces an AI response with a non-ULID, over-length `subject_id` and confirms it's sanitized to `null` rather than reaching persistence.

---

## [Bugfix — Marketing Presence "Add channel" stuck on "Adding…"] — 2026-07-11

Fixed a live bug reported during Instagram Observation testing. Frontend-only fix, no backend changes.

### Fixed

- `resources/js/Pages/App/Settings/MarketingPresence/Index.vue` — adding a channel left the "Add channel" button stuck on "Adding…" forever, even though the row was actually created successfully every time. Root cause: `rowState` (the per-row status/importance/objective edit state) was built once from the initial `channels` prop at component mount and never updated. When a channel was added, Inertia's redirect brought back an updated `channels` prop containing the new row, but `rowState` had no entry for it — the new row's `<select v-model="rowState[channel.id].status">` read `.status` off `undefined` and crashed the render. Confirmed via a real headless-browser reproduction (login → add channel → inspect console) that reproduced `Uncaught TypeError: Cannot read properties of undefined (reading 'status')` exactly. Fixed by replacing the one-time initialization with a `watch` on `props.channels` that adds an entry for any new row without touching rows that already exist, so unsaved edits to existing rows survive a reload triggered by adding another channel.

### Added

- `resources/js/Pages/App/Settings/MarketingPresence/Index.spec.ts` (3 tests) — one reproduces the exact crash against the pre-fix code (verified by temporarily reverting the fix and confirming the test fails with the same error), one confirms a newly-added row renders cleanly, one confirms unsaved edits to existing rows aren't clobbered.

### Notes

- This bug predates the Instagram Observation work (Milestone 11's Marketing Presence page) and is not specific to Instagram — it would have surfaced adding any channel type once at least one channel already existed. It was simply first noticed while testing the new Instagram integration.
- Diagnosed with a real reproduction, not guesswork: a headless Chromium session logged into a real local test account confirmed the server request always succeeded (redirect 302, row created) while the page threw a render error immediately after.
- 37 Vitest tests total, all passing. No PHP/backend files were touched; the full PHP suite (963 tests) and PHPStan/Pint remain unaffected and green.

## [Milestone 12 Phase 1 — Instagram Observation (Beta)] — 2026-07-11

Instagram is now Atlas's first observable Marketing Source alongside the website crawl. Scoped strictly to the beta: profile observation only — no publishing, scheduling, Stories, DMs, ads, analytics dashboards, competitor analysis, multiple accounts, or other social networks.

### Added

- `app/Models/InstagramAccount.php` + `database/migrations/2026_07_11_000300_create_instagram_accounts_table.php` — one row per company holding the latest known profile snapshot (account id, username, display name, profile picture, bio, website, follower/following counts, last synced timestamp). `Company::instagramAccount()` relation added.
- `app/Services/Observatory/Connectors/Instagram/` — `InstagramConnector` (implements the existing `Connector` contract), `InstagramProfileFetcher` (Instagram Graph API client, one request, no pagination), `InstagramProfileData` (value object), `Exceptions/InstagramApiException`. Registered in `ConnectorServiceProvider` alongside the existing `WebsiteConnector` — no changes to `ConnectorRegistry` itself.
- `config/instagram.php` — Graph API base URL and timeouts (the per-company access token lives encrypted on `Integration.config`, entered via Settings, not here).
- `app/Services/Analyst/Contracts/ObservationAnalyst.php` + `app/Services/Analyst/AnalystRegistry.php` — a `supports()`/`resolve()` dispatch pair mirroring `ConnectorRegistry` exactly, so `ProcessObservation` no longer hard-codes `WebsiteAnalyst` and a future observation source only needs to register its own Analyst.
- `app/Services/Analyst/InstagramAnalyst.php` — deterministic Fact mapper (`instagram.username`, `instagram.display_name`, `instagram.bio`, `instagram.website`, `instagram.follower_count`, `instagram.following_count`). Deliberately **not** an AI-calling Analyst — an Instagram profile snapshot already arrives structured, so mapping it is key/value translation, not extraction from prose, the same reasoning already applied to `MarketingPresenceSynthesizer`.
- `app/Services/Observatory/InstagramAccountService.php` — keeps the typed `InstagramAccount` snapshot in sync as a side effect of `InstagramAnalyst` processing each Observation.
- Settings UI (`resources/js/Pages/App/Settings.vue`) — a "Connect Instagram" card: an access-token form when no account is connected, or the profile snapshot (avatar, bio, follower/following counts, last synced) once one is. `SettingsController::connectInstagram()` + `POST /app/settings/integrations/instagram` route.
- 27 new tests: `InstagramProfileFetcherTest` (mocked Graph API HTTP), `InstagramConnectorTest`, `ConnectorRegistryTest` (extended), `AnalystRegistryTest`, `InstagramAnalystTest`, `InstagramAccountServiceTest` (including tenant isolation), `InstagramBusinessBrainIntegrationTest`, and `SettingsControllerTest` (extended for connect/reconnect).

### Changed

- `database/migrations/2026_06_26_000800_create_integrations_table.php` / `2026_06_26_000900_create_observations_table.php` — added `instagram`/`social` to the base enum definitions for fresh databases, plus new supplementary migrations (`2026_07_11_000100`, `2026_07_11_000200`) rewriting the Postgres CHECK constraints for already-migrated databases — mirrors `2026_07_05_000100_add_retrying_status_to_observations.php`'s exact precedent. Verified against a real local PostgreSQL instance, not just sqlite.
- `app/Services/Analyst/WebsiteAnalyst.php` — now also implements `ObservationAnalyst` (`supports()` returns true for `source_type: crawl`), alongside its existing AI-calling `Analyst` marker interface.
- `app/Jobs/ProcessObservation.php` — resolves the Analyst via the new `AnalystRegistry` instead of a hard-coded `WebsiteAnalyst` type-hint. Behavior for website observations is unchanged; all existing tests updated to inject `AnalystRegistry` instead.
- `app/Services/Observatory/IntegrationService.php` — `instagram` added to the default-name lookup.

### Notes

- **No separate AI pipeline was created**, per instruction. The Instagram snapshot reuses the existing Observation → Fact → Knowledge → Opportunity pipeline unchanged; `InstagramAnalyst` doesn't call an AI provider at all.
- **Business Brain integration required zero code changes.** `BusinessBrainService::assemble()` was already fully source-agnostic — `activeFacts` is queried by `company_id` alone, not by source — so Instagram Facts automatically join the same collection website Facts already populate. Verified with a dedicated integration test rather than assumed.
- **Multi-channel recommendation reference was already correct and already tested.** `DecisionEngine`'s channel-type affinity lists and `MarketingChannelSelector`'s primary/active preference logic already include Instagram (Milestone 11) and are covered by existing `DecisionEngineTest`/`MarketingChannelSelectorTest` — neither was touched by this phase.
- **`Integration` (observation source) and `MarketingChannel` (declared marketing presence) remain deliberately unrelated**, per `specs/core/marketing-presence.md` §7 — this phase only adds to the former; no FK or correlation was introduced between a connected Instagram Integration and a declared Instagram `MarketingChannel`.
- Full suite: 963 tests, 960 passing, 3 skipped. PHPStan level 8 — 0 errors. Pint clean. `npm run build` green. Vitest: 34 tests, all passing (no frontend regressions).

## [Private Beta Customer Success Toolkit] — 2026-07-10

Documentation-only change — no application code was touched. Three new `docs/beta/` documents operationalize `docs/plans/Version-1.0-Roadmap.md`'s Stage A private beta objective and `docs/plans/Private-Beta-Execution.md`'s operational checklist into tools a founder actually uses once real customers onboard.

### Added

- `docs/beta/Customer-Interview-Guide.md` — structured interview questions for four real customer-lifecycle checkpoints (onboarding, first recommendation, week one, month one) plus an open-ended discovery section, cross-referenced to the actual product (the four-part rationale, the three-action approval workflow, the marketing-presence onboarding step) rather than generic interview boilerplate.
- `docs/beta/Founder-Learning-Log.md` — a reusable per-customer, per-checkpoint entry template (customer, industry, expectations, surprises, struggles, what they loved, bugs, feature requests, willingness to pay, follow-up actions), plus a customer roster seeded only with the one confirmed fact available today (CBB Auctions as Stage A Customer 1).
- `docs/beta/Beta-Success-Metrics.md` — operationalizes the roadmap's Stage A success metric into eight specific, measurable criteria: onboarding completion rate, time to first recommendation, recommendation approval rate, customer engagement, recommendation usefulness (qualitative and quantitative), weekly active companies, support burden, and customer willingness to continue after beta — each with a definition, measurement method, data source, and target scoped explicitly to Stage A's 5–10 hand-picked customer scale.

### Notes

- Written with the same honesty discipline as the recent landing page work: no fabricated example customer entries, no invented metrics data, and an explicit, repeated acknowledgment that Stage A hasn't started yet (no production environment exists per `docs/STATUS.md`) — these are ready-to-use tools, not a record of beta activity that hasn't happened.
- No code was implemented and no application behavior changed, per the task's explicit scope.

## [Marketing Landing Page] — 2026-07-10

Built the public marketing landing page per `docs/marketing/Landing-Page.md`'s full 16-section specification, using the existing design system (`docs/design/System.md`).

### Added

- `resources/js/Pages/Marketing/Landing.vue` and 16 section components under `resources/js/Components/Marketing/` (nav, hero, trust bar, problem statement, how-it-works, Business Brain, recommendation showcase, approval moment, features, learning-over-time, industries, social proof, trust & security, final CTA, FAQ, footer), plus shared `ScoreBar.vue`, `SectionHeading.vue`, `MarketingButton.vue`, and `RecommendationMockup.vue`.
- `resources/js/composables/useScrollReveal.ts` and `useCountUp.ts` — scroll-triggered reveal and count-up animations, both resolving instantly (no motion) under `prefers-reduced-motion: reduce`.
- Typography scale tokens (`--text-display` through `--text-label-sm`) added to `resources/css/app.css` — specified in `docs/design/System.md` §2 but never actually implemented until now. Also added global `scroll-behavior: smooth` (with a `scroll-padding-top` offset for the fixed nav) for in-page anchor links, disabled under reduced motion.
- `@heroicons/vue` — specified by the design system's icon section but not previously installed; this is the first real icon usage in the codebase.
- `tests/Feature/Marketing/LandingPageTest.php` (3 tests) — guest sees the landing page, authenticated user redirects to their dashboard, root route is named `home`.
- `resources/js/Components/Marketing/{FaqSection,MarketingNav,ScoreBar}.spec.ts` (10 tests) — FAQ accordion ARIA/expand-collapse behavior, mobile nav menu open/close and focus management, `ScoreBar`'s progressbar attributes and reveal-triggered fill.

### Changed

- `routes/web.php` — `GET /` now renders `Marketing/Landing` for guests and redirects authenticated users to `route('app.dashboard')`, replacing the previous unconditional `redirect()->route('login')`.
- `tests/Feature/ApplicationBootTest.php` and `tests/Feature/ExampleTest.php` — updated to assert the new root-route behavior instead of the old redirect-to-login behavior they previously tested.

### Notes

- **Copy was corrected against current product reality, not copied verbatim, where the spec's draft language overstated what's built.** Every "publishes"/"schedules across channels" claim was reworded to describe the real, verifiable approval gate (an approval record must exist before anything is queued) without asserting live external delivery — per the existing [Channel-Publishing-Reality-Audit.md](docs/reviews/Channel-Publishing-Reality-Audit.md), every channel (including email) currently only logs a simulated result; nothing has ever left the application. The spec's fabricated testimonials (`"Marcus T."`, bracket placeholders) and fabricated stats (`312 campaigns approved`, `47 businesses served`) were not published — the Social Proof section instead honestly describes the real CBB Auctions design partnership. CTAs that would have pointed at non-existent infrastructure (demo booking, a pricing page, legal/company footer pages, a third lead-capture industry card) were re-pointed at real routes/anchors or omitted.
- **Heading hierarchy:** one deliberate deviation from the spec's literal heading-level table — FAQ questions are `<h3>`, not the spec's stated `<h4>`, since jumping from the section's `<h2>` straight to `<h4>` would itself skip a level, and the same accessibility section's "no heading levels are skipped" rule takes precedence over the descriptive table.
- A Vitest test (`ScoreBar.spec.ts`) caught a real inverted-boolean bug in the initial `ScoreBar` implementation (an unrevealed score bar rendered at full width instead of 0%) before it shipped — fixed in the same change.
- Full suite: 936 PHP tests (933 passing, 3 skipped), 34 Vitest tests (all passing). PHPStan level 8 — 0 errors. Pint clean. `npm run build` green.

## [Critical Production Blocker 8 — Backup and Disaster Recovery Readiness] — 2026-07-10

Eighth and final blocker from `docs/reviews/Production-Deployment-Audit.md`. Like Blocker 7, this blocker's original acceptance criteria are entirely operator-executed (real backups against a real production database) and remain undone — no production database exists yet. This entry covers only the repository-representable subset the live task scoped.

### Added

- `infrastructure/backup/atlas-db-backup.sh` — provider-neutral `pg_dump` wrapper: gzip-compressed dump, fails loudly (`set -euo pipefail`, required-variable checks, never treats an empty dump as success), optional GPG encryption (`BACKUP_GPG_RECIPIENT`), optional off-site upload hook (`BACKUP_OFFSITE_COMMAND`, provider-agnostic shell template), optional local retention pruning (`BACKUP_RETENTION_DAYS`).
- `infrastructure/backup/atlas-db-verify.sh` — lightweight integrity check (gzip test + schema presence), explicitly distinguished from a full restore drill.
- `infrastructure/backup/atlas-db-restore.sh` — destructive restore; never proceeds without exact-match confirmation of the target database name, interactively or via `--yes --confirm-database=<name>`; refuses gpg-encrypted files outright (decrypt first).
- `docs/operations/Backup-and-Recovery.md` — backup strategy (database via `pg_dump`; no application-managed uploaded files exist today, confirmed via `grep -rn "Storage::" app/`; secrets/`.env` recovery referenced but never stored in-repo), retention/encryption/off-site guidance, production scheduling guidance, a restore testing checklist, and a leading "code-complete vs. operator-complete" table.
- `tests/Feature/Backup/BackupScriptSafetyTest.php` (11 tests) — argument-parsing and safety checks (missing config, unreachable host, missing/empty files, every restore-confirmation path) requiring only a shell, no Postgres.
- `tests/Feature/Backup/BackupRestoreDrillTest.php` (1 test) — a real end-to-end drill: creates two scratch PostgreSQL databases, seeds one, backs it up, verifies the dump, restores into the other, and asserts the data matches. Skips gracefully (mirroring `RedisConnectionTest`) when a compatible local PostgreSQL client/server isn't available.

### Notes

- **Logical (`pg_dump`) backups, not WAL archiving** — chosen for provider-neutrality, since no managed PostgreSQL provider has been chosen yet (Blocker 7 remains unprovisioned). Once one is, its own automated backup feature should likely become primary, with these scripts as the portable fallback/local-drill tool.
- **A real drill surfaced a real gotcha:** `pg_dump` refuses to dump from a server newer than itself, and a dump taken by a newer client than the restore target's server can include settings the older server doesn't recognize (encountered directly: Homebrew's pg_dump 14 vs. a PostgreSQL 16 server; then a PostgreSQL 17 client's dump failing to restore into that same PostgreSQL 16 server over an unrecognized `transaction_timeout` setting). Documented in `Backup-and-Recovery.md`.
- **No uploaded-file backup strategy was invented** — a repository-wide check confirmed none exist. Documented explicitly, with guidance for what to do if this changes.
- **This does not mean backups are operational.** No cloud storage was provisioned, no infrastructure was deployed, and no claim is made anywhere that backups exist in production — see `Backup-and-Recovery.md`'s leading "code-complete vs. operator-complete" table.
- Full suite: 933 tests, 930 passing, 2 Redis + up to 1 backup-drill skipped depending on local environment. PHPStan level 8 — 0 errors. Pint clean. `npm run build` green.

## [Critical Production Blocker 7 — Production Infrastructure Configuration] — 2026-07-10

Seventh of eight critical blockers from `docs/reviews/Production-Deployment-Audit.md`. This blocker's original acceptance criteria are entirely operator-executed infrastructure provisioning (a real server, domain, SSL, a live deploy) and remain undone — nothing was provisioned this session, per explicit instruction. This entry covers only the code-representable subset the live task scoped as "Production Infrastructure Configuration."

### Added

- `app/Services/Http/TrustedProxyResolver.php` — `resolve(?string $raw): array|string|null`, parsing `TRUSTED_PROXIES` into whatever `Middleware::trustProxies(at: ...)` expects (`null` for unset/empty, `'*'` for the literal wildcard, or a trimmed array for a comma-separated IP/CIDR list).
- `TRUSTED_PROXIES` documented in `.env.example` (unset by default — trusts no proxies).
- `docs/deployment/Production-Topology.md` — the expected production shape (reverse proxy → application server → {database, Redis, queue workers, scheduler}), and what remains for an operator to actually provision.
- `tests/Feature/Http/TrustedProxyResolverTest.php` (7 tests) — the parser's full input matrix.
- `tests/Feature/Http/TrustedProxyBehaviorTest.php` (5 tests) — HTTPS detection, HSTS, client IP resolution, and the `analytics-webhook` rate limiter (from Blocker 2) all behave correctly given a trusted proxy, and are correctly unaffected by an untrusted proxy forging the same forwarded headers.

### Changed

- `bootstrap/app.php` — the hardcoded `trustProxies(at: '*')` set in Blocker 3 is replaced with `trustProxies(at: (new TrustedProxyResolver())->resolve(env('TRUSTED_PROXIES')))`. Default behavior changed from fail-open (trust the immediate caller unconditionally) to fail-closed (trust nothing unless explicitly configured).

### Notes

- **Why not hardcode a real proxy IP instead of the wildcard?** No hosting provider has been chosen yet — there is no real IP to hardcode, and guessing one would be worse than the wildcard it replaces. The fix moves the trust *decision* into an operator-set env var; the *mechanism* is production-ready now, the *value* is provisioning-time configuration, same as every other credential in `.env.example`.
- **Fail-closed, not fail-open, by design** — mirrors `ProductionMailerGuard`'s fail-clearly philosophy from Blocker 6. A production deploy that forgets to set `TRUSTED_PROXIES` will visibly misbehave (no HSTS, wrong client IPs) rather than silently trusting whatever connects.
- **No infrastructure was provisioned, no DNS configured, no backups created, and Blocker 8 was not started** — all explicitly out of scope per instruction.
- Full suite: 921 tests, 919 passing, 2 Redis-skipped. PHPStan level 8 — 0 errors. Pint clean. `npm run build` green.

## [Critical Production Blocker 6 — Real Transactional Email] — 2026-07-10

Sixth of eight critical blockers from `docs/reviews/Production-Deployment-Audit.md`, executed per `docs/plans/Critical-Production-Blockers.md`.

### Added

- `symfony/postmark-mailer` and `symfony/http-client` to `composer.json` — the actual transport package Laravel's Postmark mailer driver requires. Previously missing entirely, so `MAIL_MAILER=postmark` would have thrown a class-not-found error even with a valid API key.
- `POSTMARK_API_KEY`/`POSTMARK_MESSAGE_STREAM_ID` documented in `.env.example` with placeholders; the safe `MAIL_MAILER=log` local default is untouched.
- `app/Services/Mail/ProductionMailerGuard.php` — `isMisconfigured(string $environment, string $mailer): bool`, true only when `production` is paired with a non-delivery mailer (`log`/`array`). `MAIL_MAILER=log`/`array` never throws, so this has to be checked explicitly rather than caught as an exception.
- `tests/Feature/Mail/ProductionMailerGuardTest.php` (7 tests), `tests/Feature/Mail/PostmarkTransportConfigurationTest.php` (3 tests), `tests/Feature/Auth/PasswordResetDeliveryTest.php` (7 tests) — 17 tests covering the guard's environment/mailer matrix, Postmark transport resolution without a live API call, production+log rejection and its critical log, production+Postmark normal delivery, local/test safety, delivery-failure handling without secret leakage, and no user-enumeration regression.

### Changed

- `config/mail.php` — Postmark's `message_stream_id` uncommented and wired to `POSTMARK_MESSAGE_STREAM_ID`.
- `app/Http/Controllers/Auth/PasswordResetController.php` — `email()` now checks `ProductionMailerGuard` before calling `Password::sendResetLink()` (logging `Log::critical` and skipping the send if misconfigured), and wraps the send in a `try/catch (Throwable)` that logs `Log::error` (mailer, recipient email, exception message — never the reset token or password) on a real transport failure. In every branch, the exact same generic "If an account exists..." response is returned — the anti-enumeration guarantee is unchanged.

### Notes

- **Scope grew beyond this blocker's original "no controller changes" note**, per the live task's explicit delivery-safety requirements (production-misconfiguration rejection, failure logging without secrets, anti-enumeration re-verification) — none of which are expressible as pure configuration. Documented in `docs/plans/Critical-Production-Blockers.md`.
- **Operator signal vs. user signal, deliberately different:** a misconfigured production mailer logs critically (loud, for whoever watches logs/the error tracker from Blocker 5) while the end user always sees the identical generic response regardless of account existence, misconfiguration, or real delivery failure.
- **No mail health/readiness check added** — conditional on being part of this blocker's plan, and it isn't; `HealthController` is untouched, and no health check sends real email.
- No marketing campaign email publishing, newsletter automation, production hosting, or backups were touched — out of scope for this blocker.
- Full suite: 909 tests, 907 passing, 2 Redis-skipped. PHPStan level 8 — 0 errors. Pint clean. `npm run build` green.

## [Critical Production Blocker 5 — Failed Job Visibility and Error Tracking] — 2026-07-10

Fifth of eight critical blockers from `docs/reviews/Production-Deployment-Audit.md`, executed per `docs/plans/Critical-Production-Blockers.md`. Scope was widened at execution time to fold in the `failed_jobs` visibility gap Blocker 4 identified and deliberately deferred, alongside this blocker's original error-tracking scope.

### Added

- `app/Models/FailedJob.php` — maps the framework's own `failed_jobs` table (no migration needed, no company scope). `jobClass()`/`exceptionSummary()` parse the job's display name and the first line of its exception trace out of the raw `payload`/`exception` columns.
- `app/Services/Queue/FailedJobRecoveryService.php` — `retry()` (mirrors `artisan queue:retry`: resets the payload's `attempts` to 0, re-pushes to the original connection/queue, deletes the failed_jobs row) and `forget()` (mirrors `artisan queue:forget`: deletes the row). Both log a structured `Log::info(...)`.
- `app/Filament/Resources/FailedJobResource.php` (+ `Pages/ListFailedJobs.php`, `Pages/ViewFailedJob.php`) — a new Filament panel at `/admin/failed-jobs` listing queue, job class, exception summary, and failure timestamp, with per-row Retry/Discard actions (no bulk actions, deliberately — see notes). Gated by the existing superadmin-only `canAccessPanel()` check; no new authorization code was needed.
- `app/ErrorTracking/Contracts/ErrorTracker.php` — a one-method interface (`report(Throwable $exception, array $context = [])`), mirroring the existing `App\AI\Contracts\AiProvider` abstraction pattern.
- `app/ErrorTracking/NullErrorTracker.php` — a no-op implementation, bound by default and unconditionally in `testing`.
- `app/ErrorTracking/Testing/FakeErrorTracker.php` — a test double recording reported exceptions for assertions.
- `config/services.php` — `error_tracking.driver`/`error_tracking.dsn`, sourced from new `ERROR_TRACKING_DRIVER`/`ERROR_TRACKING_DSN` env vars (documented with placeholders in `.env.example`).
- `tests/Feature/Queue/FailedJobRecoveryServiceTest.php` (8 tests), `tests/Feature/ErrorTracking/ErrorTrackerTest.php` (4 tests), `tests/Feature/Filament/FailedJobResourceTest.php` (6 tests) — 18 tests total covering recovery workflow behavior/logging, diagnostics parsing, the `ErrorTracker` binding and `withExceptions()` wiring, resource visibility, and authorization.

### Changed

- `bootstrap/app.php` — `withExceptions()` now also registers a `reportable()` callback that resolves `ErrorTracker` from the container and calls it for every reported exception, additive to Laravel's own exception logging.
- `app/Providers/AppServiceProvider.php` — binds `ErrorTracker` to `NullErrorTracker` (forced in `testing` regardless of config); the binding is structured as a `match` on `config('services.error_tracking.driver')` so a future real driver is a one-line addition.

### Notes

- **Sentry (or an equivalent vendor) was deliberately not installed.** The live task explicitly allowed deferring full integration in favor of preparing the abstraction and documenting production-activation steps — installing a real SaaS error-tracking vendor is an operational/billing decision, not a code change, and shouldn't block shipping the wiring. Exact activation steps (composer-require the SDK, implement one `ErrorTracker` class, add one `match` arm, set the real DSN) are documented in `docs/plans/Critical-Production-Blockers.md`.
- **No bulk actions on the Failed Jobs resource.** Retry/Discard are per-row only — bulk-retrying many failed jobs at once risks re-triggering whatever caused the original failure all at once.
- No production hosting, backups, or real email were touched — out of scope for this blocker. No unrelated queues were modified.
- Full suite: 892 tests, 890 passing, 2 Redis-skipped. PHPStan level 8 — 0 errors. Pint clean. `npm run build` green.

## [Critical Production Blocker 4 — Scheduler and Queue Production Readiness] — 2026-07-10

Fourth of eight critical blockers from `docs/reviews/Production-Deployment-Audit.md`, executed per `docs/plans/Critical-Production-Blockers.md`.

### Added

- `infrastructure/cron/atlas-scheduler` — a documented, deployable crontab entry invoking `php artisan schedule:run` every minute, mirroring `infrastructure/supervisor/atlas-worker.conf`'s style. Previously nothing in the repository triggered the scheduler in production at all.
- `$tries`/`$backoff`/`failed()` on the four jobs the audit's "Queue recovery" section flagged as missing retry/backoff configuration: `app/Jobs/CheckChannelHealth.php` (3 tries, 60s backoff), `app/Jobs/ProcessAnalyticsWebhookEvent.php` (3 tries, 30s backoff), `app/Jobs/PruneRawMetrics.php` (3 tries, 300s backoff), `app/Jobs/PublishScheduledContent.php` (3 tries, 60s backoff). Each `failed()` method logs a structured `Log::error(...)` once retries are exhausted.
- `tests/Feature/Scheduling/ScheduledJobsProductionReadinessTest.php` — 14 tests: all six scheduled entries registered, every entry has `withoutOverlapping()`, `onOneServer()` on the five non-`ShouldBeUnique` jobs, queue assignment for the three `maintenance`-queue jobs, and `$tries`/`$backoff` values for the four newly-configured jobs.

### Changed

- `routes/console.php` — every `Schedule::` entry now chains `->withoutOverlapping()`, plus `->onOneServer()` on the five entries not already deduped via `ShouldBeUnique` (`ApplyLearnings` is unique per company per day, so `onOneServer()` would be redundant there).

### Notes

- Backoff values are job-appropriate, not uniform: 60s for network/DB-adjacent work (`CheckChannelHealth`, `PublishScheduledContent`, matching the existing `SyncIntegration`/`CommitDecision` convention), 30s for the lighter single-metric `ProcessAnalyticsWebhookEvent` update, and 300s for the low-urgency monthly `PruneRawMetrics` prune.
- `failed_jobs` recovery visibility (a Filament resource or recovery command) was deliberately **not** added — the audit raises it under "Queue recovery," but it isn't in this blocker's acceptance criteria, and belongs with Blocker 5 (real error tracking), whose whole point is making a human aware something failed. Documented as a follow-up recommendation in `docs/plans/Critical-Production-Blockers.md`.
- No monitoring, Sentry, real email, hosting, or backup work was touched — out of scope for this blocker.
- Full suite: 874 tests, 872 passing, 2 Redis-skipped. PHPStan level 8 — 0 errors. Pint clean. `npm run build` green.

## [Critical Production Blocker 3 — HTTPS Enforcement and Security Headers] — 2026-07-10

Third of eight critical blockers from `docs/reviews/Production-Deployment-Audit.md`, executed per `docs/plans/Critical-Production-Blockers.md`.

### Added

- `app/Http/Middleware/SecurityHeaders.php` — appended to the global middleware stack (`bootstrap/app.php`, via `$middleware->append()`, not just the `web`/`api` groups) so it covers every response surface, including the Filament admin panel, which builds its own middleware list rather than reusing `'web'`. Sets `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, and `Content-Security-Policy: frame-ancestors 'none'; object-src 'none'; base-uri 'self'` on every response, plus `Strict-Transport-Security: max-age=31536000; includeSubDomains` when the request is actually secure.
- `tests/Feature/Security/SecurityHeadersTest.php` — 5 tests: headers present on an Inertia web response, a JSON API response, and the Filament admin login page; HSTS present over a secure request; HSTS absent over a plain HTTP request.

### Changed

- `bootstrap/app.php` — `$middleware->trustProxies(at: '*')` configured (trusting the immediate calling proxy, since no production proxy/load-balancer IP exists yet).

### Notes

- HSTS is intentionally gated on `$request->secure()` rather than sent unconditionally — sending it over plain HTTP has no effect, so we simply omit it there instead of sending a meaningless header.
- The shipped CSP is deliberately narrow — `frame-ancestors 'none'; object-src 'none'; base-uri 'self'` — not a full `script-src`/`style-src`/`connect-src` policy. Filament (Livewire + Alpine.js) and Inertia both rely on inline scripts/styles, and the Vite dev server serves local-dev assets from a different origin; restricting those sources safely requires a nonce-based rollout that is a larger, separate project. Deferred and documented in `docs/plans/Critical-Production-Blockers.md`.
- `TrustProxies` trusts `*` (the immediate calling proxy) rather than a specific IP range, since Blocker 7 (production environment) hasn't provisioned the real proxy layer yet — revisit once it does.
- Full suite: 860 tests, 858 passing, 2 Redis-skipped. PHPStan level 8 — 0 errors. Pint clean. `npm run build` green.

## [Critical Production Blocker 2 — Analytics Webhook Rate Limiting] — 2026-07-10

Second of eight critical blockers from `docs/reviews/Production-Deployment-Audit.md`, executed per `docs/plans/Critical-Production-Blockers.md`.

### Added

- Named rate limiter `analytics-webhook` (`app/Providers/AppServiceProvider.php`), 60 requests/minute keyed by IP, logging a structured `Log::warning('AnalyticsWebhookController: rate limit exceeded.', [...])` before returning a 429 JSON response.
- `tests/Feature/Analytics/AnalyticsWebhookRateLimitTest.php` — 10 tests covering: requests within the limit succeed, the request beyond the limit returns 429, events stop dispatching once over the limit, the rejection is logged, the limit resets after the decay window, a legitimate multi-request retry sequence succeeds, the webhook's bucket is isolated from `/login`'s bucket in both directions, and regression coverage for the existing 422 (unknown provider) and 401 (invalid signature) responses.

### Changed

- `routes/api.php` — `POST /api/analytics/webhooks/{provider}` now has `->middleware('throttle:analytics-webhook')`. Previously fully public and unthrottled.

### Notes

- Chose a **named** limiter over a bare `throttle:60,1` string. While researching this, discovered (and verified via a throwaway test hitting `/login` then `/register`) that Laravel's bare `throttle:N,M` middleware keys its bucket by `domain + IP` only, with no route distinction — every pre-existing bare-throttled route (`/login`, `/register`, password reset, `/onboarding/integration`) currently shares one bucket per IP. A bare string on the webhook route would have joined that shared bucket, letting the webhook and login/register starve each other. The named limiter gives the webhook its own isolated bucket instead.
- This shared-bucket issue is out of scope here per "do not modify unrelated endpoints" — it is documented in `docs/plans/Critical-Production-Blockers.md` as a discovered issue, recommended as a future High Priority audit item, and left unfixed for the pre-existing routes.
- Full suite: 855 tests, 853 passing, 2 Redis-skipped. PHPStan level 8 — 0 errors. Pint clean. `npm run build` green.

## [Critical Production Blocker 1 — Tenant Isolation Container Binding] — 2026-07-10

First of eight critical blockers from `docs/reviews/Production-Deployment-Audit.md`, executed per `docs/plans/Critical-Production-Blockers.md`.

### Added

- `tests/Feature/Tenancy/CompanyScopeActivationTest.php` — 5 tests proving `current_company_id` is bound during a real request (single-membership and session-selected-multi-membership paths), that redirect paths never bind it, and that `CompanyScope` actively filters an unfiltered tenant-model query during a real request — not merely that manual `company_id` filtering still produces the correct result.

### Changed

- `app/Http/Middleware/EnsureCompanyMembership.php` — binds `current_company_id` into the container (`app()->instance(...)`) alongside the existing `$request->attributes->set('company', ...)`, on both the single-membership and resolved-multi-membership paths. `CompanyScope`'s global scope, previously inert in production, now genuinely constrains every tenant-scoped query for the duration of a real `/app/*` request.
- `app/Domain/Shared/Scopes/CompanyScope.php` — doc comment updated to state that the scope is now active defense-in-depth during real web requests, not merely a no-op everywhere outside tests.
- `app/Http/Middleware/HandleInertiaRequests.php` — the `companies` shared prop (drives the sidebar company switcher) now queries `CompanyMembership::withoutGlobalScopes()`. This closure runs after `EnsureCompanyMembership`, by which point `current_company_id` is bound to the *current* tenant — without this fix, a multi-company user's switcher would incorrectly show only the currently-active company instead of all of them.
- `app/Http/Middleware/EnsureCompanyMembership.php` — its own membership-resolution query is now `withoutGlobalScopes()` too: determining which companies a user belongs to is inherently a cross-tenant, `user_id`-keyed lookup and must never be narrowed by an already-bound tenant.
- `app/Http/Controllers/App/CompanySelectorController.php` — both membership queries (`index()`, `select()`) made `withoutGlobalScopes()` for the same reason, defensively.

### Notes

- This fix was validated by the existing test suite catching a real regression: two pre-existing tests (`CompanySelectorControllerTest::test_switching_company_changes_which_companys_data_the_dashboard_serves`, `MiddlewareTest::test_shared_companies_prop_lists_all_memberships_for_switcher`) failed immediately after the scope went live, because three places that look up a user's memberships *across* companies had never needed to think about tenant scoping before (since the scope was always inert). All three are fixed; no other behavior changed.
- 845 tests (843 passing, 2 Redis skipped — unchanged from before this fix aside from the 5 new tests). PHPStan level 8 — 0 errors. Pint clean. `npm run build` green.

---

## [Production Deployment Readiness Audit] — 2026-07-10

Read-only audit of the current repository for production deployment readiness — no code changes.

### Added

- `docs/reviews/Production-Deployment-Audit.md` — evidence-based audit (exact file/line citations throughout) covering: infrastructure (environment variables, queue workers, scheduler, storage, logging, cache, sessions, Redis, SSL, backups, email, monitoring, error tracking); Laravel production configuration (`APP_ENV`/`APP_DEBUG`, cache/config/route optimization, queue configuration, Horizon, scheduler); security (secrets, cookie settings, HTTPS, CSRF, rate limits, password reset, tenant isolation, authorization review); and operational risks (single points of failure, AI provider resilience, queue recovery, database recovery, deployment rollback). Concludes with critical blockers, high-priority items, and nice-to-have improvements.

### Notes

- Headline finding: `App\Domain\Shared\Scopes\CompanyScope` only applies its `company_id` filter when `current_company_id` is bound in the container; the only bindings of that key anywhere in the codebase are in three test files. `EnsureCompanyMembership` (the middleware that resolves the acting company) only sets a request attribute, never a container binding. Tenant isolation in production therefore relies entirely on every controller and job manually filtering by `company_id` — a pattern applied consistently today, but with no automated or structural safety net against a future omission.
- New findings not previously documented in the June Beta Readiness Audit: the analytics webhook endpoint (`POST /api/analytics/webhooks/{provider}`) has no authentication and no rate limiting; `SettingsController::update()`/`syncIntegration()` and all three `MarketingPresenceController` mutations have no role check beyond company membership (only the Recommendation approval workflow enforces owner/admin); password reset does not invalidate other active sessions.
- Confirms several June-audit blockers remain unaddressed in the repository (no production environment, no backups, no real email delivery, no error tracking, no deploy pipeline, no scheduler cron trigger) while noting these are largely provisioning work outside the repo's scope to fix via code alone.
- Confirms several things are solid and shouldn't be re-litigated: CSRF protection, secrets management (nothing real tracked in git), genuinely functional health/readiness endpoints, `BusinessBrainService`'s documented decision to avoid the cache facade, and the Anthropic provider's retry/backoff engineering.

---

## [Private Beta Execution Checklist] — 2026-07-10

Operator's checklist for running Stage A (Private Beta) — no code changes, no implementation tasks.

### Added

- `docs/plans/Private-Beta-Execution.md` — a checklist, not a roadmap or sprint plan: (1) a production infrastructure checklist covering hosting, domain, SSL, database, backups, monitoring, error tracking, email, queue workers, scheduler, and log retention; (2) a per-customer onboarding checklist from account creation through publishing expectations, including the Marketing Presence step (Milestone 11); (3) a daily internal support checklist (health checks, failed-job review, AI provider monitoring, customer issue triage, backup verification); (4) a single objective Go/No-Go gate for inviting the first 10 customers; (5) a first-week operating cadence with daily tasks and metrics to review once customers start onboarding.

### Notes

- Deliberately distinct from `docs/plans/Private-Beta-Plan.md` (the week-by-week build-out sprint plan) and `docs/plans/Version-1.0-Roadmap.md` (strategy) — this document is meant to be run and re-run during actual beta operation, not read once before building something.
- Emphasizes verification over assumption throughout: e.g., "a backup has been restored and checked" rather than "backups are configured," since the two are different claims and only the former is acceptable before Customer 1.

---

## [Version 1.0 Product Roadmap] — 2026-07-10

Strategic product roadmap for the next ~12 months — no code changes, no implementation tasks.

### Added

- `docs/plans/Version-1.0-Roadmap.md` — assesses current platform state (what's complete, production-ready, beta-ready, and remaining risks) against `docs/reviews/Beta-Readiness-Audit.md`, `docs/reviews/Product-Polish-Audit.md`, and `docs/reviews/Channel-Publishing-Reality-Audit.md`; defines four gated stages (Private Beta 5–10 customers → Paid Beta 25–50 → Version 1.0 Public Launch → Version 2.0), each with entry criteria and success metrics rather than calendar dates; prioritizes work across six categories (Infrastructure, Customer Experience, Integrations, AI Improvements, Growth, Operations); explicitly lists deferred features, technical debt worth carrying, and technical debt that must resolve before public launch.

### Notes

- Headline finding: the 8-phase product loop plus Milestones 10–11 are complete and well-tested, but the platform remains not beta-ready for entirely operational reasons (no production server, no real email delivery, no monitoring, no backups, no legal documents) — not architectural ones.
- Cross-references the Channel Publishing Reality Audit's finding that no channel type publishes externally today, and flags that the landing page's marketing copy already assumes capabilities (real email, real publishing, working analytics comparisons, a published privacy policy) that don't exist yet — this gap is called out as the top pre-launch risk.

---

## [Milestone 11 Phase 7 — Campaign & Recommendation UI] — 2026-07-10

Implements Phase 7 only of [Milestone-11-Marketing-Presence.md](docs/plans/Milestone-11-Marketing-Presence.md). Details in [Milestone-11-Phase-7-Review.md](docs/reviews/Milestone-11-Phase-7-Review.md). No publishers, no OAuth, no analytics, no onboarding changes, no app redesign.

### Added

- `App\Services\Recommendation\ChannelMixPresenter` — assembles a Recommendation's "channel mix" (primary/supporting/draft-only/unavailable channels) fresh at display time from `Decision.channel_ids` and a company-scoped `MarketingChannel` query. Never treats a `MarketingChannel` as executable; never invents a `Channel` row.
- `resources/js/Components/Recommendations/ChannelMixCard.vue` — renders the four buckets as one compact card on the Recommendation detail page, using the existing capability badge and card styling.
- `resolveChannelCapability()` and `resolveDeclaredChannelCapability()` in `resources/js/lib/channelCapability.ts` — additive extensions implementing `specs/core/marketing-presence.md` §11's mapping table (linked `MarketingChannel.supports_publishing` overrides the global type-only guess for real Channels; a declared-but-unlinked type resolves to "Not configured" or "Coming later" depending on whether it has a `Channel` type equivalent).
- `tests/Feature/Recommendation/ChannelMixPresenterTest.php` (11 tests), 3 new `RecommendationControllerTest` tests, and `ChannelMixCard.spec.ts` (6 Vitest tests).

### Changed

- `App\Http\Controllers\App\RecommendationController::show()` — now passes a `channel_mix` prop and enriches each content asset's `channel` with its linked `MarketingChannel`'s `supports_publishing` flag (when one exists).
- `resources/js/Components/UI/ChannelCapabilityBadge.vue` — gained an optional `linkedMarketingChannel` prop; existing usages without it are unaffected.
- `resources/js/Components/Recommendations/ApproveActions.vue` — its per-content-asset confirmation line now uses `resolveChannelCapability()` instead of the bare global lookup, per the plan's explicit mention of this file.
- `resources/js/types/index.ts` — added `ChannelMix`/`ExecutableChannelMixEntry`/`DraftOnlyChannelMixEntry`/`UnavailableChannelMixEntry`; `ContentAsset.channel` gained an optional `marketing_channel` field.

### Notes

- Only `Recommendations/Show.vue` and `ApproveActions.vue` were touched, not `Campaigns/Show.vue`/`Publishing.vue`/`Dashboard.vue` as the plan document's Phase 7 section lists — the live task's ask was scoped to the Recommendation detail page, and the capability-badge extension is purely additive so untouched pages keep working unchanged.
- The channel mix is recomputed at display time rather than persisting Phase 6's `MarketingChannelSelection` — no `Decision`/`Recommendation`/`Campaign` schema change, and the picture shown always reflects current Settings state rather than a stale snapshot from Decision-commit time.
- No new AI prompt work — "why these channels were chosen" is already covered by the existing `why_channel` rationale field; "why excluded"/"why draft-only is still valuable" are deterministic, hand-written copy.
- All pre-existing tests (826 PHP, 18 Vitest) pass unmodified, including every approval-workflow test — no regression.

---

## [Milestone 11 Phase 6 — Opportunity and Channel Selection Integration] — 2026-07-09

Implements Phase 6 only of [Milestone-11-Marketing-Presence.md](docs/plans/Milestone-11-Marketing-Presence.md). Details in [Milestone-11-Phase-6-Review.md](docs/reviews/Milestone-11-Phase-6-Review.md). No Opportunity detection changes, no publishers, no OAuth, no Settings UI, no external publishing, no analytics ingestion, no onboarding changes.

### Added

- `App\Services\Decision\MarketingChannelSelector` — narrows `DecisionEngine`'s type-affinity-matched `Channel` candidates using the company's declared Marketing Presence: excludes candidates whose linked `MarketingChannel.status` is `inactive` or `planned` (each with a distinct, logged reason), with a safety bypass back to the pre-existing "all active channels" fallback if exclusion would leave nothing to recommend; prefers `importance: primary`-linked candidates when any exist; reports declared, non-inactive, unlinked `MarketingChannel` rows as `draftOnlyChannels`. Queries `MarketingChannel` directly (company-scoped, `withoutGlobalScopes()`) rather than reading `BusinessBrain->marketingPresence`, since that summary (Phase 5) is deliberately display-name-only and not `channel_id`-addressable.
- `App\Services\Decision\MarketingChannelSelection` — a `readonly` value object distinguishing `executableChannelIds`, `draftOnlyChannels`, and `excludedChannels` (with reasons) — the "channel mix" output, with no `Decision`/`CampaignBlueprint` schema change.
- Structured logging: one `Log::info('DecisionEngine: marketing-presence channel selection.', [...])` entry per selection, covering channels considered, preferred, excluded (and why), and the executable/draft-only split.
- `tests/Feature/Decision/MarketingChannelSelectorTest.php` — 12 tests covering every bucketing/exclusion/preference rule and tenant isolation.
- 5 new end-to-end tests in `tests/Feature/Decision/DecisionEngineTest.php` verifying the same rules through a real committed `Decision`.

### Changed

- `App\Services\Decision\DecisionEngine` — injects `MarketingChannelSelector`; the former `resolveChannelIds()` (returned plucked ids) is now `resolveAffinityChannels()` (returns the `Channel` collection itself, identical type-affinity logic) feeding into the new selector. `Decision.channel_ids` is now populated from the selector's `executableChannelIds`.
- `App\Services\Decision\DecisionContext` — gained a `null`-defaulting `channelSelection: ?MarketingChannelSelection` property, so the full executable/draft-only/excluded breakdown is available to `DecisionService::commit()` without any persisted schema change.

### Notes

- The plan document's Phase 6 text describes building a `channel_id → MarketingChannel` map "from the `BusinessBrain`... not a fresh query" — not literally possible against the synthesized `BusinessBrain->marketingPresence` Phase 5 actually shipped. A fresh, company-scoped query (the same pattern already used for every other tenant-scoped model in `DecisionEngine`) is used instead.
- Excluding `planned`-linked channels (not just `inactive`-linked ones) was added per this phase's live task instruction, beyond what the plan document's Phase 6 text originally specified.
- All 20 pre-existing Decision Engine/pipeline tests pass unmodified — no regression to existing guard-condition or fallback behavior.

---

## [Milestone 11 Phase 5 — Business Brain Integration] — 2026-07-09

Implements Phase 5 only of [Milestone-11-Marketing-Presence.md](docs/plans/Milestone-11-Marketing-Presence.md). Details in [Milestone-11-Phase-5-Review.md](docs/reviews/Milestone-11-Phase-5-Review.md). No Opportunity Engine, Decision Engine, publishing, or onboarding changes.

### Added

- `App\Domain\BusinessBrain\MarketingPresenceSummary` — a synthesized, natural-language-ready value object (`primaryChannels`, `secondaryChannels`, `inactiveChannels`, `primaryObjectives` — all `list<string>` — plus a composed `summary` sentence). Never raw `MarketingChannel` rows.
- `App\Services\Brain\MarketingPresenceSynthesizer` — the only place that reads `MarketingChannel` rows to describe a company's marketing strategy. Buckets by status first (inactive overrides importance), then importance (primary vs. secondary/experimental); derives `primaryObjectives` from primary channels, falling back to all active channels; composes one sentence per non-empty bucket. Deterministic string composition — no AI call.
- `tests/Feature/Brain/MarketingPresenceSynthesizerTest.php` — 12 tests covering bucketing rules, objective derivation/fallback/dedup, summary composition, and tenant isolation.

### Changed

- `App\Domain\BusinessBrain\BusinessBrain` — gained a 9th constructor parameter, `public ?MarketingPresenceSummary $marketingPresence = null` (nullable with a default so every pre-existing test that constructs `BusinessBrain` directly continues to work unmodified).
- `App\Services\Brain\BusinessBrainService` — `assemble()` now synthesizes and attaches `marketingPresence` via the injected `MarketingPresenceSynthesizer`.
- `App\Providers\AppServiceProvider::boot()` — registered a listener for the previously-inert `MarketingPresenceUpdated` event (shipped in Phase 2), calling `BusinessBrainService::invalidate($event->marketingChannel->company_id)`, in the exact style of the existing `FactExtracted`/`KnowledgeSynthesized` listeners. Invalidation only clears an in-process memo entry — no synchronous rebuild, no new queue or job.
- `tests/Feature/Brain/BusinessBrainServiceTest.php` — 3 new tests: `marketingPresence` is populated and non-null; the empty-company sentence; the summary only ever contains strings, never `MarketingChannel` instances.
- `tests/Feature/Brain/BusinessBrainCacheTest.php` — 3 new tests: `MarketingPresenceUpdated` invalidates the memo; a cross-company event does not; a fresh summary is assembled after invalidation.
- `specs/core/marketing-presence.md` §8 — updated to describe the synthesized `MarketingPresenceSummary` design actually implemented (a "superseded design note" documents the change from the section's original unfiltered-`Collection<MarketingChannel>` sketch and the reasoning behind it).

### Notes

- The live task instruction for this phase ("Do NOT expose raw MarketingChannel rows directly to prompts") explicitly overrode both the plan document's and the spec's original design (an unfiltered `Collection<MarketingChannel>` property) — treated as authoritative, consistent with how earlier phases treated a more specific live instruction as superseding a rough plan sketch.
- No prompt template (`CampaignPreparationPrompt`, `RationaleGenerationPrompt`, etc.) was modified — the data is available on `BusinessBrain` for any `Analyst` that chooses to read it, but folding it into generated prompt text wasn't required for this phase and would have meant editing files immediately adjacent to Opportunity/Decision Engine territory this phase must not touch.

---

## [Milestone 11 Phase 4 — Marketing Presence Settings UI] — 2026-07-09

Implements Phase 4 only of [Milestone-11-Marketing-Presence.md](docs/plans/Milestone-11-Marketing-Presence.md). Details in [Milestone-11-Phase-4-Review.md](docs/reviews/Milestone-11-Phase-4-Review.md). No Business Brain, Opportunity Engine, publishing, channel health dashboard, or external integration changes.

### Added

- `App\Http\Controllers\App\MarketingPresenceController` — thin CRUD controller delegating entirely to `MarketingPresenceService`/`MarketingChannelCapabilityResolver` (Phase 2): `index()` lists a company's declared channels (sorted by importance then status) with each row's resolved capability; `store()` declares a new channel; `update()` edits `status`/`importance`/`objective`; `destroy()` soft-disables (sets `status: inactive`, never deletes the row).
- Routes: `GET/POST /app/settings/marketing-presence`, `PATCH/DELETE /app/settings/marketing-presence/{marketingChannel}`, under the existing `['auth', 'company']`-protected `/app` group.
- `resources/js/Pages/App/Settings/MarketingPresence/Index.vue` — new Settings sub-page: view, add, edit status/importance/objectives, disable/reactivate, capability badges. Linked from a new "Marketing Presence" card on `Settings.vue`.
- `resources/js/Components/UI/MarketingChannelCapabilityBadge.vue` + `resources/js/lib/marketingChannelCapability.ts` — a new badge for the four `MarketingChannelCapabilityResolver` values (Declared, Connected, Publishing enabled, Analytics enabled), reusing `Badge.vue`'s visual language. A sibling to the existing `ChannelCapabilityBadge.vue`, not an extension of it (see review's "Deviations").
- `resources/js/lib/marketingChannelTypes.ts` — the 12 channel-type/label pairs, factored out of `Onboarding/Index.vue` into a shared module also used by the new Settings page.
- `tests/Feature/App/MarketingPresenceControllerTest.php` — 21 tests: CRUD, capability values in the listing, tenant isolation (404 for another company's channel on both `update()` and `destroy()`), validation (missing/unknown type, unknown status, empty objective array), no `Channel` row created by `store()`.
- `resources/js/Components/UI/MarketingChannelCapabilityBadge.spec.ts` — 4 Vitest tests, including an explicit check that the "Declared" badge's text never contains the word "publish."

### Changed

- `app/Models/MarketingChannel.php` — added `@property` PHPDoc annotations for the enum-cast attributes (`type`, `status`, `importance`, `posting_frequency`, `objective`), matching the existing precedent on `Integration`/`ChannelCredentials`. Needed because this phase is the first place in the codebase to read `$channel->importance->value` (etc.) directly, and Larastan doesn't infer enum-cast types from the model's `casts()` method by default.
- `resources/js/Pages/Onboarding/Index.vue` — now imports its channel-type list from `lib/marketingChannelTypes.ts` instead of an inline array; no behavior change.

### Notes

- No owner/admin authorization gate was added — no `CompanyMembershipPolicy` (or any role-based policy) exists anywhere in this codebase, and no other Settings action is gated by role today. Tenant isolation (not role) is enforced identically to `SettingsController::syncIntegration()`.
- A new sibling badge component was chosen over extending `ChannelCapabilityBadge.vue`, since that component derives its capability from a raw channel-type string client-side, while this phase's capability is always a value the server already resolved — mixing the two strategies behind one prop risked duplicating capability-derivation logic in Vue.

---

## [Milestone 11 Phase 3 — Marketing Presence Onboarding] — 2026-07-09

Implements Phase 3 only of [Milestone-11-Marketing-Presence.md](docs/plans/Milestone-11-Marketing-Presence.md). Details in [Milestone-11-Phase-3-Review.md](docs/reviews/Milestone-11-Phase-3-Review.md). No Business Brain, Opportunity Engine, publishing, channel configuration, or OAuth changes.

### Added

- New onboarding step — "Where do your customers find you?" — added as the wizard's final data-entry step (company profile → website URL → marketing presence → confirm). A checkbox-only checklist of all 12 `MarketingChannelType` values (Website preselected); no handle, URL, username, or API-connection prompt anywhere on the step.
- `App\Http\Controllers\OnboardingController::saveMarketingPresence()` — validates `channels` as an array of valid `MarketingChannelType` values, then declares one unlinked, unconnected `MarketingChannel` per selection via `MarketingPresenceService::declare()` (status `active`, per-type `suggestedDefaults()` for the rest), skipping any type already declared for the company so resubmits are idempotent. No `Channel` record, no `Integration`, no OAuth.
- `POST /onboarding/marketing-presence` route (`routes/web.php`), inside the existing authenticated onboarding group.
- `resources/js/Pages/Onboarding/Index.vue` — new step 3 UI (2-column checkbox grid using existing design tokens, no new components); the prior transient "Connected" confirmation screen shifts to step 4 unchanged.

### Changed

- `OnboardingController::index()` — now also gates on whether the company has declared any `MarketingChannel` before redirecting to `/onboarding/status`; if an `Integration` exists but no `MarketingChannel` does, it renders the new step 3 instead.
- `OnboardingController::createIntegration()` — final redirect changed from `route('onboarding.status')` to `route('onboarding')`, so submitting the website URL returns to the wizard (now showing the marketing-presence step) instead of the pipeline status page. `SyncIntegration`'s dispatch call site and timing are unchanged — see the review's "Deviations" section for why moving the dispatch itself was rejected.
- `docs/product/UserFlows.md` Flow 1 — updated from "exactly 3 steps" to 4, documenting the new marketing-presence step.
- `tests/Feature/App/OnboardingControllerTest.php` — 22 new/changed tests: marketing-presence persistence, no-`Channel`/no-`Integration` guarantee, unlinked/unconnected/`active`-status declaration, empty-selection allowance, invalid-type rejection, missing-key rejection, resubmit idempotency, tenant isolation, no-company redirect, a full three-step progression test, and updated redirect-target assertions on the four existing integration-step tests that previously expected `onboarding.status`.

### Notes

- The plan document's Phase 3 sketch describes optional inline `handle_or_url` capture and a free-text label for "Other"; the live task's boundaries ruled both out entirely ("No required metadata yet," "Do NOT ask for handles, usernames, or URLs"). Every declared channel gets a fixed, type-derived `display_name` only.
- Marketing presence was placed as the literal final step (after website URL), per explicit instruction, rather than the plan's alternative placement between company profile and website URL.

---

## [Milestone 11 Phase 2 — Marketing Presence Service Layer] — 2026-07-09

Implements Phase 2 only of [Milestone-11-Marketing-Presence.md](docs/plans/Milestone-11-Marketing-Presence.md). Details in [Milestone-11-Phase-2-Review.md](docs/reviews/Milestone-11-Phase-2-Review.md). No onboarding, Business Brain, Opportunity Engine, publishing, or UI changes — those are Phases 3–8, not started.

### Added

- `App\Services\MarketingPresence\MarketingPresenceService` — CRUD and reasoning over a company's declared marketing channels. `declare()` creates a new declaration (forces `company_id` from the caller's `Company` argument, fills in per-type `suggestedDefaults()` when omitted, always creates unlinked, validates the merged attributes against `MarketingChannel::rules()`, fires `MarketingPresenceUpdated`); `update()` partially updates the nine business-context fields only (strips `company_id`/`channel_id`/the three capability booleans, revalidates the full prospective state); `setStatus()`/`disable()`/`reactivate()` for status transitions; `link(MarketingChannel, Channel)` sets `channel_id` + `is_connected` only, throwing a new `ChannelBelongsToDifferentCompanyException` if the `Channel` belongs to a different company (including a company-less global template — `Channel` has no `BelongsToCompany` scope, so this check is explicit); `suggestedDefaults()` — a per-type `importance`/`objective`/`posting_frequency` table for all 12 `MarketingChannelType` cases; `wouldDuplicate()` — a soft, non-blocking `(company, type, handle_or_url)` collision check; `suggestChannels()` — read-only, non-persisting candidates from a connected `website_crawl` `Integration` and existing active `Channel` rows (filtered through `MarketingChannelType::tryFrom()` so `blog`/`sms`/`landing_page` are excluded rather than throwing).
- `App\Services\MarketingPresence\MarketingChannelCapabilityResolver` — the single place that turns a `MarketingChannel`'s `is_connected`/`supports_publishing`/`supports_analytics` flags plus its linked `Channel`'s `is_active` state into one of four domain-level lifecycle results (`App\Enums\MarketingChannelCapability`: `Declared`, `Connected`, `PublishingEnabled`, `AnalyticsEnabled`). A missing or inactive linked `Channel` can never yield `PublishingEnabled`/`AnalyticsEnabled`, regardless of the `MarketingChannel`'s own flags.
- `App\Enums\MarketingChannelCapability` — the four-case lifecycle enum above (spec §5's vocabulary — distinct from spec §11's four UI-facing capability labels, which remain Phase 7 work).
- `App\Events\MarketingPresenceUpdated` — one coarse event, fired by every mutating `MarketingPresenceService` method. No listener registered yet (Business Brain cache invalidation is Phase 5) — `AppServiceProvider` untouched.
- `App\Services\MarketingPresence\Exceptions\ChannelBelongsToDifferentCompanyException` — named exception per Founding Principle 9.
- `App\Services\MarketingPresence\MarketingChannelSuggestion` — a `readonly` value object (`type`, `displayName`, `handleOrUrl`, `reason`, `channelId`) returned by `suggestChannels()`.
- 45 tests across `tests/Feature/MarketingPresence/` covering CRUD, capability resolution (all four outcomes plus inactive/missing-link edge cases), suggestion logic, validation, tenant isolation, and edge cases (duplicate handles, same-type-twice, invalid type/missing field rejection).

### Notes

- The capability resolver's labels match the live task instruction's lifecycle vocabulary (Declared/Connected/PublishingEnabled/AnalyticsEnabled — spec §5), not the plan document's illustrative UI-label sketch (`connected`/`draft_only`/`not_configured`/`coming_later` — spec §11), which appears to have been mixed into the plan's Phase 2 section from Phase 7 in error.
- `update()` additionally strips the three capability booleans (beyond `company_id`/`channel_id` as the plan's signature comment states) — a generic business-context edit must never be able to fake a connection.
- `declare()` applies `suggestedDefaults()` before validating, so a minimal `declare()` call with just `type` and `display_name` succeeds using type-appropriate defaults rather than failing validation on DB-defaulted-but-rule-required fields.

---

## [Milestone 11 Phase 1 — Marketing Presence Domain Model] — 2026-07-08

Implements Phase 1 only of [Milestone-11-Marketing-Presence.md](docs/plans/Milestone-11-Marketing-Presence.md). Details in [Milestone-11-Phase-1-Review.md](docs/reviews/Milestone-11-Phase-1-Review.md). No service layer, onboarding, Settings UI, Business Brain, Opportunity Engine, or publishing changes — those are Phases 2–8, not started.

### Added

- `marketing_channels` table — `company_id`/`channel_id` (nullable, FK → `channels.id`, `nullOnDelete`) FKs, 12-value `type` enum, `status`/`importance`/`posting_frequency` enums with defaults, required `objective` JSON array, `display_name`, nullable `handle_or_url`/`audience`/`notes`/`metadata`, three capability booleans (`is_connected`, `supports_publishing`, `supports_analytics`), composite indexes on `(company_id, status)`/`(company_id, importance)`/`(company_id, type)`. No soft deletes (per spec — `status: inactive` represents "no longer used"); no unique constraint on `(company_id, type)` (a company may declare a type more than once).
- `App\Models\MarketingChannel` — `BelongsToCompany` + `HasUlids` + `HasFactory`; `belongsTo(Channel::class)` (nullable); native-enum casts for `type`/`status`/`importance`/`posting_frequency`; array casts for `objective`/`metadata`; `active()`/`primary()`/`connected()` query scopes (`connected()` requires both a linked `channel_id` **and** `is_connected = true`); a static `rules()` method providing structural validation (enum membership, required fields) — deliberately excludes the cross-row duplicate-`handle_or_url` check, which requires a query and belongs to Phase 2's `MarketingPresenceService`.
- `App\Models\Company::marketingChannels()` — `hasMany` relationship, matching the existing relationship-listing convention already on the model.
- Five backed PHP enums — `App\Enums\MarketingChannelType` (12 cases, plus a pure `hasChannelEquivalent(): bool` method encoding which types map onto `App\Models\Channel` today: `email`, `instagram`, `facebook`, `linkedin`, `x`), `MarketingChannelStatus` (4), `MarketingChannelImportance` (3), `MarketingChannelObjective` (7), `PostingFrequency` (7) — plus a shared `App\Enums\Concerns\EnumValues` trait. First use of native PHP enums in this codebase; introduced here because this one entity carries five distinct constrained vocabularies, more than any existing model.
- `Database\Factories\MarketingChannelFactory` — auto-creates a parent `Company` via `Company::factory()` when none given; `primary()`/`inactive()` states. No `connected()` state: satisfying it requires a real linked `Channel` row, and no `ChannelFactory` exists in this codebase — tests needing a genuinely connected row create the `Channel` directly and pass `channel_id` explicitly.
- 48 tests across `tests/Unit/MarketingPresence/` (enum values, `hasChannelEquivalent()` — framework-free) and `tests/Feature/MarketingPresence/` (migration shape and FK behavior, model casts/defaults, relationships, scopes, tenant isolation mirroring the existing `Tests\Feature\Discovery\TenantIsolationTest` pattern, factory behavior, validation rules).

### Notes

- Scope method return types are `void`, not `Builder` (the plan's illustrative snippet), to match this codebase's existing local-scope convention (`Fact::scopeCurrent()`, `Knowledge::scopeActive()`) and satisfy PHPStan level 8's generics check.
- `posting_frequency` is `NOT NULL DEFAULT 'unknown'` rather than a nullable column, reconciling the plan's literal migration code with the spec's "nullable, default unknown" field description — `unknown` already is the null-sentinel value.
- `DemoSeeder` was not modified — not required by the Phase 1 plan.

---

## [Milestone 11 — Marketing Presence: Specification + Implementation Plan] — 2026-07-08

Introduces Marketing Presence as a first-class Atlas domain concept: **specification and implementation plan only — no application code.**

### Added

- `specs/core/marketing-presence.md` — authoritative domain specification. Defines the `MarketingChannel` entity (fields: `company_id`, `channel_id`, `type`, `display_name`, `handle_or_url`, `status`, `importance`, `objective`, `audience`, `posting_frequency`, `notes`, `is_connected`, `supports_publishing`, `supports_analytics`, `metadata`); 12 channel types (Website, Email, Instagram, Facebook, LinkedIn, X, YouTube, TikTok, Google Business Profile, Events, Print, Other); channel lifecycle (Declared → Connected → Publishing enabled → Analytics enabled, derived from three independent booleans rather than a single enum); channel status (active/occasional/planned/inactive); importance (primary/secondary/experimental); and objective (awareness/leads/sales/retention/trust/seo/community, multi-valued). Specifies the relationship to the existing `Channel` model (business declaration vs. technical publishing capability — deliberately separate entities, not an extension of `Channel`), to `Integration` (no direct relationship), to `BusinessBrain` (new unfiltered `marketingPresence` collection, cache-invalidated via a new `MarketingPresenceUpdated` event mirroring the existing `FactExtracted`/`KnowledgeSynthesized` pattern), to the Opportunity/Decision Engine (channel-selection preference and exclusion rules — detection itself is unaffected), to Campaign Blueprint (no schema change), and to the existing publishing capability labels from the Channel Publishing Reality Audit (a per-`MarketingChannel` refinement of the current type-only lookup). Includes full acceptance criteria and a future-extensibility section.
- `docs/plans/Milestone-11-Marketing-Presence.md` — implementation plan sequencing the spec into 8 phases: domain model (migration, model, factory, scopes, validation), service layer (`MarketingPresenceService`, capability resolver), onboarding ("Where do you market today?" step, zero API connection required), Settings UI (declared-channel CRUD with capability badges), Business Brain integration, Opportunity Engine integration (Decision Engine channel-selection preference/exclusion only — no new Opportunity type), Campaign/Recommendation UI (recommended channel mix, honest capability labeling), and a consolidated test plan (unit, feature, tenant isolation, onboarding, Business Brain, opportunity recommendation).

### Explicitly not done (per task boundaries)

- No Instagram, Facebook, or any other new publishing integration
- No social OAuth
- No analytics ingestion for any channel
- No channel health dashboard
- No change to existing publishing orchestration (`ChannelPublisherRegistry`, `PublishContent`, `ExecutionService`, `LogChannelPublisher`, `EmailPublisher` untouched)
- No claim, anywhere in the spec or plan, that external publishing exists where it does not — every example and acceptance criterion is written consistent with the [Channel Publishing Reality Audit](docs/reviews/Channel-Publishing-Reality-Audit.md)
- No code: no migration, no model, no controller, no Vue component was written as part of this task

---

## [Channel Publishing Reality Audit — Honest Publishing Copy] — 2026-07-07

Audits every UI claim of "publish," "published," "send," or a named channel (blog, email, Instagram, Facebook, LinkedIn, X, SMS, landing page) against what the backend actually does. Full findings and per-channel-type capability table in [Channel-Publishing-Reality-Audit.md](docs/reviews/Channel-Publishing-Reality-Audit.md). **No new publishers implemented** — this is a documentation and copy/labeling pass only.

### Found

- **No channel type currently publishes to a real external platform.** Tracing `ChannelPublisherRegistry` → `PublisherServiceProvider` shows every channel type (`facebook`, `instagram`, `linkedin`, `x`, `sms`, `blog`, `landing_page`) resolves to `LogChannelPublisher`, and `email` resolves to `EmailPublisher` → `EmailProviderRegistry`, where only `LogEmailProvider` is ever registered. Both terminal publishers only write a line to `storage/logs/publishing.log` and return a fake success result unconditionally — `ping()` on both always reports `reachable: true` too. The `Execution`, `ContentAsset`, and `Campaign` records still transition to `completed`/`published` as if a real send happened.
- **Most channel types can't even be created today.** Only `blog` (auto-created at onboarding) and `email` (only via `DemoSeeder`, with `provider_type: 'log'`) ever become real `Channel` rows for a company. `facebook`, `instagram`, `linkedin`, `x`, `sms`, and `landing_page` have full AI content-drafting support (`ContentGenerationAnalyst` maps each to a real prompt) but no onboarding/Settings/seeder path ever creates a channel of these types — `DecisionEngine` only selects from a company's existing active channels, so they're structurally unreachable in production, not merely unpublished.

### Fixed (copy)

- `ApproveActions.vue` confirmation dialog: replaced *"Publish the X to your Y channel"* with a capability-aware line naming the channel's display name and real capability (e.g. *"Queue the blog post ... for Blog — Draft only: logged internally, not yet sent live."*). Confirm button relabeled `"Approve & publish"` → `"Approve"`. Helper text under the buttons, the no-content-assets fallback, and the dialog's footer note all reworded to state that delivery is simulated/logged, not sent externally.
- `RecommendationController::approve()` flash message: *"Approved. Atlas will handle the publishing."* → *"Approved. Atlas will process this campaign — publishing is currently simulated until a live channel is connected."*
- `Dashboard.vue` "Recent Publishing Activity" empty state, `Publishing.vue` (new page-level notice + empty state), `Campaigns/Show.vue` "Publishing" section empty state — all reworded to describe simulated/internal processing instead of implying a live send.

### Added

- `lib/channelCapability.ts` — friendly channel display names (`Blog`, `Instagram`, …) and a capability lookup implementing four states: **Connected** (live — unused today, no channel qualifies), **Draft only** (`blog`, `email` — content drafts and the pipeline completes, but delivery is simulated), **Coming later** (`facebook`, `instagram`, `linkedin`, `x`, `sms`, `landing_page` — no way to create this channel type yet), **Not configured** (reserved for a future supported-but-unconnected state).
- `Components/UI/ChannelCapabilityBadge.vue` — renders the capability label next to a channel type; now shown in the approval confirmation dialog, `Campaigns/Show.vue`, `Publishing.vue`, and `Dashboard.vue`'s recent-executions list.
- `ApproveActions.spec.ts` updated for the new copy: asserts the dialog names the channel and shows "Draft only" / "not yet sent live" rather than an unqualified publish claim.

### Explicitly not done

- No real publisher integrations (Postmark/Mailgun email sending, Facebook/Instagram/LinkedIn/X/SMS APIs) — tracked as a P1/P2 roadmap item in [Product-Polish-Audit.md](docs/reviews/Product-Polish-Audit.md).
- No change to the `Execution`/`ContentAsset`/`Campaign` status machine — `completed`/`published` remain the correct *internal* state names; the fix is scoped to what the user is told, not to state names other code and tests depend on.
- No Channels management UI (separate, larger P1 item).

---

## [P1 Customer Trust & Navigation — Approval Confirmation, Company Switcher, Persistent Layout, Toasts] — 2026-07-07

Implements P1 items 8–11 from [Product-Polish-Audit.md](docs/reviews/Product-Polish-Audit.md). Details in [P1-Customer-Trust-Navigation-Review.md](docs/reviews/P1-Customer-Trust-Navigation-Review.md). Email notifications, Sentry, and Channels UI (items 7, 12–14) intentionally not implemented in this slice.

### Added

- **Approval confirmation** — `ConfirmDialog.vue` (new, teleported, focus-managed, Escape-to-cancel) gates recommendation approval. The dialog lists exactly what will publish and to which channel per content asset, falling back to a generic explanation when there are none. Only confirming submits; cancelling does not.
- **Company switcher** — `CompanySwitcher.vue` in the sidebar header, shown only for users with more than one company membership. Posts to the existing `/company/select` endpoint. Tenant-switching safety (rejecting a company the user doesn't belong to, and that a switch actually re-scopes subsequent requests) is covered by new tests, not just assumed from existing code.
- **Toast primitive** — `composables/useToasts.ts` (module-scoped stack, auto-dismiss, `clearToasts`) + `ToastStack.vue` (dismissible, `aria-live`, animated) replace the static, non-dismissible flash banners. New semantic design tokens (`--color-success-*`, `--color-danger-*`) back the toasts instead of hardcoded Tailwind palette classes.
- **Vitest test infrastructure** — the project's first JS test runner (`happy-dom` environment, `vitest.config.ts`, `npm test`). 13 new tests across `ApproveActions.spec.ts`, `CompanySwitcher.spec.ts`, `useToasts.spec.ts`.
- 9 new PHP tests: `CompanySelectorControllerTest` (index listing, select success, **select rejects a foreign company id — 404**, validation, end-to-end tenant switch), `MiddlewareTest` (+3: shared `companies` prop, and the regression test below), `RecommendationControllerTest` (+1: `content_assets` carry channel data for the confirmation dialog).

### Fixed

- **The shared Inertia `company` prop had always resolved to `null`.** `HandleInertiaRequests` is registered as global `web` middleware and therefore runs *before* the route-level `EnsureCompanyMembership` middleware sets the `company` request attribute — the prop was computed eagerly at share-time, when the attribute didn't exist yet. This silently broke the sidebar's company-name display (and would have broken the new switcher) on every request. Fixed by making `company` a lazy closure, same pattern as the new `companies` prop, deferring evaluation until the Inertia response is actually built.

### Changed

- **Persistent layout** — all 12 `Pages/App/**` pages now use `defineOptions({ layout: AppLayout })` instead of wrapping their template in `<AppLayout>`. The sidebar, mobile drawer state, and toast stack survive Inertia navigations instead of remounting per page.
- **Active-nav-link detection** — replaced `window.location.pathname` (which only worked because of the remount the persistent layout just removed) with a reactive `usePage().url`-derived computed.
- **`<Link>` sweep** — 18 raw `<a href="/app/...">` internal navigations across 10 files converted to Inertia `<Link>`, including `SummaryCard.vue`'s dynamic `<component :is>` tag resolution. No visual redesign — markup and styling otherwise unchanged.
- `ApproveActions.vue` now receives `content_assets` as a prop (already returned by `RecommendationController::show()`) to build the confirmation copy — no new request needed.

---

## [P0 Product Polish — Recurring Loop, Account Safety, Truthful Copy] — 2026-07-06

Implements the P0 tier of [Product-Polish-Audit.md](docs/reviews/Product-Polish-Audit.md). Details in [P0-Product-Polish-Review.md](docs/reviews/P0-Product-Polish-Review.md).

### Added

- **`atlas:sync-due-integrations` command** (scheduled every 15 min) — dispatches `SyncIntegration` for active integrations whose `next_run_at` has passed. The Observe → Learn loop now actually repeats; previously `next_run_at` was written but never consumed and Atlas observed each business exactly once.
- **`ExpireOpportunities` scheduled hourly** — the job existed but was never dispatched. Expiry is what re-enables detection of a lapsed opportunity type (the engine's dedupe only counts open/selected rows); regression test added.
- **Per-sync crawl depth** — `WebPageCrawler::crawl()` accepts a page-budget override; `WebsiteConnector` keeps the first sync shallow (`crawler.max_pages`, default 1, fast onboarding) and crawls deeper on every later sync (`crawler.recurring_max_pages`, default 10, env `CRAWLER_RECURRING_MAX_PAGES`).
- **Password reset flow** — `Auth\PasswordResetController`, `password.request/email/reset/update` routes, `ForgotPassword.vue` + `ResetPassword.vue`, "Forgot password?" link on Login (which now renders flash success). Send-link always reports success — no account enumeration.
- **Rate limiting** — `throttle:5,1` on login/register/forgot-password/reset-password; `throttle:3,1` on the onboarding website submit (each submit can queue a crawl + 5-call AI pipeline).
- 19 tests: due-sync dispatch/exclusion rules, schedule registration, expiry no-suppression, crawl-budget selection, password reset (6), rate limits (4), integration reuse (2).

### Changed

- **Onboarding resubmits reuse the existing website integration** (update URL, reset status, clear `last_error`) instead of creating a new row + queued pipeline run each time — with `SyncIntegration`'s per-integration uniqueness this caps AI spend from repeat submits, and the "Try a different URL" flow now properly resets the errored integration.
- **Truthful status-page copy** — the no-opportunity card no longer tells users to "connect more channels in Settings" (no channel UI exists); the timeout card no longer promises a notification (no notification system exists). Both now describe what actually happens: automatic re-scans and a recommendation waiting on the dashboard.

---

## [P0 — Recommendations Page Blank (null campaign_type crashes render)] — 2026-07-06

### Fixed

- **`RecommendationService::create()` never copied `campaign_type` onto the recommendation** — the decision and campaign both carried the type (e.g. `re_engagement`), but the recommendation was created without it, leaving `campaign_type` null. The recommendation detail and list pages call `campaign_type.replace(/_/g, ' ')` to render the heading; `.replace()` on `null` throws a `TypeError` that crashes the Vue render, so the page came up blank ("not loading"). Fixtures always set a non-null type, so it only surfaced once the real pipeline produced a recommendation. `create()` now sets `'campaign_type' => $campaign->campaign_type`, which also fixes `ApprovalService` reading `$recommendation->campaign_type ?? ''` (previously empty) when publishing on approval.
- **Frontend rendered `campaign_type` without a null guard** in 4 places (`Recommendations/Show.vue`, `Recommendations/Index.vue` ×2, `Dashboard/RecommendationPrompt.vue`) — all now use `(campaign_type ?? '').replace(...)` so a null can never blank the page again. The `Recommendation` TypeScript interface's `campaign_type` was corrected to `string | null` to match reality.

### Changed

- Backfilled existing recommendations with a null `campaign_type` from their campaign (2 rows in the dev database).

### Added

- `test_copies_campaign_type_from_campaign` in `RecommendationServiceTest` — asserts the recommendation carries the campaign's type.

---

## [P0 — CommitDecision Fails in 19 ms (Cached Business Brain Rejected as Incomplete Class)] — 2026-07-06

### Fixed

- **`CommitDecision` failed instantly once the pipeline ran on the queue** — `BusinessBrainService::for()` cached the assembled Business Brain in Redis via `Cache::remember`. Laravel 13's `config/cache.php` sets `serializable_classes => false`, so `RedisStore::unserialize()` decoded the object back as `__PHP_Incomplete_Class`. Because `for()` is typed `: BusinessBrain`, returning the incomplete object threw a `TypeError` in ~19 ms — before any AI call — so no decision, campaign, or recommendation was produced. It was hidden until Phase 8 moved the pipeline off the single `dispatchSync` process (where the closure result was returned directly, never round-tripping through Redis).

### Changed

- `BusinessBrainService` now memoizes the Brain in a per-process `static array` (keyed by company id, with a 300 s `expires_at`) instead of writing it to the shared cache store. The Brain is a job/request-scoped value object assembled from the database — it never needed to cross process boundaries and must not be serialized under the hardened cache policy. `invalidate()` (called by the `FactExtracted` / `KnowledgeSynthesized` listeners) unsets the memo entry, so freshness semantics are unchanged within a process. The public `cacheKey()` method was removed; `isMemoized()` and `flush()` were added for tests.

### Added

- 2 tests in `BusinessBrainCacheTest`: `test_brain_is_never_written_to_the_shared_cache_store` (regression — `Cache::get("brain:{id}")` is `null` after `for()`) and `test_memo_expires_after_ttl` (fresh instance assembled after 6 minutes). The remaining cache tests were migrated from `Cache::has` assertions to `BusinessBrainService::isMemoized`, still covering population, TTL staleness, explicit + event-driven invalidation, and per-company isolation.

---

## [P0 — Onboarding Website Submit Causes 502 Bad Gateway] — 2026-07-05

### Fixed

- **Submit ran the entire pipeline inline** — `OnboardingController::createIntegration()` used `SyncIntegration::dispatchSync()` (Phase 1) combined with `QUEUE_CONNECTION=sync` (Phase 3), so by Phase 7 the request executed crawl + five sequential Anthropic calls (facts, opportunity, rationale, campaign, content) plus overload backoff — minutes of work, past Herd/PHP-FPM's gateway timeout → 502. The controller now queues the job (`dispatch()`) and redirects immediately; the try/catch remains only for sync-driver environments.
- **Stall detection missed the pre-crawl window** — with the sync queued, a missing worker leaves `last_run_at` null and the old `pipeline_stalled` heuristic (which required a started sync) never fired. The status endpoint now also flags an integration that was queued > 90 s ago and never started.

### Changed

- `QUEUE_CONNECTION` local default: `sync` → `database` in `.env.example` (jobs table ships with Laravel's base migrations). Local dev runs via `composer dev`, which already starts a worker on all Atlas queues alongside the scheduler, pail, and Vite. Env comments warn that `sync` blocks the onboarding request for minutes.
- `Status.vue` stalled card generalized — "Atlas is waiting for a queue worker" now covers both pre-crawl and post-crawl stalls and suggests `composer dev` first, with the full `queue:work` command as fallback.

### Added

- 4 tests: submit queues the job and never dispatches it synchronously (`Bus::assertNotDispatchedSync`), submit records no observations and makes no AI calls, queued-but-never-started sync surfaces `pipeline_stalled`, and a status-progression walk from queued → started → facts.

---

## [P0 — Facts Created But No Opportunities Or Recommendations] — 2026-07-05

### Fixed

- **Opportunity detection triggered only by a once-per-lifetime event** — `TriggerOpportunityDetection` listened to `DigitalTwinActivated`, which fires only on the twin's `initializing → active` transition. Any company whose twin was already active (retried onboarding, recurring sync, earlier partial run) extracted facts and dead-ended with 0 opportunities and 0 recommendations forever. The listener now runs on `ObservationProcessed` — every successfully processed observation with current facts dispatches a scan. `DetectOpportunities` is now `ShouldBeUnique` per company so crawl bursts collapse to one queued scan; the engine's dedupe keeps repeat scans safe.
- **Downstream failures corrupted observation status** — `ObservationProcessed` was dispatched inside `ProcessObservation`'s try/catch, so an inline downstream failure (sync queue) flipped an already-processed observation to `failed` and, combined with the trigger bug, permanently killed the pipeline. The event now dispatches after the try/catch with its own containment: downstream failures are logged and reported, never alter observation status.

### Added

- `no_opportunities` field in `GET /api/onboarding/status` — `true` when facts exist but the scan legitimately produced no open opportunities and no pending recommendation, asserted only once the last processed observation is > 90 s old.
- "Atlas learned your business — no campaign opportunity yet" card in `Status.vue` — shows the fact count and next steps (review the Business Brain, connect channels or add catalog items, Atlas keeps scanning on future syncs); links to the dashboard and Brain page; polling stops.
- Structured logging across Facts → Knowledge → Opportunity → Recommendation: knowledge synthesis complete (fact/entry counts), twin activation, scan trigger/skip decisions, scan start, drop-reason counters (`dropped_duplicate`, `dropped_below_threshold`), explicit no-opportunities-persisted line, and decision evaluated/committed/skipped.
- 6 tests: full pipeline with an already-active twin still produces a recommendation (regression test for this P0), legitimate empty scan leaves everything healthy, `DetectOpportunities` dispatched after processing, downstream failure containment, and both sides of the `no_opportunities` API flag.

---

## [P0 — Anthropic overloaded_error Treated as Permanent Failure] — 2026-07-05

### Fixed

- **Transient Anthropic overload marked the integration `error` immediately** — `overloaded_error` (HTTP 529) is a temporary capacity condition, but it propagated as a generic exception and both `SyncIntegration::failed()` and `OnboardingController`'s catch called `markAsError()`, showing "Atlas couldn't reach your website" even though the crawl succeeded. Both paths now exempt the new `AiProviderOverloadedException`; the integration stays `active`.
- **No retry for overloaded_error** — `AnthropicProvider` now retries overloaded responses in-process with backoff (500 ms / 1.5 s / 3 s, 4 attempts total, injectable for tests) before throwing `AiProviderOverloadedException`. Overload is detected via HTTP 529 or an `overloaded_error` body type (authoritative even behind status-rewriting proxies). Non-overloaded errors still fail immediately with no retries.
- **Overload downgraded observations to `failed`** — `ProcessObservation` now parks the observation in the new `retrying` status and rethrows; only the final queued worker attempt marks it `failed`. Added job `$backoff = [30, 120]` for spaced queued retries.

### Added

- `app/AI/Exceptions/AiProviderOverloadedException.php` — retryable provider-capacity exception carrying the Anthropic `request-id`.
- `request_id` logging — the `request-id` response header is logged on every retry attempt and API error, embedded in exception messages, and included in the debug raw-response log.
- `retrying` observation status — added to the base observations migration (fresh/sqlite DBs) plus `2026_07_05_000100_add_retrying_status_to_observations` to rewrite the Postgres check constraint on existing DBs; `Observation::markRetrying()`.
- `ai_retrying` field in `GET /api/onboarding/status` — `true` while an observation waits on the provider; `pipeline_stalled` now excludes that state. With the sync queue (no worker), the endpoint re-dispatches stale retrying observations inline (throttled to one attempt per 30 s), so onboarding self-heals while the status page polls.
- "Atlas is waiting for the AI provider" card in `Status.vue` — amber, explains the overload is temporary and retries are automatic; polling continues instead of stopping like the failure cards.
- `FakeAiProvider::queueException()` — queue a Throwable to simulate provider failures in tests.
- 9 tests across `AnthropicProviderTest` (retry-then-succeed, retries exhausted with request_id, 503+overloaded body, no retry for non-overload errors, request_id in error messages), `ProcessObservationTest` (retrying status, `ai_retrying` in the status API, stale-observation re-dispatch recovers inline), and `OnboardingPipelineTest` (full inline chain leaves integration `active`).

---

## [P0 — Real Anthropic Responses Produce 0 Facts (max_tokens Truncation + Silent Empty Success)] — 2026-07-05

### Fixed

- **`FactExtractionPrompt::maxTokens()` too small (1024 → 4096)** — a real page yields dozens of facts and the structured tool-use JSON easily exceeds 1024 output tokens. When the Messages API hits `max_tokens` mid-way through a forced tool call it cannot return the partial JSON, so `tool_use.input` came back empty and the pipeline saw 0 facts with no error. Root cause of the "AI call completes but fact_count=0" P0.
- **`AnthropicProvider` ignored `stop_reason`** — a truncated structured response was indistinguishable from a valid one. The provider now throws when a schema prompt's response has `stop_reason=max_tokens`, or contains no `tool_use` block despite forced `tool_choice` (previously returned `''`, surfacing later as a confusing JSON parse error). `AiResponse` gained a nullable `stopReason` field.
- **`WebsiteAnalyst` treated empty/invalid AI output as success** — missing `facts` key, empty facts array, or unparseable JSON now throws the new `FactExtractionFailedException` instead of marking the observation `processed` with 0 facts. `ProcessObservation`'s existing failure path marks the observation `failed`, which the onboarding API already surfaces as `ai_failed=true`.
- **Empty tool input re-encoded as `[]`** — PHP array cast turned Claude's empty `{}` input into a JSON list; now object-cast so downstream parsers see the correct shape.
- **Prompt `temperature()` never sent to the Anthropic API** — now included in every request (fact extraction runs at 0.1).

### Added

- `app/Services/Analyst/Exceptions/FactExtractionFailedException.php` — thrown when AI output cannot be turned into facts; flows into the existing `ai_failed` onboarding signal.
- Malformed fact entries (missing `key`/`value`/`data_type`/`confidence`) are skipped with a `Log::warning()`; valid entries in the same response are kept.
- Debug-only raw AI response logging — `AnthropicProvider` logs the raw API body and `WebsiteAnalyst` logs the response content at `debug` level when `APP_DEBUG=true` (never in production; bodies can contain crawled page content).
- 15 tests: realistic Anthropic Messages API payload through the real provider + parser (`AnthropicProviderTest`, `WebsiteAnalystTest`), truncation/no-tool_use/temperature/stop_reason coverage, invalid JSON / empty facts / all-malformed failure paths, and an end-to-end `ProcessObservationTest` asserting empty facts → observation `failed` → `GET /api/onboarding/status` returns `ai_failed=true`.

### Changed

- `Status.vue` AI-failure card copy broadened — zero-fact extractions also land here, so it now explains both provider misconfiguration and pages without enough readable business text, and offers "Try a different URL" alongside "Go to dashboard".

---

## [P0 — Real Crawls Produce 0 Facts (body_text Key Mismatch)] — 2026-06-29

### Fixed

- **`WebsiteAnalyst` reads wrong payload key** — `WebPageData::toArray()` produces `body_text` (snake_case) but `WebsiteAnalyst::analyze()` was reading `$payload['bodyText']` (camelCase). The early-return guard `empty($payload['bodyText'])` was always `true` for every real crawl, returning an empty collection with no error or log. No AI call was made; observation was marked `processed` with 0 facts. Changed both occurrences to `body_text`.
- **`ANTHROPIC_API_KEY` ignored in local env** — `AppServiceProvider` bound `LocalAiProvider` for `APP_ENV=local` regardless of whether `ANTHROPIC_API_KEY` was set. Users who added an API key expecting Anthropic to be used got stub responses instead. Binding now uses `AnthropicProvider` when `ANTHROPIC_API_KEY` is set (even in local), and `LocalAiProvider` only as a fallback when no key is configured.
- **`SettingsControllerTest::test_sync_integration_dispatches_job`** — test triggered the full pipeline inline (via `QUEUE_CONNECTION=sync`) and called `FakeAiProvider::complete()` with no fixture queued, causing a 500. Fixed by adding `Bus::fake()` + `Bus::assertDispatched()` — the test now verifies dispatch only, as the name implies.
- **Test payloads used `bodyText` instead of `body_text`** — 4 test files created observation payloads with `'bodyText'` (matching the old broken analyst). Updated to `'body_text'` to reflect `WebPageData::toArray()` output: `PipelineSmokeTest`, `OnboardingPipelineTest`, `WebsiteAnalystTest`, `ProcessObservationTest`.

### Added

- Structured logging in `WebsiteAnalyst::analyze()`: `Log::warning()` when `body_text` is absent/empty (logs observation ID and actual payload keys); `Log::info()` before AI call and after fact extraction (logs observation ID and fact count).
- `crawl_succeeded` field in `GET /api/onboarding/status` — `true` when at least one Observation exists for the company; allows UI to distinguish "crawl failed" from "AI pipeline failed".
- `ai_failed` field in `GET /api/onboarding/status` — `true` when an Observation exists but has `status = 'failed'`; signals an AI provider error distinct from a crawl error.
- AI failure error card in `Status.vue` — distinct from the crawl-failure card; shown when `ai_failed` is true; explains the likely cause (missing/invalid `ANTHROPIC_API_KEY`); polling stops immediately.

### Changed

- `AppServiceProvider` — provider selection order changed: `testing` → `FakeAiProvider`; `local` without key → `LocalAiProvider`; `local` with key or production/staging → `AnthropicProvider`.
- `OnboardingStatusController` — `pipeline_stalled` guard now also requires `!$aiFailed` so stalled and AI-failed states are mutually exclusive.
- Early-return null-response path in `OnboardingStatusController` (no membership) — now includes `crawl_succeeded: false` and `ai_failed: false` for consistency.

---

## [P0 — Observation Created But Facts Never Extract] — 2026-06-28

### Fixed

- **Queue driver mismatch** — `ProcessObservation` dispatches to the `ai` queue via `dispatch()`, not `dispatchSync()`. With `QUEUE_CONNECTION=redis` and no worker, facts never extracted. `.env.example` now defaults to `QUEUE_CONNECTION=sync` so local dev works without a running worker.
- **No AI provider in local environment** — `AnthropicProvider` was bound for all non-testing environments. Without `ANTHROPIC_API_KEY`, every AI call failed. New `LocalAiProvider` provides deterministic stubs for all 5 prompt types in the `local` environment.
- **No Channel for new companies** — `DecisionEngine::evaluate()` Guard 5 requires at least one active Channel. `OnboardingController::createIntegration()` now seeds a default Blog channel if none exists, unblocking Decision evaluation.

### Added

- `app/AI/Providers/LocalAiProvider.php` — stub AI provider for `local` environment; all 5 prompt types; no API key required; passes `validateBlueprint()` validation
- `tests/Feature/OnboardingPipelineTest.php` — 2 end-to-end tests covering the full crawl → facts → recommendation path and the failed-crawl error path; mocks `ConnectorRegistry`; blog channel matches onboarding default
- `tests/Fixtures/AI/blog-content.json` — blog post content fixture for `GenerateContent` with blog channel type
- Pipeline logging — `Log::info()` at each stage of `ObservationService`, `ProcessObservation`, and `OpportunityEngine`
- `pipeline_stalled` in `GET /api/onboarding/status` — `true` when sync ran > 90s ago with no facts; surfaces queue worker absence
- Stalled state card in `Status.vue` — yellow warning card with queue worker command when `pipeline_stalled` is true

### Changed

- `AppServiceProvider` — `LocalAiProvider` bound for `local` environment; `AnthropicProvider` for non-local/non-testing
- `OnboardingController::createIntegration()` — seeds default Blog channel after integration creation if no channels exist
- `.env.example` — `QUEUE_CONNECTION` default changed from `redis` to `sync`

---

## [P0 — Onboarding Analysis Pipeline Does Not Start] — 2026-06-28

### Fixed

- `IntegrationService::create()` no longer auto-dispatches `SyncIntegration`. Callers control the dispatch so the onboarding path can run it synchronously and other callers (e.g. Settings) keep their existing async dispatch.
- `OnboardingController::createIntegration()` now calls `SyncIntegration::dispatchSync()` immediately after creating the integration. The website crawl runs inline in the same HTTP request — no queue worker is required for the first onboarding sync. If the crawl throws, the integration is marked `status=error` and the user is sent to the status page which shows the failure state.
- `OnboardingStatusController` now returns `integration_status` (the integration's `status` column) and `sync_started` (`last_run_at !== null`) so the frontend can distinguish "queued but not started", "running", and "error" states.
- `Status.vue` shows a dedicated error card ("Atlas couldn't reach your website") when `integration_status === 'error'`. Polling stops immediately on error. Progress list gained a new first step "Website scanned" driven by `sync_started`.
- `ConnectorServiceProvider` wires `WebPageCrawler` with `maxPages` from `config/crawler.php` (env: `CRAWLER_MAX_PAGES`, default: 20).

### Added

- `config/crawler.php` — new config file for website crawler settings
- `tests/Feature/Api/OnboardingStatusControllerTest.php` — 4 tests covering the status API: no membership, active integration before sync, active after sync, error state
- `test_integration_step_marks_error_when_sync_fails` — verifies that a crawl failure marks the integration as `error` and still redirects to status page
- `test_does_not_auto_dispatch_sync_job` in `IntegrationServiceTest` — documents the new contract
- `docs/reviews/P0-New-Customer-Onboarding-Fix.md` — full root-cause analysis and fix documentation

### Changed

- `test_integration_step_dispatches_sync_job` renamed `test_integration_step_dispatches_sync_job_synchronously`; uses `Bus::fake()` + `Bus::assertDispatched()` instead of `Queue::fake()` + `Queue::assertPushed()` (sync dispatch bypasses the queue driver)

---

## [New Company Onboarding Happy Path Fix] — 2026-06-28

### Fixed

Three bugs that prevented a new user from reaching the website connection step after creating a company:

| Bug | File | Fix |
|-----|------|-----|
| `OnboardingController::index()` bounced any user with a membership to `/app`, skipping the integration step | `OnboardingController.php` | Now routes by company state: no membership → step 1; membership + no integration → step 2; has integration → status page |
| Integration form posted field `url`, server validated `website_url` | `Onboarding/Index.vue` | Fixed field name; added `initial_step` prop so server controls starting step; removed "Skip for now" button |
| `/app` showed empty dashboard when company had no integration | `DashboardController.php` | Added redirect to `/onboarding` when no integration exists |

### Added

- 6 new `OnboardingControllerTest` cases covering the full happy path and `SyncIntegration` job dispatch
- 1 new `DashboardControllerTest` case for the no-integration redirect
- `docs/reviews/New-Company-Onboarding-Fix.md`

---

## [CI Fix: pdo_sqlite extension] — 2026-06-28

### Fixed

- `.github/workflows/ci.yml` — added `pdo_sqlite` to `setup-php` extensions list
- `backend/composer.json` / `backend/composer.lock` — added `brianium/paratest ^7.20` dev dependency for `php artisan test --parallel` support

**Root cause:** `phpunit.xml` overrides `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` for all tests, but the CI `setup-php` step did not explicitly include `pdo_sqlite`. This caused test failures in CI while passing locally where the extension is available by default.

---

## [Private Beta Readiness Audit] — 2026-06-27

### Added

- `docs/reviews/Beta-Readiness-Audit.md` — comprehensive CTO-style operational audit across 40 areas
- `docs/plans/Private-Beta-Plan.md` — 4-week sprint plan to safely onboard first 10 paying customers

**Audit scope:** Product readiness, authentication, authorization, multi-tenancy, data isolation, AI provider resilience, prompt management, queue architecture, scheduler, background jobs, failure recovery, logging, monitoring, health endpoints, security, SSRF, secrets management, backups, disaster recovery, migrations, deployment, CI/CD, test coverage, performance, scalability, caching, storage, email delivery, domain, analytics, learning, audit trails, customer support, admin tooling, runbooks, privacy, legal, documentation, known limitations.

**Key findings:**

| # | Finding | Severity | Blocks Beta? |
|---|---------|----------|-------------|
| B1 | `ResolveCurrentCompany` middleware unverified / missing | Critical | Yes |
| B2 | No production server provisioned | Critical | Yes |
| B3 | Email uses log driver only | Critical | Yes |
| B4 | No monitoring or alerting | Critical | Yes |
| B5 | No database backups | Critical | Yes |
| B6 | No domain configured | Critical | Yes |
| B7 | No privacy policy or terms of service | Critical | Yes |
| — | AI provider rate limiting missing | High | No |
| — | No auth rate limiting or security headers | High | No |
| — | No operational runbooks | High | No |
| — | BusinessBrainService cache not implemented | High | No |

**Beta Readiness Score: 31 / 100**
**Go / No-Go: NO-GO**

**Private Beta Plan covers:**
- Week 1: Production infrastructure (server, domain, SSL, queues, storage, email)
- Week 2: Security, tenancy verification, compliance (privacy policy, ToS, email verification)
- Week 3: Monitoring, reliability, operational runbooks, end-to-end production test
- Week 4: Customer onboarding polish, Getting Started guide, beta launch

**Updated:**

- `docs/STATUS.md` — current milestone updated with audit summary and critical blockers

---

## [Landing Page Design & Content Specification] — 2026-06-27

### Added

- `docs/marketing/Landing-Page.md` — complete landing page design and content specification for Atlas

**Document scope:** 24 sections; ~5,500 words of spec covering every element of the Atlas marketing landing page from navigation through footer.

**Sections specified:**

| # | Section | Key content |
|---|---------|-------------|
| 01 | Navigation | Fixed bar with sticky CTAs; mobile hamburger overlay |
| 02 | Hero | Three headline variants; full recommendation UI mockup; copy rationale |
| 03 | Trust Bar | Pre-launch proof signals; real CBB Auctions design partnership noted |
| 04 | Problem Statement | Marcus's 30-minute window framed as prose, not bullets |
| 05 | How Atlas Works | Nine-step loop with visual emphasis on Step 06 (Approve) as the center |
| 06 | The Digital Twin | Business Brain diagram; knowledge entries in plain language |
| 07 | Recommendation Showcase | Full CBB Auctions recommendation mockup with real content |
| 08 | The Approval Moment | Approval-as-design-intent section; "0 campaigns published without approval" |
| 09 | Features | Four feature groups: Business Intelligence, Recommendation, Approval, Learning |
| 10 | Learning Over Time | Day 1 vs Day 90 comparison; compounding value story |
| 11 | Industries | Comic book auction houses and exotic car dealers; third card for expansion |
| 12 | Social Proof | Testimonial structure for Marcus and Sofia archetypes; stat row |
| 13 | Trust & Security | Six specific data trust statements; no vague security language |
| 14 | Final CTA | Dark background close section; micro-copy removing last friction |
| 15 | FAQ | 10 questions addressing real objections in specific, honest language |
| 16 | Footer | Four-column layout; positioning tagline |
| Mobile | Mobile Layout | Per-section adjustments; breakpoints; what reduces vs what stays |
| Animation | Animation Spec | Timing values, easing, scroll triggers, reduced-motion fallbacks |
| A11y | Accessibility | WCAG 2.1 AA; ARIA patterns; heading hierarchy; keyboard nav; screen reader |
| CTA | CTA Strategy | Placement logic per section; four variants to A/B test; label rationale |
| Copy | Copy Principles | Banned phrases; what Atlas sounds like; skimmability rules |

**Strategic foundation:** Four core messages that every section reinforces:
1. Atlas thinks before it creates
2. Atlas explains every recommendation
3. Atlas learns over time
4. Humans remain in control

**Key design decisions:**
- Hero headline avoids the word "AI" — behavior communicates better than the label
- The recommendation mockup is populated with specific CBB Auctions content (Action Comics #1 CGC 6.0, closing-auction urgency framing) — not generic placeholder text
- The "Approve" step (06 of 09) in How Atlas Works receives distinct visual treatment to reinforce that approval is the product, not a limitation
- Section 08 (The Approval Moment) has a dark background — a values-forward moment that benefits from visual distinction
- CTAs are placed at the end of persuasive arguments, not randomly — explicit placement logic documented per section
- Copy principles document bans 10 generic AI marketing phrases and provides positive direction

**No code written.** This is a specification document for a designer and frontend engineer to implement.

---

## [Version 0.2 Polish — Tier 1 & 2] — 2026-06-27

### Changed

**HealthCard + Brain.vue — T1-1 (active status fix)**
- `resources/js/Components/Dashboard/HealthCard.vue` — status labels now only contain `initializing`, `active`, `error`; removed fake `crawling/analyzing/ready` variants that never matched DB values; `active` now shows "Active" in `text-emerald-600` instead of falling through to raw gray text
- `resources/js/Pages/App/Brain.vue` — same fix: `twinStatusLabels` and `twinStatusVariants` updated to `active/initializing/error` only

**Onboarding redirect + timeout — T1-2 + T2-14**
- `backend/app/Http/Controllers/Api/OnboardingStatusController.php` — added `first_recommendation_id` to JSON response (queries first pending recommendation for the company)
- `resources/js/Pages/Onboarding/Status.vue` — routes to `/app/recommendations/{id}` when recommendation ready; polls at 5s; shows timeout message after 5 min; hard-stops polling at 10 min; stepLabels use actual enum values

**Enum badge translation — T1-3**
- `resources/js/Pages/App/Opportunities.vue` — `typeLabels` map translates `featured_item`, `urgency_promotion`, `new_arrival`, `re_engagement` to readable labels
- `resources/js/Pages/App/Campaigns/Show.vue` — `statusLabels` and `executionStatusLabels` maps added; all status badges now show human-readable labels
- `resources/js/Pages/App/Campaigns/Index.vue` — `statusLabels` map added; `published` variant added
- `resources/js/Pages/App/Learning.vue` — `signalLabels` (11 signals) and `sourceTypeLabels` maps translate all signal and source values

**Analytics metric key translation — T1-4**
- `resources/js/Pages/App/Analytics/Show.vue` — `metricLabels` map covers all normalised and platform-specific metric keys; `labelMetricKey()` function with titleCase fallback; applied to expected_impact, actual_kpis, and channel breakdown metric displays

**Edit & Approve button + explanatory copy + inline errors — T2-1 + T2-2 + T2-9**
- `resources/js/Components/Recommendations/ApproveActions.vue` — "Edit & Approve" added as secondary button emitting `editAndApprove`; explanatory paragraph added below buttons; `approveError` and `rejectError` refs wired to `onError` callbacks
- `resources/js/Pages/App/Recommendations/Show.vue` — listens for `@edit-and-approve` and calls `startEdit(content_assets[0])`

**ScoreBar — T2-3 + T2-4**
- `resources/js/Components/UI/ScoreBar.vue` — fully rewritten; dynamic fill color by value range (red 0–39, orange 40–59, yellow 60–74, green 75–89, emerald 90+); `role="progressbar"` + `aria-valuenow/min/max` ARIA attributes; screen-reader span; numeric label always visible

**Opportunity expiry treatment — T2-5**
- `resources/js/Pages/App/Opportunities.vue` — `formatTimeRemaining()` returns `{ text, urgency }`; <24h → rose; 24–48h → amber; 2–7 days → plain text; >7 days → calendar date; urgency class applied to expiry label

**Page title tags — T2-6**
- `<Head>` with `<title>` added to all 16 app pages: Dashboard, Recommendations/Index, Recommendations/Show, Opportunities, Brain, Campaigns/Index, Campaigns/Show, Publishing, Analytics/Index, Analytics/Show, Learning, Settings, Onboarding/Index, Onboarding/Status, Auth/Login, Auth/Register

**Mobile padding — T2-7**
- `resources/js/Layouts/AppLayout.vue` — `<main>` changed from `px-8 py-6` to `px-4 py-6 lg:px-8`; flash message wrapper changed from `px-8` to `px-4 lg:px-8`

**Form label typography — T2-10**
- `resources/js/Pages/Auth/Login.vue` — all `<label>` elements updated to `text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest`
- `resources/js/Pages/Auth/Register.vue` — same
- `resources/js/Pages/Onboarding/Index.vue` — same
- `resources/js/Pages/App/Settings.vue` — same

**Health score in HealthCard — T2-11**
- `resources/js/Components/Dashboard/HealthCard.vue` — `twin_health_score` prop added; `score` computed (0 when null); `healthLabel` computed ("Healthy" 80+, "Building" 50+, "Learning" <50); health score row added to card display
- `resources/js/Pages/App/Dashboard.vue` — passes `twin_health_score` from health prop to HealthCard

**Business Brain nav label — T2-12**
- `resources/js/Layouts/AppLayout.vue` — navLinks entry for `/app/brain` renamed from `'Brain'` to `'Business Brain'`

**Rationale text size — T2-13**
- `resources/js/Components/Recommendations/RationaleCard.vue` — body `<p>` changed from `text-sm` to `text-base leading-relaxed`

### Quality Gates

| Gate | Result |
|------|--------|
| PHPUnit (581 tests) | 579 passing, 2 Redis skipped |
| PHPStan level 8 | 0 errors |
| Laravel Pint | Clean |
| Frontend build (Vite) | 129 modules, 0 errors |

---

## [Product Validation Sprint] — 2026-06-27

### Added

- `docs/reviews/Product-Validation-Review.md` — full customer experience review against all spec documents (CLAUDE.md, FOUNDING_PRINCIPLES.md, docs/design/System.md, PRD.md, Personas.md, UserFlows.md). 24 issues across 20 review areas. Each issue documented with: severity, description, why it matters, screenshot location, recommended fix, and estimated effort.

  **Issue severity breakdown:**
  - 1 Critical: Active DigitalTwin status not handled (HealthCard shows raw "active" in gray for every onboarded customer)
  - 4 High: Onboarding redirects to dashboard not recommendation; "Edit & Approve" not a visible button; raw enum values in all badges; analytics metric keys shown to user
  - 14 Medium: Score bar fixed color; no expiry urgency treatment; no page titles; mobile padding; form label typography; no inline error on approval failure; no page transition indicator; no timeout on status page; health score not displayed; rationale text too small; empty state CTAs missing; no skeleton loading; campaign status raw values; learning signals raw
  - 9 Low: "Brain" label not "Business Brain"; settings active state; "Publishing" label; rejection label wording; analytics empty CTA; empty state icons; settings scroll reset; typography/status tokens not in app.css; primary button shade; focus rings; favicon

- `docs/plans/Version-0.2-Polish.md` — prioritized implementation plan for all 24 identified polish issues, organized into three tiers:
  - **Tier 1 — Trust blockers (~2.5 days):** 4 issues that silently misrepresent the product's state. HealthCard active status fix, onboarding redirect to first recommendation, raw enum translation across all badges, analytics metric key translation.
  - **Tier 2 — Clarity gaps (~5.5 days):** 13 issues requiring extra customer effort to work around. "Edit & Approve" button, explanatory approve copy, score bar colors + ARIA, opportunity expiry urgency, page titles, mobile padding, NProgress transition, inline approval errors, form label typography, health score display, rationale text size, status page timeout message.
  - **Tier 3 — Polish (~4 days):** 14 lower-priority items. Campaign lifecycle trail, contextual empty state icons, empty state CTAs, nav label fixes, design system token registration, focus rings, favicon, button shade, isActive fix, etc.

### Changed

- `docs/STATUS.md` — Product Validation Sprint added as completed milestone; key findings summarized; V0.2 Planning moved to previous milestone

### Not Changed

- No code was modified in this sprint. Review and planning only. Implementation begins in the Version 0.2 Polish sprint.

---

## [Version 0.2 Planning] — 2026-06-27

### Added

- `docs/plans/Version-0.2-Roadmap.md` — 9-milestone roadmap for taking Atlas from a functional local pipeline to a live, observable, customer-onboarded product with real publishing and real feedback
  - **M11 — Production Infrastructure:** Forge + DigitalOcean provisioning, PostgreSQL RLS, Supervisor queue workers, zero-downtime deploys, staging environment
  - **M12 — Error Reporting:** Flare (or Sentry) integration, exception triage runbook, job failure alerting
  - **M13 — Telemetry & Monitoring:** Laravel Pulse (queues, slow queries, exceptions), uptime monitoring, scheduled job heartbeats
  - **M14 — Demo Environment:** Seeded `mountain-city-comics` company, nightly reset command, read-only guard, shareable URL
  - **M15 — Onboarding Improvements:** Email verification, progress persistence, crawl status copy improvements, timeout handling, welcome email, post-onboarding checklist
  - **M16 — Real Email Publishing:** `PostmarkEmailProvider`, channel credential UI, sandbox mode, Postmark webhook integration
  - **M17 — Real Social Publishing:** Meta OAuth flow, `MetaPublisher` (Instagram + Facebook), image upload, content policy error handling, token refresh
  - **M18 — Real Analytics Integrations:** `MetaAnalyticsProvider`, `PostmarkAnalyticsProvider`, real learning signal generation from live engagement data
  - **M19 — Customer Feedback Tooling:** In-app NPS widget, `Feedback` model, `FeedbackNotification`, weekly digest, Filament review panel

### Changed

- `docs/STATUS.md` — Current Milestone section updated to reflect V0.2 planning complete; planned milestones table added; Last Updated updated

---

## [Milestone 10 — Customer Dashboard & UX] — 2026-06-28

### Added

**Frontend foundation**
- `package.json` — Vue 3, TypeScript, Inertia.js v3, Tailwind CSS v4, Heroicons, Vite
- `vite.config.ts`, `tsconfig.json`, `resources/js/app.ts` — Vite + TypeScript bootstrap
- `resources/css/app.css` — `@theme {}` design token block: warm stone neutrals + indigo accent, Instrument Sans via Bunny fonts CDN
- `resources/views/app.blade.php` — Inertia root template
- `app/Http/Middleware/HandleInertiaRequests.php` — shares `auth.user`, `company`, `flash` with every Inertia response

**Layouts and shared components**
- `resources/js/Layouts/AuthLayout.vue` — centered card layout for login/register
- `resources/js/Layouts/AppLayout.vue` — 240px fixed sidebar; mobile hamburger + overlay; flash messages; user menu with logout
- `resources/js/Components/UI/Badge.vue` — 6 variants (default, accent, success, warning, neutral, muted)
- `resources/js/Components/UI/EmptyState.vue` — icon + heading + description; 3 tones
- `resources/js/Components/UI/ScoreBar.vue` — animated width bar for opportunity scoring
- `resources/js/Components/UI/LoadingSpinner.vue` — pulse spinner
- `resources/js/types/index.ts` — complete TypeScript types matching all controller response shapes

**Auth and company routing**
- `resources/js/Pages/Auth/Login.vue`, `Register.vue` — forms with Inertia `useForm`
- `resources/js/Pages/App/CompanySelector.vue` — multi-company selection; single-membership users bypass this
- `app/Http/Middleware/EnsureCompanyMembership.php` — resolves company from session (multi) or direct (single); aborts with 401/redirect as appropriate

**Onboarding**
- `resources/js/Pages/Onboarding/Index.vue` — 3-step wizard: company name + industry → website URL → confirmation
- `resources/js/Pages/Onboarding/Status.vue` — polls `/api/onboarding/status` every 4 seconds; auto-redirects when first recommendation appears
- `app/Http/Controllers/OnboardingController.php` — `createCompany`, `createIntegration`, `status`
- `app/Http/Controllers/Api/OnboardingStatusController.php` — JSON status endpoint

**Dashboard**
- `resources/js/Pages/App/Dashboard.vue` — summary counts, health card, pending recommendation prompt, recent campaigns, recent executions
- `resources/js/Components/Dashboard/SummaryCard.vue`, `HealthCard.vue`, `RecommendationPrompt.vue`
- `app/Http/Controllers/App/DashboardController.php` — health data nested under `health` key

**Recommendation workflow**
- `resources/js/Pages/App/Recommendations/Index.vue` — pending and recent lists
- `resources/js/Pages/App/Recommendations/Show.vue` — full review: rationale, expected impact, content preview, approval actions
- `resources/js/Components/Recommendations/RationaleCard.vue`, `ImpactCard.vue`, `ContentPreview.vue`, `ContentEditor.vue`, `ApproveActions.vue`
- `app/Http/Controllers/App/RecommendationController.php` — `index`, `show`, `approve`, `approveEdit`, `reject`; `requireApprovalRole` gates owner/admin only

**Business Brain and Opportunities**
- `resources/js/Pages/App/Brain.vue` — facts, knowledge, recent observations; initializing state
- `resources/js/Pages/App/Opportunities.vue` — scored opportunity cards with score bars
- `app/Http/Controllers/App/BusinessBrainController.php`, `OpportunityController.php`

**Campaigns and Publishing**
- `resources/js/Pages/App/Campaigns/Index.vue`, `Show.vue`
- `resources/js/Pages/App/Publishing.vue` — paginated execution queue
- `app/Http/Controllers/App/CampaignController.php`, `PublishingController.php`

**Analytics and Learning**
- `resources/js/Pages/App/Analytics/Index.vue`, `Show.vue`
- `resources/js/Pages/App/Learning.vue`
- `app/Http/Controllers/App/AnalyticsController.php`, `LearningController.php`

**Settings**
- `resources/js/Pages/App/Settings.vue` — company profile, integration list, sync trigger
- `app/Http/Controllers/App/SettingsController.php`

**Feature tests (62 new)**
- `tests/Feature/App/MiddlewareTest.php`
- `tests/Feature/App/DashboardControllerTest.php`
- `tests/Feature/App/RecommendationControllerTest.php`
- `tests/Feature/App/OnboardingControllerTest.php`
- `tests/Feature/App/BusinessBrainControllerTest.php`
- `tests/Feature/App/OpportunityControllerTest.php`
- `tests/Feature/App/CampaignControllerTest.php`
- `tests/Feature/App/PublishingControllerTest.php`
- `tests/Feature/App/AnalyticsControllerTest.php`
- `tests/Feature/App/LearningControllerTest.php`
- `tests/Feature/App/SettingsControllerTest.php`

### Changed

**Models — method-style to property-style casts (larastan v3.10 compatibility)**
- `app/Models/Knowledge.php` — `protected function casts()` → `protected array $casts`; larastan now infers `expires_at` as Carbon
- `app/Models/Opportunity.php` — same conversion; larastan now infers `detected_at` and `expires_at` as Carbon
- `app/Models/DigitalTwin.php` — same conversion; larastan now infers `last_enriched_at` as Carbon

**BusinessBrain VO**
- `app/Domain/BusinessBrain/BusinessBrain.php` — PHPDoc updated from `Collection<int, mixed>` to `Collection<int, Fact>`, `Collection<int, Knowledge>`, `Collection<int, Observation>`

**Controllers — PHPStan fixes**
- All App controllers: `abort_unless($user instanceof User, 401)` pattern for `$request->user()` narrowing
- `AnalyticsController`: `$s->snapshotted_at->toIso8601String()` (non-nullable Carbon, no nullsafe needed)
- `LearningController`: `$a->created_at->toIso8601String()` (same)
- `CompanySelectorController`: ternary null check for BelongsTo `company` relation
- `OpportunityController`: `$o->detected_at->toIso8601String()` (non-nullable per larastan after cast conversion)

---

## [Milestone 10.1 — Customer Design System] — 2026-06-27

### Added

- `docs/design/System.md` — comprehensive customer dashboard design system document; 21 sections + 2 appendices

**Design system contents:**

| Section | Summary |
|---------|---------|
| Design philosophy | "A quiet, capable presence" — calm, clear, low cognitive load, built for business owners not marketers |
| Typography | Instrument Sans 400/500/600; 9-size scale from 11px label-sm to 30px display; 26px line height on rationale text |
| Color palette | Warm stone/slate neutrals; single indigo accent; full semantic `@theme` token table; rejection rendered in stone (never red) |
| Spacing scale | 4px base unit; 14 tokens (4px → 96px); per-component padding tables; sidebar fixed measurements |
| Layout grid | 12-column; 1140px max-width; 240px fixed sidebar; page header pattern |
| Responsive breakpoints | 5 breakpoints; sidebar appears at `lg` (1024px); mobile-first; hamburger drawer for `< lg` |
| Icons | Heroicons v2 outline/solid; 5 sizes (16px–48px); standard icon mapping for all Atlas domain concepts |
| Card components | 4 variants: default, highlighted (pending recommendation), subtle, ghost; anatomy + padding rules |
| Buttons | 3-level hierarchy: Primary (Approve), Secondary (Edit & Approve), Tertiary (Reject); destructive style reserved for technical failures only |
| Form controls | Inputs, textarea, labels, helper text, error text, selects, checkboxes — all states documented |
| Tables | Anatomy, column patterns, row styles, pagination strip |
| Recommendation cards | Compact (dashboard) and expanded (detail page); rationale quadrant layout at `text-body-lg` (16px/26px) |
| Opportunity cards | Score bar color scale by value; 6-state expiry treatment with amber/rose urgency |
| Campaign cards | Progress trail through full campaign lifecycle |
| Metric cards | Single-metric and expected-vs-actual KPI comparison variants |
| Timeline components | Vertical event trail with status-colored dots |
| Empty states | 3 categories: Atlas is working (reassuring), action needed (single CTA), genuinely empty (matter-of-fact) |
| Loading skeletons | Pulse animation; card/metric/table variants; 300ms minimum display rule |
| Animations | Conservative: no bounce, no confetti; 5 duration tokens (100ms–300ms); Inertia page fade only |
| Accessibility | WCAG 2.1 AA minimum; full ARIA requirements table; keyboard nav; heading structure; `prefers-reduced-motion` |
| Dark mode strategy | Light only for MVP; semantic token architecture supports future dark mode via `@media` overrides only |
| Appendix A | Full Tailwind v4 `@theme {}` CSS block for all custom tokens |
| Appendix B | Component implementation checklist (10 items per component) |

**Key design decisions:**
- Rejection is never red — stone/neutral throughout to avoid creating anxiety around a valid, learning-generating user action
- Rationale quadrant text uses `text-body-lg` (16px / 26px) — the most important reading on the platform, deserves editorial treatment
- Button hierarchy maps directly to approval actions: Primary → Approve; Secondary → Edit & Approve; Tertiary/ghost → Reject
- Score bars use value-based color scale (red → orange → yellow → green → emerald) with numeric score, never a percentage
- Empty states have three distinct tones — never blank, never over-apologetic

---

## [Milestone 10 — Customer Dashboard & UX — Implementation Plan] — 2026-06-27

### Added

- `docs/plans/Milestone-10-Implementation.md` — full implementation plan for the first customer-facing experience on top of the Atlas intelligence platform

**Plan contents:**
- Personas: Marcus (comic book auction house owner) and Sofia (marketing manager) — two user archetypes that drive all UX decisions
- User flows: 6 primary flows — first-time setup, recommendation review/approve, edit before approve, reject, campaign status, analytics
- Architecture decision: Inertia.js + Vue 3 + TypeScript + Tailwind CSS for the customer dashboard (`/app/*`); Filament stays at `/admin` for superadmin ops
- 10 implementation phases in strict sequence: Specification → Frontend Foundation → Auth + Company Routing → Onboarding Wizard → Dashboard Overview → Recommendation Workflow → Opportunities + Business Brain → Campaigns + Publishing → Analytics + Learning → Settings + Polish
- Route structure: 18 customer-facing routes + 2 API endpoints
- Controller inventory: 12 controllers mapping to existing services — no new business logic
- Data shapes: exact props each Inertia page receives from each controller, with the specific models and services sourced from
- Vue component inventory: 2 layouts, 15 pages, 25+ reusable components
- TypeScript types: domain types for all 12 Atlas domain entities
- Security constraints: company isolation, role-gated approval actions (owner + admin only), SSRF protection (existing)
- Testing plan: PHPUnit feature tests per controller + Vitest component tests; approval workflow integration tests; middleware security tests
- Acceptance criteria: 11 verifiable criteria including the PRD north-star metric (URL → recommendation < 10 minutes)
- Open questions: 6 decisions required before Phase 2 begins, with recommendations for each

**Scope note:** This plan contains no new AI capabilities. The dashboard reads from existing models and services. No backend redesign.

---

## [Milestone 9.5 — Version 0.1 Stabilization Sprint] — 2026-06-27

### Added

- `app/AI/Providers/AnthropicProvider.php` — full `AiProvider` implementation against the Anthropic Messages API; supports `generate` and `tool_use` (structured JSON via forced tool call); `embed` raises `UnsupportedOperationException`
- `config/ai.php` — AI provider configuration (model, temperature, max tokens, API key)
- `.env.example` — `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`, `ANTHROPIC_MAX_TOKENS`, `ANTHROPIC_TEMPERATURE`
- `database/migrations/..._add_is_superadmin_to_users_table.php` — `is_superadmin` boolean column on `users`
- `app/Services/Observatory/Connectors/Website/SsrfValidator.php` — SSRF validator; 14 blocked CIDR ranges; blocks loopback hostnames; validates IPv4, IPv6, IPv6-mapped IPv4, DNS-resolved hostnames
- `app/Services/Observatory/Connectors/Website/Exceptions/SsrfBlockedException.php` — exception with `blockedUrl()` and `blockedIp()` factories
- `app/Http/Controllers/Api/HealthController.php` — `GET /health`, `GET /health/live`, `GET /health/ready` endpoints; 200 on healthy, 503 on DB/cache failure
- `tests/Feature/PipelineSmokeTest.php` — end-to-end smoke test; full `ObservationRecorded → Recommendation` pipeline; 5 AI fixtures; asserts all intermediate and final states

### Fixed

- **Jobs silently not dispatching** — all 10 Atlas jobs had `public function queue(): string` which Laravel's `Bus\Dispatcher::dispatchToQueue()` intercepted as a job ID, silently dropping the dispatch. Removed `queue()` method from all jobs; replaced with `$this->onQueue('name')` in constructors. Affected: `ProcessObservation`, `DetectOpportunities`, `CommitDecision`, `PrepareCampaign`, `GenerateContent`, `CreateRecommendation`, `PublishCampaign`, `PublishContent`, `PublishScheduledContent`, `CheckChannelHealth`
- **Duplicate event listeners** — Laravel's auto-discovery (`EventServiceProvider::$shouldDiscoverEvents = true`) was scanning `app/Listeners/` and registering all listeners automatically, while `AppServiceProvider::boot()` was also registering them manually. Every event had two listeners, causing every cascade to fire twice. Fixed by calling `EventServiceProvider::disableEventDiscovery()` in `AppServiceProvider::register()`
- `SsrfValidator::BLOCKED_CIDRS` — corrected `@var` annotation from `array<string, array{network: int, mask: int}>` to `list<string>` (PHPStan level 8)

### Changed

- `app/Providers/AppServiceProvider.php` — `AnthropicProvider` bound for non-test environments; `EventServiceProvider::disableEventDiscovery()` called in `register()` to prevent duplicate listener registration
- `app/Services/Observatory/Connectors/Website/WebPageCrawler.php` — `SsrfValidator::validate()` called before every Guzzle request
- `app/Filament/Providers/AdminPanelProvider.php` — `canAccess()` checks `$user->is_superadmin`; access denied returns 403
- `app/Models/User.php` — `$isSuperadmin` property added
- `database/factories/UserFactory.php` — `->superadmin()` factory state added
- `bootstrap/app.php` — health routes registered without auth middleware

### Test Stabilization

After fixing the two systemic defects, 13 unit tests required isolation updates (they were passing only because jobs were silently dropped):

- `KnowledgeServiceTest` — 3 tests: added `Event::fake([DigitalTwinActivated::class])` to prevent cascade beyond knowledge phase
- `ProcessObservationTest` — `Event::fake([DigitalTwinActivated::class])` added in `setUp()`; `test_fires_observation_processed_event` updated to fake both `ObservationProcessed` and `DigitalTwinActivated`
- `DecisionEngineTest` — 2 tests: added `Event::fake([DecisionCommitted::class])` to prevent campaign cascade
- `CampaignPipelineTest::test_campaign_assets_ready_event_dispatches_create_recommendation_job` — fixed stale assertion (`assertNotDispatched(CampaignAssetsReady)` → `Bus::assertDispatched(CreateRecommendation)`)
- `ApprovalServiceTest::test_approve_transitions_campaign_to_approved` — added `Event::fake([RecommendationApproved::class])` to prevent publishing cascade that set campaign status to `cancelled`
- `PublishCampaignJobTest::test_executions_are_dispatched_on_high_queue` — changed `$job->queue()` to `$job->queue` (method removed, property remains)

### Final State

| Metric | Value |
|--------|-------|
| Tests | 519 (517 passing, 2 Redis skipped) |
| PHPStan | Level 8 — 0 errors |
| Pint | Clean |

---

## [Version 0.1 Architecture Audit Plan] — 2026-06-26

### Added

- `docs/plans/Version-0.1-Architecture-Audit.md` — comprehensive pre-customer-dashboard readiness review covering 15 audit areas:
  1. Domain model consistency (spec/code drift, column name mismatches)
  2. Event chain integrity (missing events, orphaned listeners, SynthesizeKnowledge ambiguity)
  3. Queue topology and async reliability (ApplyLearnings queue mismatch, ShouldBeUnique coverage)
  4. Multi-tenancy safety (withoutGlobalScopes audit, Filament company scoping, PostgreSQL RLS)
  5. AI/provider abstraction (AnthropicProvider missing, prompt injection, structured output coverage)
  6. Publishing abstraction (circuit breaker, credential encryption, no real social publishers)
  7. Analytics and learning loop (BusinessBrain learning Knowledge inclusion, signal idempotency)
  8. Rollback and auditability (delete-free rollback verification, applied_at reset atomicity)
  9. Filament/admin exposure risks (superadmin gate, read-only models, credential exposure)
  10. Test coverage gaps (no E2E pipeline test, webhook adversarial tests, rollback-then-reapply)
  11. Production readiness gaps (AnthropicProvider, email provider, health check endpoint, CI)
  12. Security/privacy risks (SSRF on WebPageCrawler, prompt injection, webhook rate limiting)
  13. Performance risks (BusinessBrainService no caching, PHP-side EvidenceEvaluator filtering)
  14. Documentation cleanup (STATUS.md stale sections, spec/code column drift, duplicate roadmap entries)
  15. Recommended refactors (12 items; 5 blocking for production, 5 blocking for customer dashboard)

### Audit Findings

| Priority | Finding |
|----------|---------|
| 🔴 Critical | `AnthropicProvider` not implemented — Atlas cannot run AI pipeline in production |
| 🔴 Critical | No Filament superadmin gate — all company data exposed to any registered user |
| 🔴 Critical | SSRF risk on `WebPageCrawler` — user-supplied URLs not validated against public IP |
| 🟠 High | No health check endpoint — required before any hosting is provisioned |
| 🟠 High | `ApplyLearnings` on `ai` queue instead of `maintenance` (Architecture.md spec) |
| 🟠 High | `Learning.value` vs `Learning.payload` spec/code drift |
| 🟡 Medium | `BusinessBrainService` assembles without Redis caching (spec requires 5-min TTL) |
| 🟡 Medium | `EvidenceEvaluator` PHP-side filtering will degrade at production Learning volumes |
| 🟡 Medium | No end-to-end pipeline smoke test — cross-milestone regressions undetected |

---

## [Milestone 9 — Learning Engine Implementation] — 2026-06-26

### Added

**Migrations**

- `2026_06_26_002900_add_learning_type_to_knowledge_entries_table` — adds `'learning'` to the `knowledge_entries.type` enum via driver-aware migration (PostgreSQL CHECK constraint rebuild; SQLite no-op via updated original migration)
- `2026_06_26_003000_create_learning_applications_table` — `learning_applications`: stores applied effects, rollback state, and audit trail per applied Learning record
- `2026_06_26_003100_create_company_scoring_weights_table` — `company_scoring_weights`: versioned, append-only scoring weight rows per company; `is_current` flag for point-in-time lookups

**Models**

- `app/Models/LearningApplication.php` — append-only audit record of an applied Learning; stores `effects` JSON array; `rolled_back_at` + `rollback_reason` for compensating rollback
- `app/Models/CompanyScoringWeights.php` — versioned per-company scoring weight rows; `typeModifiers()` helper; `defaultWeights()` factory; `scopeCurrent()` for active row lookup

**Services — Learning Engine**

- `app/Services/Learning/SignalTier.php` — classifies signals into Tier 1 (safety, threshold 1), Tier 2 (performance, threshold 2), Tier 3 (preference, threshold 3); `prioritise()` sorts batches for processing order
- `app/Services/Learning/EvidenceEvaluator.php` — counts corroborating Learning records within the 90-day rolling window; per-signal discriminator extraction (channel, campaign_type, channel_type)
- `app/Services/Learning/ConflictResolver.php` — 4-rule ordered conflict resolution across opposing signal pairs (channel_outperformed ↔ channel_underperformed; campaign_type_succeeded ↔ campaign_type_underperformed); returns apply/consume/skip decisions
- `app/Services/Learning/FactMutator.php` — supersedes existing Facts and creates new ones from Learning signals; stores `previous_fact_id` in effect descriptor for rollback; covers 5 signal types
- `app/Services/Learning/KnowledgeMutator.php` — creates `type='learning'` Knowledge entries (90-day TTL) from Learning signals; supersedes existing entries; covers all 11 signal types
- `app/Services/Learning/WeightCalibrator.php` — adjusts `type_modifiers` ±0.05 per campaign performance signal; bounds [0.50, 1.50]; 14-day cooling period; versioned `CompanyScoringWeights` rows
- `app/Services/Learning/EditPatternDetector.php` — heuristic detection of content edit patterns (length, hashtag, price) from `recommendation_edited_and_approved` payloads
- `app/Services/Learning/LearningRollbackService.php` — compensating-record rollback for Fact, Knowledge, and Weight effects; never deletes rows; resets `Learning.applied_at = null`; throws on double-rollback
- `app/Services/Learning/LearningEngine.php` — orchestrates the full apply cycle: prioritisation → conflict resolution → evidence check → effect application → LearningApplication creation; fully idempotent

**Jobs and Scheduling**

- `app/Jobs/ApplyLearnings.php` — `ShouldQueue`, `ShouldBeUnique` (24-hour uniqueness), 3 retries; iterates all companies; dispatches on `ai` queue
- `routes/console.php` — `ApplyLearnings` scheduled daily at 02:00

**Providers**

- `app/Providers/LearningServiceProvider.php` — registers all Learning services as singletons
- `bootstrap/providers.php` — registers `LearningServiceProvider`

**Tests (84 new tests, 449 total)**

- `tests/Feature/Learning/LearningTestCase.php` — base class with `makeLearning()` helper; raw-DB timestamp override for time-sensitive tests
- `tests/Feature/Learning/SignalTierTest.php` — 7 tests
- `tests/Feature/Learning/EvidenceEvaluatorTest.php` — 8 tests
- `tests/Feature/Learning/ConflictResolverTest.php` — 6 tests
- `tests/Feature/Learning/FactMutatorTest.php` — 7 tests
- `tests/Feature/Learning/KnowledgeMutatorTest.php` — 8 tests
- `tests/Feature/Learning/WeightCalibratorTest.php` — 8 tests
- `tests/Feature/Learning/LearningRollbackServiceTest.php` — 5 tests
- `tests/Feature/Learning/LearningEngineTest.php` — 10 tests
- `tests/Feature/Learning/EditPatternDetectorTest.php` — 8 tests
- `tests/Feature/Learning/ApprovalServiceLearningTest.php` — 7 tests
- `tests/Unit/Opportunity/OpportunityScorerWeightTest.php` — 7 tests

### Modified

- `app/Services/Opportunity/OpportunityScorer.php` — `score()` now accepts optional `?array $typeModifiers`; applies type-specific composite score multiplier; existing callers unaffected (optional parameter)
- `app/Services/Opportunity/OpportunityEngine.php` — loads current `CompanyScoringWeights` for the company and passes `typeModifiers` to `OpportunityScorer::score()`
- `app/Services/Recommendation/ApprovalService.php` — added `editAndApprove()` method; all three approval actions (`approve`, `reject`, `editAndApprove`) now create `Learning` records with the appropriate signal; `EditPatternDetector` wired for edit pattern extraction; duplicate-safe via `source_id + signal` existence check
- `app/Filament/Resources/CompanyResource.php` — added Learning Log and Applied Effects sections to the company infolist; shows applied count, rolled-back count, last applied timestamp, and a 10-record expandable effects list
- `database/migrations/2026_06_26_001100_create_knowledge_entries_table.php` — added `'learning'` to the `type` enum (dev migration; no data loss)

### Safety Invariants — All Honored

- Learning records immutable: `applied_at` set once per normal flow; rollback is an explicit compensating action
- Applying learning creates new state: Fact supersession and Knowledge supersession are always append-only
- All applied learnings explainable: `LearningApplication.effects` contains human-readable descriptors
- Learning never reduces confidence without evidence: downward adjustments require 2+ Tier 2 signals
- Learning is always company-scoped: `EvidenceEvaluator`, `ConflictResolver`, and `LearningEngine` all filter by `company_id`

---

## [Milestone 9 Plan — Learning Engine Implementation Plan] — 2026-06-26

### Added

- `docs/plans/Milestone-9-Implementation.md` — engineering implementation plan for the Learning Engine (Phase 8 of the roadmap). Breaks implementation into 10 ordered phases:

  **Phase 1 — Migrations, Models, Prerequisite Fixes:** `learning_applications` and `company_scoring_weights` tables; `LearningApplication` and `CompanyScoringWeights` Eloquent models; conditional migrations for `facts.superseded_by_id` and `knowledge_entries.type` if absent; `ApprovalService` audit and wire-up for `recommendation_approved`, `recommendation_rejected`, and `recommendation_edited_and_approved` Learning signals.

  **Phase 2 — LearningEngine Skeleton + ApplyLearnings Job:** `LearningServiceProvider`; `LearningEngine` service (skeleton with injected dependencies); `ApplyLearnings` job (`ShouldQueue`, `ShouldBeUnique`, 3 retries with 60/300/900s backoff); `routes/console.php` daily 02:00 schedule dispatching one job per active-twin company.

  **Phase 3 — Evidence Threshold Evaluation:** `SignalTier` class (signal → tier mapping, threshold lookup); `EvidenceEvaluator` service (90-day rolling window evidence count by `(company_id, signal, discriminator)`; upward-bias asymmetric thresholds: Tier 1 = 1, `campaign_type_succeeded` = 1, performance signals = 2, preference signals = 3–4).

  **Phase 4 — Conflict Resolution:** `ConflictResolver` service; 4-rule ordered resolution: safety override → recency when count within 1 → majority when diff ≥ 2 → no-action tie; all resolutions logged at Info on dedicated `learning` log channel.

  **Phase 5 — Fact and Knowledge Mutation:** `FactMutator` service (new Fact row, old `is_current = false`, `superseded_by_id` set; effect descriptor with `previous_entity_id`); `KnowledgeMutator` service (Knowledge `type = 'learning'`, 90-day expiry, old `is_active = false`); complete signal → key/body mapping table for all 11 signal types; Tier 1 Filament notification.

  **Phase 6 — CompanyScoringWeights Versioning + OpportunityScorer Integration:** `WeightCalibrator` service (type_modifier ±0.05 per `campaign_type_succeeded/underperformed`; floor 0.50, ceiling 1.50; base weights renormalized to 1.00 with floor 0.05 / ceiling 0.60; 14-day cooling period via `LearningApplication.applied_at` lookup; versioned row creation in DB transaction); `OpportunityScorer` updated to call `weightsFor(companyId)` and apply company-specific weights with `defaultWeights()` fallback.

  **Phase 7 — LearningRollbackService:** `LearningRollbackService::rollback(LearningApplication, reason)` — iterates effect descriptors; creates compensating records for Fact, Knowledge, and Weight effects; sets `rolled_back_at` and `rollback_reason` on `LearningApplication`; resets `Learning.applied_at = null`; all in single DB transaction; double-rollback throws; Tier 1 rollback logged at Warning.

  **Phase 8 — Prompt Context + BusinessBrain Integration:** `EditPatternDetector` service (heuristic pattern detection for hashtag removal, length reduction, price inclusion from `recommendation_edited_and_approved` signals — all keyword-based, no ML); `BusinessBrainService::for()` update to include `type = 'learning'` Knowledge entries; `LearningEngine` Tier 3 wiring to call `EditPatternDetector` and pass detected preferences to `KnowledgeMutator`; prompt version tracking computes approval rates by `prompt_version` and writes `prompt_underperformed` Knowledge for engineering visibility.

  **Phase 9 — Filament Visibility:** Three new tabs on `CompanyResource` ViewCompany page: Learning Log (all `Learning` records grouped by tier, applied/pending badge), Applied Effects (all `LearningApplication` records with expanded `effects`, rollback action modal for admin), BusinessBrain Mutations (current vs. default weights comparison, weight history, Learning-derived Knowledge entries, pending signal counts).

  **Phase 10 — Tests:** 10 test files (~57 tests total) covering all 47 acceptance criteria from `specs/core/learning-engine.md` §13; `LearningTestCase` base class with `makeApprovalLearning()` and `makeMetricLearning()` helpers; PHPStan level 8 — 0 errors; Pint clean; target ≥ 420 total tests.

- **Prerequisite verification checklist** — four items to check before writing any engine code: `facts.superseded_by_id`, `knowledge_entries.type`, `ApprovalService` signal wiring, `Learning::UPDATED_AT = null`.

- **Risk table** — 7 risks with likelihood, impact, and mitigation: `superseded_by_id` absent; approval signal payload mismatch; `CompanyScoringWeights.is_current` race condition; renormalization float drift; `EditPatternDetector` false positives; BusinessBrain Knowledge query excluding `type = 'learning'`; `OpportunityScorer` signature change breaking callers.

- **Milestone exit criteria** — checklist: ≥ 420 tests passing, 0 failing, PHPStan clean, Pint clean, migrations run, schedule dispatches, `LearningApplication` created after job run, `OpportunityScorer` returns different scores with and without company weights, docs updated, CI passes.

### Explicit Out-of-Scope for M9

- Cross-company pattern aggregation
- ML-trained scoring models
- Real-time (sub-batch) learning
- User-facing "Teach Atlas" UI
- Auto-publishing based on learnings
- Prompt template mutation at runtime
- Deleting historical records

---

## [Milestone 8.5 — Learning Engine Specification] — 2026-06-26

### Added

**Specification**

- `specs/core/learning-engine.md` — Full Phase 8 Learning Engine implementation blueprint. 14 sections:

  1. **Learning Domain Model** — reviews existing `Learning` table; introduces `LearningApplication` (tracks applied effects + rollback; stores `effects` JSON descriptor per change); introduces `CompanyScoringWeights` (versioned per-company scoring adjustments; `is_current` flag; append-only). Defines all 11 signal types with payload schemas, evidence thresholds, and what each adjusts in the BusinessBrain.

  2. **Learning Lifecycle** — three states: `[applied_at = null]` → `[applied_at = timestamp]` → `[rolled_back_at = timestamp]`. Learning records are immutable. `applied_at` is set once. Rollback creates compensating records, never mutates history.

  3. **ApplyLearnings Job** — `ShouldBeUnique` per company; scheduled daily at 02:00 UTC; delegates to `LearningEngine` service; idempotent (reads only `applied_at IS NULL`; unique constraint on `(company_id, learning_id)` prevents double-application at DB level); 3-retry failure handling with exponential backoff.

  4. **Learning Prioritization** — three tiers: Tier 1 (safety: `email_deliverability_issue`, `high_unsubscribe_rate` — applied immediately, threshold = 1); Tier 2 (performance: `channel_outperformed/underperformed`, `campaign_type_succeeded/underperformed`, `recommendation_rejected` — threshold = 2); Tier 3 (preference: `recommendation_edited_and_approved`, `content_angle_engaged`, `optimal_timing_signal` — threshold = 3–4). Evidence counted via 90-day rolling window per `(company_id, signal, discriminator)`.

  5. **Conflict Resolution** — four ordered rules: (1) safety overrides everything; (2) recency wins when evidence counts within 1; (3) majority wins when counts differ by 2+; (4) no-action tie. All resolutions logged at Info level.

  6. **Confidence Recalibration** — upward bias rule: 1 positive signal can increase; 2+ negative signals required to decrease. Hard bounds per application: ±5% per weight component; ±20% total deviation from defaults; floor 0.05; ceiling 0.60; sum always 1.00. `type_modifiers` (0.50–1.50). 14-day cooling period per signal category.

  7. **BusinessBrain Mutation Rules** — what can change: Facts (new row, old `is_current = false`, `superseded_by_id` set); Knowledge (new row `type = 'learning'`, 90-day expiry, old `is_active = false`); CompanyScoringWeights (new version row, old `is_current = false`). What cannot change: Learning records, Approval/Rejection records, KPI snapshots, executions, other companies' data. Fact namespaces owned by LearningEngine: `channel_performance.*`, `campaign_type.*`, `content_preferences.*`, `audience.*`, `timing.*`. `OpportunityScorer` integration pattern documented.

  8. **Prompt Adaptation Strategy** — learning never modifies prompt templates; enriches BusinessBrain context instead. Edit-pattern detection for content preferences (length, hashtag use, price inclusion, CTA style) after 3+ edits with detectable pattern. Knowledge entries with `type = 'learning'` surfaced in `ContentGenerationAnalyst` context. `prompt_performance` signal type (Phase 8 only) for engineering visibility.

  9. **Safety Constraints** — explicit company scoping (`withoutGlobalScopes()` + `company_id` filter on every query); hard limits table (weight floor, ceiling, sum, modifier range, max shift, cooling period, evidence window); no-auto-publish constraint; Tier 1 notification requirements; immutability guards (`UPDATED_AT = null`, `applied_at` set once).

  10. **Explainability** — `LearningApplication.effects` JSON schema (5 effect types: `fact_created`, `knowledge_created`, `knowledge_updated`, `weight_version_created`, `preference_updated`); each descriptor includes `type`, `entity_type`, `entity_id`, `key`, `previous_entity_id`, `description`. Filament admin views: Learning Log, Applied Effects, BusinessBrain Mutations. Decision rationale traceability via Knowledge context.

  11. **Rollback Strategy** — admin-initiated only. For each effect: Fact — old row restored to `is_current = true`; Knowledge — old row restored to `is_active = true`; Weight — previous version restored to `is_current = true`. `LearningApplication.rolled_back_at` and `rollback_reason` set. `Learning.applied_at` reset to null for re-evaluation. Nothing deleted.

  12. **Versioning** — `CompanyScoringWeights` monotonically versioned per company (version 0 = implicit global defaults); BusinessBrain assembled on demand from current rows (no stale cache); prompt version linkage via `Campaign.prompt_version`; full audit trail SQL documented.

  13. **Acceptance Criteria** — 47 verifiable criteria organized by category: application idempotency, evidence thresholds, conflict resolution, weight calibration, cooling period, BusinessBrain mutation, company scoping, rollback, explainability, and prompt adaptation. No live API or provider calls required in any test.

  14. **Future Extensibility** — cross-company aggregate learning (separate `AggregateSignal` table; consent-gated); ML-trained scoring (existing schema compatible); preference cascade to campaign brief (prompt engineering, no structural changes); user-initiated overrides (`source_type = 'user_override'`, bypasses evidence threshold); real-time Tier 1 path (new event + high-priority queue; same mutation rules).

**Updated documents**

- `ROADMAP.md` Phase 8 — added `specs/core/learning-engine.md` reference; expanded deliverables to match spec (`LearningApplication`, `CompanyScoringWeights`, `LearningEngine` service, evidence tiers, conflict resolution, scoring bounds, preference accumulation, rollback); added Safety Invariants section with all 5 non-negotiable constraints; updated success criteria

### Explicit Out-of-Scope for M8.5 (specification only)

- No application code written — all implementation deferred to Milestone 8
- `LearningApplication` model and migration — Phase 8
- `CompanyScoringWeights` model and migration — Phase 8
- `ApplyLearnings` job — Phase 8
- `LearningEngine` service — Phase 8
- `OpportunityScorer` weight integration — Phase 8
- Filament Learning admin views — Phase 8
- Cross-company pattern aggregation — future phase (post-Phase 8)
- ML-trained scoring models — future phase
- User-initiated learning overrides — future phase

---

## [Milestone 8 — Analytics Engine] — 2026-06-26

### Added

**Migrations**

- `database/migrations/*_create_execution_metrics_table.php` — `execution_metrics` table: ULID PK, `company_id`, `execution_id`, `campaign_id`, `channel_type`, `provider_type`, `platform_id` (indexed), `is_final`, `metrics` JSON, `raw` JSON (nullable), `retrieved_at`, `window_closes_at`, `normalised_reach`, `normalised_engagement`, `normalised_engagement_rate`; no `updated_at` (immutable per retrieval)
- `database/migrations/*_create_campaign_kpi_snapshots_table.php` — `campaign_kpi_snapshots` table: ULID PK, `company_id`, `campaign_id`, `snapshot_type` enum (interim/final), `snapshotted_at`, `channels_included` JSON, `actual_kpis` JSON, `performance_rating` enum (exceeded/met/below/insufficient_data); immutable (`UPDATED_AT = null`)
- `database/migrations/*_create_metric_retrieval_logs_table.php` — `metric_retrieval_logs` table: append-only audit; `status` enum (success/failed/skipped), `error`, `provider_type`, `attempted_at`; immutable
- `database/migrations/*_create_learnings_table.php` — `learnings` table: ULID PK, `company_id`, `signal` (string), `source_type`, `source_id`, `payload` JSON, `applied_at` (nullable); unique `(company_id, source_id, signal)`

**Domain Models**

- `app/Models/ExecutionMetric.php` — `BelongsToCompany`, `HasUlids`; `metrics`/`raw` JSON casts; `UPDATED_AT = null`
- `app/Models/CampaignKpiSnapshot.php` — `BelongsToCompany`, `HasUlids`; `UPDATED_AT = null`; `kpiSnapshots()` HasMany on Campaign
- `app/Models/MetricRetrievalLog.php` — `HasUlids`; `UPDATED_AT = null`; append-only
- `app/Models/Learning.php` — `BelongsToCompany`, `HasUlids`; `payload` JSON cast; `applied_at` datetime cast
- `app/Models/Execution.php` — updated: `metrics()` HasMany added
- `app/Models/Campaign.php` — updated: `kpiSnapshots()` HasMany added

**Provider Infrastructure**

- `app/Services/Analytics/Contracts/AnalyticsProvider.php` — interface: `pull(platformId, ChannelCredentials): array`, `normalize(array): array`, `isWindowClosed(Execution): bool`, `pollingDelayHours(): int`, `repollingIntervalHours(): int`, `supports(string): bool`
- `app/Services/Analytics/AnalyticsProviderRegistry.php` — first-match registry; `register()`, `for()`, `all()`; throws `UnknownAnalyticsProviderException`
- `app/Services/Analytics/FakeAnalyticsProvider.php` — queue/assert test double; `queueMetrics()`, `queueFailure()`, `setWindowClosed()`, `assertPulled()`, `assertNotPulled()`; `supports()` returns `true` for all types
- `app/Services/Analytics/LogAnalyticsProvider.php` — no-op provider; `normalize()` returns `[]`; `isWindowClosed()` always `true`; supports `'log'`
- `app/Domain/Analytics/ValueObjects/WebhookEvent.php` — readonly VO: `providerType`, `platformMessageId`, `eventType`, `occurredAt`
- `app/Services/Analytics/Contracts/AnalyticsWebhookHandler.php` — interface: `verify(Request)`, `parse(Request): array`, `supports(string): bool`
- `app/Services/Analytics/WebhookHandlerRegistry.php` — first-match registry for webhook handlers

**Service Provider**

- `app/Providers/AnalyticsServiceProvider.php` — singletons for `AnalyticsProviderRegistry` and `WebhookHandlerRegistry`; boots `LogAnalyticsProvider` and `PostmarkWebhookHandler`
- `backend/bootstrap/providers.php` — `AnalyticsServiceProvider` registered before `ConnectorServiceProvider`
- `backend/bootstrap/app.php` — `api: __DIR__.'/../routes/api.php'` added to `withRouting()`

**Retrieval Jobs**

- `app/Listeners/ScheduleMetricRetrieval.php` — handles `ExecutionCompleted`; checks `platform_id`; resolves credentials + provider; dispatches `RetrieveExecutionMetrics` with optional delay
- `app/Jobs/RetrieveExecutionMetrics.php` — `observations` queue; polls provider via `pull()`/`normalize()`/`isWindowClosed()`; `updateOrCreate` ExecutionMetric; appends `MetricRetrievalLog`; calls `snapshotIfReady()` on window close; self-reschedules if window open; logs failure and re-throws on error
- `app/Jobs/PruneRawMetrics.php` — `maintenance` queue; monthly; nulls `raw` on ExecutionMetrics older than 1 year

**Webhook Infrastructure**

- `app/Services/Analytics/Webhooks/PostmarkWebhookHandler.php` — HMAC-SHA256 verification; maps RecordType → `open`/`click`/`bounce`/`delivery`/`spam_complaint`; `supports('postmark')`
- `app/Jobs/ProcessAnalyticsWebhookEvent.php` — `observations` queue; looks up ExecutionMetric by `platform_id`; increments `webhook_{eventType}s` counter; silent no-op if not found
- `app/Http/Controllers/Api/AnalyticsWebhookController.php` — 422 for unknown provider; 401 for invalid HMAC; 200 `{'accepted': N}` on success
- `backend/routes/api.php` — `POST /api/analytics/webhooks/{provider}` → `AnalyticsWebhookController@receive`

**KPI Services**

- `app/Services/Analytics/CampaignKpiService.php` — `aggregate()`: sums reach/engagement, builds `channel_breakdown`, computes rates; `snapshotIfReady()`: creates interim or final snapshot, idempotent, calls `LearningService::recordFromMetrics()` on final; `ratePerformance()`: ≥125% → exceeded, 75–125% → met, <75% → below, no data → insufficient_data; `bestChannel()`: returns channel type with highest engagement_rate
- `app/Services/Analytics/RecommendationKpiService.php` — approval/rejection/edit rates; median time-to-decision (driver-aware: `EXTRACT(EPOCH FROM ...)` on PostgreSQL, `julianday()` on SQLite); breakdowns by opportunity type and channel; 30-day approval rate trend
- `app/Services/Analytics/DecisionEffectivenessService.php` — accuracy rate (exceeded + met / total); breakdowns by detector and campaign type; avg composite score for exceeded vs. below bands

**Learning Service**

- `app/Services/Learning/LearningService.php` — `recordFromMetrics(Campaign, CampaignKpiSnapshot)`: 8 signal types — `channel_outperformed` (best ≥1.5× second-best), `channel_underperformed` (<50% of campaign avg), `campaign_type_succeeded` (exceeded), `campaign_type_underperformed` (2+ consecutive final below for same type), `email_deliverability_issue` (hard bounces or spam rate >0.1%), `high_unsubscribe_rate` (>1% of delivered), `content_angle_engaged` (exceeded + blueprint angle), `optimal_timing_signal` (email open rate top quartile, ≥4 prior records required); idempotency via `createIfAbsent(source_id + signal)`; all records have `applied_at = null`

**Filament Updates**

- `app/Filament/Resources/CampaignResource.php` — `infolist()` with Performance section: `performance_rating` badge, `snapshot_type`, `snapshotted_at`, `total_reach`, `total_engagement`, `best_channel`
- `app/Filament/Resources/ExecutionResource.php` — `infolist()` with Metrics section: `channel_type`, `provider_type`, `retrieved_at`, `window_closes_at`, `is_final`, normalised reach/engagement/rate
- `app/Filament/Resources/CompanyResource.php` — `infolist()` with Recommendation Analytics section: approval rate, rejection rate, edit rate, median time-to-decision
- `app/Filament/Resources/CompanyResource/Pages/ViewCompany.php` — created (extends ViewRecord)

**App Service Provider**

- `app/Providers/AppServiceProvider.php` — `ExecutionCompleted → ScheduleMetricRetrieval` event wiring; `FakeAnalyticsProvider` singleton binding in testing via `afterResolving(AnalyticsProviderRegistry::class, ...)` (fires before `LogAnalyticsProvider` — first-match wins in tests)

**Console**

- `routes/console.php` — `PruneRawMetrics` scheduled monthly

**Tests** (97 new, 365 total)

- `AnalyticsTestCase.php` — shared base class with `makeOpportunity()`, `makeExecution()` (with ContentAsset), `makeCredentials()` helpers; eliminates NOT NULL constraint failures across all analytics tests
- `ExecutionMetricTest.php` — 6 tests: create, scopes, normalised keys, immutability, raw nullability
- `CampaignKpiSnapshotTest.php` — 5 tests: create, types, performance ratings, immutability
- `MetricRetrievalLogTest.php` — 4 tests: create, status values, immutability, failure logging
- `AnalyticsProviderRegistryTest.php` — 5 tests: register, resolve, first-match, all(), unknown throws
- `FakeAnalyticsProviderTest.php` — 10 tests: queueMetrics, queueFailure, assertPulled, assertNotPulled, supports all, isWindowClosed default, setWindowClosed, normalize passthrough, pollingDelay zero
- `LogAnalyticsProviderTest.php` — 6 tests: pull empty, normalize empty, isWindowClosed always true, supports log only, delay zero, repolling zero
- `ScheduleMetricRetrievalTest.php` — 3 tests: dispatches with platform_id, skips null platform_id, skips empty result
- `RetrieveExecutionMetricsTest.php` — 6 tests: creates metric, logs success, re-dispatches when open, no duplicate metric, logs failure, skips non-completed
- `PruneRawMetricsTest.php` — 3 tests: nulls old raw, preserves metrics column, skips recent records
- `PostmarkWebhookHandlerTest.php` — covers HMAC verify, parse open/bounce/click, supports postmark
- `AnalyticsWebhookControllerTest.php` — covers 422 unknown provider, 401 invalid HMAC, 200 accepted
- `ProcessAnalyticsWebhookEventTest.php` — 5 tests: merges open, increments counter, tracks types independently, no-op on unknown, preserves is_final
- `CampaignKpiServiceTest.php` — 10 tests: aggregate sums, engagement rate, best channel, snapshot types, idempotency, ratePerformance all four bands
- `RecommendationKpiServiceTest.php` — 5 tests: zero baseline, approval rate, edit rate, total count, trend delta
- `DecisionEffectivenessServiceTest.php` — 4 tests: empty baseline, all-exceeded, all-below, mixed, accuracy by type
- `LearningServiceMetricsTest.php` — 10 tests: channel_outperformed (15×), one-channel skip, campaign_type_succeeded, deliverability issue (bounces), deliverability issue (spam), high_unsubscribe_rate, content_angle_engaged, no angle when not exceeded, idempotency, all null applied_at

### Changed

- `app/Models/ChannelCredentials.php` — added PHPDoc `@property` annotations for `provider_type`, `channel_type`, etc. to resolve PHPStan `string|null` inference
- `app/Services/Analytics/RecommendationKpiService.php` — median time-to-decision SQL is now driver-aware (PostgreSQL `EXTRACT(EPOCH FROM ...)` vs. SQLite `julianday()`); wrapped in try-catch returning `null` on failure
- `app/Services/Analytics/DecisionEffectivenessService.php` — `avg()` result extracted to intermediate variable before `round()` to resolve PHPStan nullable argument error

### Not Implemented in M8 (explicit exclusions)

- `ApplyLearnings` — Learning records are written but not applied; applying learnings is Milestone 9+ scope
- Scoring weight recalibration — `confidence_score` weights remain static
- Cross-company analytics — all queries are company-scoped
- Real social/SMS analytics providers — only Postmark webhook handler implemented
- Paid media analytics — out of scope
- Individual subscriber/contact tracking — no PII in `metrics` column
- Customer-facing frontend — analytics are internal (Filament only)

---

## [Milestone 8 — Analytics Engine Implementation Plan] — 2026-06-26

### Added

**Planning**

- `docs/plans/Milestone-8-Implementation.md` — engineering implementation plan for the Analytics Engine (Phase 7 of roadmap); breaks work into 10 sequential phases:
  - **Phase 1 — Domain models:** `execution_metrics`, `campaign_kpi_snapshots`, `metric_retrieval_logs` migrations; `ExecutionMetric`, `CampaignKpiSnapshot`, `MetricRetrievalLog` Eloquent models with scopes, casts, and relationships
  - **Phase 2 — Provider infrastructure:** `AnalyticsProvider` interface, `AnalyticsProviderRegistry`, `FakeAnalyticsProvider` (test double, queue/assert API), `LogAnalyticsProvider` (no-op for blog/landing page), `WebhookEvent` VO, `AnalyticsServiceProvider`
  - **Phase 3 — Retrieval jobs:** `ScheduleMetricRetrieval` listener (`ExecutionCompleted` → delayed dispatch), `RetrieveExecutionMetrics` job (polls, self-reschedules until window closes, calls `snapshotIfReady`), `PruneRawMetrics` job (monthly, nulls `raw` after 1 year)
  - **Phase 4 — Webhook infrastructure:** `AnalyticsWebhookHandler` interface, `WebhookHandlerRegistry`, `AnalyticsWebhookController` (HMAC verified, `POST /api/analytics/webhooks/{provider}`), `PostmarkWebhookHandler` (Open/Click/Bounce/Delivery/SpamComplaint), `ProcessAnalyticsWebhookEvent` job (idempotent counter merging)
  - **Phase 5 — Metric normalisation:** per-provider `normalize()` rules, three cross-channel normalised keys (`normalised_reach`, `normalised_engagement`, `normalised_engagement_rate`), `isWindowClosed()` logic, division-by-zero handling
  - **Phase 6 — Campaign KPI aggregation:** `CampaignKpiService` (`aggregate`, `snapshotIfReady`, `ratePerformance`, `bestChannel`); interim → final snapshot upgrade; `expected_impact` comparison; performance rating thresholds (125%/75%)
  - **Phase 7 — Recommendation and decision KPIs:** `RecommendationKpiService` (approval/rejection/edit rates, median time-to-decision, 30-day trend), `DecisionEffectivenessService` (accuracy rate, by detector, by campaign type, score-band correlation)
  - **Phase 8 — BusinessBrain feedback:** `LearningService::recordFromMetrics()` — 8 signal types; idempotency guard; consecutive-failure detection for `campaign_type_underperformed`; `applied_at = null` on all records
  - **Phase 9 — Filament UI:** campaign performance panel (rating badge, KPI breakdown, expected vs. actual), ExecutionMetric sub-panel on execution view, company approval rate on company view
  - **Phase 10 — Tests:** 16 test files, ≥ 40 new tests, all using `FakeAnalyticsProvider`; zero real API calls
- Full scope, dependency, risk, acceptance criteria, deliverable list, and exit criteria documented

---

## [Milestone 7.5 — Analytics Engine Specification] — 2026-06-26

### Added

**Specification**

- `specs/core/analytics-engine.md` — full Phase 7 analytics implementation blueprint:
  - **Domain model:** `ExecutionMetric` table (per-execution platform metrics, raw + normalised, retrieval window tracking), `CampaignKpiSnapshot` table (campaign-level rollup, expected vs. actual, performance rating), `MetricRetrievalLog` append-only audit table
  - **Event ingestion:** dual-mode pull (scheduled polling via `RetrieveExecutionMetrics` job with per-channel delay and re-poll schedules) + push (webhook callbacks via `AnalyticsWebhookController` → `ProcessAnalyticsWebhookEvent` job)
  - **Provider webhook interface:** `AnalyticsWebhookHandler` interface, `WebhookEvent` VO, `WebhookHandlerRegistry`, HMAC verification pattern, idempotent event processing
  - **Provider abstraction:** `AnalyticsProvider` interface (`pull`, `normalize`, `isWindowClosed`, `pollingDelayHours`, `repollingIntervalHours`), `AnalyticsProviderRegistry`, `FakeAnalyticsProvider` test double, provider map by channel type
  - **Attribution model:** platform-reported only in Phase 7; no cross-channel attribution; expected vs. actual comparison producing `exceeded|met|below|insufficient_data` rating
  - **Metrics by channel:** email (14 keys), Instagram/Facebook (10 keys), LinkedIn (8 keys), X (7 keys), SMS (5 keys), blog/landing page (6 keys); three normalised cross-channel keys (`normalised_reach`, `normalised_engagement`, `normalised_engagement_rate`)
  - **Campaign KPIs:** `CampaignKpiService` — `aggregate()`, `snapshotIfReady()`, `ratePerformance()`, `bestChannel()`; full `actual_kpis` JSON shape documented
  - **Recommendation KPIs:** `RecommendationKpiService` — approval rate, rejection rate, edit rate, median time-to-decision, breakdowns by opportunity type and channel, 30-day trend
  - **Decision effectiveness metrics:** `DecisionEffectivenessService` — accuracy rate by detector, by campaign type, by composite score band; correlation between score and outcome
  - **BusinessBrain feedback loop:** finalized `CampaignKpiSnapshot` → `LearningService::recordFromMetrics()` → `Learning` records (8 signal types documented) → Phase 8 applies
  - **Learning inputs table:** 10 analytics-to-learning pathways with source, signal, and Phase 8 action
  - **Data retention:** raw provider responses pruned at 1 year; normalised metrics permanent; KPI snapshots permanent; retrieval logs 90 days
  - **Privacy:** no individual tracking, no PII in `metrics` column, Apple MPP caveat for email opens, CAN-SPAM/GDPR unsubscribe signal surfacing, data classification as Company Confidential
  - **Acceptance criteria:** 18 checkboxes covering retrieval, webhooks, KPI snapshots, learning records, provider abstraction, and privacy
  - **Future extensibility:** optimal send time, cross-channel attribution, A/B content testing, industry benchmarks, real-time streaming, prompt performance tracking

### Changed

**`ROADMAP.md`** — Phase 7 now references `specs/core/analytics-engine.md` as authoritative spec; Major Deliverables section replaced with concrete model/service/job list matching the spec

---

## [Milestone 7 — EmailPublisher] — 2026-06-26

### Added

**Email Domain — Value Objects**

- `app/Domain/Publishing/ValueObjects/EmailPayload.php` — readonly VO: `subject`, `fromName`, `fromEmail`, `body`, `previewText`; `fromPlatformPayload(PlatformPayload): self` factory; throws `MalformedPayloadException` if subject is empty

**Email Provider Layer**

- `app/Services/Publishing/Email/Contracts/EmailProvider.php` — interface: `send(EmailPayload, ChannelCredentials): string`, `ping(ChannelCredentials): PingResult`, `supports(string): bool`
- `app/Services/Publishing/Email/EmailProviderRegistry.php` — resolves `EmailProvider` by `provider_type`; first-match; `register()`, `for()`, `all()`; throws `UnknownEmailProviderException` when no provider matches
- `app/Services/Publishing/Email/Exceptions/UnknownEmailProviderException.php` — extends `PublishingException`; `retryable: false`; `userMessage()` returns "The configured email provider is not supported. Contact support."
- `app/Services/Publishing/Email/LogEmailProvider.php` — writes to `publishing` log channel; returns `'log-email-{ulid}'`; `supports('log')` only; `ping()` returns `reachable: true`
- `app/Services/Publishing/Email/FakeEmailProvider.php` — test double; `queueMessageId(string)`, `queueFailure(PublishingException)`, `assertSent(int)`, `assertNotSent()`, `sentCount()`, `sentItems()`; `supports()` returns `true` for all provider types

**Publisher + Renderer**

- `app/Services/Publishing/EmailRenderer.php` — implements `ChannelRenderer`; reads `metadata.subject_line` → fallback `asset->title` → throws `MalformedPayloadException`; packs `subject/from_name/from_email/body/preview_text` into `PlatformPayload`; `supports('email')` only
- `app/Services/Publishing/EmailPublisher.php` — implements `ChannelPublisher`; resolves `ChannelCredentials`, renders via `ChannelRendererRegistry`, converts to `EmailPayload`, picks provider from `EmailProviderRegistry`, sends; `ping()` delegates to resolved provider; `supports('email')` only

**Tests** (29 new, 268 total)

- `tests/Feature/Publishing/Email/EmailRendererTest.php` — 6 tests: renders all fields, falls back to `title` when `metadata.subject_line` absent, throws on missing subject, supports only `'email'`, rejects other channel types, empty metadata fields become empty strings
- `tests/Feature/Publishing/Email/EmailProviderRegistryTest.php` — 6 tests: resolves registered provider, resolves `LogEmailProvider` by `'log'`, throws `UnknownEmailProviderException` for unknown type, `all()` returns all registered, first-match priority wins, exception is non-retryable
- `tests/Feature/Publishing/Email/LogEmailProviderTest.php` — 6 tests: message ID starts with `'log-email-'`, unique IDs per call, writes to `publishing` log with subject in context, `ping()` returns reachable, supports `'log'`, rejects other provider types
- `tests/Feature/Publishing/Email/EmailPublisherTest.php` — 12 tests: sends via provider, passes correct subject, returns `ExecutionResult` with email metadata, uses provider message ID as `platformId`, propagates non-retryable exception, throws `CredentialsNotFoundException`, throws `AuthenticationException` for error-status credentials, supports only `'email'`, `ping()` delegates to provider, full `PublishContent` job integration, result metadata includes `provider` and `subject`

### Changed

**`app/Providers/PublisherServiceProvider.php`**

- `register()` now also binds `EmailProviderRegistry` as a singleton
- `boot()` registers `EmailRenderer` **before** `GenericRenderer` (first-match priority for email channel type) and `EmailPublisher` **before** `LogChannelPublisher` (first-match priority for email channel type); registers `LogEmailProvider` in `EmailProviderRegistry`

**`tests/Feature/Publishing/LogChannelPublisherTest.php`**

- Added `'title' => 'Test email subject line'` to `makeExecution()` asset; required because `EmailRenderer` is now registered first and intercepts `email` channel type, requiring a non-empty subject

---

## [Milestone 6.5 — Publishing Hardening] — 2026-06-26

### Added

**Renderer Layer**

- `app/Services/Publishing/ChannelRendererRegistry.php` — mirrors `ChannelPublisherRegistry`; `register()`, `for(channelType)`, `all()`; throws `UnknownChannelException` when no renderer matches
- `app/Services/Publishing/GenericRenderer.php` — implements `ChannelRenderer`; `supports()` returns `true` for all channel types; wraps `ContentAsset` body/title/media/metadata into `PlatformPayload`
- `app/Services/Publishing/FakeChannelRenderer.php` — test double; `render()` records calls; `assertRendered(int)`, `assertNotRendered()`, `renderedCount()`, `renderedItems()`

**Exceptions**

- `app/Services/Publishing/Exceptions/CredentialsExpiredException.php` — non-retryable; `userMessage()` directs user to reconnect their account

**Documentation**

- `docs/technical/Tenancy.md` — explains `CompanyScope` mechanism, required `ResolveCurrentCompany` middleware pattern, subdomain vs. route parameter strategies; marked as production-readiness requirement not yet implemented

**Tests** (28 new, 239 total)

- `tests/Feature/Publishing/RendererIntegrationTest.php` — 5 tests: proves `PublishContent → LogChannelPublisher → ChannelRenderer` chain; asserts `FakeChannelRenderer::assertRendered(1)` after job handle; asserts correct asset and channel passed; asserts renderer called once per execution; `GenericRenderer` returns payload with body; `GenericRenderer` supports all channel types
- `tests/Feature/Publishing/ChannelCredentialsRepositoryTest.php` — 9 tests: returns active credentials; throws `CredentialsNotFoundException` (not found, revoked, wrong company); throws `CredentialsExpiredException` (status=expired, expires_at in past); does not throw when expires_at is future; throws `AuthenticationException` for error status; exceptions are non-retryable
- `tests/Feature/Campaign/CampaignPreparationServiceTest.php` — 14 new tests: tone.voice missing, tone.modifier missing, tone.avoid not array, invalid landing_page URL, null/valid URL accepted, primary_metric missing, secondary_metrics not array, baseline missing, timeframe missing, channel_strategy count too low, strategy missing format/angle, constraints not array, priority not numeric

### Changed

**`LogChannelPublisher`** — now injects `ChannelRendererRegistry`; calls `$renderers->for($channel->type)->render($asset, $channel)` before logging; logs `channel_type` from `PlatformPayload` instead of raw `channel_id`

**`PublisherServiceProvider`** — `register()` now binds both `ChannelRendererRegistry` and `ChannelPublisherRegistry` as singletons; `boot()` registers `GenericRenderer` in renderer registry before registering `LogChannelPublisher`

**`ChannelCredentialsRepository::for()`** — three-stage validation: `null | revoked → CredentialsNotFoundException`; `isExpired() | status=expired → CredentialsExpiredException`; `status=error → AuthenticationException`

**`CampaignPreparationService::validateBlueprint()`** — now takes `Decision $decision` as second parameter; 8 new validation checks: `tone.voice`, `tone.modifier`, `tone.avoid`, `landing_page` URL, `success_metrics.primary_metric`, `success_metrics.secondary_metrics`, `success_metrics.baseline`, `success_metrics.timeframe`, channel_strategy count vs. decision channels, per-strategy `format`/`angle`/`constraints`/`priority` fields

**`ExecutionService::checkCampaignCompletion()`** — `CampaignPublished` event now only dispatched when `$anyCompleted` is true; cancelled campaigns update status without firing the event

**`ExecutionServiceTest` / `PublishingPipelineTest`** — updated two tests to assert `Event::assertNotDispatched(CampaignPublished::class)` on all-failed-executions path

---

## [Milestone 6 — Publishing Infrastructure] — 2026-06-26

### Added

**Migrations**

- `database/migrations/2026_06_26_002200_create_channel_credentials_table.php` — `channel_credentials` table: ULID PK, `company_id`, `channel_type`, `provider_type`, `credentials` (encrypted text), `status`, `expires_at`, `last_used_at`; `UNIQUE(company_id, channel_type)`
- `database/migrations/2026_06_26_002300_create_executions_table.php` — `executions` table: ULID PK, `company_id`, `campaign_id`, `content_asset_id` (UNIQUE — one execution per asset), `channel_id`, `status`, `scheduled_at`, `executed_at`, `completed_at`, `attempts`, `last_error`, `idempotency_key` (UNIQUE), `result` JSON
- `database/migrations/2026_06_26_002400_create_execution_attempts_table.php` — `execution_attempts` table: append-only; `attempt_number`, `attempted_at`, `status`, `error`, `response` JSON; no `updated_at`

**Domain — Value Objects**

- `app/Domain/Publishing/ValueObjects/ExecutionResult.php` — readonly: `platformId`, `url`, `publishedAt`, `metadata`; `toArray()`
- `app/Domain/Publishing/ValueObjects/PlatformPayload.php` — readonly: `channelType`, `data`
- `app/Domain/Publishing/ValueObjects/PingResult.php` — readonly: `reachable`, `error`

**Domain — Exception Hierarchy**

- `app/Services/Publishing/Exceptions/PublishingException.php` — base; `isRetryable(): bool`, `userMessage(): string`
- Retryable subclasses: `RateLimitException`, `NetworkException`, `PlatformUnavailableException`
- Non-retryable subclasses: `ContentPolicyViolationException`, `AuthenticationException`, `CredentialsNotFoundException`, `MalformedPayloadException`, `UnknownChannelException`

**Domain — Interfaces**

- `app/Services/Publishing/Contracts/ChannelPublisher.php` — `publish(Execution): ExecutionResult`, `supports(string): bool`, `ping(ChannelCredentials): PingResult`
- `app/Services/Publishing/Contracts/ChannelRenderer.php` — `render(ContentAsset, Channel): PlatformPayload`, `supports(string): bool`
- `app/Services/Publishing/Contracts/SupportsRollback.php` — `rollback(Execution): bool`; implemented only by channels that can undo a publication

**Models**

- `app/Models/ChannelCredentials.php` — `BelongsToCompany`, `HasUlids`; `credentials` cast as `encrypted`; `isExpired()`
- `app/Models/Execution.php` — `BelongsToCompany`, `HasUlids`; `campaign()`, `contentAsset()`, `channel()`, `attemptLogs()` HasMany; `isSettled()`
- `app/Models/ExecutionAttempt.php` — `HasUlids` only; `$timestamps = false`; `execution()` BelongsTo
- `app/Models/Campaign.php` — added `executions()` HasMany; campaign status enum updated to include `published`
- `app/Models/ContentAsset.php` — added `execution()` HasOne

**Services**

- `app/Services/Publishing/ChannelPublisherRegistry.php` — `register()`, `for(channelType)`, `all()`; throws `UnknownChannelException` when no publisher supports the type
- `app/Services/Publishing/ChannelCredentialsRepository.php` — `for(companyId, channelType)` throws `CredentialsNotFoundException`; `update()`
- `app/Services/Publishing/ExecutionService.php` — `queueForCampaign()`: creates Execution per approved ContentAsset, transitions assets `approved → scheduled`; `markCompleted()`: stores result, transitions asset `scheduled → published`, fires `ExecutionCompleted`, calls `checkCampaignCompletion`; `markFailed()`: idempotent guard, transitions asset `scheduled → approved`, fires `ExecutionFailed`; `logAttempt()`: appends `ExecutionAttempt`, increments counter; `checkCampaignCompletion()`: transitions Campaign to `published` (any completed) or `cancelled` (all failed), fires `CampaignPublished`
- `app/Services/Publishing/RollbackService.php` — iterates completed Executions; checks `SupportsRollback`; reports `rolled_back`, `unrollable`, `failed`

**Publishers**

- `app/Services/Publishing/FakeChannelPublisher.php` — test double; `queueResult()`, `queueFailure()`; default synthetic result when queue empty; `assertPublished()`, `assertNotPublished()`, `publishedCount()`, `publishedExecutions()`; `supports()` returns `true` for all types
- `app/Services/Publishing/LogChannelPublisher.php` — writes to `Log::channel('publishing')` with execution details + body preview (120 chars); returns synthetic `ExecutionResult(platformId: 'log-{ulid}')`; `supports()` lists all 8 channel types; `ping()` always returns `reachable: true`

**Jobs**

- `app/Jobs/PublishCampaign.php` — `high` queue; `$tries = 1`; guards `status == approved`; calls `ExecutionService::queueForCampaign()`; dispatches `PublishContent` only for `scheduled_at === null` (immediate) Executions
- `app/Jobs/PublishContent.php` — `high` queue; `$tries = 4`; `backoff() = [60, 300, 900]`; idempotency check (skips if `completed`/`cancelled`); sets `executing` before publish; non-retryable → `markFailed()` + `$this->fail($e)`; retryable → reset to `queued`, re-throw; `failed()` hook catches unhandled failures
- `app/Jobs/PublishScheduledContent.php` — `maintenance` queue; queries `status=queued AND scheduled_at IS NOT NULL AND scheduled_at <= now()`; dispatches `PublishContent` on `high` queue
- `app/Jobs/CheckChannelHealth.php` — `maintenance` queue; iterates all non-revoked `ChannelCredentials`; calls `registry->for(type)->ping(credentials)`; updates status to `active` or `error`

**Events**

- `app/Events/ExecutionCompleted.php` — carries `Execution`
- `app/Events/ExecutionFailed.php` — carries `Execution`
- `app/Events/CampaignPublished.php` — carries `Campaign`; fired on both `published` and `cancelled` campaign outcomes

**Listeners**

- `app/Listeners/TriggerCampaignPublishing.php` — handles `RecommendationApproved`; dispatches `PublishCampaign::dispatch($campaign)->onQueue('high')`

**Providers**

- `app/Providers/PublisherServiceProvider.php` — `register()`: binds `ChannelPublisherRegistry` as singleton; `boot()`: registers `LogChannelPublisher` for all 8 channel types (M6 only)
- `bootstrap/providers.php` — `PublisherServiceProvider` registered

**Infrastructure**

- `config/logging.php` — `publishing` channel: `driver: single`, `path: storage/logs/publishing.log`, `level: debug`
- `routes/console.php` — `PublishScheduledContent` scheduled every 5 minutes; `CheckChannelHealth` every 30 minutes

**Filament**

- `app/Filament/Resources/ExecutionResource.php` — read-only; columns: company.name, campaign.title, contentAsset.type, channel.type, status badge, attempts, last_error, scheduled_at, completed_at, created_at; status filter
- `app/Filament/Resources/ExecutionResource/Pages/ListExecutions.php`
- `app/Filament/Resources/ExecutionResource/Pages/ViewExecution.php`

**App Service Provider**

- `app/Providers/AppServiceProvider.php` — `RecommendationApproved → TriggerCampaignPublishing` event wiring added

**Tests** (47 new, 211 total)

- `tests/Feature/Publishing/ExecutionServiceTest.php` — 19 tests: queueForCampaign (creates executions, status transitions, scheduled_at, skips non-approved), markCompleted (status, result, asset transition, event), markFailed (status, asset revert, idempotency, event), logAttempt (record created, counter increments), checkCampaignCompletion (published/cancelled/pending/mixed outcomes)
- `tests/Feature/Publishing/PublishCampaignJobTest.php` — 6 tests: creates executions, dispatches immediate, skips scheduled, guards non-approved status, handles empty campaign, verifies high queue
- `tests/Feature/Publishing/PublishContentJobTest.php` — 8 tests: success path (status, attempt, publisher called), non-retryable failure (marks failed immediately), retryable failure (resets to queued, re-throws, logs attempt), idempotency (skips completed/cancelled)
- `tests/Feature/Publishing/PublishingPipelineTest.php` — 4 tests: `RecommendationApproved` dispatches `PublishCampaign`, full pipeline from queue to `CampaignPublished`, failed channel does not block others, all-failed settles campaign as cancelled
- `tests/Feature/Publishing/LogChannelPublisherTest.php` — 7 tests: writes to publishing channel, `platformId` starts with `log-`, result has `publishedAt`, supports all 8 channel types, does not support unknown type, ping always reachable
- `tests/Feature/Publishing/RollbackServiceTest.php` — 5 tests: LogChannelPublisher is not rollable in M6 (unrollable list), rollable publisher archives asset, failed rollback reported, only completed executions included, empty campaign returns empty lists

### Changed

- `database/migrations/2026_06_26_001600_create_campaigns_table.php` — added `published` to campaign status enum

### Not Implemented in M6 (explicit exclusions)

- `InstagramPublisher`, `FacebookPublisher`, `LinkedInPublisher`, `XPublisher` — require OAuth and platform approval
- `SmsPublisher` — requires Twilio/Vonage credentials
- `BlogPublisher`, `LandingPagePublisher` — require CMS API target
- `EmailPublisher` — **first real publisher; targeted for Milestone 7**
- Analytics retrieval (Milestone 7+)
- Learning from execution outcomes (Milestone 8)

---

## [Milestone 6 — Publishing Engine Spec] — 2026-06-26

### Added

**Specification**

- `specs/core/publishing-engine.md` — authoritative publishing engine spec for Milestone 6; 16 sections covering the full publishing architecture

### Changed

- `specs/core/publishing-engine.md` — revised Milestone 6 Implementation Scope section; clarified that M6 implements publishing **infrastructure and fake/log publishers only** — no real platform publishers

**Milestone 6 scope (what is included):**
- `Execution`, `ExecutionAttempt`, `ChannelCredentials` models and migrations
- `ExecutionService` — queue, complete, fail, completion detection
- `PublishCampaign`, `PublishContent`, `PublishScheduledContent` jobs
- `ChannelPublisher` + `ChannelRenderer` interfaces; `ChannelPublisherRegistry`
- `FakeChannelPublisher` — test double with `queueResult()`, `queueFailure()`, `assertPublished()`
- `LogChannelPublisher` — local/demo publisher; writes rendered payload to `publishing` log channel; registered for all channel types in M6; no platform API calls
- Encrypted credential storage, health check structure, circuit breaker, retry/backoff, idempotency, audit logging
- `ExecutionCompleted`, `ExecutionFailed`, `CampaignPublished` events
- Filament `ExecutionResource` — read-only execution inspection

**Not in Milestone 6 (explicit exclusions):**
- `InstagramPublisher`, `FacebookPublisher`, `LinkedInPublisher`, `XPublisher` — require OAuth and platform approval
- `SmsPublisher` — requires Twilio/Vonage credentials
- `BlogPublisher`, `LandingPagePublisher` — require CMS API target
- `EmailPublisher` — **first real publisher; targeted for the milestone immediately following M6**
- Analytics retrieval (Milestone 7)
- Learning from execution outcomes (Milestone 8)

**Architecture spec sections (unchanged from initial commit):**
  1. Publisher interface — `ChannelPublisher` with `publish()`, `supports()`, `ping()`; `ChannelPublisherRegistry`
  2. ChannelRenderer vs ChannelPublisher — renderer: content transformation, no API calls, unit-testable; publisher: API execution, credentials required
  3. Execution model — full `executions` table schema with ULID PK, status enum, idempotency key, result JSON
  4. Execution status lifecycle — `queued → executing → completed | failed | cancelled`; Campaign and ContentAsset cascade rules
  5. Scheduling — `scheduled_at = null` = immediate; `PublishScheduledContent` every 5 min; UTC storage
  6. Retry strategy — retryable vs. non-retryable exception hierarchy; 60s → 300s → 900s backoff; max 3 retries
  7. Idempotency — ULID key per Execution; pre-flight status check; platform-side key forwarding
  8. Provider abstraction — `PublisherServiceProvider` registry; sub-provider selection for email/SMS
  9. Provider credentials — `channel_credentials` table; encrypted JSON; OAuth refresh; typed repository exceptions
  10. Provider health checks — pre-dispatch ping; 30-min maintenance job; Redis circuit breaker
  11. Failure handling — `PublishingException` hierarchy; user-visible messages; `NotifyPublishingFailure` listener
  12. Audit logging — `execution_attempts` append-only table; structured `publishing` log channel
  13. Rollback behavior — `SupportsRollback` interface; social rollable, email/SMS non-rollable; user-initiated only
  14. Multi-channel orchestration — independent per-channel jobs; `checkCampaignCompletion()`; priority-ordered dispatch
  15. Acceptance criteria — all `FakeChannelPublisher`-testable; no live API in CI
  16. Future extensibility — optimal send time, webhooks, multi-wave, paid media, A/B timing, credential rotation

---

## [Milestone 5 — Campaign Engine] — 2026-06-26

### Added

**Domain**

- `app/Domain/Campaign/Exceptions/BlueprintGenerationFailedException.php` — thrown when blueprint generation fails validation
- `app/Domain/Campaign/ValueObjects/CampaignBlueprint.php` — readonly VO: 10 required Blueprint fields; `fromArray()` / `toArray()`
- `app/Domain/Content/ValueObjects/ContentAssetData.php` — readonly VO: type, body, title, media, metadata, promptName, promptVersion

**AI Prompts**

- `app/AI/Prompts/CampaignPreparationPrompt.php` — version `1.0`; temperature `0.5`; full Blueprint JSON schema
- `app/AI/Prompts/Content/SocialContentPrompt.php` — for `instagram`, `facebook`, `linkedin`, `x` channels
- `app/AI/Prompts/Content/EmailContentPrompt.php` — for `email` channel
- `app/AI/Prompts/Content/SmsContentPrompt.php` — for `sms` channel (160-char constraint)
- `app/AI/Prompts/Content/BlogContentPrompt.php` — for `blog` channel
- `app/AI/Prompts/Content/LandingPageContentPrompt.php` — for `landing_page` channel

**Analysts**

- `app/Services/Analyst/CampaignPreparationAnalyst.php` — calls AI → returns `CampaignBlueprint` VO
- `app/Services/Analyst/Content/ContentGenerationAnalyst.php` — dispatches channel-specific prompt; returns `ContentAssetData`

**Services**

- `app/Services/Campaign/CampaignPreparationService.php` — validates Blueprint (7 rules); persists Campaign in `draft`; sets `expected_asset_count`
- `app/Services/Content/ContentGenerationService.php` — creates `ContentAsset`; increments `generated_asset_count`; fires `CampaignAssetsReady` when complete
- `app/Services/Recommendation/RecommendationService.php` — builds `rationale_display` from Decision; creates Recommendation; updates Decision to `recommended`; fires `RecommendationCreated`
- `app/Services/Recommendation/ApprovalService.php` — `approve()`: transitions Recommendation/Campaign/ContentAssets; `reject()`: cancels Campaign, archives assets; fires `RecommendationApproved/Rejected`

**Jobs**

- `app/Jobs/PrepareCampaign.php` — full implementation (was stub): loads Decision + Company + BusinessBrain → `CampaignPreparationService` → dispatches `GenerateContent` per channel
- `app/Jobs/GenerateContent.php` — `ai` queue; loads Campaign + Channel; calls `ContentGenerationAnalyst` → `ContentGenerationService`
- `app/Jobs/CreateRecommendation.php` — `default` queue; calls `RecommendationService::create()`

**Events**

- `app/Events/CampaignAssetsReady.php`
- `app/Events/RecommendationCreated.php`
- `app/Events/RecommendationApproved.php`
- `app/Events/RecommendationRejected.php`

**Listeners**

- `app/Listeners/TriggerRecommendationCreation.php` — handles `CampaignAssetsReady` → dispatches `CreateRecommendation`

**Models**

- `app/Models/ContentAsset.php` — full: `HasUlids`, `BelongsToCompany`, `SoftDeletes`; all fillable fields; JSON casts; `campaign()` + `channel()` relationships
- `app/Models/Approval.php` — full: `HasUlids`, `BelongsToCompany`; `morphTo approvable`; `user()` relationship
- `app/Models/Campaign.php` — updated: blueprint fields + `contentAssets()` relationship + `allAssetsGenerated()` helper; `$casts` property form
- `app/Models/Recommendation.php` — updated: `campaign_id` added; `$casts` property form; `decision()` + `campaign()` relationships
- `app/Models/Decision.php` — updated: `$casts` property form (fixes Larastan type inference for `channel_ids`, `rationale`, `expected_impact`)
- `app/Models/User.php` — implements `FilamentUser` interface + `canAccessPanel()` for Filament admin access

**Migrations**

- `2026_06_26_001800_add_blueprint_columns_to_campaigns_table.php` — `blueprint`, `blueprint_version`, `prompt_version`, `expected_asset_count`, `generated_asset_count`
- `2026_06_26_001900_create_content_assets_table.php` — full `content_assets` table with type enum, status enum, media/metadata JSON, soft deletes
- `2026_06_26_002000_create_approvals_table.php` — `approvals` table with polymorphic `approvable`, `user_id`, `action` enum, `edits` JSON
- `2026_06_26_002100_add_campaign_id_to_recommendations_table.php` — adds `campaign_id` to `recommendations`

**Filament Admin Panel**

- `app/Filament/Resources/RecommendationResource.php` — list with status badge; Approve + Reject actions (with notes form); View page
- `app/Filament/Resources/CampaignResource.php` — list with status/asset count columns; View page
- `app/Filament/Resources/ContentAssetResource.php` — list with type/status; View page
- `app/Filament/Resources/CompanyResource.php`, `DecisionResource.php`, `OpportunityResource.php` — inspect-only views
- `app/Providers/Filament/AdminPanelProvider.php` — auto-discovers resources at `/admin`
- `backend/phpstan.neon` — `app/Filament` excluded from PHPStan scanning

**Tests**

- `tests/Feature/Campaign/CampaignPreparationServiceTest.php` — 8 tests: creates Campaign, sets expected_asset_count, sends prompt, throws on invalid goal/audience/CTA/channel_strategy, persists blueprint
- `tests/Feature/Campaign/ContentGenerationServiceTest.php` — 6 tests: creates email/social assets, increments count, fires `CampaignAssetsReady` when complete, does not fire prematurely, stores prompt metadata
- `tests/Feature/Campaign/RecommendationServiceTest.php` — 5 tests: creates pending recommendation, builds rationale_display, updates decision status, fires event, copies expected_impact
- `tests/Feature/Campaign/ApprovalServiceTest.php` — 12 tests: approve/reject transitions, status cascade, approval record, events, invalid state guards, no publishing
- `tests/Feature/Campaign/CampaignPipelineTest.php` — 4 tests: job dispatches GenerateContent, full E2E pipeline, no publishing

**AI Fixtures**

- `tests/Fixtures/AI/campaign-blueprint.json` — conversion blueprint for CBB Auctions Silver Age auction
- `tests/Fixtures/AI/social-content.json` — Instagram/social post content
- `tests/Fixtures/AI/email-content.json` — email with subject line, body, preview text

**AppServiceProvider**

- `CampaignAssetsReady → TriggerRecommendationCreation` event wiring added

---

## [Milestone 5 Specification — Campaign Blueprint] — 2026-06-26

### Added

- `specs/core/campaign-blueprint.md` — authoritative specification for the Campaign Blueprint; source of truth for Milestone 5 implementation

**Defines:**
- Campaign Blueprint as the strategic creative brief generated between a Decision and channel-specific content generation
- 10 required fields: `goal`, `audience`, `core_message`, `supporting_points`, `call_to_action`, `offer`, `tone`, `landing_page`, `success_metrics`, `channel_strategy`
- Blueprint schema with `version` and `prompt_version` fields for auditability
- Blueprint immutability rule: stored on `campaigns.blueprint`; never modified after write
- `CampaignPreparationAnalyst` contract: inputs (Decision, BusinessBrain), output (`CampaignBlueprint` VO), temperature `0.5`, failure handling
- `BlueprintGenerationFailedException` — thrown when any required key is missing; Campaign stays `draft`
- Validation rules for all 10 fields with specific character minimums and enum values
- Acceptance criteria for Milestone 5 (Blueprint generation, goal mapping, channel strategy, failure paths, versioning)
- Pipeline: Blueprint → `GenerateContent` jobs per channel → `ContentGenerationAnalyst` → `ContentAsset` records → `CampaignAssetsReady` → `RecommendationService::create()`
- `ContentGenerationPrompt` variants per channel type: `SocialContentPrompt`, `EmailContentPrompt`, `SmsContentPrompt`, `BlogContentPrompt`, `LandingPageContentPrompt`
- `ContentAsset.body` + `metadata` schema per channel type (ready for Milestone 6 rendering)
- `ChannelRenderer` interface contract (Milestone 6 implementation target)
- `expected_asset_count` / `generated_asset_count` tracking on Campaign for deterministic `CampaignAssetsReady` event
- Future extensibility: human-authored blueprints, vertical templates, A/B variants, multi-wave campaigns, per-company calibration

---

## [Milestone 4 — Opportunity & Decision Engine] — 2026-06-26

### Added

**Opportunity Domain**

- `database/migrations/2026_06_26_001200_create_catalog_items_table.php` — `catalog_items` table: ULID PK, `status` enum, `price`, `media`, `metadata`, `promoted_at`, `expires_at`, soft deletes, compound indexes
- `database/migrations/2026_06_26_001300_create_channels_table.php` — `channels` table: nullable `company_id` (null = system template), `type` enum, `is_active`
- `database/migrations/2026_06_26_001400_create_opportunities_table.php` — `opportunities` table: all four score columns, `composite_score`, `ai_detected`, polymorphic `subject`, `status` enum, `expires_at`, `detected_at`
- `database/migrations/2026_06_26_001500_create_decisions_table.php` — `decisions` table: `campaign_type` enum, `channel_ids` JSON, `rationale` JSON, `expected_impact` JSON, `prompt_version`, `decided_at`
- `database/migrations/2026_06_26_001600_create_campaigns_table.php` — `campaigns` table: `campaign_type`, `completed_at`, full status enum (used for Guard 3 cooldown)
- `database/migrations/2026_06_26_001700_create_recommendations_table.php` — `recommendations` table: `campaign_type` (used for Guard 2 duplicate check), status enum

**Models**

- `app/Models/CatalogItem.php` — full implementation: `BelongsToCompany`, `HasUlids`, `SoftDeletes`, datetime casts, `scopeActive()`, `isActive()`
- `app/Models/Channel.php` — `HasUlids` only (no `BelongsToCompany`; `company_id` is nullable for system channels)
- `app/Models/Campaign.php` — updated from stub: full fillable, `campaign_type`, `completed_at`, datetime casts
- `app/Models/Recommendation.php` — new: `BelongsToCompany`, `HasUlids`, `SoftDeletes`, `campaign_type`
- `app/Models/Opportunity.php` — new: `BelongsToCompany`, `HasUlids`, polymorphic `subject()`, `decision()`, `scopeOpen()`, `select()`, `dismiss()`
- `app/Models/Decision.php` — new: `BelongsToCompany`, `HasUlids`, `opportunity()`, `recommendation()`, `campaign()`, JSON casts for `channel_ids`, `rationale`, `expected_impact`
- `app/Models/Company.php` — added `opportunities()` and `decisions()` `HasMany` relationships

**Opportunity Engine**

- `app/Services/Opportunity/OpportunityCandidate.php` — readonly VO with all four score fields + `aiDetected` flag
- `app/Services/Opportunity/Detectors/Contracts/OpportunityDetector.php` — updated interface: `detect(Company, BusinessBrain)` → `Collection<int, OpportunityCandidate>`
- `app/Services/Opportunity/OpportunityRepository.php` — `hasDuplicate()`, `openForCompany()`, `expiredCandidates()`
- `app/Services/Opportunity/OpportunityScorer.php` — composite formula `(r×0.30 + t×0.25 + c×0.25 + u×0.20)`; minimum 30 threshold; AI confidence cap at 75
- `app/Services/Opportunity/Detectors/FeaturedItemDetector.php` — rule-based: detects un-promoted items; 14-day / 45-day cooldown by value; scores by price tier
- `app/Services/Opportunity/Detectors/UrgencyDetector.php` — rule-based: item-level expiry within 48h; falls back to `catalog.ending_within_48h_count` Fact
- `app/Services/Opportunity/Detectors/NewArrivalDetector.php` — rule-based: items created within 48h; timing score degrades with age
- `app/Services/Opportunity/Detectors/ReEngagementDetector.php` — rule-based: uses `marketing.days_since_last_campaign` Fact or `recentCampaigns`; 14-day threshold
- `app/Services/Opportunity/OpportunityEngine.php` — orchestrates all detectors → AI analyst → deduplication → scoring → persistence → `OpportunityDetected` event per candidate

**AI: Opportunity Detection**

- `app/AI/Prompts/OpportunityDetectionPrompt.php` — version `1.0`, temperature `0.3`; structured JSON schema; passes already-detected types to avoid overlap
- `app/Services/Analyst/OpportunityDetectionAnalyst.php` — implements `Analyst`; calls `OpportunityDetectionPrompt`; marks all results `aiDetected: true`; validates required fields
- `tests/Fixtures/AI/opportunity-detection.json` — fixture: one seasonal candidate

**Decision Engine**

- `app/Services/Decision/DecisionContext.php` — immutable readonly VO: `Opportunity`, `BusinessBrain`, `campaignType`, `channelIds`
- `app/Services/Decision/Exceptions/RationaleGenerationFailedException.php` — thrown when any of 5 required rationale keys is missing or empty
- `app/Services/Decision/DecisionRepository.php` — `openForCompany()`, `findByOpportunity()`
- `app/Services/Decision/DecisionEngine.php` — five guard conditions in order; deterministic score-ordered selection; channel affinity resolution; commits via `DecisionService`
- `app/Services/Decision/DecisionService.php` — calls `RationaleGenerationAnalyst`, validates all 5 rationale keys + 4 `expected_impact` sub-keys, persists `Decision`, transitions Opportunity to `selected`, fires `DecisionCommitted`
- `app/AI/Prompts/RationaleGenerationPrompt.php` — version `1.0`, temperature `0.4`; includes Opportunity, company identity, selected channels, Facts, Knowledge, subject item (if CatalogItem); structured JSON schema
- `app/Services/Analyst/RationaleGenerationAnalyst.php` — implements `Analyst`; returns raw rationale array for caller to validate
- `tests/Fixtures/AI/rationale-generation.json` — fixture: complete 5-key rationale with all `expected_impact` sub-keys

**Jobs**

- `app/Jobs/DetectOpportunities.php` — `default` queue; calls `BusinessBrainService::for()` then `OpportunityEngine::scan()`
- `app/Jobs/CommitDecision.php` — `ai` queue; `ShouldBeUnique` per company (`uniqueId()` = company ID); calls `DecisionEngine::evaluate()`
- `app/Jobs/ExpireOpportunities.php` — `maintenance` queue; bulk-expires open Opportunities past `expires_at`
- `app/Jobs/PrepareCampaign.php` — `ai` queue; Milestone 4 no-op stub; wired and dispatched; implemented in Milestone 5

**Events & Listeners**

- `app/Events/OpportunityDetected.php` — fired per persisted Opportunity from `OpportunityEngine::scan()`
- `app/Events/DecisionCommitted.php` — fired after `DecisionService` persists a Decision
- `app/Listeners/TriggerOpportunityDetection.php` — `DigitalTwinActivated` → dispatches `DetectOpportunities`
- `app/Listeners/TriggerDecisionEvaluation.php` — `OpportunityDetected` → dispatches `CommitDecision`
- `app/Listeners/DispatchCampaignPreparation.php` — `DecisionCommitted` → dispatches `PrepareCampaign`

**Infrastructure Updates**

- `app/Providers/AppServiceProvider.php` — added morph map (`catalog_item`, `catalog`, `company`); wired 3 new event/listener pairs
- `app/Services/Brain/BusinessBrainService.php` — populated `featuredItems` with active/featured `CatalogItem` records; populated `recentCampaigns` with 10 most recent `Campaign` records

**Tests** (127 passing, 2 Redis skipped)

- `tests/Unit/Opportunity/OpportunityScorerTest.php` — 5 unit tests: threshold, clamp, AI cap, weighted formula, score output shape
- `tests/Feature/Opportunity/FeaturedItemDetectorTest.php` — 6 tests: empty brain, never-promoted, in-cooldown, out-of-cooldown, high-value cooldown
- `tests/Feature/Opportunity/UrgencyDetectorTest.php` — 5 tests: no expiry, item-level 24h, item-level 36h, catalog-fact fallback, item priority over fact
- `tests/Feature/Opportunity/NewArrivalDetectorTest.php` — not enumerated here; covered by engine integration test
- `tests/Feature/Opportunity/ReEngagementDetectorTest.php` — 5 tests: no items, below threshold, above threshold from fact, campaign fallback, 999-day never-campaigned
- `tests/Feature/Opportunity/OpportunityEngineTest.php` — 4 tests: persists candidates, deduplicates by type+subject, fires `OpportunityDetected`, marks AI candidates
- `tests/Feature/Opportunity/OpportunityExpiryTest.php` — 3 tests: expires past-expiry, leaves future open, ignores null-expiry
- `tests/Feature/Opportunity/OpportunityDetectionAnalystTest.php` — 6 tests: parses fixture, marks AI detected, sends correct prompt, empty response, invalid fields filtered, scores clamped
- `tests/Feature/Decision/DecisionEngineTest.php` — 7 tests: Guard 1–5, commits on all-pass, selects highest score
- `tests/Feature/Decision/RationaleGenerationAnalystTest.php` — 2 tests: parses complete fixture, sends correct prompt
- `tests/Feature/Decision/DecisionPipelineTest.php` — 2 tests: full committed decision, rationale failure leaves opportunity open

### Updated

- `app/Models/Company.php` — added `opportunities()` and `decisions()` `HasMany` relationships
- `app/Services/Brain/BusinessBrainService.php` — `featuredItems` and `recentCampaigns` now populated from DB
- `app/Providers/AppServiceProvider.php` — morph map + new events

---

## [Milestone 4 Specification — Decision Engine] — 2026-06-25

### Added

- `specs/core/decision-engine.md` — pre-implementation design specification for the Decision Engine

**Document covers:**
- What a Decision is and what distinguishes it from an Opportunity (the full comparison table)
- Decision lifecycle from `pending` through `executed`; M4 boundary explicitly at `pending`
- Six Decision statuses with transition rules and who sets each
- Decision types (`campaign_type`) and how they map from Opportunity types
- Decision inputs: selected Opportunity, BusinessBrain, score components, guard conditions, company context
- Five guard conditions with implementation logic, query shapes, and on-failure behaviour:
  - Guard 1: minimum score (composite_score >= 30)
  - Guard 2: duplicate recommendation (no `pending`/`viewed` Recommendation of same campaign_type)
  - Guard 3: campaign cooldown (per-type windows: 3 days for urgency_promotion, 14 days for others)
  - Guard 4: catalog availability (CatalogItem must still be `active`; on failure: Opportunity dismissed)
  - Guard 5: channel availability (at least one active Channel exists)
- Selection algorithm: score-ordered, deterministic, with tie-breaking rules
- Channel selection logic and type-affinity defaults
- Five required rationale fields with good/bad examples and validation rules enforced in `DecisionService`
- `RationaleGenerationAnalyst` interface: inputs, output shape, prompt design (temperature 0.4, versioned), failure handling
- Campaign pipeline handoff (Milestone 5): full flow from `DecisionCommitted` through Recommendation
- Decision fields that drive the Campaign Engine (`campaign_type`, `channel_ids`, rationale keys, `confidence_score`)
- Complete M4 implementation list: models, services, jobs, events, listeners, exceptions
- Explicit out-of-scope list per milestone
- Acceptance criteria (all verifiable by automated tests): detection, guards, commitment, rationale, failure paths, expiry, test requirements
- Future extensibility: additional guards, per-company scoring weights (Phase 8), channel affinity learning, multiple Decisions per cycle, vertical calibration, human-initiated Decisions

### Updated

- `specs/core/opportunity-engine.md` — authority claim narrowed: DecisionEngine removed from scope (decision-engine.md is now authoritative for guard conditions and rationale); cross-reference to decision-engine.md added to header

---

## [Milestone 4 Specification — CTO Review & Scope Finalisation] — 2026-06-25

### Updated

- `specs/core/opportunity-engine.md` — CTO reviewed; implementation scope section rewritten and moved to the top of the document (immediately after the header block), replacing the earlier Section 15 draft

**Scope section now records authoritatively:**
- Required opportunity types in M4: `featured_item`, `urgency`, `new_arrival`, `re_engagement`
- Optional / spec-defined but not required in M4: `seasonal`, `milestone`
- Supporting models permitted: `CatalogItem`, `Campaign`, `Recommendation` — intentionally minimal; exist only to support detection, subject validation, evidence tracking, deduplication, cooldown checks, and duplicate recommendation guard conditions
- Hard DO NOT list: Campaign Engine behavior, campaign preparation, Marketing Assets, ContentAssets, channel renderers, any publishing integration (Facebook, Instagram, Email, SMS, LinkedIn, Google Ads, Meta Ads, Blog, Landing Pages), analytics, learning
- Goal of Milestone 4: produce a validated Decision with a complete rationale; Campaign creation begins in Milestone 5

---

## [Milestone 4 Specification] — Opportunity Engine — 2026-06-25

### Added

- `specs/core/opportunity-engine.md` — authoritative design specification for Milestone 4; supersedes any conflicting guidance in other documents for the Opportunity Engine, OpportunityDetectors, OpportunityScorer, and DecisionEngine

**Document covers:**
- What an Opportunity is and what it is not (not content, not a suggestion — a scored claim with evidence and expiry)
- Opportunity lifecycle: `open → selected → [Campaign created]`; also `dismissed` and `expired` transitions and who sets each
- Six opportunity types with trigger conditions, required evidence, scoring profiles, and vertical examples: `featured_item`, `urgency`, `new_arrival`, `re_engagement`, `seasonal`, `milestone`
- Composite scoring formula: `(relevance × 0.30) + (timing × 0.25) + (confidence × 0.25) + (urgency × 0.20)`; minimum threshold 30; component definitions with 0–100 ranges; tie-breaking rules
- Evidence chain: Facts → Knowledge → Opportunity description → Decision rationale; requirement that detectors read from `BusinessBrain` only (no direct DB queries except CatalogItem lookups)
- Expiration rules per type with rationale; `ExpireOpportunities` nightly maintenance job
- Deduplication rule: no new Opportunity persisted if an open or selected Opportunity with same `(type, subject_type, subject_id)` exists for the company; cooldown windows per campaign type enforced separately in `DecisionEngine`
- `OpportunityDetector` interface contract with `appliesTo(): string[]` and `detect(Company, BusinessBrain): Collection<int, OpportunityCandidate>`; full list of detector rules (no DB writes, no AI calls, return empty on sparse data)
- `OpportunityCandidate` readonly value object definition
- Four MVP rule-based detectors: `FeaturedItemDetector`, `UrgencyDetector`, `NewArrivalDetector`, `ReEngagementDetector`
- `OpportunityDetectionAnalyst` for AI-assisted detection: runs after rule-based pass; AI failure is non-fatal; confidence cap at 75 for AI-detected candidates
- Decision Engine selection algorithm: ordered by composite score; three guard conditions (no duplicate open recommendation, cooldown window, catalog availability); `RationaleGenerationAnalyst` generates all five rationale keys or throws `RationaleGenerationFailedException`
- How Decisions become Campaigns: field mapping from Decision to Campaign Engine (Milestone 5 scope); Milestone 4 stops at `Decision.status = "pending"` + `DecisionCommitted` event
- Full Milestone 4 acceptance criteria checklist (detection, detectors, Decision Engine, scoring, expiry, tests)
- Future extensibility: new detector pattern, new opportunity types, per-company weighted scoring (Phase 8), cross-company patterns (Phase 8), vertical-specific detectors, manual opportunity creation
- Scoring appendix: three worked examples (CBB urgency, exotic dealer featured item, dealer re-engagement) with per-component breakdown

### Updated

- `docs/STATUS.md` — current milestone section updated to reflect spec complete and implementation pending; Next Tasks rewritten with specific Milestone 4 implementation steps; Recently Completed updated

---

## [Milestone 3 Cleanup] — 2026-06-26

### Fixed

- `app/Models/Observation.php` — added `facts(): HasMany<Fact>` relationship; resolves the deferred spec compliance item from the M3 review
- `app/Services/Brain/KnowledgeService.php` — `updateTwin()` (renamed from `activateTwinIfReady()`) now updates `last_enriched_at` on every synthesis run, not only when the twin first transitions from `initializing → active`
- `tests/Feature/Brain/KnowledgeServiceTest.php` — added `test_updates_last_enriched_at_on_every_synthesis` to assert the fix

### Result

- 83 tests total; 81 passing, 2 skipped (Redis); PHPStan level 8 — 0 errors; Pint — clean

---

## [Milestone 3] — Fact Extraction & Knowledge Synthesis — 2026-06-26

### Added

**Database Migrations (`backend/database/migrations/`)**
- `2026_06_26_001000_create_facts_table.php` — `facts` table; `char(26)` ULID PK; `is_current` boolean; `superseded_by_id` self-referential; compound index `(company_id, key, is_current)`
- `2026_06_26_001100_create_knowledge_entries_table.php` — `knowledge_entries` table; `char(26)` ULID PK; type enum; `is_active` boolean; `expires_at` nullable; compound index `(company_id, type, is_active)`

**Eloquent Models (`backend/app/Models/`)**
- `Fact.php` — `BelongsToCompany`, `HasUlids`; `value` cast as `json`; `is_current` boolean cast; `current()` local scope; `observation()` and `supersededBy()` relationships
- `Knowledge.php` — `BelongsToCompany`, `HasUlids`; table `knowledge_entries`; `active()` local scope with `expires_at` handling
- `Company.php` — added `facts()` and `knowledge()` `hasMany` relationships

**AI Layer (`backend/app/AI/`)**
- `Prompts/FactExtractionPrompt.php` — extends `Prompt`; structured JSON schema; version `1.0`; temperature `0.1`; system prompt defines fact key conventions and confidence rules
- `StructuredResponseParser.php` — parses AI response to `array`; strips markdown code fences; throws `InvalidArgumentException` on non-JSON or non-array

**Analysts (`backend/app/Services/Analyst/`)**
- `WebsiteAnalyst.php` — implements `Analyst`; reads `Observation.raw_payload` as WebPageData JSON; calls `AiProvider::complete(FactExtractionPrompt)`; returns `Collection<int, FactData>`; short-circuits on empty `bodyText`

**Brain Services (`backend/app/Services/Brain/`)**
- `Data/FactData.php` — readonly VO: key, value, dataType, confidence
- `FactRepository.php` — `findCurrent(companyId, key)`, `currentForCompany(companyId)` — always `withoutGlobalScopes()`
- `KnowledgeRepository.php` — `activeForCompany(companyId)`, `findActiveForSubject(companyId, subject)`
- `FactService.php` — `storeExtracted(Observation, Collection<FactData>): Collection<Fact>`; creates new Facts; supersedes existing current fact for same key; fires `FactExtracted`
- `KnowledgeService.php` — `synthesizeForCompany(Company)`: groups current Facts by domain key; upserts Knowledge (type: `context`); fires `KnowledgeSynthesized`; activates DigitalTwin if `initializing`
- `BusinessBrainService.php` — `for(Company): BusinessBrain`; assembles from current Facts, active Knowledge, recent Observations, DigitalTwin, Catalog

**Events (`backend/app/Events/`)**
- `FactExtracted.php` — fired per Fact created by `FactService`
- `KnowledgeSynthesized.php` — fired per Knowledge entry upserted
- `ObservationProcessed.php` — fired when `ProcessObservation` marks an observation processed
- `DigitalTwinActivated.php` — fired when `KnowledgeService` transitions twin `initializing → active`

**Jobs (`backend/app/Jobs/`)**
- `ProcessObservation.php` — fully implemented (was stub); pipeline: `markProcessing → WebsiteAnalyst → FactService → KnowledgeService → markProcessed → ObservationProcessed`; `markFailed()` + re-throw on error

**Providers**
- `AppServiceProvider.php` — `register()` binds `AiProvider` to `FakeAiProvider` in `testing` environment

**Test Fixture**
- `tests/Fixtures/AI/website-facts.json` — 4-fact sample response used by analyst and pipeline tests

**Feature Tests (`backend/tests/Feature/Brain/`)**
- `WebsiteAnalystTest.php` — 3 tests: fact extraction, field mapping, empty payload short-circuit
- `FactServiceTest.php` — 4 tests: persist, supersede, observation linkage, empty input
- `KnowledgeServiceTest.php` — 6 tests: synthesis, events, twin activation, no duplicate, idempotent, empty input
- `BusinessBrainServiceTest.php` — 6 tests: company/twin, current facts, superseded excluded, active knowledge, catalog, empty M3 collections
- `ProcessObservationTest.php` — 6 tests: observation processed, facts created, knowledge created, twin activated, event fired, failure path

**Unit Tests (`backend/tests/Unit/AI/`)**
- `StructuredResponseParserTest.php` — 4 tests: plain JSON, markdown fences, code fences, invalid JSON exception
- `FactExtractionPromptTest.php` — 5 tests: system/user strings, schema structure, version, low temperature

### Result

- 82 tests total; 80 passing, 2 skipped (Redis); PHPStan level 8 — 0 errors; Pint — clean

### Spec Deviations

None. All implemented entities match `specs/core/domain-model.md` exactly.

### Technical Debt Introduced

| Item | Notes |
|------|-------|
| No production `AiProvider` implementation | Production deployment requires `AnthropicProvider` before AI jobs run |
| Knowledge synthesis is rule-based in M3 | AI-powered pattern synthesis deferred to M4+ |
| `DigitalTwin.last_enriched_at` only updated on activation | Should also update on re-synthesis |
| `Observation hasMany Fact` not added to Observation model | Deferred — not yet needed by any query path |

---

## [Milestone 2 Cleanup] — 2026-06-26

### Fixed

- `app/Services/Company/CompanyService.php` — default Catalog type corrected from `'inventory'` to `'mixed'`; `'mixed'` is the correct generic default for a newly onboarded company
- `tests/Feature/Discovery/CompanyServiceTest.php` — `test_creates_catalog_for_company` now explicitly asserts `type = 'mixed'`

### Added

- `app/Services/Observatory/IntegrationService.php` — `create(Company, string $type, array $config): Integration`; sets `name` via `defaultName()` match, `status: active`, `next_run_at: +7 days`; dispatches `SyncIntegration` immediately on creation
- `app/Jobs/SyncIntegration.php` — now implements `ShouldBeUnique`; `uniqueId()` returns `$this->integration->id` — prevents duplicate sync jobs from stacking in the queue
- `tests/Feature/Discovery/IntegrationServiceTest.php` — 5 new tests: correct attributes, encrypted config, `next_run_at` 7-day window, immediate `SyncIntegration` dispatch, default name for `website_crawl`
- `tests/Feature/Discovery/SyncPipelineTest.php` — `test_sync_integration_is_unique_per_integration` asserts job implements `ShouldBeUnique` and `uniqueId()` returns integration id

### Result

- 48 tests total; 46 passing, 2 skipped (Redis); PHPStan level 8 — 0 errors; Pint — clean

---

## [Milestone 2] — Discovery & Knowledge Platform — 2026-06-26

### Added

**Database Migrations (`backend/database/migrations/`)**
- `2026_06_26_000000_create_users_table.php` — rewrites Laravel default; `char(26)` ULID PK; sessions table `user_id` updated to `char(26)`
- `2026_06_26_000300_create_personal_access_tokens_table.php` — Sanctum migration; `char(26)` tokenable_id replacing default `bigInteger` morphs
- `2026_06_26_000400_create_companies_table.php` — `char(26)` PK, `slug` unique, `brand`/`settings` JSON, `softDeletes`
- `2026_06_26_000500_create_company_memberships_table.php` — `char(26)` PK/FKs, role enum (owner/admin/member/viewer)
- `2026_06_26_000600_create_catalogs_table.php` — `char(26)` PK, one per company, type enum (inventory/services/menu/listings/mixed)
- `2026_06_26_000700_create_digital_twins_table.php` — `char(26)` PK, status enum (initializing/active/stale/archived), health_score
- `2026_06_26_000800_create_integrations_table.php` — `char(26)` PK, type enum, encrypted config column, `last_successful_run_at`
- `2026_06_26_000900_create_observations_table.php` — `char(26)` PK, status enum (pending/processing/processed/failed), compound indexes

**Eloquent Models (`backend/app/Models/`)**
- `User.php` — `HasUlids`, `HasApiTokens`, `HasFactory<UserFactory>`; `memberships()` relationship
- `Company.php` — `HasUlids`, `SoftDeletes`, `HasFactory<CompanyFactory>`; auto-slugs from name; all relationships with generic type annotations
- `CompanyMembership.php` — `BelongsToCompany`, `HasUlids`; `user()`, `inviter()` relationships
- `Catalog.php` — `BelongsToCompany`, `HasUlids`; `item_schema` array cast
- `DigitalTwin.php` — `BelongsToCompany`, `HasUlids`; `isActive()`, `isInitializing()` helpers
- `Integration.php` — `BelongsToCompany`, `HasUlids`; `config` cast as `encrypted:array`; `markAsError()`; `last_successful_run_at`
- `Observation.php` — `BelongsToCompany`, `HasUlids`, `Prunable`; 180-day prune with payload nulling; `markProcessing/Processed/Failed()`

**Multi-Tenancy Foundation (`backend/app/Domain/Shared/`)**
- `Scopes/CompanyScope.php` — applies `WHERE company_id = ?` when `current_company_id` is bound in the container; no-op otherwise
- `Concerns/BelongsToCompany.php` — registers `CompanyScope`; provides `company()` `BelongsTo` relationship

**Connector Framework (`backend/app/Services/Observatory/Connectors/`)**
- `Contracts/Connector.php` — `supports(Integration)`, `sync(Integration): Collection<int, ConnectorResult>`
- `ConnectorResult.php` — readonly value object: `sourceType`, `sourceIdentifier`, `payload`, `observedAt`
- `ConnectorRegistry.php` — `resolve(Integration): Connector` (throws `UnsupportedIntegrationException`); `all(): array`
- `Exceptions/UnsupportedIntegrationException.php` — thrown when no connector supports an integration type
- `Website/WebPageData.php` — readonly value object for a single crawled page; `toArray()` serialises for payload
- `Website/WebPageCrawler.php` — BFS crawler; Guzzle HTTP + DOMDocument + DOMXPath; max 20 pages / depth 3; strips nav/footer/scripts; 5,000-char body text cap; single fetch per page (links extracted from same parse)
- `Website/WebsiteConnector.php` — implements `Connector`; crawls URL from `integration->config['url']`; maps `WebPageData → ConnectorResult`

**Observation Pipeline**
- `app/Services/Company/CompanyService.php` — `create(User, array): Company`; one DB transaction wraps Company + Catalog + DigitalTwin + owner CompanyMembership
- `app/Services/Observatory/ObservationService.php` — `record()` / `recordAll()`; persists `ConnectorResult` as `Observation`; dispatches `ObservationRecorded`
- `app/Events/ObservationRecorded.php` — fired after each Observation is persisted
- `app/Events/IntegrationSyncStarted.php` — fired when `SyncIntegration` begins
- `app/Events/IntegrationSyncCompleted.php` — fired when sync finishes; carries observation count
- `app/Jobs/SyncIntegration.php` — resolves connector via registry; syncs; records observations; updates timestamps; on `observations` queue; marks integration as error on failure
- `app/Jobs/ProcessObservation.php` — stub job on `ai` queue; no-op until Milestone 3 adds AI fact extraction
- `app/Listeners/DispatchObservationProcessing.php` — listens to `ObservationRecorded`; dispatches `ProcessObservation`

**Service Providers**
- `app/Providers/ConnectorServiceProvider.php` — registers `ConnectorRegistry` singleton with `WebsiteConnector`
- `app/Providers/AppServiceProvider.php` — wires `ObservationRecorded → DispatchObservationProcessing`
- `bootstrap/providers.php` — registers `ConnectorServiceProvider`

**Factories**
- `database/factories/CompanyFactory.php` — generates realistic company data for tests

**Feature Tests (`backend/tests/Feature/Discovery/`)**
- `CompanyServiceTest.php` — 5 tests: company creation, catalog, digital twin status, owner membership, atomicity
- `TenantIsolationTest.php` — 2 tests: CompanyScope filters by bound company; no-op when no company bound
- `ConnectorRegistryTest.php` — 3 tests: resolves WebsiteConnector; throws for unsupported type; registry is non-empty
- `WebsiteConnectorTest.php` — 2 tests: maps crawled pages to ConnectorResults; `supports()` correctly typed
- `SyncPipelineTest.php` — 2 tests: `SyncIntegration` dispatches to `observations` queue; `ProcessObservation` dispatches to `ai` queue

### Changed

- `backend/app/Models/Observation.php` — import order fixed by Pint
- `backend/app/Domain/Shared/Scopes/CompanyScope.php` — `@implements Scope<Model>` annotation added; FQCN fix by Pint

### Spec Deviation

- `Connector::sync()` declared as `sync(): Collection<int, ConnectorResult>` instead of spec's `sync(): Observation` — one result per crawled page/feed item, not one aggregate per sync. `ObservationService` is responsible for persisting each `ConnectorResult` as its own `Observation`.

---

## [Milestone 1 Hardening] — 2026-06-25

### Changed

- `backend/phpstan.neon` — raised from level 6 to **level 8**; passes with 0 errors; no code changes required
- `docs/STATUS.md` — stack table added (PHP 8.3+, Laravel 13.x, PHPStan level 8); technical debt section expanded with three named items; next tasks reordered to put ULID `User` PK conversion first; PHPStan level 8 decision recorded; project health notes clarified to distinguish placeholder models from implemented persistence
- `CHANGELOG.md` — this entry

### Technical Debt Recorded

| Item | Notes |
|------|-------|
| Eloquent model stubs are placeholders only | No migrations, fillable, casts, or relationships — exist for PHPStan type resolution only |
| Queue tests use `Queue::fake()` | Dispatch mechanism is proven; live Redis worker execution is not tested yet |
| `User` model uses integer PK | Must be converted to `char(26)` ULID before `company_memberships` migration |

---

## [Milestone 1] — Platform Foundation — 2026-06-25

### Added

**Laravel Application (`backend/`)**
- Laravel 13.17 project created in `backend/`
- PHP 8.3, Composer 2.x
- `backend/.env` — configured for PostgreSQL + Redis (queue, cache, session drivers)
- `backend/.env.example` — documented template for new environments
- `backend/pint.json` — Laravel preset with `simplified_null_return`, `blank_line_before_statement`, `new_with_parentheses`
- `backend/phpstan.neon` — Larastan at level 8; paths: `app/`

**Queue Topology (`backend/config/queue.php`)**
- Five named queue connections: `high`, `ai`, `default` (Redis), `observations`, `maintenance`
- `ai` queue has elevated `retry_after` (300s) to accommodate long AI calls
- Batching and failed job tables point to PostgreSQL (not SQLite)

**Domain Folder Structure**
- `app/Domain/Company/`
- `app/Domain/Catalog/`
- `app/Domain/BusinessBrain/`
- `app/Domain/Opportunity/`
- `app/Domain/Decision/`
- `app/Domain/Recommendation/`
- `app/Domain/Campaign/`
- `app/Domain/Shared/`
- `app/Application/`
- `app/Infrastructure/`
- `app/Presentation/`

**Core AI Contracts and Abstractions**
- `app/AI/Contracts/AiProvider.php` — single `complete(Prompt): AiResponse` method; the only interface external code touches
- `app/AI/AiResponse.php` — readonly value object: `content`, `model`, `inputTokens`, `outputTokens`
- `app/AI/Prompts/Prompt.php` — abstract base: `system()`, `user()`, `schema()`, `temperature()`, `maxTokens()`, `version()`, `name()`
- `app/AI/Testing/FakeAiProvider.php` — test double: `queueResponse()`, `queueFixture()`, `complete()`, `assertPromptSent()`, `assertNothingSent()`, `sentCount()`
- `tests/Fixtures/AI/` — directory for JSON fixtures consumed by `FakeAiProvider::queueFixture()`

**Domain Service Contracts**
- `app/Services/Analyst/Contracts/Analyst.php` — marker interface; only Analysts may call `AiProvider`
- `app/Services/Observatory/Connectors/Contracts/Connector.php` — `supports(Integration): bool`, `sync(Integration): Observation`
- `app/Services/Opportunity/Detectors/Contracts/OpportunityDetector.php` — `appliesTo(): string[]`, `detect(BusinessBrain): Collection`
- `app/Services/Content/Contracts/ContentGenerator.php` — `channel(): string`, `generate(Campaign): ContentAsset`

**Domain Value Objects**
- `app/Domain/BusinessBrain/BusinessBrain.php` — readonly value object assembled by `BusinessBrainService::for(Company)`; never persisted

**Eloquent Model Stubs** (structure only; no migrations, fillable, or relationships yet)
- `app/Models/Company.php` — with `SoftDeletes`
- `app/Models/DigitalTwin.php`
- `app/Models/Catalog.php`
- `app/Models/Integration.php`
- `app/Models/Observation.php`
- `app/Models/Campaign.php` — with `SoftDeletes`
- `app/Models/ContentAsset.php` — with `SoftDeletes`

**Bootstrap Tests (25 tests, all passing)**
- `tests/Feature/ApplicationBootTest.php` — Laravel boots, container resolves core bindings, environment is `testing`
- `tests/Feature/DatabaseConnectionTest.php` — DB connection established, migrations table exists, users table exists
- `tests/Feature/QueueDispatchTest.php` — jobs dispatched to queues, all five Atlas queues configured
- `tests/Feature/RedisConnectionTest.php` — Redis ping + set/get (skipped when Redis not in test env)
- `tests/Unit/AI/FakeAiProviderTest.php` — queueResponse, ordering, empty-queue exception, assertPromptSent, assertNothingSent, chaining
- `tests/Unit/AI/PromptTest.php` — defaults, version override, name, system/user return strings

**Infrastructure**
- `infrastructure/supervisor/atlas-worker.conf` — Supervisor config for all five queue workers

**CI/CD**
- `.github/workflows/ci.yml` — GitHub Actions: PostgreSQL 16 + Redis 7 services, Pint → PHPStan → PHPUnit on push/PR to `main`/`develop`

**Packages Installed**
- `laravel/sanctum` ^4.3 — API token authentication (used in Phase 2)
- `larastan/larastan` ^3.10 — PHPStan extension for Laravel

### Changed

- `app/Models/User.php` — untouched; uses default Laravel integer PK (will be migrated to ULID in Phase 2)

---

## [Milestone 0] — Specification Phase — 2026-06-25

All foundational specification documents written and committed. No application code.

**Documents created:**
- `specs/core/domain-model.md` — 18 entities with fields, relationships, lifecycle, Laravel notes
- `specs/product/mvp-workflow.md` — 13-step MVP workflow with acceptance criteria
- `docs/technical/Architecture.md` — module structure, event chain, queue topology
- `docs/technical/Database.md` — data classification, multi-tenancy, indexing, retention
- `docs/technical/AI.md` — provider abstraction, 6 MVP analysts, prompt versioning, FakeAiProvider pattern
- `docs/technical/DigitalTwin.md` — definition, purpose, competitive moat
- `docs/technical/DecisionEngine.md` — opportunity scoring formula, explainability, decision lifecycle
- `FOUNDING_PRINCIPLES.md` — 10 engineering principles with self-tests
- `ROADMAP.md` — 8-phase product roadmap with goals, deliverables, success criteria
- `docs/product/PRD.md` — product requirements document
- `docs/vision/FoundersBible.md` — founder vision, design partners, first use cases
- `README.md` — updated to reflect Atlas as autonomous marketing operating system
