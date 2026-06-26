# Milestone 6.5 Review — Publishing Hardening Before EmailPublisher

**Date:** 2026-06-26  
**Branch:** main  
**Tests:** 239 passing (2 Redis skipped)  
**PHPStan:** Level 8 — 0 errors  
**Pint:** Clean  

---

## Purpose

Milestone 6.5 is a hardening pass on the M6 publishing infrastructure before implementing the first real channel publisher (`EmailPublisher`). It addresses six architectural weaknesses identified after M6 shipped:

1. `LogChannelPublisher` did not exercise the renderer layer it was supposed to validate
2. `CampaignPublished` fired even when all executions failed — semantically wrong
3. Blueprint validation lacked checks for tone, landing page, success metrics, and channel strategy structure
4. `ChannelCredentialsRepository` only checked `revoked` status — did not handle `expired` or `error`
5. Tenancy implementation was undocumented — no clarity on the customer-facing route gap
6. No review documentation or status/changelog updates from M6

---

## What Shipped

### 1. Renderer Integration

**Problem:** `LogChannelPublisher` in M6 had an injected `ChannelRendererRegistry` in its interface contract but the implementation did not call `render()`. The renderer pipeline was defined but never exercised.

**Fix:** `LogChannelPublisher` now:
- Loads `Channel` via `Channel::withoutGlobalScopes()->findOrFail($execution->channel_id)`
- Calls `$this->renderers->for($channel->type)->render($asset, $channel)` to get a `PlatformPayload`
- Logs `channel_type` from the payload (not raw `channel_id`)

**New classes:**
- `ChannelRendererRegistry` — `register()`, `for()`, `all()` — resolves renderer by `supports(channelType)` first-match
- `GenericRenderer` — `supports()` returns `true` for all channel types; wraps body/title/media/metadata into `PlatformPayload`
- `FakeChannelRenderer` — test double; records `render()` calls; `assertRendered(int)`, `assertNotRendered()`, `renderedItems()`

**`PublisherServiceProvider` updated:** `register()` binds both registries as singletons; `boot()` registers `GenericRenderer` before `LogChannelPublisher`.

**Tests:** `RendererIntegrationTest` (5 tests) proves the full `PublishContent → LogChannelPublisher → ChannelRenderer` chain using `FakeChannelRenderer` swapped into the registry.

### 2. CampaignPublished Correctness

**Problem:** `ExecutionService::checkCampaignCompletion()` dispatched `CampaignPublished` regardless of whether any execution completed or all failed.

**Fix:** `CampaignPublished` is now only dispatched when `$anyCompleted` is true. When all executions are `failed` or `cancelled`, the campaign is marked `cancelled` and no event fires.

```php
if ($anyCompleted) {
    $campaign->update(['status' => 'published', 'completed_at' => now()]);
    CampaignPublished::dispatch($campaign);
} else {
    $campaign->update(['status' => 'cancelled']);
    // CampaignPublished intentionally not fired
}
```

**Tests updated:** `ExecutionServiceTest::test_campaign_becomes_cancelled_when_all_executions_fail()` and `PublishingPipelineTest::test_all_failed_executions_settle_campaign_as_cancelled()` both assert `Event::assertNotDispatched(CampaignPublished::class)`.

### 3. Blueprint Validation Hardening

**Problem:** `CampaignPreparationService::validateBlueprint()` only checked `goal`, `audience`, `core_message`, `supporting_points`, `call_to_action`, and the presence (not structure) of `channel_strategy`. Seven fields were unvalidated.

**Fix:** `validateBlueprint()` now takes `Decision $decision` as a second parameter and validates:

| Field | Rule |
|-------|------|
| `tone.voice` | required, non-empty string |
| `tone.modifier` | required, non-empty string |
| `tone.avoid` | must be an array |
| `landing_page` | must be a valid URL (`filter_var`) or null |
| `success_metrics.primary_metric` | required, non-empty |
| `success_metrics.secondary_metrics` | must be an array |
| `success_metrics.baseline` | required, non-empty |
| `success_metrics.timeframe` | required, non-empty |
| `channel_strategy` count | must have ≥ one entry per `decision.channel_ids` |
| per-strategy `format` | required |
| per-strategy `angle` | required |
| per-strategy `constraints` | must be an array |
| per-strategy `priority` | must be numeric |

**Tests:** 14 new test cases added to `CampaignPreparationServiceTest`.

### 4. Credential Validation

**Problem:** `ChannelCredentialsRepository::for()` only checked `status === 'revoked'`. Credentials with `status = 'expired'`, `status = 'error'`, or `expires_at` in the past were silently returned.

**Fix:** Three-stage validation chain:

```
null | revoked → CredentialsNotFoundException (non-retryable)
isExpired() | status=expired → CredentialsExpiredException (non-retryable, NEW)
status=error → AuthenticationException (non-retryable)
→ return credentials
```

**New exception:** `CredentialsExpiredException` — extends `PublishingException`; `retryable: false`; `userMessage()` instructs reconnection.

**Tests:** `ChannelCredentialsRepositoryTest` (9 tests) — active, not found, revoked, status=expired, expires_at in past, expires_at in future, error status, error non-retryable, expired non-retryable.

### 5. Tenancy Documentation

**Added:** `docs/technical/Tenancy.md`

Documents:
- How `CompanyScope` works (global scope applied via `BelongsToCompany` trait)
- Why services/jobs call `withoutGlobalScopes()` (intentional — not a bug)
- The missing production-readiness requirement: `ResolveCurrentCompany` middleware must bind `current_company_id` before any customer-facing query runs
- Two viable binding strategies: subdomain routing (preferred) and route parameter
- Clear statement that the admin panel (Filament) is unscoped and must never be exposed to customers

### 6. Documentation

- `CHANGELOG.md` — M6.5 entry added at top
- `docs/STATUS.md` — project health note updated; M6.5 added to completed milestones; "Recently Completed" updated; "Last Updated" updated
- `docs/reviews/Milestone-6.5-Review.md` — this document

---

## Test Summary

| Test File | New / Updated | Count |
|-----------|---------------|-------|
| `RendererIntegrationTest` | new | 5 |
| `ChannelCredentialsRepositoryTest` | new | 9 |
| `CampaignPreparationServiceTest` | 14 new methods added | +14 |
| `ExecutionServiceTest` | 1 assertion updated | — |
| `PublishingPipelineTest` | 1 assertion updated | — |
| **Total new tests** | | **28** |
| **Total passing** | | **239** |
| **Skipped (Redis)** | | **2** |

---

## What Was Explicitly NOT Implemented

- `EmailPublisher` — deferred; M6.5 stop condition
- `EmailRenderer` — deferred
- `ResolveCurrentCompany` middleware — documented but not implemented; production-readiness item
- Any analytics or learning loop changes

---

## Pre-M7 State

The publishing infrastructure is now correct and hardened. The renderer pipeline is exercised end-to-end with `FakeChannelRenderer`. The `CampaignPublished` event is semantically correct. Credentials are validated against all failure modes. Blueprint validation is complete.

`EmailPublisher` (Milestone 7) can now be added with confidence that the underlying infrastructure handles all edge cases correctly.
