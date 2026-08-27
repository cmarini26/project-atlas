# SCRUM-71 — Visual Asset Generation for Content Drafts

**Status:** Slices A + B implemented locally (2026-08-27) — abstraction, fake provider, storage service, channel gating, guardrails, generated-image metadata marker, and an "AI-generated" preview label. Real vendor provider still pending the §5 vendor decision.
**Date:** 2026-08-10
**Scope:** social + blog drafts first
**Reason this exists:** SCRUM-70 is now complete locally; the clearest remaining Adobe-comparison gap on Atlas's current content path is that content drafting is still text-only.

---

## 1. Current audited state

This plan is grounded in the current codebase, not assumption.

### What exists today
- `ContentGenerationAnalyst` generates copy only and returns `ContentAssetData` with optional `media` already supported at the value-object level.
- `ContentGenerationService::createAsset()` persists `ContentAsset.media` directly, so generated-media URLs can already flow into saved draft assets with no schema change required for a first slice.
- Recommendation preview UI already renders `asset.media[0].url` in `ContentPreview.vue`, and recommendation approval already treats media as part of the content asset being approved.
- Current media is only a fallback from existing customer/source images or website crawl images (`ContentGenerationAnalyst::resolveMediaFallback()`), not AI-generated creative.

### Files confirmed relevant
- `backend/app/Services/Analyst/Content/ContentGenerationAnalyst.php`
- `backend/app/Domain/Content/ValueObjects/ContentAssetData.php`
- `backend/app/Services/Content/ContentGenerationService.php`
- `backend/app/Models/ContentAsset.php`
- `backend/resources/js/Components/Recommendations/ContentPreview.vue`
- `backend/resources/js/Components/Recommendations/ApproveActions.vue`
- `backend/resources/js/types/index.ts`

### Constraint discovered from current architecture
Atlas already has strong provider-abstraction patterns for text generation, email, SMS, analytics, and publishing. SCRUM-71 should follow that pattern. Do **not** bolt image generation directly into `ContentGenerationAnalyst` with vendor-specific HTTP calls.

---

## 2. Product goal for the first honest slice

Add a generated image proposal alongside generated copy for:
- `instagram`
- `facebook`
- `blog`

Not in scope for V1 of SCRUM-71:
- video generation
- multi-image carousels
- per-product image matching
- image editing / inpainting workflow
- automatic publishing of generated assets without approval
- landing-page hero-image generation
- email-image generation

This keeps the slice aligned with Atlas's stated strategy: **depth over breadth**.

---

## 3. Recommended product/technical approach

### Recommendation
Use a provider abstraction named something like `ImageGenerationProvider`, then add one service responsible for deciding **when** Atlas should generate an image and how to map the result into `ContentAsset.media`.

### Why this is the right first shape
- It matches existing repo architecture principles.
- It keeps vendor choice swappable.
- It lets Atlas store generated images using the same `ContentAsset.media` surface the UI already knows how to render.
- It avoids inventing a second approval artifact model before the first real image-gen slice proves value.

### Suggested flow
1. `ContentGenerationAnalyst` generates copy as it does today.
2. For eligible channel types (`instagram`, `facebook`, `blog`), it asks a new collaborator for a generated image proposal.
3. The collaborator calls the configured `ImageGenerationProvider`.
4. The returned image is downloaded/stored on Atlas-controlled storage.
5. `ContentAssetData.media` is populated with a stored URL plus minimal metadata.
6. Recommendation review shows the generated image exactly like any other media-bearing draft.
7. Approval gate remains unchanged: the image is approved as part of the content asset.

---

## 4. Proposed implementation slices

### Slice A — provider abstraction + narrow generation path

#### Backend
Add:
- `app/Services/Imaging/Contracts/ImageGenerationProvider.php`
- `app/Services/Imaging/ImageGenerationProviderRegistry.php`
- `app/Services/Imaging/FakeImageGenerationProvider.php` for tests/dev
- one real provider implementation after vendor selection
- `app/Services/Imaging/GeneratedAssetService.php` (or similar) to:
  - build the image prompt from campaign + channel + business context
  - call the provider
  - store the returned image
  - return `list<array{url: string}>` compatible with `ContentAssetData.media`

Update:
- `ContentGenerationAnalyst` to call generated-image service only for supported channels

#### Storage shape
For the first slice, store generated images on public storage under something like:
- `generated-content/{company_id}/{campaign_id}/{channel_id}/...`

This mirrors existing media-storage patterns and keeps URLs stable for approval/review.

### Slice B — approval/review truth polish

UI already renders media, so the first slice may need little or no design work. But it should explicitly communicate that:
- the image is AI-generated
- it is part of the same approval artifact as the copy
- Atlas will not publish it without approval

Possible additions:
- `metadata.generated_image = true`
- `metadata.image_prompt_version = '1.0'`
- subtle preview label in `ContentPreview.vue`

### Slice C — cost/rate-limit guardrail

Before broad rollout, add at least one guardrail:
- config-level global toggle
- per-company daily limit
- only generate for selected channels/types
- do not regenerate an image if a draft asset already has one unless the draft is explicitly regenerated

---

## 5. Vendor decision recommendation

### Recommendation for the first implementation
Use a single hosted image API behind the abstraction for the first slice.

### Selection criteria
Pick the vendor/model that best satisfies:
1. reliable still-image generation via simple prompt
2. predictable API shape
3. hosted output or binary download support
4. acceptable latency for queued generation
5. cost that is tolerable for draft-stage generation
6. no requirement to run heavy local infra in Atlas production

### Decision note
The codebase does not yet contain an image-generation integration pattern, so the specific vendor choice should be made deliberately before coding the real provider. The abstraction can be built first, but the production provider should not be guessed blindly.

---

## 6. Acceptance criteria

- [x] Atlas has an `ImageGenerationProvider` abstraction and registry, with no direct vendor coupling inside `ContentGenerationAnalyst`. (`app/Services/Imaging/*`, wired via `ImagingServiceProvider`; the analyst only knows `GeneratedImageService`.)
- [x] Social (`instagram`/`facebook`) and blog drafts can include one generated image proposal alongside generated copy. (`config('imaging.channels')`; `GeneratedImageService::proposeFor()` called from `ContentGenerationAnalyst::resolveMedia()`.)
- [x] Generated images are stored as part of the draft content asset's `media` payload and render in the existing recommendation preview. (Stored on the `imaging.disk` filesystem under `generated-content/{company}/{campaign}/{channel}/`; returned in the `ContentAssetData.media` shape the preview already reads.)
- [x] Approval flow remains unchanged in safety terms: generated images are never auto-published outside the existing approval boundary. (Media only enters the same `ContentAsset` that already passes through approval; no publish path touched.)
- [x] A cost/rate-limit guard exists before the feature is treated as production-ready. (Off by default via `imaging.enabled`; `imaging.per_company_daily_limit` caps generated images per company per day.)
- [x] Feature tests cover: image generated for supported channels, no image generated for unsupported channels, failures degrade honestly, and daily-limit / disabled behaviour. (`tests/Feature/Imaging/GeneratedImageServiceTest.php`, `tests/Feature/Campaign/ContentGenerationAnalystGeneratedImageTest.php`.)
- [x] Full verification passes: `php artisan test` (1506 passing; 5 pre-existing `AiProviderConfigurationTest` failures unrelated, green on CI), `npm test`, `npm run build`, PHPStan level max, Pint.
- [x] Slice B — the image is marked AI-generated (`metadata.generated_image` + `metadata.image_prompt_version`, set in `ContentGenerationAnalyst`) and `ContentPreview.vue` shows an "AI-generated · draft proposal" label so it is never mistaken for real inventory (§7). Covered by `ContentPreview.spec.ts` and two `ContentGenerationAnalystGeneratedImageTest` cases.

### Still open (deliberately deferred)
- Real hosted image provider — blocked on the §5 vendor decision (Slice A's real-provider step).
- Retention/cleanup policy for superseded or rejected generated draft images (§7).

---

## 7. Risks and decisions to preserve

### Risk: fake realism
Atlas must not imply the image is sourced from real product inventory if it is AI-generated from prompt alone. The UI should preserve that distinction.

### Risk: expensive draft churn
If every regeneration creates a new image by default, costs can climb quickly. Queue-time throttling and regeneration policy matter.

### Risk: weak brand consistency
Without a stronger brand-kit / source-asset grounding layer, generated images may be visually plausible but not brand-true. That is acceptable for V1 if the UI is honest that this is a draft proposal.

### Risk: storage growth
Generated assets will accumulate. If this ships, follow-up work should define retention and cleanup behavior for superseded/rejected draft images.

---

## 8. Recommended next step

Implement **Slice A design scaffolding first**:
1. abstraction
2. fake provider
3. storage service
4. supported-channel gating
5. tests

Then wire the first real provider only after the vendor decision is confirmed.
