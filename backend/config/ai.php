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

];
