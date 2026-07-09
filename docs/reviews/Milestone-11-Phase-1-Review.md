# Milestone 11 Phase 1 — Marketing Presence Domain Model — Review

**Date:** 2026-07-08
**Scope:** Phase 1 only, per [Milestone-11-Marketing-Presence.md](../plans/Milestone-11-Marketing-Presence.md). No service layer, no onboarding, no Settings UI, no Business Brain integration, no Opportunity Engine changes, no publishing changes, no UI.
**Tests:** 715 total (713 passing, 2 Redis skipped) — 48 new
**PHPStan:** Level 8 — 0 errors · **Pint:** Clean · **Frontend build:** unaffected (no frontend files touched), verified green

---

## What shipped

### Database

- `database/migrations/2026_07_08_000100_create_marketing_channels_table.php` — implements the `marketing_channels` table exactly as specified in `specs/core/marketing-presence.md` §2 and the plan's Phase 1 migration block, with no deviation:
  - `id` (ULID PK), `company_id` (indexed, FK → `companies.id`, `cascadeOnDelete`), `channel_id` (nullable, indexed, FK → `channels.id`, `nullOnDelete`)
  - `type` (12-value enum), `status` (4-value enum, default `active`), `importance` (3-value enum, default `secondary`), `posting_frequency` (7-value enum, default `unknown`)
  - `objective` (`json`, required — no default, matching the spec's "array, min 1 item" requirement)
  - `display_name` (required string), `handle_or_url`/`audience`/`notes`/`metadata` (nullable)
  - `is_connected`, `supports_publishing`, `supports_analytics` (booleans, default `false`)
  - Composite indexes: `(company_id, status)`, `(company_id, importance)`, `(company_id, type)`
  - **No `deleted_at` column** — per spec §2, "No soft deletes." A channel the business stops using transitions to `status: inactive`; verified by `MarketingChannelMigrationTest::test_table_has_no_soft_delete_column`.
  - **No unique constraint on `(company_id, type)`** — per spec §2, a company may declare the same type more than once (two Instagram accounts, multiple Print placements).
- Ran against the local Postgres database (`php artisan migrate`) — confirmed clean, and rolled forward/back checked via `migrate --pretend` before applying.

### Domain Model

- `app/Models/MarketingChannel.php` — `use BelongsToCompany, HasFactory, HasUlids;`. `BelongsToCompany` (not `Channel`'s nullable-`company_id`/no-scope pattern) per spec §2's explicit instruction, since a `MarketingChannel` is always company-specific.
  - `belongsTo(Channel::class)` — nullable relationship, resolves to `null` when `channel_id` is unset (the common "declared but not linked" case)
  - `company()` relationship comes from the `BelongsToCompany` trait, consistent with `Opportunity`/`Fact`/`Knowledge`
  - Casts: `type`, `status`, `importance`, `posting_frequency` cast to native PHP backed enums (see below); `objective` and `metadata` cast to `array`; the three capability booleans cast to `boolean`
  - Query scopes: `scopeActive()`, `scopePrimary()`, `scopeConnected()` — implemented exactly as given in the plan, with return type `void` (not `Builder`) to match this codebase's existing scope convention (`Fact::scopeCurrent()`, `Knowledge::scopeActive()`) rather than the plan snippet's illustrative `Builder` return type, which Larastan rejects as an under-specified generic (see "Deviations from the plan" below)
  - `static rules(): array` — structural validation rules (enum membership, required fields, `objective` array shape) via `Illuminate\Validation\Rule::enum()`. Deliberately does **not** include the soft duplicate-`handle_or_url` check described in spec §2 — that requires querying existing rows and is explicitly Phase 2 (`MarketingPresenceService`) work, not a Domain Model concern.
- `app/Models/Company.php` — added `marketingChannels(): HasMany`, matching the existing relationship-listing convention (`opportunities()`, `decisions()`, etc.) already on the model. This is the only modification to an existing file in this phase.

### Enums / Value Objects

The plan and spec define five distinct constrained vocabularies for this one entity — more than any other model in the codebase carries today. Rather than fall back to this repo's usual bare-string-plus-service-validation pattern (no other model uses PHP native enums), five backed enums were introduced, since the task explicitly called for "Enums/value objects where appropriate" and this is the clearest case for it in the codebase so far:

- `App\Enums\MarketingChannelType` (12 cases) — plus `hasChannelEquivalent(): bool`, a pure, DB-free method encoding spec §3/§6's fact that only `email`, `instagram`, `facebook`, `linkedin`, `x` have a corresponding `App\Models\Channel` type today. This is a stable property of the *type itself*, independent of any company's data, which is why it lives on the enum rather than waiting for Phase 2's `MarketingChannelCapabilityResolver` (which resolves a specific *instance's* capability from its `channel_id`/flags — a different, later concern).
- `App\Enums\MarketingChannelStatus` (4 cases)
- `App\Enums\MarketingChannelImportance` (3 cases)
- `App\Enums\MarketingChannelObjective` (7 cases)
- `App\Enums\PostingFrequency` (7 cases)
- `App\Enums\Concerns\EnumValues` — a small shared trait providing `values(): array` (used by the migration's enum column definitions' single source of truth check, and directly by tests) to avoid repeating `array_column(self::cases(), 'value')` five times.

Native Eloquent enum casting means `$marketingChannel->type` returns a `MarketingChannelType` instance, not a raw string — this is new for this codebase (no other model does this yet) but was judged worth the small inconsistency given how many constrained fields this one entity has; Larastan (PHPStan level 8) has full, correct support for it.

### Factory

- `database/factories/MarketingChannelFactory.php` — `company_id` defaults to `Company::factory()` (auto-creating a parent when not given, standard Laravel factory behavior); `type` is a random valid enum value; sensible defaults for everything else (`status: active`, `importance: secondary`, `objective: ['awareness']`, `posting_frequency: unknown`, all three capability booleans `false`, `channel_id: null`).
- Two convenience states: `primary()` and `inactive()`.
- **No `connected()` state.** A `connected()` state was drafted and then removed: satisfying `MarketingChannel::connected()`'s scope (`channel_id` set *and* `is_connected = true`) requires a real linked `Channel` row, and no `ChannelFactory` exists in this codebase (only `Company` and `User` have factories today, confirmed before starting). Adding one was out of scope for this task. Tests that need a genuinely connected row create the `Channel` directly (`Channel::withoutGlobalScopes()->create([...])`, matching this repo's established pattern) and pass `channel_id` explicitly — see `MarketingChannelScopeTest::test_connected_scope_requires_both_channel_id_and_is_connected`.
- Per the plan's own note: most domain models in this repo (`Opportunity`, `Fact`, `Knowledge`, `Channel`) don't have factories at all — tests construct rows directly. The `MarketingChannelFactory` was added because it was explicitly requested, and this phase's own tests use both styles side by side (factory tests exercise the factory itself; model/relationship/scope/isolation tests construct rows directly, matching the rest of the suite).

### Seeding

**`DemoSeeder` was not modified.** The Phase 1 section of the implementation plan makes no mention of seeding, and the task instructions said to update it "only if required by the implementation plan" — it is not. No `MarketingChannel` rows are seeded anywhere yet.

### Onboarding

**Not touched**, per explicit instruction. `OnboardingController` and all onboarding Vue pages are unmodified.

---

## Tests (48 new)

| File | Covers |
|---|---|
| `tests/Unit/MarketingPresence/MarketingChannelEnumsTest.php` | Pure, framework-free: each enum's exact value set; `hasChannelEquivalent()` returns `true` for exactly the five channel-equivalent types and `false` for the other seven |
| `tests/Feature/MarketingPresence/MarketingChannelMigrationTest.php` | Table and all 18 columns exist; no `deleted_at`; `company_id` FK cascades on delete; `channel_id` FK nulls on delete; the `type` column's DB-level CHECK constraint rejects an out-of-enum value |
| `tests/Feature/MarketingPresence/MarketingChannelModelTest.php` | Mass assignment via `$fillable`; enum columns cast to backed-enum instances; `objective`/`metadata` cast to array; boolean casts; DB column defaults (`active`/`secondary`/`unknown`/`false`×3/`null`); nullable fields accept `null` |
| `tests/Feature/MarketingPresence/MarketingChannelRelationshipTest.php` | `belongsTo(Company)`, `belongsTo(Channel)` when linked and when not linked (`null`), `Company::marketingChannels()` `hasMany` |
| `tests/Feature/MarketingPresence/MarketingChannelScopeTest.php` | `active()`, `primary()`, `connected()` individually and combined; `connected()` specifically requires **both** `channel_id` set **and** `is_connected = true` — a declared-but-unconnected or flagged-but-unlinked row is excluded either way |
| `tests/Feature/MarketingPresence/MarketingChannelTenantIsolationTest.php` | Bound-company filtering, unbound-company visibility (mirrors `Tests\Feature\Discovery\TenantIsolationTest`'s exact pattern), cross-company `find()` returns `null`, explicit `company_id` filtering (the shape `BusinessBrainService` will use in Phase 5) |
| `tests/Feature/MarketingPresence/MarketingChannelFactoryTest.php` | Factory persists a valid row; auto-creates a `Company` when none given; accepts an explicit `Company`; default state matches spec defaults; `primary()`/`inactive()` states; `count()`; `make()` does not persist |
| `tests/Feature/MarketingPresence/MarketingChannelValidationTest.php` | `MarketingChannel::rules()` accepts a fully valid payload; rejects an unknown `type`/`status`/`importance`/`posting_frequency`; requires `display_name`; requires `objective` to be non-empty and rejects an unknown value inside the array; `posting_frequency` is nullable |

No test in this phase touches a service, a controller, a Vue component, `BusinessBrain`, `DecisionEngine`, or any publishing class — consistent with the phase boundary.

---

## Deviations from the plan (and why)

1. **Scope method return type: `void`, not `Builder`.** The plan's illustrative code for `scopeActive()`/`scopePrimary()`/`scopeConnected()` returns `Builder`. This codebase's existing local scopes (`Fact::scopeCurrent()`, `Knowledge::scopeActive()`) return `void` and only type the `@param`. Returning `Builder<MarketingChannel>` without further generic specification is exactly what PHPStan level 8 flags (`missingType.generics`) — the plan's snippet was illustrative pseudocode, not meant to be copied verbatim past a static-analysis gate. Fixed to match the existing, working convention; behavior is identical (Eloquent's local scope mechanism doesn't require an explicit return).
2. **`posting_frequency` is `NOT NULL DEFAULT 'unknown'`, not a nullable column**, exactly as the plan's migration code specifies — even though spec §2's field table describes it as "Nullable." This was a deliberate reconciliation, flagged in the spec document itself: `unknown` already *is* the null-sentinel value ("default when not specified," spec §4.4), so a non-nullable column with that default achieves the same practical effect (the field is never meaningfully absent) without a column that's nullable in name only. No test asserts `posting_frequency` can be stored as literal SQL `NULL`; `MarketingChannelValidationTest::test_posting_frequency_is_nullable` tests that the *validation rule* accepts `null` as an input value (for a future form that hasn't decided what to store yet) — distinct from the column's own nullability.
3. **No `MarketingChannelCapabilityResolver`.** The plan lists it under Phase 2 ("Service Layer"), and the task's explicit boundary said "No services" for this phase. `MarketingChannelType::hasChannelEquivalent()` (a pure enum method, not a service) was added instead, since it's genuinely a Domain Model fact and Phase 2's resolver will call it directly rather than re-deriving the same five-type list.

---

## Quality gates

```
php artisan test           715 tests, 713 passing, 2 Redis-skipped, 0 failures
phpstan analyse (level 8)  0 errors
pint --test                clean
npm run build               succeeds (no frontend files touched this phase)
```

---

## What Phase 1 does not include (confirmed)

- No `MarketingPresenceService` or any other service class
- No onboarding changes — `OnboardingController` and onboarding Vue pages untouched
- No Settings UI, no controller, no route, no Vue component of any kind
- No `BusinessBrain`/`BusinessBrainService` changes — `marketingPresence` does not yet exist on the Business Brain
- No `DecisionEngine`/`OpportunityEngine` changes — channel selection is unaffected
- No publishing changes — `ChannelPublisherRegistry`, `PublishContent`, `LogChannelPublisher`, `EmailPublisher` untouched
- No `DemoSeeder` changes — not required by the Phase 1 plan
- No claim, anywhere in code or tests, that any channel can be published to or measured as a result of this work

---

## Next step

Phase 2 — Service Layer (`MarketingPresenceService`, `MarketingChannelCapabilityResolver`, the `MarketingPresenceUpdated` event) — is specified in the plan but **not started**. Per instruction, this session stops here.
