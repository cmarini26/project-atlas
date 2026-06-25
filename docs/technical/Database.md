# Database

PostgreSQL is the primary datastore for Atlas. This document covers schema strategy, data classification, tenancy, indexing, retention, and future storage considerations. Read `specs/core/domain-model.md` for entity definitions and field-level documentation. This document focuses on how the data is stored, organized, and maintained — not what each field means.

---

## Data Classification

Atlas tables fall into three functional categories. The distinction matters for query patterns, retention policy, and future caching or read-replica strategy.

### Operational

Tables that represent the current running state of the platform. Frequently read and written. Source of truth for what exists right now.

| Table                | Description                                      |
|----------------------|--------------------------------------------------|
| `companies`          | Tenant root                                      |
| `users`              | Authentication and identity                      |
| `company_memberships`| User ↔ Company access and roles                  |
| `catalogs`           | Catalog container per company                    |
| `catalog_items`      | Live inventory / product data                    |
| `integrations`       | Configured data source connections               |
| `channels`           | Publishing destinations                          |
| `digital_twins`      | Aggregate health and readiness state per company |

### Knowledge

Tables that represent what Atlas has learned about a business. Append-heavy, rarely updated. Value compounds over time.

| Table               | Description                                          |
|---------------------|------------------------------------------------------|
| `observations`      | Raw snapshots from integrations                      |
| `facts`             | Discrete, structured facts extracted from observations|
| `knowledge_entries` | Higher-order patterns and insights derived from facts|
| `learnings`         | Signals from user actions and campaign outcomes      |

### Decision

Tables that represent the autonomous decision pipeline — from opportunity detection through execution. Written sequentially as the loop progresses. Most rows in this category are effectively immutable once created.

| Table             | Description                                         |
|-------------------|-----------------------------------------------------|
| `opportunities`   | Scored marketing moments                            |
| `decisions`       | Committed choices by the Decision Engine            |
| `recommendations` | User-facing output of decisions                     |
| `campaigns`       | Marketing plans prepared per decision               |
| `content_assets`  | Individual content pieces within a campaign         |
| `approvals`       | User responses to recommendations and content       |
| `executions`      | Publishing records per content asset                |

---

## Multi-Tenancy Strategy

Atlas uses a **shared schema, row-level tenancy** model. All tenant data lives in the same PostgreSQL database, with every table scoped to a `company_id`.

### Why shared schema

Schema-per-tenant (one PostgreSQL schema or database per company) is operationally expensive: it complicates migrations, connection pooling, and cross-company reporting. For Atlas's scale in the MVP phase, shared schema with strict row-level isolation is the right tradeoff.

### Enforcement layers

**Laravel global scope (primary enforcement)**

Every Eloquent model that carries `company_id` must have a `CompanyScope` global scope applied. This ensures no query can accidentally return rows from another company.

```php
// Applied automatically via HasCompanyScope trait
protected static function booted(): void
{
    static::addGlobalScope(new CompanyScope);
}
```

**PostgreSQL Row-Level Security (defense in depth)**

Enable RLS on all tenant tables as a secondary enforcement layer. This ensures the database itself rejects cross-company queries even if the application layer fails to apply a scope.

```sql
ALTER TABLE catalog_items ENABLE ROW LEVEL SECURITY;

CREATE POLICY tenant_isolation ON catalog_items
  USING (company_id = current_setting('app.current_company_id')::ulid);
```

The application sets `app.current_company_id` at the start of each request. This is defense-in-depth, not the primary mechanism — do not rely on it as a substitute for global scopes.

**Never** query across companies without an explicit, audited exception. Aggregate/admin queries that span companies must go through a dedicated service class (`App\Services\Admin\CrossTenantQueryService`) and require an `admin` guard.

### The `company_id` rule

If a table carries data that belongs to a company, it carries `company_id`. No exceptions. If a table is global (e.g., `users`, `channels` with `company_id = null` for system templates), make the intent explicit in a comment and ensure the query path always filters on `company_id IS NOT NULL` or a specific value.

---

## ULID Strategy

All primary keys use ULIDs (`Str::ulid()`). Foreign keys that reference ULIDs are stored as the same type.

### Why ULIDs over UUIDs

| Property        | UUID v4     | ULID                        |
|-----------------|-------------|-----------------------------|
| Sortable        | No          | Yes (time-prefixed)         |
| URL-safe        | With hyphens removed | Yes              |
| Sequential risk | None        | None                        |
| Index fragmentation | High (random) | Low (monotonic within millisecond) |
| Readability     | Low         | Low                         |
| Standard        | RFC 4122    | ULID spec                   |

ULIDs are monotonically increasing within the same millisecond, which reduces B-tree index fragmentation compared to random UUIDs. The time-sortable property also means the natural sort order of `id` approximates creation order — useful for pagination and debugging without an additional `created_at` sort.

### Column type

Store ULIDs as `char(26)` in PostgreSQL. They are always exactly 26 characters. Using `varchar` wastes the variable-length overhead; using PostgreSQL's `uuid` type requires conversion and loses the lexicographic sort property.

```php
// Migration
$table->char('id', 26)->primary();
$table->char('company_id', 26)->index();
```

Laravel's `HasUlids` trait handles generation automatically. Set `$keyType = 'string'` and `$incrementing = false` on all models (the trait does this).

---

## Soft Delete Policy

Not every entity should be soft-deleted. The policy below is intentional.

### Apply `SoftDeletes`

| Table           | Reason                                                          |
|-----------------|-----------------------------------------------------------------|
| `companies`     | Company deactivation should be recoverable                      |
| `catalog_items` | Sold/expired items contain Learning history; don't lose the FK  |
| `campaigns`     | Cancelled campaigns are referenced by Decisions and Learning    |
| `content_assets`| Referenced by Approvals and Executions                          |
| `recommendations`| Rejected recommendations feed Learning; preserve the record   |

### Hard delete on schedule (prunable)

| Table          | Prune condition                                              |
|----------------|--------------------------------------------------------------|
| `observations` | `processed_at` older than 180 days; null `raw_payload` first at 30 days |
| `executions`   | `status = failed` and older than 90 days, after Learning is extracted |

Implement pruning using Laravel's `MassPrunable` trait and the `model:prune` scheduled command.

### Never delete

| Table              | Reason                                                       |
|--------------------|--------------------------------------------------------------|
| `facts`            | Use `is_current = false` to archive; history is intentional  |
| `knowledge_entries`| Use `is_active = false`; historical knowledge informs Learning|
| `decisions`        | Immutable audit record; deletion would break Approval and Learning FKs |
| `approvals`        | Human oversight audit trail; must be preserved               |
| `learnings`        | Compounding value; deletion degrades the Digital Twin        |
| `opportunities`    | Expired opportunities are historically meaningful; mark `expired` |

---

## Indexing Strategy

### Baseline: every foreign key gets an index

All `_id` columns that are FKs should have a single-column index unless they are already covered by a compound index. This is enforced via code review, not automatically.

### Compound indexes by query path

The query paths below drive compound index decisions. Each index is named with its table and purpose.

**`catalog_items`**
```sql
-- Primary listing query: active items for a company
CREATE INDEX idx_catalog_items_company_status ON catalog_items (company_id, status);

-- Feed sync deduplication
CREATE UNIQUE INDEX idx_catalog_items_external_id ON catalog_items (company_id, external_id)
  WHERE external_id IS NOT NULL;

-- Crawl provenance lookup
CREATE INDEX idx_catalog_items_source_url ON catalog_items (company_id, source_url)
  WHERE source_url IS NOT NULL;
```

**`facts`**
```sql
-- Primary Business Brain query: current facts for a company
CREATE INDEX idx_facts_company_key_current ON facts (company_id, key, is_current);
```

**`knowledge_entries`**
```sql
-- Opportunity Engine knowledge scan
CREATE INDEX idx_knowledge_company_type_active ON knowledge_entries (company_id, type, is_active);
```

**`opportunities`**
```sql
-- Decision Engine selection query
CREATE INDEX idx_opportunities_company_status_score ON opportunities (company_id, status, composite_score DESC);

-- Polymorphic subject lookup
CREATE INDEX idx_opportunities_subject ON opportunities (subject_type, subject_id);
```

**`recommendations`**
```sql
-- Dashboard: pending recommendations per company
CREATE INDEX idx_recommendations_company_status ON recommendations (company_id, status);
```

**`observations`**
```sql
-- Processing queue
CREATE INDEX idx_observations_company_status ON observations (company_id, status);

-- Integration history
CREATE INDEX idx_observations_integration_observed ON observations (integration_id, observed_at);
```

**`approvals`**
```sql
-- Polymorphic approvable lookup
CREATE INDEX idx_approvals_approvable ON approvals (approvable_type, approvable_id);
```

**`executions`**
```sql
CREATE INDEX idx_executions_campaign_status ON executions (campaign_id, status);
```

**`learnings`**
```sql
-- Apply job: unapplied learnings per company
CREATE INDEX idx_learnings_company_applied ON learnings (company_id, applied_at)
  WHERE applied_at IS NULL;
```

### Partial indexes

Use partial indexes (PostgreSQL-native) for status-filtered queries to keep index size small. The `WHERE applied_at IS NULL` on `learnings` above is an example. Other candidates:

```sql
-- Only index active integrations for the scheduler
CREATE INDEX idx_integrations_active_next_run ON integrations (next_run_at)
  WHERE status = 'active';

-- Only index pending observations for the processing queue
CREATE INDEX idx_observations_pending ON observations (created_at)
  WHERE status = 'pending';
```

### JSON column indexes (GIN)

For `metadata` on `catalog_items`, add a GIN index if vertical-specific queries against JSON fields become a performance concern. Defer this until there is a concrete query that needs it — GIN indexes are expensive to maintain.

```sql
-- Add only when needed
CREATE INDEX idx_catalog_items_metadata ON catalog_items USING GIN (metadata);
```

---

## Event Sourcing Considerations

Atlas is not a full event-sourced system, but several tables are designed with event-sourcing properties. Understanding the distinction matters when designing migrations and services.

### Tables that behave like event logs

**`observations`** — Every sync creates a new row. Observations are never updated in place after creation (only `status` and `processed_at` change). The table is an append-only log of what was seen and when.

**`facts`** — New facts supersede old ones via `is_current` / `superseded_by_id`. The full chain of facts for a given key is preserved, giving a point-in-time queryable history.

**`learnings`** — Append-only. A Learning is never modified after creation; `applied_at` is set once.

**`decisions`** — Immutable after creation. Status transitions are recorded on the Decision, but the rationale and commitment are write-once.

**`approvals`** — Write-once. An Approval captures a moment of human intent. If a user changes their mind, a new Approval is created (with a reference to the previous one if needed), not an update.

### What this means in practice

- Do not use `UPDATE` on Facts, Learnings, Decisions, or Approvals to change business-meaningful fields. Only `status` and housekeeping timestamps (`applied_at`, `processed_at`) are mutable after creation.
- Do not delete Knowledge or Facts. Archive them with `is_active = false` or `is_current = false`.
- Services that touch these tables should only call `create()`, not `update()` on the business payload.

### Future: formal event log table

If Atlas evolves toward full event sourcing (unlikely in MVP, possible in v2), introduce a dedicated `domain_events` table:

```sql
CREATE TABLE domain_events (
    id         char(26) PRIMARY KEY,
    company_id char(26),
    type       varchar(128),  -- e.g., 'DecisionCommitted', 'RecommendationApproved'
    payload    jsonb,
    occurred_at timestamp with time zone,
    created_at  timestamp with time zone
);
```

This is not planned for MVP. Design services to emit Laravel events now — they can be persisted to this table later without changing the service code.

---

## Vector Storage (Future)

Semantic search and similarity matching will eventually require vector embeddings. This is not in scope for MVP but the schema should not preclude it.

### Planned use cases

- **Catalog item similarity** — "Find items similar to this one" for Opportunity detection
- **Knowledge retrieval** — Semantic search over the Business Brain when building AI context
- **Content deduplication** — Detect if a near-identical campaign has run before
- **Audience matching** — Match catalog items to audience segments using embedding similarity

### Recommended approach: `pgvector`

Use the `pgvector` PostgreSQL extension for MVP-era vector storage. It keeps the stack homogeneous (one database, not a separate vector service) and supports the workloads Atlas will need at early scale.

```sql
CREATE EXTENSION IF NOT EXISTS vector;

-- Example: embeddings for catalog items
CREATE TABLE catalog_item_embeddings (
    id              char(26) PRIMARY KEY,
    catalog_item_id char(26) NOT NULL REFERENCES catalog_items(id) ON DELETE CASCADE,
    company_id      char(26) NOT NULL,
    model           varchar(64),  -- embedding model identifier, e.g., 'text-embedding-3-small'
    dimensions      smallint,
    embedding       vector(1536), -- dimension count matches the model
    created_at      timestamp with time zone
);

CREATE INDEX idx_catalog_item_embeddings_hnsw
  ON catalog_item_embeddings USING hnsw (embedding vector_cosine_ops);
```

### Abstraction requirement

Wrap all embedding generation and similarity queries behind an `EmbeddingService` interface. The implementation can swap `pgvector` for Pinecone, Qdrant, or Weaviate later without changing the callers.

```php
interface EmbeddingService
{
    public function embed(string $text, string $model): array;
    public function similar(array $vector, string $collection, int $limit): Collection;
}
```

Do not call an embedding provider directly from a service class or job.

---

## Retention Policies

### `observations` table

| Phase | Trigger | Action |
|-------|---------|--------|
| 1 | `processed_at` + 30 days | Null out `raw_payload` column; `raw_payload_ref` retained |
| 2 | `processed_at` + 180 days | Hard delete the row if `status = processed` |
| N/A | Any age | Never delete rows with `status = failed`; retain for debugging |

Object storage objects referenced by `raw_payload_ref` follow a separate lifecycle policy configured in the storage provider (S3 lifecycle rules or equivalent). Default: 90-day expiry.

### `content_assets` table

| Phase | Trigger | Action |
|-------|---------|--------|
| 1 | Campaign `status = archived` | Soft delete (`deleted_at`) |
| 2 | `deleted_at` + 730 days (2 years) | Hard delete |

Media files in object storage are deleted when the hard delete runs.

### `executions` table

| Phase | Trigger | Action |
|-------|---------|--------|
| 1 | `status = failed` + 90 days + Learning extracted | Hard delete |
| N/A | `status = completed` | Retain indefinitely (measurement data) |

### `digital_twins.health_score`

Recomputed weekly by the `RecalculateDigitalTwinHealth` job. No pruning — the score is a single column that is overwritten, not a time series. If historical health scores become valuable, introduce a `digital_twin_health_snapshots` table.

### Retention enforcement

All prune operations run as scheduled Laravel commands:

```
php artisan model:prune          # uses MassPrunable on eligible models
php artisan atlas:prune-payloads # nulls raw_payload per observation retention schedule
php artisan atlas:prune-storage  # removes orphaned object storage objects
```

Schedule these in `routes/console.php` or `app/Console/Kernel.php`. Run during off-peak hours (e.g., 03:00 UTC daily).

---

## Backup and Recovery

### Strategy

| Layer | Mechanism | RPO | Notes |
|-------|-----------|-----|-------|
| PostgreSQL | Continuous WAL archiving + daily base backup | < 5 minutes | Enables point-in-time recovery (PITR) to any second |
| Object storage | S3 versioning | N/A (versioned) | Previous versions retained per bucket policy |
| Redis | AOF persistence | < 1 second | For queue state; cache is disposable |

### Recovery targets (MVP)

| Scenario | RTO Target | Approach |
|----------|------------|----------|
| Single table corruption | < 30 minutes | PITR restore to a point before corruption; replay WAL |
| Full database loss | < 2 hours | Restore latest base backup + WAL replay |
| Accidental bulk delete | < 1 hour | PITR to pre-delete timestamp |

### Implementation checklist

- [ ] WAL archiving configured and verified (pg_basebackup or managed equivalent)
- [ ] Automated daily base backup with retention of 30 days
- [ ] Backup restoration tested monthly in a staging environment
- [ ] Object storage bucket versioning enabled
- [ ] Redis AOF enabled; RDB snapshots every 15 minutes
- [ ] Alerting on backup job failures

### Environment separation

Production data must never be copied to developer laptops. Staging uses anonymized snapshots generated by a dedicated seed command (`php artisan atlas:seed-staging`) that replaces PII with generated values. Developers work against local databases seeded from factories.

---

## Entity Relationship Overview

This is a database-level summary. For semantic definitions and lifecycle states, see `specs/core/domain-model.md`.

```
users ──────────────────────────────────────────────────────┐
                                                            │ N
companies ──── company_memberships ─────────────────────────┘
    │
    ├── digital_twins (1:1)
    │
    ├── integrations (1:N)
    │       └── observations (1:N)
    │               └── facts (1:N, superseded_by_id self-ref)
    │
    ├── knowledge_entries (1:N)
    │
    ├── catalogs (1:1)
    │       └── catalog_items (1:N)
    │               └── [subject of opportunities, polymorphic]
    │
    ├── channels (1:N, also global templates with company_id = null)
    │
    ├── opportunities (1:N)
    │       │   subject_type / subject_id → CatalogItem | Catalog | Company
    │       └── decisions (1:1)
    │               ├── recommendations (1:1)
    │               │       └── approvals (1:1, polymorphic approvable)
    │               └── campaigns (1:1)
    │                       └── content_assets (1:N)
    │                               ├── approvals (1:1, polymorphic approvable)
    │                               └── executions (1:1)
    │
    └── learnings (1:N)
            source_type / source_id → Approval | Execution
            subject_type / subject_id → Campaign | ContentAsset | Channel | CatalogItem
```

### Key join paths

| Question | Join path |
|----------|-----------|
| All pending recommendations for a company | `recommendations` where `company_id` + `status = pending` |
| The catalog item behind a recommendation | `recommendations → decisions → opportunities → subject (CatalogItem)` |
| All content assets for a campaign | `campaigns → content_assets` |
| Who approved a recommendation | `recommendations → approvals → users` |
| Learnings derived from a rejection | `approvals → learnings` via `source_type = rejection, source_id` |
| Active facts for a company | `facts` where `company_id` + `is_current = true` |
| Unapplied learnings | `learnings` where `company_id` + `applied_at IS NULL` |
