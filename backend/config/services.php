<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
    ],

    'ollama' => [
        // Ollama remains private: the provider rejects non-loopback base URLs.
        'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen3:14b'),
        'context_length' => (int) env('OLLAMA_CONTEXT_LENGTH', 8192),
        'think' => (bool) env('OLLAMA_THINK', false),
    ],

    // OpenAI Images API — the default (swappable) image generation provider.
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        'image' => [
            'model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
            // Cheapest tier for gpt-image-1. Widen only if quality demands it.
            'quality' => env('OPENAI_IMAGE_QUALITY', 'low'),
            // List-price estimate in USD per generated image, used only for
            // cost accounting. Verify against the OpenAI pricing page before
            // relying on it — third-party comparison figures disagree.
            'cost_usd' => (float) env('OPENAI_IMAGE_COST_USD', 0.011),
        ],
    ],

    // Stripe billing. Test keys only until a real product/price is created in
    // the Stripe dashboard; the app never resolves the live provider without
    // STRIPE_SECRET set. See docs (CM-79 runbook).
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // Meta Graph API (Instagram/Facebook publishing OAuth). No Meta App is
    // registered yet — these are config stubs only, unusable until real
    // values are set.
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'redirect_uri' => env('META_REDIRECT_URI'),
    ],

    // The null driver is inert. The sentry driver is activated only when both
    // it and a production DSN are configured.
    'error_tracking' => [
        'driver' => env('ERROR_TRACKING_DRIVER', 'null'),
        'dsn' => env('ERROR_TRACKING_DSN'),
    ],

];
