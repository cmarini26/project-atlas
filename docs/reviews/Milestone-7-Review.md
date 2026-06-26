# Milestone 7 Review — EmailPublisher

**Completed:** 2026-06-26  
**Tests:** 268 passing / 2 skipped (Redis) / 0 failing  
**PHPStan:** Level 8 — 0 errors  
**Pint:** Clean

---

## What Shipped

Milestone 7 wired the first real channel publisher into the M6 publishing infrastructure. The email channel now has a dedicated sub-layer beneath `ChannelPublisher` that can route to multiple email API providers (Postmark, SES, Mailgun) without changing any orchestration code.

### New Files

| File | Purpose |
|------|---------|
| `app/Domain/Publishing/ValueObjects/EmailPayload.php` | Typed VO for email data; validates subject at construction |
| `app/Services/Publishing/Email/Contracts/EmailProvider.php` | Interface for concrete email API providers |
| `app/Services/Publishing/Email/EmailProviderRegistry.php` | Resolves `EmailProvider` by `provider_type` string; first-match |
| `app/Services/Publishing/Email/Exceptions/UnknownEmailProviderException.php` | Non-retryable; user-facing message directs to support |
| `app/Services/Publishing/Email/LogEmailProvider.php` | Dev/test provider; writes to `publishing` log; no API calls |
| `app/Services/Publishing/Email/FakeEmailProvider.php` | Test double; queue/assertion API mirroring `FakeChannelPublisher` |
| `app/Services/Publishing/EmailRenderer.php` | Converts `ContentAsset` → `PlatformPayload`; subject resolution chain |
| `app/Services/Publishing/EmailPublisher.php` | Full publisher: credentials → render → `EmailPayload` → provider → result |

### Modified Files

| File | Change |
|------|--------|
| `app/Providers/PublisherServiceProvider.php` | `EmailProviderRegistry` singleton; `EmailRenderer` + `EmailPublisher` registered with priority |
| `tests/Feature/Publishing/LogChannelPublisherTest.php` | Added `title` to test asset so `EmailRenderer` (now first) can resolve subject |

---

## Architecture Decisions

### Two-tier publisher design

`EmailPublisher` implements `ChannelPublisher` (the M6 interface) and additionally depends on `EmailProviderRegistry` (a new sub-layer). This means:

- The orchestration layer (`PublishContent` job, `ExecutionService`) sees only one publisher per channel type — unchanged.
- Swapping from `LogEmailProvider` to `PostmarkEmailProvider` requires only a credential update (`provider_type = 'postmark'`) and registering the new provider — no orchestration changes.

### `provider_type` on `ChannelCredentials`

`ChannelCredentials.provider_type` is the dispatch key. `EmailPublisher` reads this field and resolves the correct `EmailProvider` from `EmailProviderRegistry`. This makes the email provider selection data-driven and per-company.

### Subject resolution in `EmailRenderer`

Resolution order: `metadata.subject_line` → `asset->title` → `MalformedPayloadException`. This keeps the model generic (no email-specific column on `content_assets`) while giving content generators two paths to set the subject.

### Registration order matters

`EmailRenderer` must be registered before `GenericRenderer` in `ChannelRendererRegistry`. `EmailPublisher` must be registered before `LogChannelPublisher` in `ChannelPublisherRegistry`. Both registries use first-match resolution. This is documented in `PublisherServiceProvider` comments and enforced by test ordering.

### `FakeEmailProvider` mirrors `FakeChannelPublisher`

Same queue/assertion pattern: `queueMessageId()` for success, `queueFailure()` for errors, `assertSent(int)`, `assertNotSent()`. Consistent test ergonomics across the publisher layer.

---

## Test Summary

| Test Class | Count | Coverage |
|------------|-------|---------|
| `EmailRendererTest` | 6 | Render all fields, title fallback, missing subject throws, supports/rejects channel types, empty metadata |
| `EmailProviderRegistryTest` | 6 | Resolve by type, log provider, unknown type throws, `all()`, first-match, non-retryable exception |
| `LogEmailProviderTest` | 6 | Message ID prefix, unique IDs, log write with subject, ping reachable, supports/rejects provider types |
| `EmailPublisherTest` | 12 | Send via provider, correct subject, result metadata, message ID passthrough, exception propagation, credentials not found, error-status credentials, supports only email, ping delegation, full `PublishContent` job integration, metadata includes provider+subject |
| **Total new** | **29** | |

All 268 tests use `FakeEmailProvider` — no SMTP connections or transactional API calls in CI.

---

## Explicit Exclusions

These were explicitly out of scope for M7:

- Email analytics (open rates, click tracking)
- Webhooks from email providers
- `PostmarkEmailProvider` or any real API provider
- Learning from execution results
- SMS, social, paid ads publishers
- Frontend changes to the Filament admin (executions were already visible via M6 `ExecutionResource`)

---

## Known Gaps / Follow-up

| Item | Priority | Notes |
|------|----------|-------|
| `PostmarkEmailProvider` | High | M8 target; credential validation should ping `/server` endpoint |
| `AnthropicProvider` (real) | High | Still `FakeAiProvider` in all environments; must bind real provider before production use |
| `from_name` / `from_email` default fallback | Low | `EmailRenderer` returns empty strings when not in metadata; `LogEmailProvider` logs them but real providers will need valid sender details — enforce via credential validation in M8 |
| Filament email preview | Low | Executions visible in `ExecutionResource`; no email-specific preview UI |
