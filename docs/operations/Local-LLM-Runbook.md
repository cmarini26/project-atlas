# Local LLM Runbook (Ollama on the Mac mini)

Operating [Ollama](https://ollama.com) as a local inference backend for Atlas.
Part of the CM-80 local-LLM epic (task CM-87). Pairs with:

- `docs/technical/AI.md` — the `AiProvider` abstraction, `OllamaAiProvider`,
  the `LocalAiException` categories, and task-level routing.
- `docs/technical/Local-LLM-Evaluation.md` — the quality bar and harness.

> **Approval is unchanged.** Anything a local model produces is subject to the
> exact same validation and human-approval controls as hosted-model output:
> JSON Schema validation on every structured response, and no external
> publishing without explicit human approval. A local model is a swappable
> inference backend, never a bypass of Atlas's guardrails.

---

## 1. Host profile

| Item | Value |
|------|-------|
| Machine | Mac mini (Apple Silicon), 24 GB unified memory |
| Model | `qwen3:14b` (`OLLAMA_MODEL`) |
| Memory footprint | ~9–10 GB resident for the 14B Q4 weights + ~1–3 GB for an 8K context. Budget ~13 GB peak; leaves headroom for the OS and the PHP workers. Do **not** run a second model concurrently on this host. |
| Initial context | 8192 tokens (`OLLAMA_CONTEXT_LENGTH`). Raising it increases memory roughly linearly — re-check the footprint before changing. |
| Concurrency | **1 in-flight request.** Atlas serialises this by running local inference only on the `ai` queue with a **single worker** (`--queue=ai` on its own process, no `OLLAMA_NUM_PARALLEL` override). More than one concurrent generation will swap and time out on 24 GB. |
| Bind address | Loopback only — `127.0.0.1:11434`. Enforced in two places (see §4). |

---

## 2. Install & service management

Install (Homebrew):

```bash
brew install ollama
```

Run as a background service (loopback bind is the default; §4 verifies it):

```bash
brew services start ollama          # start + keep running across reboots
brew services stop ollama
brew services restart ollama
brew services info ollama           # status
```

Foreground (debugging only):

```bash
OLLAMA_HOST=127.0.0.1:11434 ollama serve
```

Logs:

```bash
tail -f "$(brew --prefix)/var/log/ollama.log"      # brew services
# foreground runs log to stdout
```

API base URL: `http://127.0.0.1:11434`. Endpoints Atlas uses: `POST /api/chat`
(inference) and `GET /api/tags` (health probe).

---

## 3. Model lifecycle

```bash
ollama pull qwen3:14b        # download / update the model (first run: several GB)
ollama list                  # installed models + sizes + quantization tag
ollama show qwen3:14b        # parameter count, quantization, context, template
ollama ps                    # currently loaded models + VRAM/RAM + keep-alive TTL
ollama stop qwen3:14b        # unload from memory now (does not delete)
ollama rm qwen3:14b          # delete weights from disk
```

**Unload behaviour.** Ollama keeps a model resident for `keep_alive` after the
last request (default 5 min) then frees it. To free memory sooner, `ollama stop`.
To keep it always warm (faster first request, permanent ~10 GB cost), set
`OLLAMA_KEEP_ALIVE=-1` in the service environment. On this host the default is
fine — the `ai` queue is low-volume.

**Record for evaluations.** `ollama show qwen3:14b` prints the quantization
(e.g. `Q4_K_M`) — copy it into any eval run note (see Local-LLM-Evaluation.md),
since the API only exposes the bare tag.

---

## 4. Security: localhost-only binding

Two independent guarantees:

1. **Ollama** is started bound to `127.0.0.1` (the default, and explicit in the
   foreground command above). Never set `OLLAMA_HOST=0.0.0.0`.
2. **Atlas** rejects a non-loopback `OLLAMA_BASE_URL` at construction —
   `OllamaAiProvider` throws `InvalidArgumentException` for any host that is not
   `127.0.0.1`, `localhost`, or `[::1]`, and for any URL carrying a path, query,
   fragment, or credentials. So even a misconfigured `.env` cannot point Atlas
   at a remote Ollama.

**Verify the listening socket** (should show only loopback):

```bash
lsof -nP -iTCP:11434 -sTCP:LISTEN
# expect: ... TCP 127.0.0.1:11434 (LISTEN)   — NOT *:11434 or 0.0.0.0:11434

# from another machine on the LAN this must fail / time out:
curl -m 3 http://<mac-mini-lan-ip>:11434/api/tags   # expected: connection refused
```

If you see `*:11434`, stop the service, unset `OLLAMA_HOST`/set it to
`127.0.0.1:11434`, restart, and re-verify.

---

## 5. Health checks

Atlas ships a probe of reachability + required-model presence:

```bash
php artisan ai:local:health            # human-readable table, exit 0 = healthy
php artisan ai:local:health --json     # machine-readable
```

It also feeds `GET /api/ready` **when `AI_PROVIDER=ollama`** — a missing model
or unreachable daemon makes readiness return `503 {status: degraded, checks:
{ollama: {status: error, ...}}}`. When Ollama is not the active provider the
check is omitted so a hosted deployment never degrades on its account.

Raw check without Atlas:

```bash
curl -s http://127.0.0.1:11434/api/tags | jq '.models[].name'
```

---

## 6. Enabling / disabling local inference for Atlas

Local inference is **opt-in per task**. Today only website fact extraction can
be routed (see `docs/technical/AI.md` → task-level routing).

```dotenv
# .env — route ONLY fact extraction to the local model:
AI_FACT_EXTRACTION_PROVIDER=ollama
# everything else stays on AI_PROVIDER (anthropic in staging/prod).
```

```bash
php artisan config:clear    # if config is cached
```

**Disable / roll back — no code change:** remove `AI_FACT_EXTRACTION_PROVIDER`
(or set it blank), `php artisan config:clear`, restart the `ai` worker. Fact
extraction is immediately back on the default provider. Nothing else to undo.

Confirm the running state: the app logs `AI task routing active.` at boot when
an override is set; absence of that line means everything is on the default.

---

## 7. Failure behaviour (what Atlas does)

`OllamaAiProvider` raises a typed `LocalAiException` per condition; the value in
logs is `failure_category`.

| Condition | Category | Retried? | Operator action |
|-----------|----------|----------|-----------------|
| Daemon down / connection refused / timeout / HTTP 5xx | `unavailable` | Yes — bounded backoff in the provider (4 attempts), then the observation is parked `retrying` and the queued job retries | Start/restart Ollama (§2); `ai:local:health` |
| Configured model not pulled | `model_missing` | No — job fails fast | `ollama pull qwen3:14b` (§3) |
| Out of memory / allocation failure | `out_of_memory` | No — job fails fast | `ollama ps` then `ollama stop` other models; lower `OLLAMA_CONTEXT_LENGTH`; use a smaller quant |
| Malformed / truncated / schema-invalid output | `invalid_response` | No — job fails fast | Inspect prompt/schema and model output; consider a stronger model. Retrying as-is will not help |

Every exception also carries an operator-actionable `guidance` string, surfaced
in the failed-job record and (for source-asset observations) on the asset's
`processing_error`.

Bounded retries: the `ai` queue jobs use `$tries = 3` with backoff. A local
model can never be hammered by an unbounded retry loop — transient failures get
4 provider attempts + up to 3 job attempts, permanent failures get 1.

---

## 8. Troubleshooting

**`ai:local:health` says `unreachable`.**
`brew services info ollama` → if stopped, `brew services start ollama`. Check
`$(brew --prefix)/var/log/ollama.log` for a bind error or a crash loop.

**`unreachable` but the daemon is up.**
Wrong port/host. `lsof -nP -iTCP:11434 -sTCP:LISTEN` — confirm it's listening on
`127.0.0.1:11434`. Confirm `OLLAMA_BASE_URL` in Atlas's `.env` matches.

**`model_missing`.**
`ollama list` — if `qwen3:14b` is absent, `ollama pull qwen3:14b`. If present
under a different tag, align `OLLAMA_MODEL` to the exact tag.

**First request after idle is very slow (30–90 s), then fast.**
Cold model load. Expected. Optionally `OLLAMA_KEEP_ALIVE=-1` to keep it warm
(§3) at a permanent memory cost.

**Generations time out under load.**
More than one concurrent request on 24 GB. Ensure exactly one worker consumes
the `ai` queue and that no other process is calling Ollama.

**Memory pressure / system sluggish.**
`ollama ps` — if multiple models are loaded, `ollama stop <model>` the ones you
don't need. Only `qwen3:14b` should ever be resident on this host.

**Schema-validation failures spike (`invalid_response`).**
The model drifted or was changed. Run the eval harness
(`php artisan ai:eval:fact-extraction`, see Local-LLM-Evaluation.md) to quantify;
if it's below threshold, roll back to the default provider (§6) while
investigating.

---

## 9. Upgrades

**Ollama runtime:**

```bash
php artisan ai:local:health              # baseline: healthy
brew upgrade ollama && brew services restart ollama
php artisan ai:local:health              # confirm still healthy
```

**Model version:**

```bash
ollama pull qwen3:14b                     # pulls a newer build of the same tag
php artisan ai:eval:fact-extraction --providers=ollama,anthropic --gate=ollama
# only keep the new build routed if the gated eval still passes
```

Record the new `ollama show` quantization/build alongside the eval result.

**Rollback:** revert `AI_FACT_EXTRACTION_PROVIDER` (§6). If a bad model build
is the problem and a prior build isn't cached, re-pull the known-good tag from
your notes.

---

## 10. Disk cleanup

Models live under `~/.ollama/models`.

```bash
du -sh ~/.ollama/models                   # total on disk
ollama list                               # per-model sizes
ollama rm <unused-model>                  # remove models you no longer route to
```

Keep only `qwen3:14b` unless a migration is in progress. There is no request/
response cache to prune — Atlas stores nothing model-side. Ollama's own logs
rotate via `brew services`; if running foreground under a supervisor, rotate
that supervisor's log.
