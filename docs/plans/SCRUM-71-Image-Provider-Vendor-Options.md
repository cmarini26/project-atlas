# SCRUM-71 — Image Generation Provider: Vendor Options

**Status:** decision note, needs sign-off before the real provider is coded
**Date:** 2026-08-27
**Blocks:** the real `ImageGenerationProvider` implementation (SCRUM-71-Visual-Asset-Generation-Plan.md §5 / Slice A's deferred "wire the first real provider" step)

---

## 1. Why this note exists

Slice A of SCRUM-71 is merged (or in review) with a provider abstraction and a
`FakeImageGenerationProvider` only. The plan deliberately did **not** pick a
hosted vendor up front:

> "The codebase does not yet contain an image-generation integration pattern, so
> the specific vendor choice should be made deliberately before coding the real
> provider." — SCRUM-71-Visual-Asset-Generation-Plan.md §5

This note is that deliberate step. It exists so the choice is recorded, not so
implementation waits on a long evaluation — the abstraction means we can start
with one provider and swap later with a single new class.

## 2. Selection criteria (from the plan §5)

1. Reliable still-image generation from a simple text prompt.
2. Predictable, stable API shape.
3. Hosted output **or** binary/base64 download — must fit `GeneratedImage(contents, mimeType, …)`.
4. Acceptable latency for **queued** generation (we generate on the `ai` queue, not in a request).
5. Cost tolerable for **draft-stage** generation that a human may then reject.
6. No requirement to run heavy local GPU infra in Atlas production.

Extra Atlas-specific constraints:

- The provider abstraction (`App\Services\Imaging\ImageGenerationProvider`) already
  isolates the vendor. Whatever we pick touches exactly one new class plus
  `config/imaging.php` + `ImagingServiceProvider`.
- Atlas already carries an Anthropic dependency and (optionally) a localhost
  Ollama one. Anthropic has **no** image-generation API, so this is a genuinely
  new external dependency regardless of choice.
- Beta volume is tiny and capped: `imaging.per_company_daily_limit` defaults to
  20, and there are a handful of active companies. Worst-case ballpark is ~100
  generated images/day.

## 3. Candidates

Pricing below is per 1024×1024 image, from public pricing pages / third-party
comparisons as of mid-2026. Treat as order-of-magnitude, re-check the vendor
page before implementation.

| Option | ~Cost/image | Response shape | Local infra | Notes |
|---|---|---|---|---|
| **Google Imagen 4 Fast** (Gemini API) | ~$0.02 | base64 JSON | none | Cheapest of the "major" hosted models; Gemini API also exposes an OpenAI-compatible surface. |
| Google Imagen 4 Standard | ~$0.04 | base64 JSON | none | Higher quality, same API. |
| **OpenAI `gpt-image-1-mini`** | ~$0.005 | `data[].b64_json` (always base64) | none | Cheapest overall; same Images API shape as the full model, easy to upgrade to `gpt-image-1`. |
| OpenAI `gpt-image-1` (High) | ~$0.17 | `data[].b64_json` | none | Strong quality/instruction-following; ~30× the mini cost — hard to justify at draft stage. |
| Stability AI — Stable Image Core | ~$0.03 | binary / base64 | none | Independent of the two big clouds; decent quality floor. |
| Replicate / fal.ai (host FLUX, SDXL, etc.) | ~$0.003–0.05 depending on model | **hosted URL** (download then re-store) | none | Aggregators — one integration, many models. Adds a middleman and its own reliability surface. |
| Self-hosted (SDXL/FLUX on our own GPU) | infra cost only | binary | **yes — GPU box** | Fails criterion 6. Out of scope for the first real slice. |

All hosted candidates satisfy criteria 1–4 and 6. Base64 responses (OpenAI,
Google, Stability) drop straight into `GeneratedImage`; aggregator URL responses
need a download step (we already download+store, so this is minor).

## 4. Recommendation

**Primary: OpenAI `gpt-image-1-mini`.**

Rationale:

- **Cost** — at ~$0.005/image, worst-case beta volume (~100/day) is well under
  $1/day. Draft images a human may reject should be near-free, and this is.
- **API shape** — the OpenAI Images API is stable and well-documented, always
  returns `b64_json`, and upgrading to the full `gpt-image-1` later is a
  one-line model-string change behind our abstraction if quality proves
  insufficient.
- **No new vendor relationship** — we don't use OpenAI today, but a single API
  key with hard usage limits is a smaller operational footprint than onboarding
  an aggregator.
- **Criterion 5 fit** — cheapest credible option; the quality gap vs. Imagen
  Standard / gpt-image-1 High does not matter for a labelled *draft proposal*
  (see the plan §7 "weak brand consistency is acceptable for V1").

**Fallback / swap target: Google Imagen 4 Fast** (~$0.02) if OpenAI quality at
the `mini` tier is unusable in practice, or if billing/enterprise reasons favour
Google. Same base64 shape, so the swap is another single provider class.

**Not recommended for the first real slice:** aggregators (extra reliability
surface for no clear V1 benefit), `gpt-image-1` High / Imagen Ultra (cost
unjustified at draft stage), self-hosting (fails criterion 6).

## 5. What implementation looks like once this is signed off

Small, contained — the abstraction did its job:

1. `app/Services/Imaging/OpenAiImageProvider.php` implementing
   `ImageGenerationProvider` — one `POST` to the Images endpoint, decode
   `data[0].b64_json`, return `GeneratedImage(contents, 'image/png', 'openai', 'gpt-image-1-mini')`.
2. `config/services.php` — `openai.api_key`, `openai.image_model`.
3. `config/imaging.php` — no shape change; `IMAGE_GENERATION_PROVIDER=openai`.
4. `.env.example` — `OPENAI_API_KEY=`, note it's image-generation-only today.
5. `ImagingServiceProvider::boot()` — register the new provider alongside the fake one.
6. Tests: mock the HTTP call (`Http::fake`), assert the stored file + `media`
   payload; the existing `FakeImageGenerationProvider` stays the default for
   the rest of the suite and local dev.
7. Keep `IMAGE_GENERATION_ENABLED=false` in `.env.example`; enable per-environment
   only after a manual smoke test against a real key.

No change to `ContentGenerationAnalyst`, `GeneratedImageService`, storage layout,
the preview UI, or the approval flow — all of that landed in Slices A + B.

## 6. Open questions for sign-off

- OK to take on an **OpenAI** API dependency (key management, billing) purely for
  image generation, given we otherwise standardise on Anthropic + optional Ollama?
- Any data-handling constraint that rules out sending campaign message / offer
  text to OpenAI as an image prompt? (The prompt today contains company name,
  industry, campaign core message, offer, tone — no customer PII.)
- Preferred billing guardrail beyond the per-company daily cap — a hard monthly
  spend limit on the OpenAI key itself?
