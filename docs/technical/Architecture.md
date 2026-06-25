# Architecture

Atlas is a Laravel monolith organized around domain modules. Each module owns its services, jobs, events, and listeners. Controllers are thin. AI is behind an abstraction. Business logic lives in domain services — not controllers, jobs, or models.

This document defines the system structure. Read `specs/core/domain-model.md` for entity definitions and `docs/technical/Database.md` for schema strategy.

---

## System Modules

| Module             | Responsibility                                                              |
|--------------------|-----------------------------------------------------------------------------|
| **Observatory**    | Integrations, crawls, feed syncs. Produces Observations.                    |
| **Analyst**        | AI-powered extraction. Turns Observations into Facts and Facts into Knowledge.|
| **Brain**          | Assembles the Business Brain. Manages the Digital Twin's health and state.  |
| **Opportunity**    | Scans the Business Brain. Detects and scores marketing Opportunities.        |
| **Decision**       | Selects Opportunities. Commits Decisions. Generates rationale.              |
| **Campaign**       | Prepares campaign strategy from a committed Decision.                        |
| **Content**        | Generates channel-specific Content Assets for a Campaign.                   |
| **Approval**       | Manages the human approval workflow. Records user responses.                |
| **Execution**      | Schedules and publishes approved Content Assets.                             |
| **Learning**       | Captures signals from approvals and execution results. Updates the twin.    |

These modules are not Laravel packages or separate services — they are namespaces within a single Laravel application. Module boundaries are enforced by convention and code review, not by the runtime.

---

## Application Structure

```
app/
├── Models/                          # Eloquent models — relationships and casts only
│
├── Services/
│   ├── Brain/
│   │   └── BusinessBrainService.php
│   ├── Observatory/
│   │   ├── ObservationService.php
│   │   └── Connectors/              # One connector class per integration type
│   │       ├── Contracts/
│   │       │   └── Connector.php
│   │       ├── ConnectorRegistry.php
│   │       ├── WebsiteCrawlConnector.php
│   │       ├── RssFeedConnector.php
│   │       └── ApiConnector.php
│   ├── Analyst/                     # One analyst class per analysis task
│   │   ├── Contracts/
│   │   │   └── Analyst.php
│   │   ├── FactExtractionAnalyst.php
│   │   ├── KnowledgeSynthesisAnalyst.php
│   │   └── OpportunityDetectionAnalyst.php
│   ├── Opportunity/
│   │   ├── OpportunityEngine.php
│   │   ├── OpportunityScorer.php
│   │   └── Detectors/               # One detector per opportunity type
│   │       ├── Contracts/
│   │       │   └── OpportunityDetector.php
│   │       ├── FeaturedItemDetector.php
│   │       ├── UrgencyDetector.php
│   │       └── ReEngagementDetector.php
│   ├── Decision/
│   │   ├── DecisionEngine.php
│   │   └── RationaleGenerator.php
│   ├── Campaign/
│   │   └── CampaignPreparationService.php
│   ├── Content/
│   │   └── ContentGenerationService.php
│   ├── Approval/
│   │   └── ApprovalService.php
│   ├── Execution/
│   │   └── ExecutionService.php
│   └── Learning/
│       └── LearningService.php
│
├── AI/
│   ├── Contracts/
│   │   └── AiProvider.php           # The only interface external code touches
│   ├── Providers/
│   │   ├── AnthropicProvider.php
│   │   └── OpenAiProvider.php
│   ├── Prompts/                     # One prompt class per task; versioned
│   │   ├── FactExtractionPrompt.php
│   │   ├── KnowledgeSynthesisPrompt.php
│   │   ├── RationaleGenerationPrompt.php
│   │   └── ContentGenerationPrompt.php
│   └── Responses/                   # Structured output parsers
│       └── StructuredResponseParser.php
│
├── Jobs/                            # Orchestration only — no business logic
│   ├── SyncIntegration.php
│   ├── ProcessObservation.php
│   ├── SynthesizeKnowledge.php
│   ├── DetectOpportunities.php
│   ├── CommitDecision.php
│   ├── PrepareCampaign.php
│   ├── GenerateContent.php
│   └── ApplyLearnings.php
│
├── Events/                          # Past-tense domain events
├── Listeners/                       # Call one service method; return
│
├── Http/
│   ├── Controllers/                 # Thin — validate input, call service, return response
│   ├── Requests/
│   └── Resources/
│
└── Console/
    └── Commands/
```

---

## Layered Architecture

Atlas uses four layers. The dependency rule: outer layers depend on inner layers. Inner layers never depend on outer layers.

```
┌─────────────────────────────────────┐
│         Presentation Layer          │  HTTP controllers, Console commands,
│    app/Http  ·  app/Console         │  Inertia pages, API resources
└───────────────┬─────────────────────┘
                │ calls
┌───────────────▼─────────────────────┐
│         Application Layer           │  Jobs, Listeners, Application services
│    app/Jobs  ·  app/Listeners       │  Orchestration — no business logic
└───────────────┬─────────────────────┘
                │ calls
┌───────────────▼─────────────────────┐
│           Domain Layer              │  Domain services, Business Brain,
│    app/Services  ·  app/Models      │  Opportunity/Decision/Content logic
└───────────────┬─────────────────────┘
                │ calls
┌───────────────▼─────────────────────┐
│       Infrastructure Layer          │  AI providers, HTTP clients,
│    app/AI  ·  Storage  ·  Queues    │  Connectors, object storage
└─────────────────────────────────────┘
```

### What belongs in each layer

**Presentation** — Receives HTTP requests. Validates input via Form Requests. Calls one service or dispatches one job. Returns a response. No business logic.

**Application** — Jobs and Listeners. Orchestrates calls to domain services. A Job should read like a sequence of service calls: `$this->service->doX(); $this->service->doY();`. If a Job is making business decisions, that logic belongs in a service.

**Domain** — Where the real work happens. Service classes in `app/Services/` own all business rules. They are framework-aware (can use Eloquent, events, caches) but not HTTP-aware.

**Infrastructure** — Everything that talks to the outside world. AI providers, HTTP crawlers, RSS parsers, S3 clients. All external dependencies are wrapped here behind interfaces. The domain layer calls interfaces, not concrete implementations.

---

## Event-Driven Workflow

The Atlas loop is driven by domain events. Each step fires an event; listeners respond by dispatching a job. Jobs call domain services. Services fire the next event.

```
[Scheduler]
    └── SyncIntegration (Job)
            └── Connector::sync()
                    └── Observation created
                            └── ObservationRecorded (Event)
                                    └── ProcessObservation (Job)
                                            └── FactExtractionAnalyst::analyze()
                                                    └── Facts created
                                                            └── FactExtracted (Event)
                                                                    └── SynthesizeKnowledge (Job)
                                                                            └── KnowledgeSynthesisAnalyst::analyze()
                                                                                    └── Knowledge created
                                                                                            └── KnowledgeSynthesized (Event)

[Scheduler]
    └── DetectOpportunities (Job, per company)
            └── OpportunityEngine::scan()
                    └── Opportunities scored and persisted
                            └── OpportunityDetected (Event)

[Scheduler or event-triggered]
    └── CommitDecision (Job, per company)
            └── DecisionEngine::evaluate()
                    └── Decision committed
                            └── DecisionCommitted (Event)
                                    └── PrepareCampaign (Job)
                                            └── CampaignPreparationService::prepare()
                                                    └── Campaign + ContentAssets created
                                                            └── CampaignPrepared (Event)
                                                                    └── Recommendation surfaced

[User action: Approve]
    └── ApprovalService::approve()
            └── RecommendationApproved (Event)
                    └── ExecuteCampaign (Job)
                            └── ExecutionService::execute()
                                    └── ExecutionCompleted (Event)
                                            └── LearningService::record()
                                                    └── LearningRecorded (Event)

[User action: Reject]
    └── ApprovalService::reject()
            └── RecommendationRejected (Event)
                    └── LearningService::record()
```

### Event rules

- Events are past-tense, noun-first: `ObservationRecorded`, not `RecordObservation`.
- One listener per event per concern. If two things need to happen when an event fires, use two listeners.
- Listeners are thin: validate preconditions, dispatch a job or call one service method.
- Events carry the entity ID, not the full entity. Listeners reload from the database.

---

## Queue Topology

Four queues, each with a dedicated worker configuration:

| Queue           | Purpose                                    | Priority | Timeout |
|-----------------|--------------------------------------------|----------|---------|
| `high`          | User-facing notifications, approval events | Highest  | 30s     |
| `ai`            | All AI API calls (rate-limited)            | High     | 120s    |
| `default`       | Standard jobs (campaigns, content prep)    | Normal   | 60s     |
| `observations`  | Integration syncs, crawl processing        | Low      | 300s    |
| `maintenance`   | Prune jobs, health score recalculation     | Lowest   | 600s    |

### Worker configuration (Supervisor)

```ini
# High-priority worker — always responsive
[program:atlas-high]
command=php artisan queue:work --queue=high --sleep=1 --tries=3

# AI worker — single process to respect rate limits
[program:atlas-ai]
command=php artisan queue:work --queue=ai --sleep=3 --tries=3 --backoff=60

# Standard worker — scale horizontally
[program:atlas-default]
command=php artisan queue:work --queue=default,observations --sleep=3 --tries=3

# Maintenance worker — one process, off-peak
[program:atlas-maintenance]
command=php artisan queue:work --queue=maintenance --sleep=10 --tries=1
```

### Job assignment

| Job                   | Queue          |
|-----------------------|----------------|
| `SyncIntegration`     | `observations` |
| `ProcessObservation`  | `observations` |
| `SynthesizeKnowledge` | `ai`           |
| `DetectOpportunities` | `default`      |
| `CommitDecision`      | `ai`           |
| `PrepareCampaign`     | `ai`           |
| `GenerateContent`     | `ai`           |
| `ExecuteCampaign`     | `default`      |
| `ApplyLearnings`      | `maintenance`  |
| Notification dispatch | `high`         |

All jobs implement `ShouldBeUnique` where re-queuing would be harmful (e.g., `DetectOpportunities` and `CommitDecision` are unique per company).

---

## Connector Architecture

A Connector is the bridge between an external data source and an Observation.

### Interface

```php
// app/Services/Observatory/Connectors/Contracts/Connector.php

interface Connector
{
    public function supports(Integration $integration): bool;
    public function sync(Integration $integration): Observation;
}
```

`supports()` is a type check — it returns `true` if this connector handles the given Integration type. `sync()` does the work: fetches data, creates and returns a persisted Observation.

### Registry

`ConnectorRegistry` holds all registered connectors and resolves the right one for an integration:

```php
class ConnectorRegistry
{
    /** @param Connector[] $connectors */
    public function __construct(private array $connectors) {}

    public function resolve(Integration $integration): Connector
    {
        foreach ($this->connectors as $connector) {
            if ($connector->supports($integration)) {
                return $connector;
            }
        }
        throw new UnsupportedIntegrationException($integration->type);
    }
}
```

Connectors are registered in `AppServiceProvider` or a dedicated `ConnectorServiceProvider`.

### Flow

```
Scheduler → SyncIntegration (Job)
    → ConnectorRegistry::resolve($integration)
    → Connector::sync($integration)
        → Fetches raw data (HTTP, RSS, API call)
        → Creates Observation with raw_payload + raw_payload_ref
        → Fires ObservationRecorded
    → Updates Integration.last_run_at, next_run_at
```

---

## Analyst Architecture

An Analyst is an AI-powered service that takes domain input, calls the AI layer, and returns structured domain output. Analysts are the only services that call `AiProvider`.

### Interface

```php
// app/Services/Analyst/Contracts/Analyst.php

interface Analyst
{
    public function analyze(mixed $input, BusinessBrain $brain): mixed;
}
```

The `$input` and return type are defined concretely by each implementation.

### Anatomy of an Analyst

Every Analyst follows the same internal pattern:

```
1. Receive domain input (Observation, Collection<Fact>, etc.)
2. Pull the BusinessBrain context snapshot for this company
3. Build a prompt via a typed Prompt class
4. Call AiProvider::complete($prompt) → raw response
5. Parse and validate the structured JSON response
6. Hydrate and persist domain objects (Facts, Knowledge, etc.)
7. Fire domain events
8. Return results
```

### Example: FactExtractionAnalyst

```php
class FactExtractionAnalyst implements Analyst
{
    public function __construct(
        private AiProvider $ai,
        private BusinessBrainService $brains,
    ) {}

    public function analyze(Observation $observation, BusinessBrain $brain): Collection
    {
        $prompt = new FactExtractionPrompt($observation, $brain);

        $response = $this->ai->complete($prompt);

        $facts = StructuredResponseParser::parse($response, FactExtractionSchema::class);

        return $this->persistFacts($observation, $facts);
    }
}
```

### AI Provider abstraction

```php
// app/AI/Contracts/AiProvider.php

interface AiProvider
{
    public function complete(Prompt $prompt): AiResponse;
    public function embed(string $text): array;
}
```

The active provider is bound in `AppServiceProvider` via the config:

```php
$this->app->bind(AiProvider::class, config('atlas.ai.provider'));
```

Swapping providers is a config change, not a code change.

### Prompts

Each prompt is a typed class. It knows how to render itself into a provider-agnostic format:

```php
abstract class Prompt
{
    abstract public function system(): string;
    abstract public function user(): string;
    public function schema(): ?array { return null; }  // JSON schema for structured output
    public function version(): string { return '1.0'; }
}
```

Prompt versions are tracked in the class. When a prompt changes meaningfully, bump `version()`. The version is stored on the Decision or Knowledge record that was produced, enabling future audit of which prompt produced which output.

---

## Signal → Opportunity → Decision Flow

This is the core intelligence loop. It runs on a schedule and is also triggered by significant state changes (new Observation processed, new high-value catalog item added).

```
┌──────────────────────────────────────────────────────┐
│                   Business Brain                     │
│  activeFacts + activeKnowledge + recentCampaigns     │
│  catalog (active items) + Digital Twin state         │
└──────────────────┬───────────────────────────────────┘
                   │ BusinessBrainService::for(company)
                   ▼
┌──────────────────────────────────────────────────────┐
│               OpportunityEngine::scan()              │
│                                                      │
│  For each registered OpportunityDetector:            │
│    detector->detect(brain) → Opportunity[]           │
│                                                      │
│  For each Opportunity candidate:                     │
│    OpportunityScorer::score(opportunity, brain)      │
│      → relevance_score (0–100)                       │
│      → timing_score    (0–100)                       │
│      → confidence_score(0–100)                       │
│      → urgency_score   (0–100)                       │
│      → composite_score (weighted sum)                │
│                                                      │
│  Persist new Opportunities (status: open)            │
│  Skip duplicates (same subject + type already open)  │
└──────────────────┬───────────────────────────────────┘
                   │ OpportunityDetected event
                   ▼
┌──────────────────────────────────────────────────────┐
│              DecisionEngine::evaluate()              │
│                                                      │
│  Query: open Opportunities, ordered by               │
│    composite_score DESC                              │
│                                                      │
│  For each candidate (highest score first):           │
│    Guard: no open Recommendation of this type        │
│    Guard: no similar campaign in cooldown window     │
│    Guard: sufficient catalog content exists          │
│                                                      │
│  Select first candidate that passes all guards       │
│                                                      │
│  RationaleGenerator::generate(opportunity, brain)    │
│    → AI call → {why_now, why_this,                   │
│                 why_channel, why_works}              │
│                                                      │
│  Persist Decision (status: pending)                  │
│  Mark Opportunity (status: selected)                 │
└──────────────────┬───────────────────────────────────┘
                   │ DecisionCommitted event
                   ▼
┌──────────────────────────────────────────────────────┐
│        CampaignPreparationService::prepare()         │
│                                                      │
│  AI call → campaign strategy, angle, CTA,            │
│            target audience, suggested schedule       │
│                                                      │
│  ContentGenerationService::generate()                │
│    For each selected Channel:                        │
│      AI call → ContentAsset (body, media refs)       │
│                                                      │
│  Persist Campaign + ContentAssets (status: draft)    │
│  Create Recommendation (status: pending)             │
└──────────────────────────────────────────────────────┘
                   │ CampaignPrepared event
                   ▼
            User sees Recommendation
```

---

## Where AI Lives

**All AI interactions go through `app/AI/`.**

| Layer              | Location                        | Role                                             |
|--------------------|---------------------------------|--------------------------------------------------|
| Provider interface | `app/AI/Contracts/AiProvider`   | The only thing the domain layer touches          |
| Providers          | `app/AI/Providers/`             | Concrete implementations (Anthropic, OpenAI)     |
| Prompts            | `app/AI/Prompts/`               | Typed, versioned prompt builders                 |
| Response parsers   | `app/AI/Responses/`             | Validate and deserialize structured JSON output  |
| Callers            | `app/Services/Analyst/`         | Analysts are the only services that call AI      |

**No controller, job, listener, or model calls `AiProvider` directly.** If AI output is needed, it flows through an Analyst service.

**Structured outputs.** All AI responses that produce domain data (Facts, Knowledge, Decisions, Content) must use structured JSON output (provider's JSON mode or tool-use / function-calling). Never parse free-form prose for machine-consumed output.

**Prompt versioning.** Every Prompt class has a `version()` method. Records produced by AI (Knowledge entries, Decision rationales, ContentAssets) store the prompt name and version that produced them. This enables audit and regression testing when prompts change.

---

## Where Business Logic Lives

| Concern                         | Location                          | Not in                            |
|---------------------------------|-----------------------------------|-----------------------------------|
| Opportunity scoring formula     | `OpportunityScorer`               | Job, Controller, Model            |
| Decision guard conditions       | `DecisionEngine`                  | Job, Listener                     |
| Rationale validation (4 keys)   | `DecisionEngine` / `RationaleGenerator` | Model validation        |
| Campaign strategy               | `CampaignPreparationService`      | Controller, Job                   |
| Content generation              | `ContentGenerationService`        | Controller                        |
| Learning signal extraction      | `LearningService`                 | Listener (listener calls service) |
| Business Brain assembly         | `BusinessBrainService`            | Anywhere else                     |
| Tenant isolation enforcement    | Global scope + `CompanyScope`     | Ad-hoc where clauses              |

**Models carry:** Eloquent relationships, casts, scopes, `HasUlids`, `SoftDeletes`. Nothing else.

**Jobs carry:** Input validation, one or two service calls, event dispatch. No `if` statements on business state.

**Controllers carry:** Form Request validation, one service call or job dispatch, resource transformation. No Eloquent queries, no business rules.

---

## How Modules Communicate

Modules communicate through **domain events**, not direct method calls across module boundaries.

```
Observatory  →  fires ObservationRecorded
Analyst      →  listens, processes, fires FactExtracted / KnowledgeSynthesized
Opportunity  →  listens or is scheduled, fires OpportunityDetected
Decision     →  listens or is scheduled, fires DecisionCommitted
Campaign     →  listens, fires CampaignPrepared
Approval     →  fires RecommendationApproved / Rejected
Execution    →  listens, fires ExecutionCompleted
Learning     →  listens to Approved, Rejected, ExecutionCompleted
```

A module should never `new` or inject a service from another module. If module A needs something from module B, it fires an event and module B's listener handles it.

The exception is the Brain module — `BusinessBrainService` is a shared dependency that any module can inject. It is a read-only snapshot assembler, not a writer.

---

## How to Add a New Connector

A Connector adds support for a new integration type (e.g., a new inventory API, a Shopify feed, a custom webhook).

**1. Add the type to the enum**

```php
// In a new migration
$table->enum('type', [
    'website_crawl', 'rss_feed', 'api', 'csv_upload', 'manual', 'shopify', // new
]);
```

**2. Create the Connector class**

```php
// app/Services/Observatory/Connectors/ShopifyConnector.php

class ShopifyConnector implements Connector
{
    public function supports(Integration $integration): bool
    {
        return $integration->type === 'shopify';
    }

    public function sync(Integration $integration): Observation
    {
        // 1. Decrypt $integration->config to get credentials
        // 2. Fetch data from the external source
        // 3. Store raw payload in object storage → get ref
        // 4. Create and return an Observation
        return Observation::create([
            'company_id'        => $integration->company_id,
            'integration_id'    => $integration->id,
            'source_type'       => 'api',
            'source_identifier' => $integration->config['shop_domain'],
            'raw_payload'       => $rawJson,       // will be nulled after processing
            'raw_payload_ref'   => $storageKey,    // retained per retention policy
            'status'            => 'pending',
            'observed_at'       => now(),
        ]);
    }
}
```

**3. Register in the service provider**

```php
// app/Providers/ConnectorServiceProvider.php

$this->app->singleton(ConnectorRegistry::class, fn () => new ConnectorRegistry([
    app(WebsiteCrawlConnector::class),
    app(RssFeedConnector::class),
    app(ApiConnector::class),
    app(ShopifyConnector::class), // add here
]));
```

**4. Write a test**

Mock the HTTP client. Assert that `sync()` creates an Observation with the expected fields. Use a fixture file for the external API response.

That's it. The `SyncIntegration` job, `ConnectorRegistry`, and the rest of the pipeline pick it up automatically.

---

## How to Add a New Analyst

An Analyst adds a new AI-powered analysis capability (e.g., a competitor signal analyst, a seasonal trend analyst).

**1. Create the Prompt class**

```php
// app/AI/Prompts/SeasonalTrendPrompt.php

class SeasonalTrendPrompt extends Prompt
{
    public function __construct(
        private BusinessBrain $brain,
        private string $season,
    ) {}

    public function system(): string
    {
        return "You are a marketing analyst identifying seasonal campaign opportunities...";
    }

    public function user(): string
    {
        return view('prompts.seasonal-trend', [
            'brain'  => $this->brain->toArray(),
            'season' => $this->season,
        ])->render();
    }

    public function schema(): array
    {
        return SeasonalTrendSchema::jsonSchema(); // JSON Schema for structured output
    }

    public function version(): string { return '1.0'; }
}
```

**2. Define the output schema**

```php
// app/AI/Responses/Schemas/SeasonalTrendSchema.php

class SeasonalTrendSchema
{
    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['opportunities', 'reasoning'],
            'properties' => [
                'opportunities' => ['type' => 'array', 'items' => [...]],
                'reasoning'     => ['type' => 'string'],
            ],
        ];
    }
}
```

**3. Create the Analyst class**

```php
// app/Services/Analyst/SeasonalTrendAnalyst.php

class SeasonalTrendAnalyst implements Analyst
{
    public function __construct(
        private AiProvider $ai,
        private BusinessBrainService $brains,
    ) {}

    public function analyze(Company $company, BusinessBrain $brain): Collection
    {
        $season = $this->resolveSeason();
        $prompt = new SeasonalTrendPrompt($brain, $season);
        $response = $this->ai->complete($prompt);
        $result = StructuredResponseParser::parse($response, SeasonalTrendSchema::class);

        return $this->persistOpportunities($company, $result);
    }
}
```

**4. Wire it up**

Decide where it's called: inside `DetectOpportunities` job, or its own scheduled job. Inject it and call it like any other service.

**5. Write a test**

Stub the `AiProvider`. Provide a fixture JSON response matching the schema. Assert that the analyst creates the expected domain objects. Test the schema validation rejects malformed responses.

---

## Vertical Calibration

Atlas is vertical-aware but generic in its core. Vertical-specific behavior is introduced through:

- **Opportunity Detector registration** — which detectors run for which company types
- **Prompt context** — vertical-specific system prompt sections based on `company.industry`
- **Scoring weights** — configurable per vertical in `digital_twins.metadata`
- **Catalog item schema** — defined in `catalogs.item_schema`; no code change required for new metadata fields

When adding support for a new vertical:

1. Define the `item_schema` for that vertical's catalog items
2. Register the relevant `OpportunityDetector` classes for that vertical
3. Extend prompt templates with vertical-specific system context sections
4. Adjust default scoring weights in the vertical's seeder or knowledge pack

No new models, tables, or modules are needed to add a vertical.
