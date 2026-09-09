# Scheduling Engine — Plan

**Status:** Proposed (2026-09-09). Epic: CM-112. No code yet.

Lets campaigns run on a cadence instead of one-off. Three capabilities on one
small shared core:

1. **Scheduled publishing + calendar** — choose *when* an approved campaign
   goes out; see and reschedule what's queued.
2. **Recurring campaigns** — a repeating plan ("weekly featured item") that
   regenerates a fresh campaign + recommendation each cycle, still
   human-approved.
3. **Autonomous cadence** — Atlas paces its own recommendation generation from
   the company's stated marketing frequency and observed results.

## Non-goals (this pass)

- Auto-publishing without approval. Founding principle #9 stands: every cycle
  lands as a *pending* `Recommendation`.
- A fully generic cron/RRULE rules engine. Cadence vocabulary is the existing
  `PostingFrequency` set plus a weekday/time (or day-of-month) anchor.
- Multi-step drip sequences / journeys.

## What already exists

- The pipeline: `Opportunity → Decision → Campaign → ContentAsset →
  Recommendation → (approval) → Execution → PublishContent`.
- `Execution.scheduled_at` + the `PublishScheduledContent` job (Laravel
  scheduler, every 5 min) already dispatch due executions. `Campaign.
  scheduled_start_at`/`scheduled_end_at` and `ContentAsset.scheduled_at`
  columns exist and are unused by any UI.
- Laravel scheduler wired in `routes/console.php` with the established
  `withoutOverlapping()` / `onOneServer()` conventions.
- `CustomCampaignService` — the user-initiated "brief → pipeline" path. The
  recurring engine reuses this shape with a scheduled trigger instead of a
  button click.
- `OnboardingProfile.marketing_frequency` (`MarketingFrequency` enum) —
  captured at onboarding, currently drives nothing.

**Gap:** there is no company timezone. `Company` has `settings` (json) and
`brand` (json) but no `timezone`. Every dated behaviour below is
timezone-aware, so this is the first slice.

## Domain model (new)

### `CampaignSchedule`

The repeating plan; one row per recurring intent.

| field | notes |
|---|---|
| `company_id` | |
| `name` | "Weekly featured vehicle" |
| `owner` | `user` \| `atlas` (autonomous cadence uses `atlas`) |
| `status` | `active` \| `paused` \| `ended` |
| `cadence` | `PostingFrequency` — daily / weekly / biweekly / monthly / quarterly |
| `anchor` | weekday + local time (weekly-ish); day-of-month + time (monthly-ish) |
| `timezone` | snapshot of the company tz at creation |
| `template` (json) | what to build each cycle — see below |
| `next_run_at` (UTC) | recomputed from the local anchor after each run (DST-safe) |
| `last_run_at`, `runs_count` | |
| `starts_on`, `ends_on`, `max_runs` | all nullable |

`template`:
- `campaign_type` — `featured_item` / `new_arrival` / `urgency_promotion` /
  `re_engagement` / `custom`
- `channel_ids` — explicit, or "use marketing-presence defaults"
- `source` — how each cycle picks its subject:
  - `fixed_brief` — a stored `CampaignBrief` (S3)
  - `top_opportunity` — let `OpportunityEngine` choose the strongest current
    opportunity (S5)
  - `catalog_filter` — e.g. "highest-value catalog item not featured in 30d"
    (S5)
- `guidance` — freeform tone/constraints passed to generation

### `ScheduleRun`

Audit + idempotency for each firing.

| field | notes |
|---|---|
| `campaign_schedule_id`, `company_id` | |
| `scheduled_for` (UTC) | the intended local slot |
| `ran_at` | |
| `status` | `pending` \| `generated` \| `skipped` \| `failed` |
| `skip_reason` | `blackout` \| `frequency_cap` \| `pending_backlog` \| `no_subject` \| `paused` \| `cost_ceiling` |
| `recommendation_id` | set when a cycle produces one |
| `error` | |

`unique(campaign_schedule_id, scheduled_for)` — a slot never double-fires.

### Extending approval / `Execution`

- The Recommendation approve panel gains **Send now** | **Schedule for…**
  (date + time in company tz). "Schedule" sets `Execution.scheduled_at`
  (already honoured downstream) and populates `Campaign.scheduled_start_at`
  for the calendar.
- New reschedule / cancel endpoints on a scheduled campaign.

## The tick

One new command `atlas:run-due-schedules`, on the Laravel scheduler:
`everyFiveMinutes()->withoutOverlapping()->onOneServer()`, queued on `ai`
(generation is LLM-heavy).

For each due `CampaignSchedule` (`status = active`, `next_run_at <= now`):

1. Insert a `ScheduleRun` for the slot — the unique constraint dedupes.
2. Run guardrails. Blocked → `ScheduleRun.status = skipped` + reason;
   still advance `next_run_at`.
3. Resolve the subject per `template.source`. Nothing → `skipped: no_subject`
   (no filler campaigns).
4. Drive the existing pipeline (`DecisionService` → `CampaignPreparationService`
   → `RecommendationService`) with the template params → a **pending**
   `Recommendation`. Link it on the `ScheduleRun`.
5. Notify (respecting existing notification prefs): one digest-style message,
   "Your weekly featured-item campaign is ready to review."
6. Advance `next_run_at` to the next local slot.

## Guardrails — `ScheduleGuardrailService`

- **Blackout windows** — company-level quiet dates/ranges (holidays, closures),
  stored in company settings.
- **Frequency cap** — at most N campaigns per channel per rolling 7 days
  (default derived from `marketing_frequency`; hard ceiling e.g. 1/day/channel).
- **Pending backlog** — if the company already has ≥K unactioned pending
  Recommendations, skip (`pending_backlog`) rather than pile up unreviewed work.
- **Cost ceiling** — monthly AI-generation budget per company; skip when
  exceeded.

Every skip is recorded on `ScheduleRun` and surfaced in the UI ("skipped last
Monday — 3 recommendations still pending").

## Autonomous cadence (capability 3)

A `CampaignSchedule` with `owner = atlas`, created once per company after
onboarding from `marketing_frequency` (`weekly` → weekly, `rarely` → monthly,
etc.), `source = top_opportunity`.

- Each cycle picks the strongest current opportunity via `OpportunityEngine` /
  `OpportunityScorer`; nothing clears the bar → `skipped: no_subject`.
- The Learning engine tunes it: sustained low approval/engagement → back off a
  cadence step; consistently strong → allow a step up (bounded).
- The user can pause it, or replace it with explicit recurring schedules.

## UI

- **`/app/calendar`** (new "Plan" nav item) — month/week view of pending
  recommendations on their target dates, scheduled executions, and (later)
  ghosted projections of recurring schedules. Click through to the
  recommendation or campaign.
- **Recommendation approve panel** — Send now | Schedule (date+time, company
  tz). Reschedule / cancel from the campaign page and the calendar.
- **`/app/settings/schedules`** — list / create / edit / pause
  `CampaignSchedule`s; each shows its recent `ScheduleRun`s and skip reasons.
  The autonomous schedule appears here marked "Managed by Atlas".

## Slices

| # | Slice | Ticket |
|---|---|---|
| S0 | `companies.timezone` column + onboarding step + `UTC` backfill | CM-113 |
| S1 | Schedule an approved campaign for a future send; reschedule / cancel | CM-114 |
| S2 | `/app/calendar` read-only plan view (pending recs + scheduled executions) | CM-115 |
| S3 | `CampaignSchedule` + `ScheduleRun` + `atlas:run-due-schedules` tick; `source = fixed_brief` only; unique-slot guard only | CM-116 |
| S3 | Recurring-schedule management UI (`/app/settings/schedules`) | CM-117 |
| S4 | Guardrails — blackout, frequency cap, pending-backlog, cost; skip logging + UI | CM-118 |
| S5 | Cycle subject resolution — `top_opportunity` and `catalog_filter` sources | CM-119 |
| S6 | Autonomous cadence — Atlas-managed schedule from `marketing_frequency` + Learning-engine tuning | CM-120 |
| S7 | Calendar projections for recurring schedules + schedule-management polish | CM-121 |

Ship order is the slice order. S1–S2 are low risk (mostly UI over existing
columns). S4 lands before S5/S6 — no autonomous generation without guardrails.

## Risks

- **Double-firing** — `unique(campaign_schedule_id, scheduled_for)` +
  `withoutOverlapping` / `onOneServer`.
- **Timezone / DST** — always recompute `next_run_at` from the local anchor;
  never add fixed UTC intervals.
- **Runaway generation / cost** — guardrails (S4) before subject-choosing
  sources (S5) and autonomous cadence (S6).
- **Notification fatigue** — one message per cycle, existing prefs respected;
  skips are silent (visible only in the run log).
- **Approval bottleneck** — the pending-backlog guardrail plus a visible
  calendar.
- **Stale templates** — a `catalog_filter` / `fixed_brief` that resolves to
  nothing (discontinued product) skips rather than generating garbage.

## Open questions

- Blackout windows: company-level only, or also per-channel?
- Autonomous cadence: opt-in at onboarding, or on-by-default but paused until
  the first manual approval?
- Do recurring schedules need per-run channel overrides, or is the template
  fixed until edited?
