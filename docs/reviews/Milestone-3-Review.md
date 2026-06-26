# Milestone 3 CTO Review — Fact Extraction & Knowledge Synthesis

**Date:** 2026-06-26
**Milestone:** Milestone 3 — Fact Extraction & Knowledge Synthesis
**Reviewer:** Prepared for CTO review

> **Cleanup pass applied post-review.** Two items resolved: `Observation.facts()` relationship added; `KnowledgeService` now updates `last_enriched_at` on every synthesis run. See CHANGELOG.md [Milestone 3 Cleanup] for details.

---

## Milestone Summary

### What Was Implemented

Milestone 3 built the intelligence layer of the Atlas loop — the complete path from raw Observation through AI-powered fact extraction, knowledge synthesis, and BusinessBrain assembly. No campaigns, no content, no channel rendering.

Specifically delivered:

- **`Fact` model + migration** — atomic, versioned knowledge units with `is_current` superseding (never delete; archive old facts). Indexed on `(company_id, key, is_current)`.
- **`Knowledge` model + migration** — higher-order insights synthesized from groups of Facts. Table: `knowledge_entries` (avoids SQL reserved word). `active()` scope handles `expires_at` logic.
- **`FactData` value object** — typed, readonly data carrier from AI extraction to persistence. Decouples `WebsiteAnalyst` from Eloquent.
- **`FactRepository` + `KnowledgeRepository`** — query encapsulation; always use `withoutGlobalScopes()` to be safe in queue/CLI contexts.
- **`FactExtractionPrompt`** — extends abstract `Prompt`; low temperature (0.1) for determinism; structured JSON schema; versioned at `1.0`.
- **`StructuredResponseParser`** — strips markdown code fences if present; throws `InvalidArgumentException` on non-JSON or non-array responses.
- **`WebsiteAnalyst`** — implements `Analyst`; reads `Observation.raw_payload` (WebPageData JSON); calls `AiProvider::complete()`; returns `Collection<int, FactData>`. Returns empty collection if `bodyText` is empty — no AI call made.
- **`FactService`** — `storeExtracted(Observation, Collection<int, FactData>): Collection<int, Fact>`; creates new Fact, supersedes existing current fact for same key, fires `FactExtracted`.
- **`KnowledgeService`** — `synthesizeForCompany(Company)`: groups current Facts by top-level domain key; creates/updates one `Knowledge` entry per domain (type: `context`); fires `KnowledgeSynthesized`; activates the DigitalTwin if it is still `initializing`.
- **`BusinessBrainService::for(Company)`** — assembles the `BusinessBrain` value object from current Facts, active Knowledge, recent Observations, DigitalTwin, and Catalog. Never persisted.
- **Real `ProcessObservation`** — replaced the M2 stub; full pipeline: `markProcessing → WebsiteAnalyst → FactService → KnowledgeService → markProcessed → ObservationProcessed`; marks failed and re-throws on error.
- **4 domain events** — `FactExtracted`, `KnowledgeSynthesized`, `ObservationProcessed`, `DigitalTwinActivated`.
- **`Company` model updated** — `facts()` and `knowledge()` `hasMany` relationships added.
- **`AppServiceProvider` updated** — binds `AiProvider` to `FakeAiProvider` in `testing` environment; production binding is a pre-launch prerequisite.
- **34 new tests** — 7 test classes; FakeAiProvider fixture pattern used throughout. 82 total tests, 80 passing, 2 Redis-skipped.
- **PHPStan level 8** — 0 errors. Pint — clean.

### Stop Boundary Respected

Milestone 3 stopped at BusinessBrain assembly. Not implemented:

- Opportunity detection
- Decision engine
- Campaign engine
- Marketing assets
- Channel renderers
- Any publishing

---

## Database

### New Migrations

| File | Table | Purpose |
|------|-------|---------|
| `2026_06_26_001000_create_facts_table.php` | `facts` | Atomic knowledge units; `is_current` versioning |
| `2026_06_26_001100_create_knowledge_entries_table.php` | `knowledge_entries` | Synthesized domain insights |

### `facts` Table

| Column | Type | Notes |
|--------|------|-------|
| id | char(26) | ULID PK |
| company_id | char(26) | FK → companies |
| observation_id | char(26) | nullable FK → observations; nullOnDelete |
| key | string | Dot-notation: `business.name`, `services.primary` |
| value | json | Always JSON, even for scalars |
| data_type | enum | `integer`, `float`, `string`, `boolean`, `json` |
| confidence | tinyint unsigned | 0–100 |
| is_current | boolean | false when superseded |
| superseded_by_id | char(26) | nullable; FK to facts (self-referential) |
| valid_from | timestamp | when this fact became true |
| valid_until | timestamp | nullable; set when superseded |
| timestamps | — | standard |

**Index:** `(company_id, key, is_current)` — primary query path.

### `knowledge_entries` Table

| Column | Type | Notes |
|--------|------|-------|
| id | char(26) | ULID PK |
| company_id | char(26) | FK → companies |
| type | enum | `pattern`, `insight`, `preference`, `performance`, `context` |
| subject | string | top-level domain (e.g., `business`, `services`, `contact`) |
| body | text | human-readable synthesis statement |
| structured | json | machine-readable map of `key → value` |
| source_fact_ids | json | array of contributing Fact IDs |
| confidence | tinyint unsigned | 0–100; average of source facts |
| is_active | boolean | false when superseded or invalidated |
| generated_at | timestamp | when this entry was created/updated |
| expires_at | timestamp | nullable; used for time-sensitive knowledge |
| timestamps | — | standard |

**Index:** `(company_id, type, is_active)`.

---

## Domain

### Models Created

| Model | File | Key Notes |
|-------|------|-----------|
| `Fact` | `app/Models/Fact.php` | `BelongsToCompany`, `HasUlids`; `value` cast as `json`; `is_current` boolean; `current()` local scope |
| `Knowledge` | `app/Models/Knowledge.php` | `BelongsToCompany`, `HasUlids`; table `knowledge_entries`; `active()` scope handles `expires_at` |

### Services Created

| Service | File | Responsibility |
|---------|------|----------------|
| `FactData` | `app/Services/Brain/Data/FactData.php` | Readonly VO: key, value, dataType, confidence |
| `FactRepository` | `app/Services/Brain/FactRepository.php` | `findCurrent()`, `currentForCompany()` |
| `KnowledgeRepository` | `app/Services/Brain/KnowledgeRepository.php` | `activeForCompany()`, `findActiveForSubject()` |
| `FactService` | `app/Services/Brain/FactService.php` | `storeExtracted(Observation, Collection): Collection<Fact>`; handles superseding |
| `KnowledgeService` | `app/Services/Brain/KnowledgeService.php` | `synthesizeForCompany(Company)`: groups facts by domain, upserts Knowledge, activates twin |
| `BusinessBrainService` | `app/Services/Brain/BusinessBrainService.php` | `for(Company): BusinessBrain`; assembles value object from DB |
| `WebsiteAnalyst` | `app/Services/Analyst/WebsiteAnalyst.php` | Implements `Analyst`; calls `AiProvider`, parses response into `Collection<FactData>` |

### AI Layer Created

| Class | File | Purpose |
|-------|------|---------|
| `FactExtractionPrompt` | `app/AI/Prompts/FactExtractionPrompt.php` | Structured prompt for fact extraction; version `1.0`; schema-driven output |
| `StructuredResponseParser` | `app/AI/StructuredResponseParser.php` | Parses AI JSON response; strips markdown fences; throws on invalid JSON |

### Events Created

| Event | Fired When |
|-------|------------|
| `FactExtracted` | After each Fact is persisted by `FactService` |
| `KnowledgeSynthesized` | After each Knowledge entry is created/updated |
| `ObservationProcessed` | After `ProcessObservation` completes successfully |
| `DigitalTwinActivated` | When `KnowledgeService` transitions twin from `initializing` → `active` |

### Jobs Updated

**`ProcessObservation`** — fully implemented. Was a stub (M2). Now:
1. `markProcessing()`
2. `WebsiteAnalyst::analyze(Observation)` → `Collection<FactData>`
3. `FactService::storeExtracted()` → persists Facts
4. `KnowledgeService::synthesizeForCompany()` → upserts Knowledge, may activate twin
5. `markProcessed()` + `ObservationProcessed::dispatch()`
6. On any exception: `markFailed()` then re-throw (queue retry mechanism handles retries)

---

## Testing

### New Test Classes

| File | Tests | Covers |
|------|-------|--------|
| `tests/Unit/AI/StructuredResponseParserTest.php` | 4 | Plain JSON, markdown fences, code fences, invalid JSON exception |
| `tests/Unit/AI/FactExtractionPromptTest.php` | 5 | System/user strings, schema structure, version, temperature |
| `tests/Feature/Brain/WebsiteAnalystTest.php` | 3 | Fact extraction via FakeAiProvider, correct field mapping, empty payload short-circuit |
| `tests/Feature/Brain/FactServiceTest.php` | 4 | Persists facts, superseding, observation linkage, empty input |
| `tests/Feature/Brain/KnowledgeServiceTest.php` | 6 | Synthesis creates entries, fires events, activates twin, no duplicate on re-synthesis, empty facts |
| `tests/Feature/Brain/BusinessBrainServiceTest.php` | 6 | Assembles BusinessBrain; current facts; superseded facts excluded; active knowledge; catalog; M3 empty collections |
| `tests/Feature/Brain/ProcessObservationTest.php` | 6 | End-to-end: observation processed, facts created, knowledge created, twin activated, event fired, failure path |

**Fixture:** `tests/Fixtures/AI/website-facts.json` — 4 sample facts used by WebsiteAnalyst and ProcessObservation tests.

### Test Count

| Status | Count |
|--------|-------|
| Passing | 80 |
| Skipped | 2 (Redis) |
| Failing | 0 |
| Total | 82 |

---

## Technical Debt

| Item | Notes |
|------|-------|
| `AiProvider` has no production implementation | `AppServiceProvider` binds `FakeAiProvider` in `testing`; production requires an `AnthropicProvider` (or `OpenAiProvider`) before `ProcessObservation` can run in production. |
| Knowledge synthesis is rule-based, not AI-powered | M3 synthesis groups facts by top-level domain key. AI-powered synthesis (e.g., pattern detection, cross-fact inference) is deferred to M4+. |
| ~~`DigitalTwin.last_enriched_at` is only set on activation~~ | **Resolved in cleanup pass** — `KnowledgeService` now updates `last_enriched_at` on every synthesis run. |
| `Fact.superseded_by_id` is not a formal FK | SQLite (used in tests) would require deferred FK constraints for self-referential tables. The column is written correctly but the FK is not declared in the migration to maintain SQLite compatibility. |
| `FakeAiProvider` is the default in `testing` | Tests that don't need AI must call `assertNothingSent()` if they want to prove no AI call was made, or explicitly queue a response. The default singleton means any test can accidentally call AI without noticing. |
| `ProcessObservation` only handles `website_crawl` observations | The `WebsiteAnalyst` will return empty if `raw_payload` is not a WebPageData JSON blob. Other observation types (RSS, API) need their own analysts. |

---

## Specification Compliance

### Domain Model (`specs/core/domain-model.md`)

| Requirement | Status | Notes |
|-------------|--------|-------|
| `Fact`: key, value (json), data_type enum, confidence (0-100), is_current, superseded_by_id, valid_from, valid_until | ✅ Compliant | All columns implemented |
| `Fact`: `is_current` flag, never delete | ✅ Compliant | `FactService` supersedes rather than deletes |
| `Fact`: `current()` scope | ✅ Compliant | Local scope on `Fact` model |
| `Fact`: index `(company_id, key, is_current)` | ✅ Compliant | Applied in migration |
| `Knowledge`: table `knowledge_entries` | ✅ Compliant | Avoids SQL reserved word |
| `Knowledge`: type enum (pattern/insight/preference/performance/context) | ✅ Compliant | M3 uses `context` type; others available |
| `Knowledge`: `active()` scope | ✅ Compliant | Handles `expires_at` correctly |
| `Knowledge`: index `(company_id, type, is_active)` | ✅ Compliant | Applied in migration |
| `BusinessBrain`: assembled by `BusinessBrainService::for(Company)` | ✅ Compliant | Returns `BusinessBrain` value object |
| `BusinessBrain`: never persisted | ✅ Compliant | Service-layer only |
| `ObservationProcessed` event | ✅ Compliant | Fired by `ProcessObservation` on success |
| `FactExtracted` event | ✅ Compliant | Fired per Fact by `FactService` |
| `KnowledgeSynthesized` event | ✅ Compliant | Fired per Knowledge entry by `KnowledgeService` |
| `DigitalTwinActivated` event | ✅ Compliant | Fired when twin transitions `initializing → active` |
| Only `Analyst` implementations call `AiProvider` | ✅ Compliant | `WebsiteAnalyst` is the only caller |
| `ProcessObservation` on `ai` queue | ✅ Compliant | Unchanged from M2 |
| `Company hasMany Fact` | ✅ Compliant | Added in M3 |
| `Company hasMany Knowledge` | ✅ Compliant | Added in M3 |
| `Observation hasMany Fact` | ✅ Resolved | `facts()` relationship added to `Observation` model in cleanup pass |

### AI.md

| Requirement | Status | Notes |
|-------------|--------|-------|
| Only Analysts call `AiProvider` | ✅ Compliant | `WebsiteAnalyst` is the only AI caller |
| Prompt is versioned | ✅ Compliant | `FactExtractionPrompt::version()` returns `'1.0'` |
| Structured JSON outputs | ✅ Compliant | Schema defined in `FactExtractionPrompt::schema()` |
| `FakeAiProvider` used in tests | ✅ Compliant | All AI tests use fixture-based `FakeAiProvider` |
| No AI in tests without fake | ✅ Compliant | Real AI calls would fail with empty queue |

---

## Ready for Milestone 4?

**YES, with prerequisites.**

The core intelligence loop is complete and tested. The Digital Twin now activates after the first observation cycle. `BusinessBrainService::for(Company)` returns a populated `BusinessBrain` with Facts and Knowledge.

**What Milestone 4 can build on immediately:**

- `BusinessBrain` is already the input type for the Opportunity Engine
- `activeFacts` and `activeKnowledge` on the `BusinessBrain` are populated Collections
- DigitalTwin `status: active` signals the twin is ready for opportunity detection
- Domain events are in place (`FactExtracted`, `KnowledgeSynthesized`) for event-driven triggers

**Prerequisites for Milestone 4:**

1. **Real `AiProvider` implementation** — bind `AnthropicProvider` (or `OpenAiProvider`) in `AppServiceProvider` before any job runs in production.
2. **Opportunity model + migration** — spec is fully defined in `specs/core/domain-model.md`.
3. **`OpportunityDetector` contract implementations** — rule-based detectors run first; AI analyst supplements.
4. **Decision model + migration** — one Decision per Opportunity; required rationale fields enforced in service.
5. **`Observation → Fact` relationship** — add `hasMany` to `Observation` model when needed by Opportunity Engine queries.
