# AI

Atlas uses AI at four points in the loop: extracting Facts from raw Observations, synthesizing Knowledge from Facts, generating Decision rationale, and producing Campaign content and strategy. All four paths go through the same abstraction layer.

This document defines that abstraction and the six MVP Analysts built on top of it. Read `docs/technical/Architecture.md` for how Analysts fit into the broader module structure.

---

## Core Rules

These are non-negotiable. They apply everywhere, always.

1. **Only Analysts call `AiProvider`.** No controller, job, listener, model, or service class outside `app/Services/Analyst/` calls `AiProvider` directly.
2. **All machine-consumed AI output uses structured JSON.** Never parse free-form prose for data that will be persisted or used programmatically. Use provider JSON mode or tool/function calling.
3. **Every AI-produced record stores the prompt name and version** that generated it. This enables audit and regression testing when prompts change.
4. **AI calls run on the `ai` queue.** Jobs that call Analysts go on the `ai` queue. Analysts are never called synchronously from a controller or listener.
5. **Failed AI calls retry with exponential backoff**, max 3 attempts, before the job is sent to the failed queue.
6. **Prompt text never interpolates unsanitized external content.** Crawled HTML or user input passed into a prompt must be stripped of control characters and length-capped before inclusion.

---

## AI Provider Abstraction

### AiProvider Interface

```php
// app/AI/Contracts/AiProvider.php

namespace App\AI\Contracts;

interface AiProvider
{
    public function complete(Prompt $prompt): AiResponse;
}
```

`complete()` is the only method the domain layer cares about. It takes a typed `Prompt` and returns a typed `AiResponse`. The caller never sees the provider SDK.

### AiResponse

```php
// app/AI/AiResponse.php

namespace App\AI;

readonly class AiResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly int    $inputTokens,
        public readonly int    $outputTokens,
    ) {}
}
```

`content` is always a JSON string when the prompt specifies a schema. The parser validates it before any Analyst touches the data.

### Concrete Providers

```
app/AI/Providers/
├── AnthropicProvider.php
└── OpenAiProvider.php
```

**Binding** — The active provider is bound in `AppServiceProvider`:

```php
$this->app->bind(
    \App\AI\Contracts\AiProvider::class,
    config('atlas.ai.provider'), // e.g., App\AI\Providers\AnthropicProvider::class
);
```

Swapping providers is a config change. No application code references a provider class directly.

### AnthropicProvider

Anthropic does not have a JSON mode. Structured output is achieved via **tool use**: define a tool with the expected JSON Schema as its `input_schema`, then force the model to call it with `tool_choice`.

```php
// app/AI/Providers/AnthropicProvider.php

class AnthropicProvider implements AiProvider
{
    public function __construct(private \Anthropic\Client $client) {}

    public function complete(Prompt $prompt): AiResponse
    {
        $params = [
            'model'      => config('atlas.ai.anthropic.model', 'claude-sonnet-4-6'),
            'max_tokens' => $prompt->maxTokens(),
            'system'     => $prompt->system(),
            'messages'   => [['role' => 'user', 'content' => $prompt->user()]],
        ];

        if ($schema = $prompt->schema()) {
            $params['tools'] = [[
                'name'         => 'output',
                'description'  => 'Return the result in this exact format.',
                'input_schema' => $schema,
            ]];
            $params['tool_choice'] = ['type' => 'tool', 'name' => 'output'];
        }

        $response = $this->client->messages()->create($params);

        $content = $prompt->schema()
            ? json_encode($response->content[0]->input)
            : $response->content[0]->text;

        return new AiResponse(
            content:      $content,
            model:        $response->model,
            inputTokens:  $response->usage->inputTokens,
            outputTokens: $response->usage->outputTokens,
        );
    }
}
```

### OpenAiProvider

OpenAI supports JSON mode via `response_format`. When a schema is present, use `json_schema` response format.

```php
// app/AI/Providers/OpenAiProvider.php

class OpenAiProvider implements AiProvider
{
    public function __construct(private \OpenAI\Client $client) {}

    public function complete(Prompt $prompt): AiResponse
    {
        $params = [
            'model'    => config('atlas.ai.openai.model', 'gpt-4o'),
            'messages' => [
                ['role' => 'system', 'content' => $prompt->system()],
                ['role' => 'user',   'content' => $prompt->user()],
            ],
            'max_tokens' => $prompt->maxTokens(),
        ];

        if ($schema = $prompt->schema()) {
            $params['response_format'] = [
                'type'        => 'json_schema',
                'json_schema' => ['name' => 'output', 'schema' => $schema, 'strict' => true],
            ];
        }

        $response = $this->client->chat()->create($params);

        return new AiResponse(
            content:      $response->choices[0]->message->content,
            model:        $response->model,
            inputTokens:  $response->usage->promptTokens,
            outputTokens: $response->usage->completionTokens,
        );
    }
}
```

---

## Prompt Class Design

Every AI call is defined by a typed `Prompt` class. There are no anonymous or ad-hoc prompts anywhere in the codebase.

### Abstract Base Class

```php
// app/AI/Prompts/Prompt.php

namespace App\AI\Prompts;

abstract class Prompt
{
    /** The system-level instruction for the model. */
    abstract public function system(): string;

    /** The user turn — the actual task, with context interpolated. */
    abstract public function user(): string;

    /**
     * JSON Schema for structured output.
     * Return null for free-form text responses (rare — only for non-persisted output).
     */
    public function schema(): ?array
    {
        return null;
    }

    /** Sampling temperature. Lower = more deterministic. Default suits most analytical tasks. */
    public function temperature(): float
    {
        return 0.2;
    }

    /** Max tokens in the response. Override per prompt as needed. */
    public function maxTokens(): int
    {
        return 2048;
    }

    /**
     * Prompt version. Bump this when the prompt changes meaningfully.
     * Stored on AI-produced records for audit and regression.
     */
    public function version(): string
    {
        return '1.0';
    }

    /** Canonical prompt name. Used for logging and stored on AI-produced records. */
    public function name(): string
    {
        return class_basename(static::class);
    }
}
```

### Prompt Conventions

**System prompt** — Define the AI's role, constraints, and output expectations. Should be stable across calls. Keep it focused.

**User prompt** — Inject the specific task context: the Business Brain snapshot, the Observation payload, the catalog item in question. This is where the per-call variability lives.

**Context injection** — Use Blade views or heredocs for complex user prompts. Do not build prompts with string concatenation. Blade is preferred for prompts longer than a few lines:

```php
public function user(): string
{
    return view('ai.prompts.fact-extraction', [
        'company'     => $this->brain->company,
        'catalog'     => $this->brain->catalog,
        'observation' => $this->observation->toContextArray(),
    ])->render();
}
```

Prompt Blade views live in `resources/views/ai/prompts/`. They are version-controlled and reviewed like code.

**Length caps** — Observations and catalog metadata passed into prompts must be capped. Truncate raw HTML to 8,000 characters. Truncate catalog item lists to the top 20 by relevance score before including in context.

---

## Prompt Versioning

### Why versioning matters

A prompt change can silently change the behavior of every Analyst that uses it. Without versioning, there is no way to determine which prompt version produced a given Fact, Knowledge entry, or Decision rationale. This makes debugging and regression testing impractical.

### How it works

1. Each Prompt class has a `version()` method returning a semver string.
2. When an Analyst produces a record (Fact, Knowledge, Decision rationale, ContentAsset), it stores `prompt_name` and `prompt_version` on that record.
3. These fields are added to the relevant tables:

```php
// In facts, knowledge_entries, decisions, content_assets migrations:
$table->string('prompt_name')->nullable();
$table->string('prompt_version')->nullable();
```

4. When a prompt changes in a way that would change output (not just formatting), the `version()` return value is bumped. A changelog entry is added to the prompt class as a comment.

### Versioning rules

| Change type | Version bump | Example |
|-------------|-------------|---------|
| Rephrase for clarity, same intent | None | Fixing a typo |
| Changed instructions or constraints | Minor (1.0 → 1.1) | Adding a new output field |
| Changed schema or fundamental approach | Major (1.x → 2.0) | Restructuring the output format |

When a major version is introduced, keep the previous prompt class as `{Name}V1Prompt.php` until existing records produced by it are no longer referenced.

---

## Structured JSON Output

### Requirement

Every Analyst that produces domain data (Facts, Knowledge, Opportunities, Campaign strategy, Content) must use structured JSON output. The provider must be instructed to return JSON conforming to a defined schema. The response must be validated against that schema before the Analyst processes it.

Unstructured text responses are only acceptable for non-persisted, human-facing output (e.g., a one-off summary). In the MVP, all Analysts produce persisted records — all use structured output.

### JSON Schema classes

Each structured prompt has a companion schema class:

```php
// app/AI/Schemas/FactExtractionSchema.php

namespace App\AI\Schemas;

class FactExtractionSchema
{
    public static function definition(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['facts'],
            'properties' => [
                'facts' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'required'   => ['key', 'value', 'data_type', 'confidence'],
                        'properties' => [
                            'key'        => ['type' => 'string'],
                            'value'      => {},                    // any JSON type
                            'data_type'  => ['type' => 'string', 'enum' => ['integer', 'float', 'string', 'boolean', 'json']],
                            'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                            'rationale'  => ['type' => 'string'], // optional; why this fact was extracted
                        ],
                    ],
                ],
            ],
        ];
    }
}
```

The schema class is referenced in the Prompt:

```php
public function schema(): array
{
    return FactExtractionSchema::definition();
}
```

---

## Response Validation

`StructuredResponseParser` validates the provider response against the schema before returning data to the Analyst.

```php
// app/AI/StructuredResponseParser.php

namespace App\AI;

use App\AI\Exceptions\MalformedAiResponseException;
use App\AI\Exceptions\AiResponseValidationException;
use JsonSchema\Validator;

class StructuredResponseParser
{
    /**
     * @throws MalformedAiResponseException
     * @throws AiResponseValidationException
     */
    public static function parse(AiResponse $response, string $schemaClass): array
    {
        $data = json_decode($response->content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new MalformedAiResponseException(
                "AI response is not valid JSON: {$response->content}"
            );
        }

        $validator = new Validator();
        $validator->validate($data, $schemaClass::definition());

        if (!$validator->isValid()) {
            $errors = array_map(fn($e) => $e['message'], $validator->getErrors());
            throw new AiResponseValidationException(implode('; ', $errors));
        }

        return $data;
    }
}
```

### Exception handling

- `MalformedAiResponseException` — the provider returned invalid JSON. Log and retry.
- `AiResponseValidationException` — the JSON does not match the schema. Log the response and prompt version. Do not retry — the prompt needs investigation.

Both exceptions are caught in the Analyst's calling Job. The Job logs the exception with the prompt name, version, and company ID, then either retries or fails gracefully.

---

## Analyst Architecture

### Interface

```php
// app/Services/Analyst/Contracts/Analyst.php

namespace App\Services\Analyst\Contracts;

interface Analyst
{
    // Concrete analysts define typed analyze() methods.
    // This interface is a marker for binding and testing.
}
```

PHP lacks generics, so the interface does not enforce the `analyze()` signature. Each concrete Analyst defines its own typed method. The interface exists for service container binding and `instanceof` checks.

### Internal Anatomy

Every Analyst follows the same five-step pattern:

```
1. Receive typed domain input
2. Assemble context (BusinessBrain snapshot or subset)
3. Instantiate the typed Prompt with context
4. Call AiProvider::complete($prompt) → AiResponse
5. Parse + validate via StructuredResponseParser
6. Hydrate and persist domain objects
7. Fire domain events
8. Return typed result
```

### Base class

```php
// app/Services/Analyst/BaseAnalyst.php

namespace App\Services\Analyst;

use App\AI\Contracts\AiProvider;
use App\AI\StructuredResponseParser;

abstract class BaseAnalyst
{
    public function __construct(protected AiProvider $ai) {}

    protected function call(Prompt $prompt, string $schemaClass): array
    {
        $response = $this->ai->complete($prompt);
        return StructuredResponseParser::parse($response, $schemaClass);
    }
}
```

All Analysts extend `BaseAnalyst`. The `call()` helper wraps the complete → parse flow.

---

## MVP Analysts

### 1. FactExtractionAnalyst

**Input:** `Observation`, `BusinessBrain`
**Output:** `Collection<Fact>`
**Queue:** `ai`
**Triggered by:** `ObservationProcessed` event → `ExtractFacts` job

**Purpose:** Given a raw observation (crawled HTML, JSON feed, API response), extract discrete, machine-readable Facts about the business. Facts are typed key-value pairs: `catalog.active_item_count = 47`, `brand.tone = "enthusiastic"`, `catalog.most_expensive_item_price = 485000`.

**System prompt summary:**
> You are a data analyst extracting structured facts from raw business data. Extract only facts that are clearly stated or directly inferable. Do not speculate. Each fact must have a key (dot-namespaced), a typed value, a data_type, and a confidence score.

**Schema:** `FactExtractionSchema` — `{facts: [{key, value, data_type, confidence, rationale?}]}`

**Post-processing:**
- Check if a current Fact with the same `key` already exists for this company
- If identical: skip
- If different: set old Fact `is_current = false`, `superseded_by_id = new_id`
- Fire `FactExtracted` for each new Fact

---

### 2. KnowledgeSynthesisAnalyst

**Input:** `Company`, `BusinessBrain` (with recent Facts)
**Output:** `Collection<Knowledge>`
**Queue:** `ai`
**Triggered by:** `FactExtracted` event (batched via `SynthesizeKnowledge` job after N new Facts or time threshold)

**Purpose:** Derive patterns, insights, and strategic observations from the Business Brain's current Facts. Knowledge is higher-order — it describes what the Facts mean, not just what they are. Example: Facts say "no campaign in 14 days" + "email open rate dropped 12%." Knowledge says "this company is at risk of audience disengagement — a re-engagement campaign is overdue."

**System prompt summary:**
> You are a senior marketing analyst reviewing data about a business. Identify patterns, insights, risks, and opportunities from the provided facts. Express each as a clear, actionable observation that a marketing professional would find valuable.

**Schema:** `KnowledgeSynthesisSchema` — `{knowledge: [{type, subject, body, structured, confidence}]}`

Where `type` is one of: `pattern`, `insight`, `preference`, `performance`, `context`.

**Post-processing:**
- Set `is_active = false` on existing Knowledge entries with the same `(company_id, type, subject)` before persisting new ones
- Store `source_fact_ids` as the IDs of Facts included in the prompt context
- Fire `KnowledgeSynthesized` for each new Knowledge entry

---

### 3. OpportunityDetectionAnalyst

**Input:** `Company`, `BusinessBrain`
**Output:** `array` of scored Opportunity candidates (not persisted; passed to `OpportunityScorer`)
**Queue:** `ai`
**Triggered by:** `DetectOpportunities` scheduled job (after `KnowledgeSynthesized` or on schedule)

**Purpose:** Identify marketing opportunities that rule-based detectors might miss — seasonal relevance, long-tail inventory patterns, audience timing, competitive gaps. Rule-based `OpportunityDetector` classes handle obvious patterns (item unsold for N days, auction ending soon). This Analyst handles nuanced, context-dependent opportunities.

**System prompt summary:**
> You are a marketing strategist reviewing a business's current state. Identify marketing opportunities — moments where a campaign would be timely, relevant, and likely to perform. For each opportunity, explain what makes now the right moment.

**Schema:** `OpportunityDetectionSchema` — `{opportunities: [{type, title, description, subject_type?, subject_id?, urgency_rationale}]}`

**Post-processing:**
- Each candidate is passed through `OpportunityScorer::score()` to get numeric scores
- Duplicates (same `company_id`, `type`, `subject_type`, `subject_id`, `status = open`) are skipped
- Survivors are persisted as `Opportunity` records with `status = open`
- Fire `OpportunityDetected` for each persisted Opportunity

**Note:** This Analyst supplements, not replaces, the rule-based `OpportunityDetector` classes. Rule-based detection runs first (faster, cheaper, deterministic). This Analyst runs afterward to catch what rules miss.

---

### 4. RationaleGenerationAnalyst

**Input:** `Opportunity`, `Decision` (partially formed — campaign_type and channel_ids already set), `BusinessBrain`
**Output:** `array` — `{why_now, why_this, why_channel, why_works, expected_impact}`
**Queue:** `ai`
**Triggered by:** `DecisionEngine::commit()` → called inline before `Decision` is persisted

**Purpose:** Generate the four-part rationale that Atlas presents to the user with every Recommendation. This is the explainability requirement: every Decision must answer why now, why this, why this channel, and why Atlas expects it to work. The rationale is also what feeds user trust — opaque output gets rejected.

**System prompt summary:**
> You are the chief marketing strategist for this business. You've decided to run a campaign. Explain your decision clearly and persuasively in four parts. Be specific — reference actual data about the business, catalog, or history. Avoid generic marketing language.

**Schema:** `RationaleSchema`

```php
public static function definition(): array
{
    return [
        'type'       => 'object',
        'required'   => ['why_now', 'why_this', 'why_channel', 'why_works', 'expected_impact'],
        'properties' => [
            'why_now'         => ['type' => 'string', 'maxLength' => 300],
            'why_this'        => ['type' => 'string', 'maxLength' => 300],
            'why_channel'     => ['type' => 'string', 'maxLength' => 300],
            'why_works'       => ['type' => 'string', 'maxLength' => 300],
            'expected_impact' => [
                'type'       => 'object',
                'required'   => ['summary'],
                'properties' => [
                    'summary'           => ['type' => 'string'],
                    'reach_estimate'    => ['type' => 'string', 'nullable' => true],
                    'engagement_signal' => ['type' => 'string', 'nullable' => true],
                ],
            ],
        ],
    ];
}
```

**Validation:** All five keys are required. If any is empty or missing, the Decision is not committed and a `RationaleGenerationFailedException` is thrown. Do not persist a Decision without a complete rationale.

**Temperature:** 0.4 — slightly higher than other Analysts to allow more natural language in the rationale while remaining grounded.

---

### 5. CampaignPreparationAnalyst

**Input:** `Decision`, `BusinessBrain`
**Output:** `array` — campaign strategy fields
**Queue:** `ai`
**Triggered by:** `DecisionCommitted` event → `PrepareCampaign` job

**Purpose:** Translate a committed Decision into a full campaign brief: the angle, the positioning, the target audience, the call to action, and the suggested schedule. This is the strategic layer — the "what are we doing and why" — before the Content Analyst generates the actual post copy or email body.

**System prompt summary:**
> You are a marketing strategist preparing a campaign brief. You have a clear objective. Define the campaign angle, target audience, positioning, call to action, and suggested schedule. Be specific and actionable.

**Schema:** `CampaignPreparationSchema`

```php
[
    'type'       => 'object',
    'required'   => ['title', 'strategy', 'target_audience', 'positioning', 'call_to_action', 'schedule'],
    'properties' => [
        'title'          => ['type' => 'string', 'maxLength' => 120],
        'strategy'       => ['type' => 'string'],          // the angle and approach
        'target_audience'=> ['type' => 'string'],
        'positioning'    => ['type' => 'string'],
        'call_to_action' => ['type' => 'string', 'maxLength' => 80],
        'schedule'       => [
            'type'       => 'object',
            'properties' => [
                'suggested_start' => ['type' => 'string'],   // relative: "immediately", "Monday"
                'duration_days'   => ['type' => 'integer'],
                'cadence'         => ['type' => 'string'],   // "daily", "3x per week", etc.
            ],
        ],
    ],
]
```

**Post-processing:** Persist a `Campaign` record from the output. Do not attempt to parse `schedule.suggested_start` into a timestamp in this step — resolve it to an actual `scheduled_start_at` timestamp in the `CampaignPreparationService` using business context.

---

### 6. ContentGenerationAnalyst

**Input:** `Campaign`, `Channel`, `BusinessBrain`
**Output:** `array` — content asset fields for the given channel
**Queue:** `ai`
**Triggered by:** `CampaignPrepared` event → `GenerateContent` job (one per Channel)

**Purpose:** Generate the actual publishable content for a specific Channel. The output varies by channel type: a social post needs body copy and hashtags; an email needs a subject line, preview text, and HTML body; SMS needs a short message under 160 characters. The Channel type drives which prompt variant and schema are used.

**Channel-aware prompt selection:**

```php
class ContentGenerationAnalyst extends BaseAnalyst
{
    public function analyze(Campaign $campaign, Channel $channel, BusinessBrain $brain): array
    {
        $prompt = match ($channel->type) {
            'instagram', 'facebook', 'x', 'linkedin' => new SocialContentPrompt($campaign, $channel, $brain),
            'email'                                   => new EmailContentPrompt($campaign, $channel, $brain),
            'sms'                                     => new SmsContentPrompt($campaign, $channel, $brain),
            default                                   => throw new UnsupportedChannelException($channel->type),
        };

        $schema = match ($channel->type) {
            'instagram', 'facebook', 'x', 'linkedin' => SocialContentSchema::class,
            'email'                                   => EmailContentSchema::class,
            'sms'                                     => SmsContentSchema::class,
        };

        return $this->call($prompt, $schema);
    }
}
```

**Social content schema:**
```php
[
    'type'       => 'object',
    'required'   => ['body'],
    'properties' => [
        'body'        => ['type' => 'string', 'maxLength' => 2200],
        'hashtags'    => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 10],
        'alt_text'    => ['type' => 'string', 'nullable' => true],
        'notes'       => ['type' => 'string', 'nullable' => true], // for reviewer
    ],
]
```

**Email content schema:**
```php
[
    'type'       => 'object',
    'required'   => ['subject_line', 'preview_text', 'body_html'],
    'properties' => [
        'subject_line' => ['type' => 'string', 'maxLength' => 80],
        'preview_text' => ['type' => 'string', 'maxLength' => 140],
        'body_html'    => ['type' => 'string'],
        'body_plain'   => ['type' => 'string'],
    ],
]
```

**SMS content schema:**
```php
[
    'type'       => 'object',
    'required'   => ['body'],
    'properties' => [
        'body'            => ['type' => 'string', 'maxLength' => 160],
        'character_count' => ['type' => 'integer'],
    ],
]
```

**Post-processing:** Persist one `ContentAsset` record per Channel. Store `prompt_name` and `prompt_version` on each record.

---

## Embedding Abstraction

Embeddings are not in scope for MVP but the abstraction must be in place before any feature that needs them is built.

### EmbeddingService Interface

```php
// app/AI/Contracts/EmbeddingService.php

namespace App\AI\Contracts;

interface EmbeddingService
{
    /**
     * Generate a vector embedding for the given text.
     *
     * @return float[] Vector of floats; dimension count depends on model.
     */
    public function embed(string $text, ?string $model = null): array;

    /**
     * Find the most similar stored vectors to the given vector.
     * Returns records with a similarity score.
     */
    public function similar(array $vector, string $collection, int $limit = 10): \Illuminate\Support\Collection;
}
```

### PgvectorEmbeddingService

The MVP-era implementation stores embeddings in PostgreSQL via `pgvector`. It is not wired up until the first feature that requires it.

```php
// app/AI/Embeddings/PgvectorEmbeddingService.php

class PgvectorEmbeddingService implements EmbeddingService
{
    public function embed(string $text, ?string $model = null): array
    {
        // Calls AiProvider::embed() (added to AiProvider interface when needed)
        // Stores result in the appropriate embeddings table
    }

    public function similar(array $vector, string $collection, int $limit = 10): Collection
    {
        // Queries the collection table using pgvector cosine similarity:
        // ORDER BY embedding <=> '[...]' LIMIT $limit
    }
}
```

**Collections** map to embedding tables defined in `docs/technical/Database.md`:
- `catalog_items` → `catalog_item_embeddings`
- `knowledge_entries` → (table to be defined when needed)

**Do not add `embed()` to `AiProvider`** until the first Analyst or service that requires it is being built. Adding it now would require both provider implementations to stub it.

---

## Testing AI Services

### Core principle

Tests never call a real AI provider. Every test that exercises an Analyst uses a `FakeAiProvider` that returns pre-defined fixture responses.

### FakeAiProvider

```php
// app/AI/Testing/FakeAiProvider.php

namespace App\AI\Testing;

use App\AI\AiResponse;
use App\AI\Contracts\AiProvider;
use App\AI\Prompts\Prompt;

class FakeAiProvider implements AiProvider
{
    private array $queue = [];
    private array $recorded = [];

    public function queueResponse(string $jsonContent): static
    {
        $this->queue[] = $jsonContent;
        return $this;
    }

    public function queueFixture(string $name): static
    {
        $path = base_path("tests/Fixtures/AI/{$name}.json");
        $this->queue[] = file_get_contents($path);
        return $this;
    }

    public function complete(Prompt $prompt): AiResponse
    {
        if (empty($this->queue)) {
            throw new \RuntimeException(
                'FakeAiProvider has no queued response. Call queueResponse() or queueFixture() first.'
            );
        }

        $content = array_shift($this->queue);
        $this->recorded[] = ['prompt' => $prompt, 'response' => $content];

        return new AiResponse(
            content:      $content,
            model:        'fake-model',
            inputTokens:  100,
            outputTokens: 200,
        );
    }

    /** Assert a prompt of the given class was sent. */
    public function assertPromptSent(string $promptClass): void
    {
        $sent = array_filter($this->recorded, fn($r) => $r['prompt'] instanceof $promptClass);
        \PHPUnit\Framework\Assert::assertNotEmpty($sent, "Expected prompt [{$promptClass}] was not sent.");
    }

    public function recordedPrompts(): array
    {
        return $this->recorded;
    }
}
```

### Fixture files

Store fixture JSON responses in `tests/Fixtures/AI/`. Name them `{AnalystName}-{scenario}.json`:

```
tests/Fixtures/AI/
├── FactExtractionAnalyst-vehicle-inventory.json
├── FactExtractionAnalyst-comic-auction.json
├── KnowledgeSynthesisAnalyst-low-activity.json
├── RationaleGenerationAnalyst-featured-vehicle.json
└── ContentGenerationAnalyst-instagram-featured-item.json
```

Fixtures are real provider responses captured from manual testing runs. They are committed to the repository and reviewed when prompts change.

### Binding in tests

Bind `FakeAiProvider` in the test's `setUp()` or via a test-specific service provider:

```php
class FactExtractionAnalystTest extends TestCase
{
    private FakeAiProvider $fakeAi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAi = new FakeAiProvider();
        $this->app->instance(AiProvider::class, $this->fakeAi);
    }

    public function test_extracts_facts_from_vehicle_inventory_observation(): void
    {
        $this->fakeAi->queueFixture('FactExtractionAnalyst-vehicle-inventory');

        $company     = Company::factory()->create();
        $observation = Observation::factory()->for($company)->create();
        $brain       = app(BusinessBrainService::class)->for($company);

        $analyst = app(FactExtractionAnalyst::class);
        $facts   = $analyst->analyze($observation, $brain);

        $this->assertNotEmpty($facts);
        $this->assertTrue($facts->every(fn($f) => $f->is_current));
        $this->fakeAi->assertPromptSent(FactExtractionPrompt::class);
    }
}
```

### What to test

| Test | What it verifies |
|------|-----------------|
| Analyst with valid fixture | Facts/Knowledge/etc. are persisted with correct fields |
| Analyst with malformed JSON fixture | `MalformedAiResponseException` is thrown |
| Analyst with schema-invalid fixture | `AiResponseValidationException` is thrown |
| Prompt rendering | `system()` and `user()` contain expected context values |
| Schema validation | `StructuredResponseParser::parse()` rejects invalid shapes |
| Supersession logic | A second call with updated Facts correctly archives the old ones |

### Never in tests

- Do not make real HTTP calls to AI providers in unit or feature tests.
- Do not use `Http::fake()` for AI calls — bind `FakeAiProvider` instead.
- Do not assert on AI response content in tests that use fixtures — assert on the domain objects that were created.
