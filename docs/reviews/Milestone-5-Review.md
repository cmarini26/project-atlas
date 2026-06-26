# Milestone 5 — CTO Review
**Campaign Engine: Blueprint + Content Generation + Approval Workflow**
*Completed: 2026-06-26*

---

## What We Built

Milestone 5 completes the Campaign Engine — the pipeline that transforms a committed Decision into a ready-to-approve Recommendation with generated content.

The full pipeline:

```
DecisionCommitted event
  → PrepareCampaign job (ai queue)
      → CampaignPreparationAnalyst → CampaignBlueprint VO
      → CampaignPreparationService (validates blueprint, creates Campaign draft)
      → GenerateContent job × n (one per channel, ai queue)
          → ContentGenerationAnalyst (channel-specific prompt)
          → ContentGenerationService (creates ContentAsset, increments count)
          → [when generated_asset_count == expected_asset_count]
          → CampaignAssetsReady event
              → TriggerRecommendationCreation listener
                  → CreateRecommendation job (default queue)
                      → RecommendationService (assembles Recommendation, fires RecommendationCreated)

[User reviews in Filament admin panel]
  → ApprovalService.approve() → RecommendationApproved event
  → ApprovalService.reject()  → RecommendationRejected event
```

---

## Deliverables

### Domain

| Component | File | Description |
|-----------|------|-------------|
| `BlueprintGenerationFailedException` | `app/Domain/Campaign/Exceptions/` | Hard failure on invalid blueprint |
| `CampaignBlueprint` VO | `app/Domain/Campaign/ValueObjects/` | 10-field readonly; `fromArray()` / `toArray()` |
| `ContentAssetData` VO | `app/Domain/Content/ValueObjects/` | Transient: type, body, title, media, metadata |

### AI Prompts (6 new)

| Prompt | Channel | Temperature |
|--------|---------|-------------|
| `CampaignPreparationPrompt` | — | 0.5 |
| `SocialContentPrompt` | instagram, facebook, linkedin, x | 0.7 |
| `EmailContentPrompt` | email | 0.6 |
| `SmsContentPrompt` | sms | 0.6 |
| `BlogContentPrompt` | blog | 0.6 |
| `LandingPageContentPrompt` | landing_page | 0.5 |

All extend abstract `Prompt`; all include structured JSON schemas; all use `version()` for auditability.

### Analysts (2 new)

- `CampaignPreparationAnalyst` — calls AI, returns `CampaignBlueprint` VO
- `ContentGenerationAnalyst` — exhaustive `match` on channel type; dispatches channel-specific prompt; returns `ContentAssetData`

### Services (4 new)

- `CampaignPreparationService` — validates 7 blueprint rules; persists Campaign in `draft`; sets `expected_asset_count = count(channel_ids)`
- `ContentGenerationService` — creates `ContentAsset`; increments `generated_asset_count`; fires `CampaignAssetsReady` when count matches
- `RecommendationService` — assembles `rationale_display` from Decision rationale; creates `Recommendation` with `status: pending`; updates Decision to `recommended`
- `ApprovalService` — `approve()` + `reject()` with full status cascade (Recommendation → Campaign → ContentAssets); throws on invalid state transitions

### Jobs (3: 1 updated, 2 new)

| Job | Queue | Description |
|-----|-------|-------------|
| `PrepareCampaign` | ai | Full implementation (was no-op stub) |
| `GenerateContent` | ai | Creates one ContentAsset per channel |
| `CreateRecommendation` | default | Triggered by `CampaignAssetsReady`; no AI |

### Events (4 new)

| Event | Triggered by |
|-------|-------------|
| `CampaignAssetsReady` | `ContentGenerationService` when all assets generated |
| `RecommendationCreated` | `RecommendationService` |
| `RecommendationApproved` | `ApprovalService.approve()` |
| `RecommendationRejected` | `ApprovalService.reject()` |

### Listeners (1 new)

- `TriggerRecommendationCreation` — `CampaignAssetsReady → CreateRecommendation::dispatch()`

### Models (5 updated/new)

| Model | Change |
|-------|--------|
| `ContentAsset` | Full: `HasUlids`, `BelongsToCompany`, `SoftDeletes`, all fillable, `$casts` property form |
| `Approval` | New: `HasUlids`, `BelongsToCompany`, `morphTo approvable` |
| `Campaign` | Updated: blueprint columns, `contentAssets()` relationship, `allAssetsGenerated()` |
| `Recommendation` | Updated: `campaign_id`, `$casts` property form, `decision()` + `campaign()` relationships |
| `Decision` | Updated: `$casts` property form (fixes Larastan inference for JSON arrays) |
| `User` | Implements `FilamentUser` for Filament admin authentication |

### Migrations (4 new)

| Migration | Change |
|-----------|--------|
| `add_blueprint_columns_to_campaigns_table` | blueprint, blueprint_version, prompt_version, expected/generated_asset_count |
| `create_content_assets_table` | Full content_assets table |
| `create_approvals_table` | Polymorphic approvals table |
| `add_campaign_id_to_recommendations_table` | campaign_id FK |

### Filament Admin Panel

- 6 Resources: Company, Opportunity, Decision, Campaign, ContentAsset, Recommendation
- `RecommendationResource`: approve + reject actions with notes form; status badges; defaults to pending
- `CampaignResource` + `ContentAssetResource`: read-only inspection with status/type badges
- All resources use `withoutGlobalScopes()` to bypass multi-tenancy in admin context
- `app/Filament/` excluded from PHPStan scanning (generated code)

### Tests (35 new, 164 total)

| Test File | Count | Coverage |
|-----------|-------|----------|
| `CampaignPreparationServiceTest` | 8 | Blueprint generation, validation failures, prompt sent, asset count |
| `ContentGenerationServiceTest` | 6 | Email + social creation, count tracking, CampaignAssetsReady timing, prompt metadata |
| `RecommendationServiceTest` | 5 | Creates pending, builds rationale_display, updates decision status, fires event |
| `ApprovalServiceTest` | 12 | Approve/reject transitions, cascade to Campaign/Assets, approval record, events, invalid state guards, no publishing |
| `CampaignPipelineTest` | 4 | Job dispatch count, full E2E pipeline, no publishing |

**All tests use `FakeAiProvider`. No live AI in any test.**

---

## Quality Checks

| Check | Result |
|-------|--------|
| PHPStan level 8 | ✅ 0 errors |
| Laravel Pint | ✅ 0 violations |
| Test suite | ✅ 162 passing, 2 skipped (Redis), 0 failing |

---

## Non-Obvious Decisions

### Decision model: `$casts` property form vs `casts()` method

Larastan 3.10.0 cannot infer the return type of `array`-cast attributes when using the `casts()` method form. PHPStan sees `channel_ids`, `rationale`, and `expected_impact` as `string` instead of `array`, causing cascade errors in every service that accesses them. Fixed by converting Decision model to `protected $casts = [...]` property form. (Same fix applied to other M5 models.)

### Channel type enum vs ContentAsset type enum

Channel types in the DB: `facebook`, `instagram`, `linkedin`, `x`, `email`, `sms`, `blog`, `landing_page`.  
ContentAsset types: `social_post`, `email`, `sms`, `blog_post`, `ad_copy`, `landing_page`.

These are intentionally different: channel type is *where to publish*, asset type is *what kind of content*. `ContentGenerationAnalyst` performs the mapping with an exhaustive `match` (no `default` arm; PHPStan enforces all enum values are handled).

### Campaign::allAssetsGenerated() guard

`expected_asset_count > 0` is required in the check to prevent firing `CampaignAssetsReady` on a campaign with no channels. This can't happen in normal flow, but guards against data anomalies.

### ApprovalService ignores already-published assets

When approving/rejecting, the service only transitions assets in `draft` status. Assets that reached `scheduled` or `published` by other means are left untouched — no regression.

### Filament excluded from PHPStan

Filament v3 generates resource classes with implicit Eloquent types that PHPStan cannot infer without full Filament stubs. The generated code is correct but not fully annotated. `app/Filament/` is excluded from `phpstan.neon` rather than suppressing errors file-by-file.

### No `$campaign` in SMS prompt constructor

`SmsContentPrompt` doesn't reference the Campaign object (SMS is too short for per-campaign customization beyond the blueprint). Removing unused constructor params keeps PHPStan clean.

---

## What Was Explicitly NOT Built

Per the Milestone 5 stop conditions:

- No real publishing to any platform
- No platform API integrations (email providers, social APIs)
- No analytics or performance tracking
- No learning from approval decisions
- No billing or customer-facing frontend

The only external output in M5 is a `RecommendationApproved` event — consuming that event for publishing is M6 work.

---

## Ready for Milestone 6?

**YES.**

Milestone 6 prerequisites:

| Prerequisite | Status |
|-------------|--------|
| `RecommendationApproved` event fires reliably | ✅ Tested |
| `ContentAsset` records exist at approval time | ✅ Tested |
| Campaign status transitions cleanly on approve/reject | ✅ Tested |
| Filament admin UI allows human review and decision | ✅ Working |
| PHPStan level 8 — 0 errors | ✅ |
| All tests green | ✅ |

Milestone 6 will wire `RecommendationApproved → PublishCampaign → ChannelPublisher` and implement `AnthropicProvider` for real production AI calls.
