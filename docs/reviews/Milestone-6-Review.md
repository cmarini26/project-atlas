# Milestone 6 — CTO Review
**Publishing Infrastructure: Execution Model, Publisher Registry, Retry/Idempotency, Audit Logging**
*Completed: 2026-06-26*

---

## What We Built

Milestone 6 implements the full publishing infrastructure. After a Recommendation is approved, the system now has a complete, production-grade pipeline to execute that approval: creating Execution records, dispatching publishers, retrying on transient failures, logging every attempt, and settling the Campaign once all channels complete.

No real platform publishers (Instagram, Facebook, Email, SMS, etc.) are wired yet — all channels in M6 use `LogChannelPublisher`, which writes to a dedicated log file and returns a synthetic result. The infrastructure is fully production-ready; real publishers plug in via `PublisherServiceProvider` without changing any job or service code.

The full pipeline:

```
RecommendationApproved event
  → TriggerCampaignPublishing listener
      → PublishCampaign job (high queue)
          → ExecutionService::queueForCampaign()
              → Execution × n (one per approved ContentAsset)
              → ContentAsset: approved → scheduled
          → PublishContent job × n (high queue, immediate only)
              → ChannelPublisherRegistry::for(channelType)
              → ChannelPublisher::publish(Execution)
                  [in M6: LogChannelPublisher]
              → ExecutionService::logAttempt()
              → ExecutionService::markCompleted() / markFailed()
                  → ContentAsset: scheduled → published / approved
                  → ExecutionCompleted / ExecutionFailed event
                  → ExecutionService::checkCampaignCompletion()
                      → Campaign: → published (any completed) / cancelled (all failed)
                      → CampaignPublished event

[Scheduled executions — separate path]
  → PublishScheduledContent job (maintenance, every 5 min)
      → Dispatches PublishContent for due Executions

[Health monitoring]
  → CheckChannelHealth job (maintenance, every 30 min)
      → ChannelPublisher::ping(ChannelCredentials)
      → ChannelCredentials.status: active / error
```

---

## Deliverables

### Domain Layer

| Component | File | Description |
|-----------|------|-------------|
| `ExecutionResult` | `app/Domain/Publishing/ValueObjects/` | Readonly VO: `platformId`, `url`, `publishedAt`, `metadata` |
| `PlatformPayload` | `app/Domain/Publishing/ValueObjects/` | Readonly VO: `channelType`, `data` |
| `PingResult` | `app/Domain/Publishing/ValueObjects/` | Readonly VO: `reachable`, `error` |
| `PublishingException` + 8 subclasses | `app/Services/Publishing/Exceptions/` | Base with `isRetryable()` + `userMessage()`; retryable: `RateLimitException`, `NetworkException`, `PlatformUnavailableException`; non-retryable: `ContentPolicyViolationException`, `AuthenticationException`, `CredentialsNotFoundException`, `MalformedPayloadException`, `UnknownChannelException` |
| `ChannelPublisher` | `app/Services/Publishing/Contracts/` | Interface: `publish()`, `supports()`, `ping()` |
| `ChannelRenderer` | `app/Services/Publishing/Contracts/` | Interface: `render()`, `supports()` — pure data transformation, no API calls |
| `SupportsRollback` | `app/Services/Publishing/Contracts/` | Opt-in interface: `rollback(Execution): bool`; not implemented by any M6 publisher |

### Database

| Migration | Table | Key Design Decisions |
|-----------|-------|----------------------|
| `2026_06_26_002200` | `channel_credentials` | `UNIQUE(company_id, channel_type)`; `credentials` cast as `encrypted` text; `expires_at` for token rotation |
| `2026_06_26_002300` | `executions` | `content_asset_id UNIQUE` — one execution per asset; `idempotency_key UNIQUE`; `result` JSON; `attempts` smallint |
| `2026_06_26_002400` | `execution_attempts` | Append-only; no `updated_at`; `attempt_number` + `attempted_at` for audit trail |

### Models

| Model | Traits | Notes |
|-------|--------|-------|
| `ChannelCredentials` | `BelongsToCompany`, `HasUlids` | `isExpired()` helper; encrypted cast |
| `Execution` | `BelongsToCompany`, `HasUlids` | `attemptLogs()` HasMany (not `attempts()` — naming conflict with column); `isSettled()` |
| `ExecutionAttempt` | `HasUlids` only | No `BelongsToCompany` — no `company_id` column; `$timestamps = false` |

### Services

| Service | Responsibility |
|---------|---------------|
| `ChannelPublisherRegistry` | Resolves publisher by `supports(channelType)`; throws `UnknownChannelException` |
| `ChannelCredentialsRepository` | Typed credential access; throws `CredentialsNotFoundException` |
| `ExecutionService` | Full execution lifecycle: queue, complete, fail, log attempt, campaign completion check |
| `RollbackService` | Iterates completed executions; dispatches rollback if `SupportsRollback`; categorises results |

### Publishers

| Publisher | Channel Types | Behavior |
|-----------|--------------|---------|
| `FakeChannelPublisher` | All (returns `true` for `supports()`) | Test double; queue-based responses; assert methods |
| `LogChannelPublisher` | All 8 (`facebook`, `instagram`, `linkedin`, `x`, `email`, `sms`, `blog`, `landing_page`) | Writes to `publishing` log channel; no API calls; M6 default |

### Jobs

| Job | Queue | Tries | Notes |
|-----|-------|-------|-------|
| `PublishCampaign` | `high` | 1 | Guards `approved` status; dispatches immediate `PublishContent` only |
| `PublishContent` | `high` | 4 | Backoff: 60s/300s/900s; non-retryable → `fail()`; retryable → re-throw; `failed()` hook |
| `PublishScheduledContent` | `maintenance` | default | Every 5 min; dispatches due Executions |
| `CheckChannelHealth` | `maintenance` | default | Every 30 min; pings all active credentials |

### Events & Listeners

| Event/Listener | Trigger | Effect |
|----------------|---------|--------|
| `TriggerCampaignPublishing` | `RecommendationApproved` | Dispatches `PublishCampaign` on `high` queue |
| `ExecutionCompleted` | `ExecutionService::markCompleted()` | Carries `Execution` |
| `ExecutionFailed` | `ExecutionService::markFailed()` | Carries `Execution` |
| `CampaignPublished` | `ExecutionService::checkCampaignCompletion()` | Carries `Campaign`; fires on both `published` and `cancelled` outcomes |

### Filament

`ExecutionResource` — read-only admin panel resource with:
- Company name, campaign title, asset type, channel type
- Status badge (queued/executing/completed/failed/cancelled with colour coding)
- Attempts counter, last error, scheduled/completed timestamps
- Status filter

---

## Architecture Decisions

### Why `attemptLogs()` not `attempts()`

The `executions` table has an `attempts` integer column. Laravel's relationship naming would have created a method conflict with `attempts()` as a HasMany. The relationship is named `attemptLogs()` to avoid the collision.

### Why `SupportsRollback` is separate from `ChannelPublisher`

Not all publishers can undo a publication. Email and SMS sent = delivered; you cannot unsend. Social posts can be deleted via API. Making rollback opt-in (a separate interface) lets `RollbackService` detect capability without needing every publisher to implement a no-op `rollback()`.

### Why `CampaignPublished` fires on `cancelled` too

`CampaignPublished` signals "the campaign has reached final settlement" — not necessarily success. Downstream consumers (analytics, learning, notifications) need to know the campaign is done regardless of outcome. If we only fired on `published`, a campaign where all executions fail would leave listeners waiting indefinitely.

### Why the campaign status enum needed `published`

The original `campaigns` migration included `completed` but not `published`. The `ExecutionService` sets `published` when at least one execution completes, treating "published" as "live on at least one channel." `completed` is reserved for a fully-exhausted campaign lifecycle (future use). The migration was updated in M6.

### FakeChannelPublisher pattern

`FakeChannelPublisher` follows the same pattern as `FakeAiProvider`: queue-based results, PHPUnit assert methods built in, used in test setup rather than via Laravel's container swap magic. Tests that need to control publisher outcomes swap the registry directly (`$registry->register($fake)`) before passing it into the job.

---

## Test Coverage

| Test Class | Tests | Coverage Focus |
|------------|-------|----------------|
| `ExecutionServiceTest` | 19 | All lifecycle methods; status transitions; idempotency of `markFailed`; campaign settlement |
| `PublishCampaignJobTest` | 6 | Creates executions; immediate vs scheduled dispatch; guards non-approved; high queue |
| `PublishContentJobTest` | 8 | Success path; non-retryable failure; retryable failure; idempotency (completed/cancelled) |
| `PublishingPipelineTest` | 4 | RecommendationApproved → CampaignPublished; failed channel doesn't block others; all-fail = cancelled |
| `LogChannelPublisherTest` | 7 | Writes to publishing channel; result shape; supports all 8 types; ping always reachable |
| `RollbackServiceTest` | 5 | M6 publishers are unrollable; rollable publisher archives asset; failed rollback; only completed; empty |

**Total: 47 new tests. 211 total (209 passing, 2 Redis skipped).**

No live API calls in any test. All tests use `FakeChannelPublisher` or `LogChannelPublisher` with Mockery log interception.

---

## Quality Gates

| Gate | Result |
|------|--------|
| `./vendor/bin/pint` | ✅ Clean (all M6 files formatted) |
| `./vendor/bin/phpstan analyse` | ✅ 0 errors at level 8 |
| `php artisan test` | ✅ 209/211 passing (2 Redis skipped) |

---

## What Is NOT In M6

Per `specs/core/publishing-engine.md` Milestone 6 Implementation Scope:

- `InstagramPublisher`, `FacebookPublisher`, `LinkedInPublisher`, `XPublisher` — OAuth + platform approval required
- `SmsPublisher` — Twilio/Vonage credentials required
- `BlogPublisher`, `LandingPagePublisher` — CMS API target required
- `EmailPublisher` — **targeted for Milestone 7** (first real publisher)
- Analytics retrieval — Milestone 7+
- Learning from execution outcomes — Milestone 8
- Real credential management UI — deferred

---

## Milestone 7 Setup

M6 intentionally leaves all channel types wired to `LogChannelPublisher`. Milestone 7 adds `EmailPublisher`:

1. Implement `EmailPublisher implements ChannelPublisher` — calls Laravel `Mail` or transactional email provider
2. Implement `EmailRenderer implements ChannelRenderer` — transforms `ContentAsset` body + metadata into email payload
3. Register `EmailPublisher` in `PublisherServiceProvider` for the `email` channel type (replaces `LogChannelPublisher` for that type)
4. Add `ChannelCredentials` record for email provider credentials
5. Test with `FakeChannelPublisher` + integration test asserting mail is queued

No changes to `ExecutionService`, `PublishCampaign`, `PublishContent`, or `PublishingPipelineTest` are required — the infrastructure accepts any `ChannelPublisher` implementation.
