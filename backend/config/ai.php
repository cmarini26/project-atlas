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

];
