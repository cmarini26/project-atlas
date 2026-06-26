# Publishing Engine — Design Specification

**Version:** 1.0  
**Status:** Approved — authoritative specification for Milestone 6  
**Depends on:** `specs/core/domain-model.md`, `specs/core/campaign-blueprint.md`  
**See also:** `docs/technical/Architecture.md`, `docs/technical/AI.md`

When this document conflicts with others, this document wins for anything related to Executions, `ChannelPublisher`, `ChannelRenderer`, publishing lifecycle, credential management, and multi-channel orchestration.

---

## Milestone 6 Implementation Scope

Milestone 6 implements the **publishing infrastructure** — the contracts, jobs, models, registry, and test tooling that all real publishers will depend on. It does not implement any real platform publishers. The first real publisher (`EmailPublisher`) is a follow-up milestone.

The goal of Milestone 6 is to prove the full pipeline end-to-end using `LogChannelPublisher`, so that adding a real publisher later requires only implementing `ChannelPublisher` and registering it — no structural changes.

### What Milestone 6 Includes

**Domain models and migrations**

- `Execution` model + `executions` table — status lifecycle, scheduling, idempotency key, result JSON, audit fields
- `ExecutionAttempt` model + `execution_attempts` table — append-only per-attempt log
- `ChannelCredentials` model + `channel_credentials` table — encrypted credential storage, status, provider type, `expires_at`

**Services**

- `ExecutionService` — `queueForCampaign()`, `checkCampaignCompletion()`, `markFailed()`, `markCompleted()`

**Jobs**

- `PublishCampaign` — triggered by `RecommendationApproved`; creates `Execution` records; dispatches `PublishContent` per asset
- `PublishContent` — resolves publisher from registry; calls `publish()`; handles retry/backoff; records result or failure
- `PublishScheduledContent` — maintenance queue; runs every 5 minutes; dispatches due `Executions`

**Interfaces and contracts**

- `ChannelPublisher` interface — `publish()`, `supports()`, `ping()`
- `ChannelRenderer` interface — `render()`, `supports()`
- `ChannelPublisherRegistry` — resolution by channel type; `UnknownChannelException` on missing publisher

**Publisher implementations**

- `FakeChannelPublisher` — for tests; `queueResult()`, `queueFailure()`, `assertPublished()`, `assertNotPublished()`
- `LogChannelPublisher` — for local and demo environments; writes payload to the `publishing` log channel instead of calling a platform API; always returns a synthetic `ExecutionResult`

**Infrastructure**

- Encrypted credential storage (`ChannelCredentials`, `ChannelCredentialsRepository`)
- Health check structure (`ping()` contract, `CheckChannelHealth` maintenance job, `PingResult` VO)
- Circuit breaker logic (Redis-backed, per channel type)
- Retry and backoff (`PublishingException` hierarchy, retryable vs. non-retryable)
- Idempotency (key assignment, pre-flight status check)
- Audit logging (`ExecutionAttempt` records, structured `publishing` log channel)
- Rollback interface (`SupportsRollback`, `RollbackService`)

**Admin visibility**

- Filament `ExecutionResource` — read-only inspection of Execution records; status badge; attempts; last error; result JSON

**Events**

- `ExecutionCompleted` — fires when `Execution.status → completed`
- `ExecutionFailed` — fires when `Execution` reaches final failed state
- `CampaignPublished` — fires when all Executions for a Campaign have settled

**Tests**

- All tests use `FakeChannelPublisher` — no live platform calls in CI
- `LogChannelPublisher` tested via log assertion (`Log::assertLogged(...)`)
- Full pipeline test: `RecommendationApproved → PublishCampaign → PublishContent → Execution(completed) → CampaignPublished`

---

### What Milestone 6 Does Not Include

No real platform publishers are implemented in Milestone 6.

| Publisher | Status | Notes |
|-----------|--------|-------|
| `InstagramPublisher` | Not in M6 | Requires Graph API credentials and OAuth setup |
| `FacebookPublisher` | Not in M6 | Requires Graph API credentials and page token |
| `LinkedInPublisher` | Not in M6 | Requires LinkedIn OAuth |
| `XPublisher` | Not in M6 | Requires X API v2 credentials |
| `SmsPublisher` | Not in M6 | Requires Twilio / Vonage credentials |
| `BlogPublisher` | Not in M6 | Requires CMS API or file-based publishing target |
| `LandingPagePublisher` | Not in M6 | Requires hosted page service |
| `EmailPublisher` | **First real publisher** | Targeted for the milestone immediately following M6 |

The `EmailPublisher` is prioritised as the first real publisher because:
1. Email does not require an OAuth flow or social platform approval
2. Transactional email providers (Mailchimp, Postmark, Klaviyo) have well-documented APIs
3. Email publishing is directly measurable (opens, clicks) — feeds Milestone 7 analytics earliest
4. CBB Auctions and exotic car dealers both have existing email lists, making it immediately valuable

Analytics retrieval (open rates, engagement, clicks) is Milestone 7. Learning from execution outcomes is Milestone 8. Paid media and white-label publishing surfaces are not on the current roadmap.

---

### End-to-End Flow in Milestone 6

```
RecommendationApproved
→ PublishCampaign job (high queue)
→ [one PublishContent job per ContentAsset]
→ ChannelPublisherRegistry::for($channelType)
→ LogChannelPublisher::publish()    ← writes to 'publishing' log channel
    → ChannelRenderer::render()     ← platform payload (real transformation)
    → Log::channel('publishing')    ← no real API call
    → ExecutionResult (synthetic)
→ Execution (completed)
→ ExecutionAttempt (logged)
→ [all Executions complete]
→ Campaign (published)
→ CampaignPublished event
```

In production before a real publisher exists, `LogChannelPublisher` is registered for all channel types. When `EmailPublisher` is added, it is registered for `email` only — all other types remain on `LogChannelPublisher` until their real publishers are implemented.

---

## 1. Publisher Interface

The `ChannelPublisher` is the top-level contract for publishing a single `ContentAsset` to its target channel. Every supported channel type has exactly one `ChannelPublisher` implementation.

```php
interface ChannelPublisher
{
    /**
     * Publish the content asset described by the given Execution.
     * Returns an ExecutionResult describing the platform response.
     * Throws PublishingException on unrecoverable failure.
     * Throws RateLimitException on transient platform throttling.
     */
    public function publish(Execution $execution): ExecutionResult;

    /**
     * Returns true if this publisher handles the given channel type.
     */
    public function supports(string $channelType): bool;

    /**
     * Verifies that the publisher can reach the platform with the given credentials.
     * Used for health checks and credential validation — does not publish anything.
     */
    public function ping(ChannelCredentials $credentials): PingResult;
}
```

**`ExecutionResult`** is a readonly value object returned on successful publish:

```php
readonly class ExecutionResult
{
    public function __construct(
        public string $platformId,      // platform-assigned post/message/thread ID
        public ?string $url,            // canonical URL to the published content, if available
        public \DateTimeImmutable $publishedAt,
        public array $metadata,         // channel-specific supplemental data
    ) {}
}
```

**`PingResult`** indicates whether credentials are valid and the platform is reachable:

```php
readonly class PingResult
{
    public function __construct(
        public bool $reachable,
        public ?string $error,
    ) {}
}
```

### Publisher Registry

Publishers are registered centrally and resolved by channel type, following the same pattern as `ConnectorRegistry`:

```php
class ChannelPublisherRegistry
{
    /** @var list<ChannelPublisher> */
    private array $publishers = [];

    public function register(ChannelPublisher $publisher): void;
    public function for(string $channelType): ChannelPublisher;  // throws UnknownChannelException
}
```

The registry is bound as a singleton in a `PublisherServiceProvider`. Each publisher is registered by the provider on boot.

---

## 2. ChannelRenderer vs ChannelPublisher

These are two distinct responsibilities in the publishing pipeline, kept separate for testability and correctness.

### ChannelRenderer — Content Transformation (no API calls)

A `ChannelRenderer` transforms a `ContentAsset` into a `PlatformPayload` — the platform-specific data structure the API expects. It has no knowledge of credentials, HTTP clients, or API endpoints. It is a pure data transformation layer.

```php
interface ChannelRenderer
{
    /**
     * Transform a ContentAsset into a platform-ready payload.
     * No API calls. No credentials. Pure transformation.
     */
    public function render(ContentAsset $asset, Channel $channel): PlatformPayload;

    public function supports(string $channelType): bool;
}
```

**`PlatformPayload`** is a readonly VO:

```php
readonly class PlatformPayload
{
    public function __construct(
        public string $channelType,
        public array $data,         // platform-specific field map (varies by channel)
    ) {}
}
```

Each channel type has a corresponding `ChannelRenderer` implementation:

| Channel type | Renderer | Output fields |
|--------------|----------|---------------|
| `instagram` | `InstagramRenderer` | `image_url`, `caption`, `hashtags` |
| `facebook` | `FacebookRenderer` | `message`, `link`, `link_description` |
| `linkedin` | `LinkedInRenderer` | `commentary`, `visibility`, `content` |
| `x` | `XRenderer` | `text` (under 280 chars), `media_ids` |
| `email` | `EmailRenderer` | `subject`, `preview_text`, `html_body`, `list_id` |
| `sms` | `SmsRenderer` | `body` (under 160 chars), `opt_out_suffix` |
| `blog` | `BlogRenderer` | `title`, `body` (Markdown), `meta_description`, `slug` |
| `landing_page` | `LandingPageRenderer` | `headline`, `sub_headline`, `sections`, `cta_label`, `cta_url` |

`ChannelRenderer` implementations can be unit-tested without credentials, mocked API clients, or network access.

### ChannelPublisher — API Execution (credentials required)

A `ChannelPublisher` is the component that actually calls the platform API. It:

1. Retrieves the company's decrypted `ChannelCredentials` for this channel
2. Calls its `ChannelRenderer` to produce a `PlatformPayload`
3. Calls the platform API
4. Returns a `ExecutionResult` on success, throws on failure

The `ChannelPublisher` wraps the `ChannelRenderer`. It is integration-tested, not unit-tested, because it requires real credentials (or a mock HTTP client).

**Separation benefit:** A render failure (malformed content) can be detected and surfaced before an API call is ever attempted. A publish failure (API down, rate limit) does not affect the rendered payload — it can be retried with the same render output.

### In the Execution Flow

```
PublishContent job
    → ChannelPublisherRegistry::for($channelType)
        → ChannelPublisher::publish($execution)
            → ChannelCredentialsRepository::for($company, $channelType)
            → ChannelRenderer::render($contentAsset, $channel)  ← PlatformPayload
            → Platform API call
            → ExecutionResult
```

---

## 3. Execution Model

An `Execution` represents a single attempt to publish a specific `ContentAsset` to a specific `Channel`. One `ContentAsset` → one `Execution`.

### Schema

```sql
executions
    id              char(26)    PK (ULID)
    company_id      char(26)    FK companies.id
    campaign_id     char(26)    FK campaigns.id
    content_asset_id char(26)   FK content_assets.id (unique — one execution per asset)
    channel_id      char(26)    FK channels.id
    status          enum        queued | executing | completed | failed | cancelled
    scheduled_at    timestamp   nullable — null means "publish immediately"
    executed_at     timestamp   nullable — set when status transitions to executing
    completed_at    timestamp   nullable — set on completed or final failed
    attempts        smallint    default 0
    last_error      text        nullable — message from last failed attempt
    idempotency_key char(26)    unique — ULID assigned at creation, never changes
    result          json        nullable — ExecutionResult data on success
    created_at      timestamp
    updated_at      timestamp
```

### Eloquent Model

```php
class Execution extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'campaign_id', 'content_asset_id', 'channel_id',
        'status', 'scheduled_at', 'executed_at', 'completed_at',
        'attempts', 'last_error', 'idempotency_key', 'result',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'scheduled_at'  => 'datetime',
        'executed_at'   => 'datetime',
        'completed_at'  => 'datetime',
        'result'        => 'array',
    ];
}
```

### Creation

`Execution` records are created by `ExecutionService::queueForCampaign(Campaign $campaign)`, called immediately after `ApprovalService::approve()` transitions the Recommendation. One record is created per `ContentAsset` in the campaign:

```php
foreach ($campaign->contentAssets as $asset) {
    Execution::create([
        'company_id'       => $campaign->company_id,
        'campaign_id'      => $campaign->id,
        'content_asset_id' => $asset->id,
        'channel_id'       => $asset->channel_id,
        'status'           => 'queued',
        'scheduled_at'     => $asset->scheduled_at,  // null = immediate
        'idempotency_key'  => Str::ulid()->toString(),
    ]);
}
```

---

## 4. Execution Status Lifecycle

```
queued → executing → completed
                   ↘ failed (retryable — increments attempts, re-queues after backoff)
                   ↘ failed (non-retryable — stays failed, notifies user)
queued → cancelled  (user-initiated before execution starts)
executing → cancelled  (not allowed — execution in flight cannot be cancelled)
```

### Status Descriptions

| Status | Meaning |
|--------|---------|
| `queued` | Execution is waiting for its `scheduled_at` window to open (or for immediate dispatch) |
| `executing` | A `PublishContent` job has picked it up and is calling the platform API |
| `completed` | Platform accepted the content; `result` populated with `platform_id`, `url`, `published_at` |
| `failed` | Platform rejected or was unreachable; `last_error` populated; `attempts` incremented |
| `cancelled` | Execution was cancelled before it ran; never published |

### Campaign Status Transitions

| Condition | Campaign transition |
|-----------|---------------------|
| All Executions reach `completed` | `approved` → `published` |
| Any Execution reaches `failed` (non-retryable) | Campaign remains `approved`; user notified |
| User explicitly cancels all Executions | `approved` → `cancelled` |

### ContentAsset Status Transitions

| Event | ContentAsset transition |
|-------|-------------------------|
| Execution created (`queued`) | `approved` → `scheduled` |
| Execution reaches `completed` | `scheduled` → `published` |
| Execution reaches `failed` (final) | `scheduled` → `approved` (reverts; available for retry) |
| Execution cancelled | `scheduled` → `approved` |

---

## 5. Scheduling

### Immediate vs. Scheduled Publishing

By default, publishing is immediate: `scheduled_at = null` on the `Execution` means "publish as soon as the job runs." This is the default for the MVP.

A non-null `scheduled_at` means the `Execution` should not publish before that timestamp. The `PublishScheduledContent` job enforces this:

```php
class PublishScheduledContent implements ShouldQueue
{
    // maintenance queue; runs every 5 minutes via scheduler
    public function handle(): void
    {
        Execution::withoutGlobalScopes()
            ->where('status', 'queued')
            ->where(function ($q) {
                $q->whereNull('scheduled_at')
                  ->orWhere('scheduled_at', '<=', now());
            })
            ->each(fn ($execution) => PublishContent::dispatch($execution)->onQueue('high'));
    }
}
```

The Laravel scheduler entry:

```php
$schedule->job(PublishScheduledContent::class)->everyFiveMinutes();
```

### Scheduling Constraints

- An `Execution` is never dispatched before its `scheduled_at`, regardless of queue backlog.
- `scheduled_at` is set at Execution creation from `ContentAsset.scheduled_at`. Users may update `ContentAsset.scheduled_at` before the Execution is dispatched — this updates the corresponding `Execution.scheduled_at`.
- Once an Execution reaches `executing`, `scheduled_at` cannot be changed.
- Time zone: all `scheduled_at` values are stored in UTC; the UI displays local time per the company's configured timezone.

### Queue Priority

Publishing jobs run on the `high` queue to ensure prompt dispatch after approval. The `high` queue has dedicated workers and is not shared with observation or maintenance workloads.

---

## 6. Retry Strategy

Not all failures are equal. The retry strategy distinguishes transient failures (retry) from permanent failures (give up and notify).

### Retryable Failures

| Failure type | Example | Behaviour |
|-------------|---------|-----------|
| Rate limit | HTTP 429 from platform API | Retry after `Retry-After` header or exponential backoff |
| Network timeout | Connection reset, DNS failure | Retry with backoff |
| Platform 5xx | Instagram API 503 | Retry with backoff |
| Token expired | OAuth access token stale | Refresh token, then retry |

### Non-Retryable Failures

| Failure type | Example | Behaviour |
|-------------|---------|-----------|
| Content policy violation | Platform rejected post for prohibited content | Mark `failed`, notify user, do not retry |
| Authentication failure | Invalid credentials, revoked access | Mark `failed`, flag channel as `error`, notify user |
| Content asset missing | `content_asset_id` record deleted | Mark `failed`, log error, do not retry |
| Invalid payload | Renderer produced malformed payload | Mark `failed`, log error, do not retry |

### Backoff Schedule

Retryable failures use exponential backoff:

| Attempt | Delay |
|---------|-------|
| 1 | 60 seconds |
| 2 | 300 seconds (5 minutes) |
| 3 | 900 seconds (15 minutes) |
| 4+ | Job fails permanently; `Execution.status → failed` |

Maximum 3 retry attempts. After the third failure, the Execution is marked `failed` and `ExecutionFailed` fires.

### Implementation

`PublishContent` implements the delay logic via Laravel's `backoff()` method:

```php
class PublishContent implements ShouldQueue
{
    public int $tries = 4;   // 1 attempt + 3 retries

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function failed(\Throwable $e): void
    {
        ExecutionService::markFailed($this->execution, $e->getMessage(), retryable: false);
    }
}
```

Non-retryable `PublishingException` variants should call `$this->fail($e)` immediately rather than exhausting retries.

---

## 7. Idempotency

Platform APIs are not always idempotent. A retry after a partial failure may result in duplicate posts. Atlas prevents this with two layers of protection.

### Layer 1: Idempotency Key per Execution

Every `Execution` is assigned a unique `idempotency_key` (ULID) at creation. This key is:

- Sent as a request header or parameter when the platform API supports it (e.g., Mailchimp's `idempotency_key`, some ad APIs)
- Stored in `Execution.idempotency_key` and never changed across retries
- Used by Atlas to detect a "successfully published but result not recorded" scenario

### Layer 2: Status Check Before Re-attempt

Before calling the platform API on a retry, `PublishContent` checks whether the Execution has already completed (e.g., a previous attempt succeeded but the response was lost due to a job crash):

```php
$execution = Execution::withoutGlobalScopes()->findOrFail($this->execution->id);

if ($execution->status === 'completed') {
    // Already published — do nothing
    return;
}

if ($execution->status === 'cancelled') {
    // Cancelled while in queue — skip
    return;
}
```

### Layer 3: Platform-Side Deduplication

Where the platform supports idempotency keys (email providers, some CMS APIs), Atlas includes `Execution.idempotency_key` in the API request. If the platform has already processed this key, it returns the original result without creating a duplicate.

For platforms that do not support idempotency keys (Instagram Graph API, most social APIs), Atlas relies on Layer 1 and Layer 2 only. A duplicate post is possible in the narrow window between a successful API call and job result recording. This is an accepted risk for the MVP; resolution is platform-dependent.

---

## 8. Provider Abstraction

Channel publishers are resolved through a registry pattern that decouples the execution engine from platform-specific implementations.

### PublisherServiceProvider

```php
class PublisherServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $registry = $this->app->make(ChannelPublisherRegistry::class);

        $registry->register($this->app->make(InstagramPublisher::class));
        $registry->register($this->app->make(FacebookPublisher::class));
        $registry->register($this->app->make(LinkedInPublisher::class));
        $registry->register($this->app->make(XPublisher::class));
        $registry->register($this->app->make(EmailPublisher::class));
        $registry->register($this->app->make(SmsPublisher::class));
        $registry->register($this->app->make(BlogPublisher::class));
        $registry->register($this->app->make(LandingPagePublisher::class));
    }
}
```

### Channel-Level Provider Selection

Some channels support multiple providers. For example, email can be sent via Mailchimp, Klaviyo, or Postmark. The active provider is resolved from the company's channel configuration:

```php
class EmailPublisher implements ChannelPublisher
{
    public function __construct(
        private readonly EmailProviderRegistry $providers,
    ) {}

    public function publish(Execution $execution): ExecutionResult
    {
        $credentials = ChannelCredentialsRepository::for(
            $execution->company_id, 'email'
        );

        $provider = $this->providers->for($credentials->providerType);
        $renderer = new EmailRenderer();
        $payload  = $renderer->render($execution->contentAsset, $execution->channel);

        return $provider->send($payload, $credentials);
    }
}
```

**Email provider types:** `mailchimp`, `klaviyo`, `postmark`  
**SMS provider types:** `twilio`, `vonage`  
**Social platforms:** each has its own API; no sub-provider registry needed

### Swapping Providers

Swapping a company's email provider from Mailchimp to Klaviyo requires:

1. The company updates their channel credentials (new provider type + API key)
2. No code changes — the `EmailPublisher` resolves the provider from credentials at runtime

Swapping a channel type implementation (e.g., replacing `InstagramPublisher` with a different Instagram client) requires updating the `PublisherServiceProvider`. No other code changes.

---

## 9. Provider Credentials

### Storage

Channel credentials are stored in a dedicated `channel_credentials` table, encrypted at rest using Laravel's `encrypted` cast:

```sql
channel_credentials
    id              char(26)    PK (ULID)
    company_id      char(26)    FK companies.id
    channel_type    enum        facebook | instagram | linkedin | x | email | sms | blog | landing_page
    provider_type   varchar     e.g., 'mailchimp', 'twilio', 'instagram_graph' (nullable for channels with single provider)
    credentials     text        encrypted JSON — never stored in plaintext
    status          enum        active | expired | error | revoked
    expires_at      timestamp   nullable — for OAuth tokens with expiry
    last_used_at    timestamp   nullable
    created_at      timestamp
    updated_at      timestamp

    UNIQUE (company_id, channel_type)
```

The `credentials` column stores a JSON object encrypted via Laravel's `Crypt::encryptString()`. The structure varies by channel type:

**OAuth-based (Instagram, Facebook, LinkedIn, X):**
```json
{
    "access_token": "...",
    "refresh_token": "...",
    "page_id": "...",
    "token_expires_at": "ISO 8601"
}
```

**API key-based (Mailchimp, Postmark):**
```json
{
    "api_key": "...",
    "list_id": "...",
    "from_email": "...",
    "from_name": "..."
}
```

**SMS (Twilio):**
```json
{
    "account_sid": "...",
    "auth_token": "...",
    "from_number": "+1..."
}
```

### Credential Access

Credentials are never returned to the frontend. They are decrypted server-side, used within the `ChannelPublisher`, and then discarded. Decrypted credentials must not be logged or serialized.

```php
class ChannelCredentialsRepository
{
    public static function for(string $companyId, string $channelType): ChannelCredentials;
    // throws CredentialsNotFoundException if no record exists
    // throws CredentialsExpiredException if status = 'expired' and no refresh available
}
```

### OAuth Token Refresh

For channels using OAuth, `ChannelPublisher` implementations must refresh expired access tokens before calling the API:

```php
if ($credentials->isExpired()) {
    $credentials = $this->refreshToken($credentials);
    ChannelCredentialsRepository::update($credentials);  // persist refreshed token
}
```

If the refresh fails, the `ChannelCredentials.status` is set to `expired` and `CredentialsExpiredException` is thrown. The `PublishContent` job catches this as a non-retryable failure and notifies the user.

### Credential Setup Flow

Before a company can publish to any channel, they must complete the credential setup flow for that channel. This is a UI concern (Milestone 6 frontend task) but the publishing engine enforces it: `ChannelCredentialsRepository::for()` throws `CredentialsNotFoundException` if no credentials exist, and the `Execution` is marked `failed` (non-retryable) with a user-readable error.

---

## 10. Provider Health Checks

Health checks verify that a publisher can reach its platform before a batch of `PublishContent` jobs is dispatched.

### Pre-Dispatch Check

`ExecutionService::queueForCampaign()` runs a health check for each unique channel type in the campaign before creating `Execution` records. If a channel is unhealthy, its `Execution` records are created in `failed` status immediately and the user is notified before the jobs are dispatched.

```php
foreach ($channelTypes as $channelType) {
    $publisher   = $this->registry->for($channelType);
    $credentials = ChannelCredentialsRepository::for($campaign->company_id, $channelType);
    $pingResult  = $publisher->ping($credentials);

    if (! $pingResult->reachable) {
        // Create Execution as failed; skip dispatching PublishContent for this channel type
    }
}
```

### Scheduled Health Check

A `CheckChannelHealth` job runs on the `maintenance` queue every 30 minutes. It pings each active `ChannelCredentials` record and updates the `status` field:

| Ping result | Status set |
|-------------|-----------|
| Reachable | `active` |
| Token expired | `expired` (triggers refresh attempt) |
| Auth failure | `error` |
| Platform unreachable | no change (transient) |

This allows the UI to show a per-channel health indicator without waiting for a publish attempt.

### Circuit Breaker

If a platform has failed 3+ times consecutively for any company in the past 15 minutes, `ChannelPublisherRegistry` opens a circuit for that channel type and all pending `PublishContent` jobs for that channel type are deferred by 15 minutes. This prevents a brief platform outage from burning all retry attempts.

The circuit breaker state is stored in Redis:

```php
$key = "circuit:publisher:{$channelType}";
// int: consecutive failure count; TTL: 15 minutes
```

---

## 11. Failure Handling

### PublishingException Hierarchy

```
PublishingException (base, retryable by default)
├── RateLimitException          — retryable; respect Retry-After header
├── NetworkException            — retryable
├── PlatformUnavailableException — retryable
├── TokenExpiredException       — retryable if refresh succeeds; non-retryable if refresh fails
├── ContentPolicyViolationException — non-retryable
├── AuthenticationException     — non-retryable
├── CredentialsNotFoundException — non-retryable
├── MalformedPayloadException   — non-retryable (render-time failure)
└── UnknownChannelException     — non-retryable (no publisher registered)
```

### Failure Path

When a `PublishContent` job exhausts its retries or catches a non-retryable exception:

1. `Execution.status → failed`
2. `Execution.last_error → exception message`
3. `Execution.completed_at → now()`
4. `ContentAsset.status → approved` (reverts from `scheduled` — available for retry)
5. `ExecutionFailed` event fires
6. `NotifyPublishingFailure` listener dispatches an in-app notification to the company's `owner` and `admin` members

### User-Visible Error Messages

`PublishingException` subclasses carry a user-readable `userMessage()` method. The notification surfaces this message without exposing internal stack traces or API error codes.

| Exception | User message |
|-----------|-------------|
| `ContentPolicyViolationException` | "Instagram rejected this post for policy reasons. Review the content and edit before retrying." |
| `AuthenticationException` | "Your Instagram connection has expired. Reconnect your account to continue publishing." |
| `RateLimitException` | "Instagram is temporarily limiting new posts. Retrying automatically — no action needed." |

---

## 12. Audit Logging

Every publish attempt — successful or failed — is durably logged. The audit log is an immutable record of what was attempted, when, and what the platform responded.

### Execution Record as Audit Log

The `Execution` record is the primary audit artifact. Its columns form the log entry:

| Column | Audit value |
|--------|------------|
| `attempts` | Number of times publishing was attempted |
| `executed_at` | Timestamp of most recent attempt |
| `completed_at` | Timestamp of final outcome |
| `last_error` | Error message from most recent failure |
| `result` | Platform response on success: `platform_id`, `url`, `published_at` |
| `idempotency_key` | Stable reference across all attempts for this Execution |

### Execution Attempt Log

For detailed per-attempt audit trails, each attempt appends to an `execution_attempts` table:

```sql
execution_attempts
    id              char(26)    PK (ULID)
    execution_id    char(26)    FK executions.id
    attempt_number  smallint
    attempted_at    timestamp
    status          enum        completed | failed
    error           text        nullable
    response        json        nullable — raw platform response (success) or error body (failure)
    created_at      timestamp
```

This table is append-only. No updates, no deletes. It is the audit trail for regulatory and support purposes.

### Structured Logging

In addition to the database record, every publish attempt emits a structured log entry via `Log::channel('publishing')`:

```json
{
    "event": "publishing.attempt",
    "execution_id": "01HZ...",
    "company_id": "01HY...",
    "campaign_id": "01HX...",
    "channel_type": "instagram",
    "attempt": 1,
    "status": "failed",
    "error": "Rate limit exceeded",
    "duration_ms": 1234,
    "timestamp": "2026-06-26T14:32:01Z"
}
```

The `publishing` log channel routes to a separate log file or external aggregator (Papertrail, Datadog) so publishing logs can be searched independently of application logs.

---

## 13. Rollback Behavior

After a successful publish, the published content may need to be removed. Rollback behavior varies by channel type.

### Rollback Coverage by Channel

| Channel type | Rollback possible? | Method |
|-------------|-------------------|--------|
| `instagram` | Yes | Delete post via Instagram Graph API using `platform_id` |
| `facebook` | Yes | Delete post via Facebook Graph API using `platform_id` |
| `linkedin` | Yes | Delete post via LinkedIn Share API using `platform_id` |
| `x` | Yes | Delete tweet via X API v2 using `platform_id` |
| `email` | No | Cannot recall a sent email; warn user explicitly |
| `sms` | No | Cannot recall a sent SMS; warn user explicitly |
| `blog` | Yes (if via API) | Unpublish or delete via CMS API |
| `landing_page` | Yes (if via API) | Unpublish or delete via CMS/hosted page API |

### Rollback Interface

```php
interface SupportsRollback
{
    /**
     * Remove the published content from the platform.
     * Only called when Execution.status = 'completed' and result.platform_id is present.
     */
    public function rollback(Execution $execution): RollbackResult;
}
```

`ChannelPublisher` implementations that support rollback implement `SupportsRollback`. Publishers that do not (email, SMS) do not implement it.

### Rollback Execution

When a user requests rollback of a published campaign:

1. `RollbackService::rollback(Campaign $campaign)` is called
2. For each `completed` Execution, it checks `$publisher instanceof SupportsRollback`
3. If supported: calls `$publisher->rollback($execution)`; records outcome in `Execution.result`
4. If not supported: marks as unrollable; surfaces a warning to the user ("Email sends cannot be recalled")
5. On success: `ContentAsset.status → archived`; `Execution.status` remains `completed` (it was published — the rollback is a separate event)

### Rollback Limitations

- Rollback is a best-effort operation. If the platform API returns an error on deletion (the post was already removed, the account was disconnected), the failure is logged but the Atlas record is still updated to `archived`.
- No automatic rollback occurs. Rollback is always explicitly triggered by a user action.
- There is no rollback for campaigns in `published` status where some channels are rollable and others (email, SMS) are not. The user must acknowledge the limitation before proceeding.

---

## 14. Multi-Channel Orchestration

A campaign may publish to N channels simultaneously. Each channel publishes independently — one failure does not block the others.

### Dispatch Pattern

`PublishCampaign` (triggered by `RecommendationApproved`) dispatches one `PublishContent` job per `ContentAsset`:

```php
class PublishCampaign implements ShouldQueue
{
    // high queue; 1 try (orchestration only — no platform calls)
    public function handle(ExecutionService $service): void
    {
        $campaign  = Campaign::withoutGlobalScopes()->findOrFail($this->campaign->id);
        $executions = $service->queueForCampaign($campaign);

        foreach ($executions as $execution) {
            if ($execution->status === 'queued') {
                // Immediate: dispatch now; scheduler handles scheduled_at
                if ($execution->scheduled_at === null) {
                    PublishContent::dispatch($execution)->onQueue('high');
                }
                // Scheduled: PublishScheduledContent job picks it up at the right time
            }
        }
    }
}
```

### Independent Channel Execution

Each `PublishContent` job is independent:

- No shared lock across channels
- A `failed` Execution on Instagram does not affect the `email` Execution
- Jobs may complete in any order

### Campaign Completion Detection

After each `PublishContent` job completes, `ExecutionService::checkCampaignCompletion($campaign)` is called:

```php
public function checkCampaignCompletion(Campaign $campaign): void
{
    $executions = Execution::withoutGlobalScopes()
        ->where('campaign_id', $campaign->id)
        ->get();

    $allComplete = $executions->every(
        fn ($e) => in_array($e->status, ['completed', 'failed', 'cancelled'])
    );

    if (! $allComplete) {
        return;
    }

    $anyCompleted = $executions->contains(fn ($e) => $e->status === 'completed');

    if ($anyCompleted) {
        $campaign->update(['status' => 'published', 'published_at' => now()]);
        CampaignPublished::dispatch($campaign);
    } else {
        // All failed or cancelled — campaign never published
        $campaign->update(['status' => 'cancelled']);
    }
}
```

### Ordering and Priority

Within a campaign, channels publish in priority order where platform rate limits or sequencing requirements exist. `channel_strategy.priority` from the `CampaignBlueprint` is advisory:

- Priority 1 channels are dispatched first
- Priority 2 and 3 channels are dispatched after a short delay (5 seconds each) to avoid hitting platform rate limits simultaneously

This is implemented via `PublishContent::dispatch($execution)->delay(seconds: $priority * 5)`.

---

## 15. Acceptance Criteria

All criteria are verifiable by automated tests. No criterion requires a live platform API — tests use `FakeChannelPublisher` (mirrors `FakeAiProvider` pattern).

### Execution Creation

- [ ] `RecommendationApproved` event triggers `PublishCampaign` job
- [ ] `PublishCampaign` creates one `Execution` per `ContentAsset` in the campaign
- [ ] Each `Execution` has a unique `idempotency_key` assigned at creation
- [ ] `Execution.status = 'queued'` immediately after creation
- [ ] `ContentAsset.status` transitions from `approved` → `scheduled` when `Execution` is created

### Publishing Flow

- [ ] `PublishContent` job calls `ChannelPublisher::publish($execution)` exactly once per attempt
- [ ] `Execution.status` transitions `queued → executing → completed` on success
- [ ] `Execution.result` is populated with `platform_id`, `url`, `published_at` on success
- [ ] `ContentAsset.status` transitions `scheduled → published` when `Execution` completes
- [ ] `Campaign.status` transitions `approved → published` when all `Executions` complete

### Scheduling

- [ ] `Execution` with `scheduled_at = null` is dispatched immediately
- [ ] `Execution` with `scheduled_at` in the future is not dispatched until `scheduled_at` has passed
- [ ] `PublishScheduledContent` job only dispatches `Executions` whose `scheduled_at ≤ now()`

### Retry

- [ ] `RateLimitException` causes retry with backoff (60s → 300s → 900s)
- [ ] `ContentPolicyViolationException` fails immediately without retry
- [ ] After 3 failed retries, `Execution.status = 'failed'` and `ExecutionFailed` fires
- [ ] `ContentAsset.status` reverts from `scheduled → approved` when `Execution` reaches final `failed`

### Idempotency

- [ ] `PublishContent` skips publishing if `Execution.status = 'completed'` at job start
- [ ] `Execution.idempotency_key` is identical across all retry attempts for the same Execution

### Audit

- [ ] Every attempt creates an `ExecutionAttempt` record with `attempt_number`, `attempted_at`, `status`, `error`
- [ ] `Execution.attempts` is incremented on each attempt
- [ ] `Execution.last_error` contains the error message from the most recent failure

### Multi-Channel

- [ ] A failure on one channel's Execution does not affect other channels in the same campaign
- [ ] `Campaign.status = 'published'` only when at least one Execution reaches `completed`
- [ ] `Campaign.status = 'cancelled'` only when all Executions are `failed` or `cancelled`

### Rollback

- [ ] `RollbackService::rollback()` calls `ChannelPublisher::rollback()` for channels implementing `SupportsRollback`
- [ ] Rollback skips channels not implementing `SupportsRollback` and reports them as unrollable
- [ ] `ContentAsset.status → archived` after successful rollback

### No Live API in Tests

- [ ] All publishing engine tests use `FakeChannelPublisher`; no test calls a real platform API
- [ ] `FakeChannelPublisher` supports `queueResult(ExecutionResult)`, `queueFailure(PublishingException)`, `assertPublished()`, `assertNotPublished()`

---

## 16. Future Extensibility

### Per-Company Optimal Send Time

In Milestone 7+, Atlas accumulates engagement data per channel. Learning records per company will identify when their audience is most active. A future `OptimalSendTimeResolver` will set `scheduled_at` automatically based on historical engagement peaks, replacing the current "publish immediately" default.

### Webhook-Based Publish Confirmation

Some platforms (email providers in particular) offer webhook callbacks to confirm delivery. A future `PublishingWebhookController` will receive these callbacks and enrich `Execution.result` with delivery confirmations without polling the API.

### Multi-Wave Campaign Publishing

The current model publishes all `ContentAssets` at once. A future multi-wave model (teaser → feature → close) will associate each wave with a time offset from the initial publish. The `Execution` table is extended with a `wave` column; `PublishScheduledContent` dispatches each wave at the configured offset.

### Paid Media (Ads)

Paid media requires budget management, bid configuration, and audience targeting — concerns beyond the current publishing model. When implemented, a `PaidMediaPublisher` implementing `ChannelPublisher` would handle ad creation, but the `ExecutionService` orchestration layer remains unchanged. The `channel_type` enum is extended with `facebook_ad`, `instagram_ad`, etc.

### Approval-to-Publish Delay

Some companies will want a mandatory review window between approval and publishing. A future company-level setting `publish_delay_hours` would add a minimum `scheduled_at` offset to all Executions. No code changes to the publisher layer — `scheduled_at` absorbs the offset.

### A/B Publish Timing Tests

A future `AbPublishTimingService` could dispatch two versions of a campaign at different times and measure which produces higher engagement. The `Execution` table accommodates this via the `idempotency_key` — each variant gets its own Execution with its own key.

### Publisher Credential Rotation

Platform tokens expire. A future `CredentialRotationService` will proactively refresh OAuth tokens before they expire using a `maintenance` queue job, keeping `ChannelCredentials.status = active` without requiring a failed publish to trigger a refresh.
