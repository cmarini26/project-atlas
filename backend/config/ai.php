<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider
    |--------------------------------------------------------------------------
    |
    | Select the provider Atlas resolves for AI work. Provider-specific
    | credentials and endpoints remain in config/services.php.
    |
    */

    'provider' => env('AI_PROVIDER'),

    /*
    |--------------------------------------------------------------------------
    | Local Inference (Ollama)
    |--------------------------------------------------------------------------
    |
    | Operational knobs for the local provider path. Connection details and the
    | model name live in config/services.php under 'ollama'.
    |
    */

    'local' => [
        // Timeout (seconds) for the /api/tags reachability probe used by the
        // readiness check and `ai:local:health`. Kept short so a hung daemon
        // does not stall /api/ready.
        'health_timeout_seconds' => (int) env('AI_LOCAL_HEALTH_TIMEOUT', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Task-level provider routing
    |--------------------------------------------------------------------------
    |
    | Route a single task type to a specific provider without moving the global
    | default above. An empty value means the task uses the default provider.
    |
    | CM-85 pilot: set AI_FACT_EXTRACTION_PROVIDER=ollama to run website fact
    | extraction on the local model while every other task stays on the
    | default. Unset it to restore the prior path — no code change.
    | Supported values match AI_PROVIDER: anthropic, local, fake, ollama.
    |
    */

    'task_providers' => [
        'fact_extraction' => env('AI_FACT_EXTRACTION_PROVIDER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Evaluation harness (CM-86)
    |--------------------------------------------------------------------------
    |
    | Defaults for `php artisan ai:eval:fact-extraction`. The gate provider is
    | the candidate whose metrics must clear the thresholds for the command to
    | exit 0 — the baseline (anthropic) is measured for comparison but never
    | gated. Thresholds are the agreed bar before local inference may become a
    | default path; see docs/technical/Local-LLM-Evaluation.md.
    |
    */

    'eval' => [
        'providers' => ['anthropic', 'ollama'],
        'gate_provider' => 'ollama',
        'thresholds' => [
            'min_schema_valid_rate' => (float) env('AI_EVAL_MIN_SCHEMA_VALID_RATE', 0.95),
            'min_recall' => (float) env('AI_EVAL_MIN_RECALL', 0.80),
            'min_f1' => (float) env('AI_EVAL_MIN_F1', 0.75),
            'max_unsupported_claims_per_case' => (float) env('AI_EVAL_MAX_UNSUPPORTED_PER_CASE', 1.5),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Provider
    |--------------------------------------------------------------------------
    |
    | Provider Atlas resolves for image generation. Independent of the text
    | provider above — per-image pricing varies by an order of magnitude
    | across vendors, so this is kept swappable. Supported values: openai,
    | fake. Provider credentials live in config/services.php.
    |
    */

    'image' => [
        'provider' => env('AI_IMAGE_PROVIDER'),
    ],

];
