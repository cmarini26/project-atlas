<?php

use App\ErrorTracking\SentryEventScrubber;

$sentryEnabled = env('ERROR_TRACKING_DRIVER', 'null') === 'sentry';

return [
    'dsn' => $sentryEnabled ? env('ERROR_TRACKING_DSN') : null,
    'environment' => env('APP_ENV', 'production'),
    'release' => env('SENTRY_RELEASE', env('APP_VERSION')),

    'sample_rate' => 1.0,
    'traces_sample_rate' => $sentryEnabled
        ? (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.05)
        : null,
    'profiles_sample_rate' => null,

    'send_default_pii' => false,
    'max_request_body_size' => 'none',
    'enable_logs' => false,
    'enable_metrics' => false,

    'before_send' => [SentryEventScrubber::class, 'scrub'],

    'breadcrumbs' => [
        'logs' => false,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => false,
        'http_client_requests' => false,
        'notifications' => false,
    ],

    'tracing' => [
        'queue_job_transactions' => true,
        'queue_jobs' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'sql_origin' => true,
        'sql_origin_threshold_ms' => 100,
        'views' => true,
        'livewire' => false,
        'http_client_requests' => false,
        'cache' => true,
        'redis_commands' => false,
        'redis_origin' => false,
        'notifications' => false,
        'missing_routes' => false,
        'continue_after_response' => true,
        'gen_ai' => false,
        'gen_ai_invoke_agent' => false,
        'gen_ai_chat' => false,
        'gen_ai_execute_tool' => false,
        'gen_ai_embeddings' => false,
        'default_integrations' => true,
    ],
];
