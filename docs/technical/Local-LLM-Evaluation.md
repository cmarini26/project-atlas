# Local LLM Evaluation (Ollama vs Anthropic)

Part of the CM-80 local-LLM epic (task CM-86). This is the harness and the
agreed bar for deciding whether local inference may become a default path for a
task. Today it covers **website fact extraction** only.

## What it does

`php artisan ai:eval:fact-extraction` runs the **real** `FactExtractionPrompt`
— the same `Prompt` object production uses, prompt semantics unchanged —
through each named provider over a set of synthetic cases, scores the output,
and writes a machine-readable JSON report.

```
php artisan ai:eval:fact-extraction \
  --providers=anthropic,ollama \
  --gate=ollama \
  --cases=eval/fact-extraction/cases \
  --out=storage/app/eval/run.json
```

All flags default from `config('ai.eval.*')`. Exit code is `0` when the gate
provider clears every threshold (or was not run), `1` otherwise.

## Cases

`eval/fact-extraction/cases/*.json` — **synthetic only, never customer
content**. Each file:

| Field | Meaning |
|-------|---------|
| `name` | Unique case id |
| `url`, `title`, `body_text` | The page fed to the prompt |
| `expected_keys` | Dot-notation fact keys a competent extractor should produce |
| `expected_values` | `key → substring` the extracted value should contain |
| `notes` | Rationale / what the case probes |

Add cases by dropping in a new JSON file. Keep them small and representative of
the CBB Auctions and exotic-car-dealer verticals.

## Metrics (per provider, averaged over cases)

| Metric | Definition |
|--------|-----------|
| `schema_valid_rate` | Fraction of cases where the response parsed and contained a usable `facts` array. A provider exception (unreachable, model missing, OOM, schema-invalid) counts as invalid and is listed under `failures` with its category. |
| `precision` | matched keys ÷ extracted keys |
| `recall` | matched keys ÷ expected keys |
| `f1` | harmonic mean of precision and recall |
| `value_accuracy` | For `expected_values`, fraction whose extracted value contains the expected substring (case-insensitive). `null` when a case defines none. |
| `unsupported_claims_per_case` | Mean count of extracted keys with **no** expected counterpart. A *proxy* for hallucination / over-extraction, not a precise measure. |
| `latency_ms.avg` / `.max`, `tokens.avg_input` / `.avg_output` | Wall-clock and token cost from `AiResponse`. |

`failures[]` records `{case, category, message}`; `category` is the
`LocalAiException` category (`unavailable`, `model_missing`, `out_of_memory`,
`invalid_response`) or `error`.

## Acceptance thresholds

The gate provider (default `ollama`) must clear **all** of these for the
command to pass. Defaults in `config/ai.php`; override via env.

| Threshold | Default | Env |
|-----------|---------|-----|
| `min_schema_valid_rate` | 0.95 | `AI_EVAL_MIN_SCHEMA_VALID_RATE` |
| `min_recall` | 0.80 | `AI_EVAL_MIN_RECALL` |
| `min_f1` | 0.75 | `AI_EVAL_MIN_F1` |
| `max_unsupported_claims_per_case` | 1.5 | `AI_EVAL_MAX_UNSUPPORTED_PER_CASE` |

Anthropic is always measured as the **baseline** but never gated. Local
inference does not become a default path for a task until its gated run passes
**and** its metrics are within a reasonable margin of the Anthropic baseline on
the same run (judgement call, recorded in CM-88).

## Recording a run

Every report already captures `generated_at`, `git_sha`, the case list, and per
provider `model` + `settings`. For an Ollama run, **also record by hand** in the
CM-88 decision note:

- Exact model tag and **quantization** (`ollama show <model>` → parameter size
  / quantization; the API only exposes the tag).
- `context_length` and `think` (in the report `settings`, sourced from
  `OLLAMA_CONTEXT_LENGTH` / `OLLAMA_THINK`).
- Host: machine, unified/GPU memory, other models resident during the run.

Reports land in `storage/app/eval/` (git-ignored). Keep the ones that inform a
decision by copying them next to the CM-88 note.

## Notes / limitations

- Key-set precision/recall rewards using the **documented key vocabulary**
  (`business.name`, `contact.email`, …). A model that extracts the right fact
  under a differently-named key scores as a miss — this is intentional:
  downstream Business Brain consumers key on those names.
- `unsupported_claims` counts *any* unexpected key, including legitimately
  useful extra facts. Read it alongside the raw `per_case` output, not alone.
- The harness makes real provider calls. Running the `anthropic` provider needs
  `ANTHROPIC_API_KEY`; running `ollama` needs the service up with the model
  pulled (`php artisan ai:local:health`).
